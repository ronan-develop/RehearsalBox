<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Le contenu d'une version : bureau, présences, ordre du jour et résolutions (les tables filles de `assemblies`). */
final class AssemblyContent
{
    /**
     * @param array<string, BoardMember> $board indexé par la valeur de BoardPost
     * @param list<Attendee> $attendees
     * @param list<AgendaItem> $agenda
     * @param list<Resolution> $resolutions
     */
    public function __construct(
        public readonly array $board,
        public readonly array $attendees,
        public readonly array $agenda,
        public readonly array $resolutions,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }
}
