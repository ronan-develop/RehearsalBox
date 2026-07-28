<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\Group;
use App\Entity\User;

interface AuthServiceInterface
{
    /** Retourne null en cas d'échec (email inconnu, mot de passe incorrect, compte verrouillé) — jamais de distinction, cf. plan §10.4. */
    public function attempt(string $email, string $plainPassword): ?User;

    public function currentUser(): ?User;

    public function logout(): void;

    /** @return list<Group> groupes du dernier utilisateur connecté, vide si un seul groupe (ou aucun) — pas de sélection nécessaire */
    public function groupsRequiringSelection(): array;

    /** @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId */
    public function selectActiveGroup(int $groupId): void;
}
