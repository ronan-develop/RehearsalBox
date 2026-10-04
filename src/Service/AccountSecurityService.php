<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\User;
use App\Repository\Contract\PasswordResetRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\ResetToken;
use App\Service\Exception\InvalidResetTokenException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use App\Mail\MailRenderer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Alerte envoyée après un changement de mot de passe, et bouton « Ce n'est pas moi ».
 *
 * « Ce n'est pas moi » ne restaure PAS l'ancien mot de passe (l'auteur du changement le connaît
 * forcément) : il verrouille le compte, ferme toutes les sessions et envoie un lien de
 * réinitialisation à l'adresse du compte, que seul son propriétaire contrôle.
 */
final class AccountSecurityService
{
    private const ALERT_TTL = '+24 hours';
    private const LOCK_DURATION = '+7 days';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordResetRepositoryInterface $resetRepository,
        private readonly MailerInterface $mailer,
        private readonly TransactionRunner $transactions,
        private readonly PasswordResetService $passwordReset,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
    ) {
    }

    /** Un échec d'envoi n'est pas remonté (le changement de mot de passe a déjà réussi) : le jeton est annulé. */
    public function sendPasswordChangedAlert(User $user, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $token = ResetToken::generate();
        $this->resetRepository->invalidateAllForUser($user->id(), $now, PasswordResetRepositoryInterface::PURPOSE_ALERT);
        $this->resetRepository->create(
            $user->id(),
            ResetToken::hash($token),
            $now->modify(self::ALERT_TTL),
            $now,
            PasswordResetRepositoryInterface::PURPOSE_ALERT,
        );

        try {
            $this->mailer->send($this->buildAlertMail($user->email(), $token));
        } catch (TransportExceptionInterface) {
            $this->resetRepository->invalidateAllForUser($user->id(), $now, PasswordResetRepositoryInterface::PURPOSE_ALERT);
            error_log(sprintf('Alerte de changement de mot de passe : envoi du mail impossible (utilisateur #%d).', $user->id()));
        }
    }

    /** @throws InvalidResetTokenException jeton inconnu, expiré, déjà utilisé ou d'une autre finalité */
    public function secureAccount(string $token, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $email = $this->transactions->run(function () use ($token, $now): string {
            $userId = $this->resetRepository->consume(ResetToken::hash($token), $now, PasswordResetRepositoryInterface::PURPOSE_ALERT);
            $user = $userId === null ? null : $this->userRepository->findById($userId);
            if ($user === null) {
                throw new InvalidResetTokenException();
            }

            $this->userRepository->save($user->withSessionsRevoked()->withLockedUntil($now->modify(self::LOCK_DURATION)));

            return $user->email();
        });

        // Le propriétaire reprend la main par sa boîte mail : lien de réinitialisation envoyé.
        $this->passwordReset->requestReset($email, $now);
    }

    private function buildAlertMail(string $to, string $token): Email
    {
        $link = rtrim($this->baseUrl, '/') . '/account/secure?token=' . $token;

        $mail = ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->render('account-alert', [
            'link' => $link,
            'preheader' => 'Votre mot de passe vient d\'être modifié. Si ce n\'est pas vous, sécurisez votre compte.',
        ]);

        return (new Email())
            ->from($this->fromAddress)
            ->to($to)
            ->subject('RehearsalBox — votre mot de passe a été modifié')
            ->html($mail['html'])
            ->text($mail['text']);
    }
}
