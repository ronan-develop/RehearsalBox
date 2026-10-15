<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\Resolution;

/**
 * Saisie validée d'un brouillon (sortie de AssemblyInputPolicy). Le client n'envoie que des identifiants de comptes : le service
 * relit les noms en base. Heures au format `HH:MM`.
 */
final class AssemblyDraft
{
    /**
     * @param array<string, int> $boardUserIds identifiant de compte par valeur de BoardPost
     * @param list<array{userId: ?int, name: ?string, attendance: Attendance, proxyHolder: ?string}> $attendees
     * @param list<AgendaItem> $agenda
     * @param list<Resolution> $resolutions
     */
    public function __construct(
        public readonly AssemblyKind $kind,
        public readonly \DateTimeImmutable $heldOn,
        public readonly ?string $location,
        public readonly ?string $openedAt,
        public readonly ?string $closedAt,
        public readonly ?bool $quorumReached,
        public readonly ?string $moralReport,
        public readonly ?string $financialReport,
        public readonly ?string $otherBusiness,
        public readonly array $boardUserIds,
        public readonly array $attendees,
        public readonly array $agenda,
        public readonly array $resolutions,
    ) {
    }
}
