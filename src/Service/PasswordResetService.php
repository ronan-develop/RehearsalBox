<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Repository\Contract\PasswordResetRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Security\PasswordPolicy;
use App\Security\ResetToken;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\Exception\UserValidationException;
use App\Mail\MailRenderer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class PasswordResetService
{
    private const TOKEN_TTL = '+1 hour';
    private const RATE_LIMIT_WINDOW = '-1 hour';
    private const MAX_REQUESTS_PER_WINDOW = 3;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordResetRepositoryInterface $resetRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly MailerInterface $mailer,
        private readonly TransactionRunner $transactions,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
    ) {
    }

    /**
     * Ne révèle jamais l'existence du compte : compte inconnu, inactif, limite
     * atteinte ou échec d'envoi ne produisent ni erreur ni signal observable.
     */
    public function requestReset(string $email, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $user = $this->userRepository->findByEmail($email);
        if ($user === null || !$user->isActive()) {
            return;
        }

        $recentRequests = $this->resetRepository->countCreatedSince($user->id(), $now->modify(self::RATE_LIMIT_WINDOW));
        if ($recentRequests >= self::MAX_REQUESTS_PER_WINDOW) {
            return;
        }

        $token = ResetToken::generate();

        $this->transactions->run(function () use ($user, $token, $now): void {
            $this->resetRepository->invalidateAllForUser($user->id(), $now);
            $this->resetRepository->create($user->id(), ResetToken::hash($token), $now->modify(self::TOKEN_TTL), $now);
        });

        try {
            $this->mailer->send($this->buildMail($user->email(), $token));
        } catch (TransportExceptionInterface) {
            // Le jeton n'a pas pu être remis : on l'annule, sans rien révéler à l'appelant.
            // Aucun jeton ni adresse dans le journal.
            $this->resetRepository->invalidateAllForUser($user->id(), $now);
            error_log(sprintf('Réinitialisation de mot de passe : envoi du mail impossible (utilisateur #%d).', $user->id()));
        }
    }

    /**
     * @throws UserValidationException     mot de passe non conforme (le jeton n'est pas consommé)
     * @throws InvalidResetTokenException  jeton inconnu, expiré, déjà utilisé, ou compte inactif
     */
    public function resetPassword(string $token, string $newPassword, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $violation = $this->passwordPolicy->violation($newPassword);
        if ($violation !== null) {
            throw new UserValidationException(['password' => $violation]);
        }

        $this->transactions->run(function () use ($token, $newPassword, $now): void {
            $userId = $this->resetRepository->consume(ResetToken::hash($token), $now);
            if ($userId === null) {
                throw new InvalidResetTokenException();
            }

            $user = $this->userRepository->findById($userId);
            if ($user === null || !$user->isActive()) {
                // Levée dans la transaction : la consommation du jeton est annulée.
                throw new InvalidResetTokenException();
            }

            $this->userRepository->save($user->withPasswordHash($this->passwordHasher->hash($newPassword)));
        });
    }

    private function buildMail(string $to, string $token): Email
    {
        $link = rtrim($this->baseUrl, '/') . '/reset-password?token=' . $token;

        $mail = ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->render('password-reset', [
            'link' => $link,
            'preheader' => 'Choisissez un nouveau mot de passe (lien valable 1 heure).',
        ]);

        return (new Email())
            ->from($this->fromAddress)
            ->to($to)
            ->subject('RehearsalBox — réinitialisation de votre mot de passe')
            ->html($mail['html'])
            ->text($mail['text']);
    }
}
