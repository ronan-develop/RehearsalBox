<?php

declare(strict_types=1);

namespace App\Entity;

/** Résultat de la validation des mentions d'un message : qui est mentionné, et qui doit être invité (extérieur aux groupes). */
final class MentionPlan
{
    /**
     * @param array<int, string> $labels    identifiant => « @Nom » (seulement les personnes dont le texte contient bien le libellé)
     * @param array<int, string> $outsiders identifiant => nom, pour les personnes extérieures aux deux groupes et pas encore invitées
     */
    public function __construct(private readonly array $labels, private readonly array $outsiders)
    {
    }

    /** @return array<int, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return array<int, string> */
    public function outsiders(): array
    {
        return $this->outsiders;
    }

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }
}
