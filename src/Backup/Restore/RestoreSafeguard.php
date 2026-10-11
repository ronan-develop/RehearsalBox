<?php

declare(strict_types=1);

namespace App\Backup\Restore;

use App\Account\Entity\User;
use App\Mail\Mailbox;

/**
 * Garde-fous de la restauration lancée depuis le web (#424), en plus du compte unique, du mot de passe et du mot de confirmation :
 * (1) elle est FERMÉE par défaut (`restore.enabled`), à ouvrir à la main sur le serveur le temps d'une restauration ; (2) chaque
 * demande acceptée alerte par e-mail le compte qui la lance (un compte dont la session serait volée ne restaure pas en silence).
 */
final class RestoreSafeguard
{
    public function __construct(
        private readonly bool $enabled,
        private readonly Mailbox $mailbox,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** L'envoi ne bloque jamais la restauration (sendSafely journalise l'échec). */
    public function announce(User $user, string $file, \DateTimeImmutable $at): void
    {
        $this->mailbox->sendSafely(fn () => $this->mailbox->compose(
            $user->email(),
            'une restauration de la base a été lancée',
            'restore-started',
            ['file' => $file, 'when' => $at->format('d/m/Y \\à H:i'), 'preheader' => 'Une restauration de la base de données vient d\'être lancée depuis votre compte.'],
        ), sprintf('Restauration : alerte par e-mail impossible (utilisateur #%d)', $user->id()));
    }
}
