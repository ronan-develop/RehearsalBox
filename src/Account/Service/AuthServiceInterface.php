<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Group\Entity\Group;
use App\Account\Entity\User;

interface AuthServiceInterface
{
    /** Retourne null en cas d'échec (email inconnu, mot de passe incorrect, compte verrouillé) — jamais de distinction, cf. plan §10.4. */
    public function attempt(string $email, string $plainPassword): ?User;

    public function currentUser(): ?User;

    /** Garde l'appareil courant connecté après un changement de version de session : nouvel identifiant de session, version resynchronisée. */
    public function refreshSession(User $user): void;

    public function logout(): void;

    /** @return list<Group> groupes du dernier utilisateur connecté, vide si un seul groupe (ou aucun) — pas de sélection nécessaire */
    public function groupsRequiringSelection(): array;

    /** @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId */
    public function selectActiveGroup(int $groupId): void;
}
