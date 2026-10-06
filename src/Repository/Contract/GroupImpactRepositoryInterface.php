<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/** Lecture seule : ce que la suppression de chaque groupe emporterait avec lui (confirmation chiffrée de l'administrateur, #224). */
interface GroupImpactRepositoryInterface
{
    /**
     * Pour chaque groupe : ses membres, ses conversations (initiées ou reçues, corbeille comprise), ses documents et les demandes de
     * créneau qu'il a faites. Un groupe vide a des zéros (jamais d'entrée manquante).
     *
     * @return array<int, array{members: int, conversations: int, documents: int, requests: int}> indexé par identifiant de groupe
     */
    public function countsByGroup(): array;
}
