<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\User;
use App\Mail\MailRenderer;
use App\Mail\SafeMail;
use App\Repository\Contract\EmailChangeRepositoryInterface;
use App\Repository\Contract\PasswordResetRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Security\ResetToken;
use App\Service\Exception\InvalidEmailChangeException;
use App\Service\Exception\UserValidationException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Changement de sa propre adresse e-mail (#164), en deux temps : la demande (connecté, mot de passe actuel)
 * envoie un lien à la NOUVELLE adresse ; la confirmation (le lien fait foi) change l'adresse, ferme toutes les
 * sessions et alerte l'ANCIENNE adresse. L'identifiant du compte vient de la session, jamais de la requête.
 */
final class EmailChangeService
{
    private const TOKEN_TTL = '+1 hour';
    private const RATE_LIMIT_WINDOW = '-1 hour';
    private const MAX_REQUESTS_PER_WINDOW = 3;
    private const MAX_EMAIL_LENGTH = 190;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly EmailChangeRepositoryInterface $changeRepository,
        private readonly PasswordResetRepositoryInterface $resetRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly MailerInterface $mailer,
        private readonly TransactionRunner $transactions,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
    ) {
    }

    /**
     * Ne révèle jamais si l'adresse demandée a déjà un compte : dans ce cas, comme en cas d'échec d'envoi,
     * la demande a l'air de réussir (aucun mail, aucun jeton, aucune erreur).
     *
     * @throws UserValidationException mot de passe actuel faux ou compte verrouillé, adresse invalide ou identique, trop de demandes
     */
    public function requestChange(int $userId, string $currentPassword, string $newEmail, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $newEmail = trim($newEmail);

        $user = $this->userRepository->findById($userId) ?? throw new \InvalidArgumentException('Utilisateur introuvable.');

        (new CurrentPasswordVerifier($this->userRepository, $this->passwordHasher))->assertMatches($user, $currentPassword, $now);

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > self::MAX_EMAIL_LENGTH) {
            throw new UserValidationException(['email' => 'Adresse email invalide.']);
        }
        if (strcasecmp($newEmail, $user->email()) === 0) {
            throw new UserValidationException(['email' => "C'est déjà votre adresse e-mail."]);
        }

        if ($this->changeRepository->countCreatedSince($user->id(), $now->modify(self::RATE_LIMIT_WINDOW)) >= self::MAX_REQUESTS_PER_WINDOW) {
            throw new UserValidationException(['email' => 'Trop de demandes. Réessayez dans une heure.']);
        }

        // Adresse déjà utilisée par un autre compte : même réponse qu'un succès, sans rien envoyer.
        if ($this->userRepository->findByEmail($newEmail) !== null) {
            return;
        }

        $token = ResetToken::generate();

        $this->transactions->run(function () use ($user, $newEmail, $token, $now): void {
            $this->changeRepository->invalidateAllForUser($user->id(), $now);
            $this->changeRepository->create($user->id(), $newEmail, ResetToken::hash($token), $now->modify(self::TOKEN_TTL), $now);
        });

        $link = rtrim($this->baseUrl, '/') . '/account/email/confirm?token=' . $token;

        $sent = SafeMail::send($this->mailer, fn () => $this->renderer()->compose(
            (new Email())
                ->from($this->fromAddress)
                ->to($newEmail)
                ->subject('RehearsalBox — confirmez votre nouvelle adresse e-mail'),
            'email-change',
            ['link' => $link, 'preheader' => 'Confirmez votre nouvelle adresse (lien valable 1 heure).'],
        ), sprintf("Changement d'adresse e-mail : envoi du mail impossible (utilisateur #%d)", $user->id()));
        if (!$sent) {
            // Le jeton n'a pas pu être remis : on l'annule, sans rien révéler.
            $this->changeRepository->invalidateAllForUser($user->id(), $now);
        }
    }

    /**
     * @throws InvalidEmailChangeException jeton inconnu, expiré, déjà utilisé, compte inactif, ou adresse devenue indisponible
     */
    public function confirm(string $token, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();

        [$updated, $oldEmail] = $this->transactions->run(function () use ($token, $now): array {
            $change = $this->changeRepository->consume(ResetToken::hash($token), $now);
            if ($change === null) {
                throw new InvalidEmailChangeException();
            }

            $user = $this->userRepository->findById($change['userId']);
            if ($user === null || !$user->isActive()) {
                throw new InvalidEmailChangeException();
            }

            // Adresse prise entre la demande et la confirmation : même message qu'un lien expiré.
            $holder = $this->userRepository->findByEmail($change['newEmail']);
            if ($holder !== null && $holder->id() !== $user->id()) {
                throw new InvalidEmailChangeException();
            }

            try {
                $saved = $this->userRepository->save($user->withEmail($change['newEmail']));
            } catch (\PDOException) {
                // Course sur l'unicité de l'adresse (clé unique en base) : même issue.
                throw new InvalidEmailChangeException();
            }
            $this->revokeOutstandingTokens($user->id(), $now);

            return [$saved, $user->email()];
        });

        $this->sendChangedAlert($oldEmail, $updated->email());

        return $updated;
    }

    /**
     * Tout lien déjà envoyé à l'ANCIENNE boîte (réinitialisation, alerte « sécuriser mon compte », autre changement en attente) cesse
     * de valoir : qui garde l'accès à cette boîte ne peut plus s'en servir une fois l'adresse changée. Dans la même transaction.
     */
    private function revokeOutstandingTokens(int $userId, \DateTimeImmutable $now): void
    {
        $this->resetRepository->invalidateAllForUser($userId, $now, PasswordResetRepositoryInterface::PURPOSE_RESET);
        $this->resetRepository->invalidateAllForUser($userId, $now, PasswordResetRepositoryInterface::PURPOSE_ALERT);
        $this->changeRepository->invalidateAllForUser($userId, $now);
    }

    /** L'alerte part à l'ANCIENNE adresse ; la nouvelle y est masquée. Un échec d'envoi n'annule pas le changement. */
    private function sendChangedAlert(string $oldEmail, string $newEmail): void
    {
        SafeMail::send($this->mailer, fn () => $this->renderer()->compose(
            (new Email())
                ->from($this->fromAddress)
                ->to($oldEmail)
                ->subject('RehearsalBox — votre adresse e-mail a été modifiée'),
            'email-changed',
            [
                'newEmailMasked' => self::mask($newEmail),
                'preheader' => "L'adresse e-mail de votre compte a été modifiée.",
            ],
        ), "Changement d'adresse e-mail : alerte à l'ancienne adresse impossible");
    }

    /** n***@domaine : le premier caractère et le domaine, rien d'autre. */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    private function renderer(): MailRenderer
    {
        return $this->mailRenderer ?? MailRenderer::withDefaultTemplates();
    }
}
