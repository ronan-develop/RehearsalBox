<?php

declare(strict_types=1);

namespace App\Assembly\Presenter;

use App\Assembly\Entity\Assembly;

/** Lignes de la liste des comptes rendus (une par compte rendu, dernière version) ; texte brut, le gabarit échappe. */
final class AssemblyListView
{
    /**
     * @param list<Assembly> $heads
     * @return list<array{id: int, title: string, date: string, status: string, version: int, url: string}>
     */
    public function rows(array $heads): array
    {
        return array_map(static fn (Assembly $a): array => [
            'id' => $a->id,
            'title' => 'Assemblée générale ' . $a->kind->label(),
            'date' => $a->heldOn->format('d/m/Y'),
            'status' => $a->isDraft() ? ($a->previousId === null ? 'Brouillon' : 'Correction de la version ' . ($a->version - 1) . ' en cours') : 'Validé',
            'version' => $a->version,
            'url' => '/admin/assemblies/' . $a->id,
        ], $heads);
    }
}
