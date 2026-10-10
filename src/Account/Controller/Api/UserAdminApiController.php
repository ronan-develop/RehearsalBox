<?php

declare(strict_types=1);

namespace App\Account\Controller\Api;

use App\Account\Entity\AdminUserItem;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Account\Service\UserAccountAdminService;
use App\Account\Service\UserAdminServiceInterface;
use App\Account\Exception\UserValidationException;

/**
 * Gestion des comptes (#138). Admin uniquement. L'admin ne choisit ni ne voit jamais
 * un mot de passe : un compte créé ici n'a aucun mot de passe connu, l'utilisateur
 * passe par « Mot de passe oublié » pour sa première connexion.
 */
final class UserAdminApiController
{
    public function __construct(
        private readonly UserAdminServiceInterface $userAdminService,
        private readonly AuthGuard $authGuard,
        private readonly UserAccountAdminService $accounts,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $items = $this->userAdminService->listUsers(new \DateTimeImmutable());

        return new JsonResponse(['users' => array_map(self::itemToArray(...), $items)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $email = $request->body('email');
        $displayName = $request->body('displayName');
        $roleValue = $request->body('role');
        $groupId = $request->body('groupId');

        $fields = [];
        if (!is_string($email)) {
            $fields['email'] = 'Adresse email invalide.';
        }
        if (!is_string($displayName)) {
            $fields['displayName'] = 'Nom affiché requis (100 caractères maximum).';
        }
        $role = is_string($roleValue) ? UserRole::tryFrom($roleValue) : null;
        if ($role === null) {
            $fields['role'] = 'Rôle invalide.';
        }
        if ($groupId !== null && $groupId !== '' && !is_int($groupId) && !(is_string($groupId) && ctype_digit($groupId))) {
            $fields['groupId'] = 'Groupe invalide.';
        }
        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $fields], 422);
        }

        $groupIdValue = ($groupId === null || $groupId === '') ? null : (int) $groupId;

        $user = $this->userAdminService->create(trim((string) $email), (string) $displayName, $role, $groupIdValue);

        return new JsonResponse(self::userToArray($user), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);

        $active = $request->body('active');
        if (!is_bool($active)) {
            return new JsonResponse(['error' => "Le champ « active » (vrai ou faux) est requis."], 422);
        }

        $userId = self::parseId($id);
        if ($userId === null) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }

        $user = $this->userAdminService->setActive($userId, $active, $actor->id());

        return new JsonResponse(self::userToArray($user));
    }

    public function unlock(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $userId = self::parseId($id);
        if ($userId === null) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }

        $user = $this->userAdminService->unlock($userId);

        return new JsonResponse(self::userToArray($user));
    }

    /** Nom affiché et adresse e-mail d'un compte (#272). Jamais de mot de passe : l'administrateur n'en choisit ni n'en connaît. */
    public function updateIdentity(Request $request, string $id): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);

        $userId = self::parseId($id);
        if ($userId === null) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }
        $displayName = $request->body('displayName');
        $email = $request->body('email');
        $fields = [];
        if (!is_string($displayName)) {
            $fields['displayName'] = 'Nom affiché requis (100 caractères maximum).';
        }
        if (!is_string($email)) {
            $fields['email'] = 'Adresse email invalide.';
        }
        if ($fields !== []) {
            throw new UserValidationException($fields);
        }

        return new JsonResponse(self::userToArray($this->accounts->updateIdentity($userId, (string) $displayName, (string) $email, $actor->id())));
    }

    /** Rôle d'un compte (#272) : jamais le dernier administrateur actif, jamais soi-même. */
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);

        $userId = self::parseId($id);
        if ($userId === null) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }
        $value = $request->body('role');
        $role = is_string($value) ? UserRole::tryFrom($value) : null;
        if ($role === null) {
            throw new UserValidationException(['role' => 'Rôle invalide.']);
        }

        return new JsonResponse(self::userToArray($this->accounts->changeRole($userId, $role, $actor->id())));
    }

    /** Identifiant strict : uniquement des chiffres (« 1.5 », « abc », « -1 » ne sont pas réinterprétés). */
    private static function parseId(string $id): ?int
    {
        return ctype_digit($id) && strlen($id) <= 10 ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private static function userToArray(User $user): array
    {
        return [
            'id' => $user->id(),
            'email' => $user->email(),
            'displayName' => $user->displayName(),
            'role' => $user->role()->value,
            'isActive' => $user->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private static function itemToArray(AdminUserItem $item): array
    {
        return self::userToArray($item->user()) + [
            'isLocked' => $item->isLocked(),
            'groups' => array_map(static fn (Group $g): array => ['id' => $g->id(), 'name' => $g->name(), 'role' => $item->groupRole($g->id())], $item->groups()),
        ];
    }
}
