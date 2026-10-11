<?php

declare(strict_types=1);

namespace App\Account\Service\Data;

use App\Account\Exception\UserNotFoundException;
use App\Account\Repository\AccountDataRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Export des données d'UNE personne (#431), à sa demande : son profil, ses groupes, les messages qu'elle a écrits, ses réservations et
 * ses demandes de créneau. Jamais de secret (mot de passe, empreinte, compteur de session) ni de donnée d'autrui. L'identité vient de la
 * session de l'appelant, jamais de la requête.
 */
final class AccountDataExporter
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AccountDataRepositoryInterface $data,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{generatedAt: string, account: array{id: int, email: string, displayName: string, role: string, active: bool}, groups: list<array<string, mixed>>, messages: list<array<string, mixed>>, bookings: list<array<string, mixed>>, slotRequests: list<array<string, mixed>>}
     *
     * @throws UserNotFoundException
     */
    public function export(int $userId): array
    {
        $user = $this->users->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");

        return [
            'generatedAt' => $this->clock->now()->format(\DATE_ATOM),
            'account' => [
                'id' => $user->id(),
                'email' => $user->email(),
                'displayName' => $user->displayName(),
                'role' => $user->role()->value,
                'active' => $user->isActive(),
            ],
            'groups' => $this->data->memberships($userId),
            'messages' => $this->data->messages($userId),
            'bookings' => $this->data->bookings($userId),
            'slotRequests' => $this->data->slotRequests($userId),
        ];
    }
}
