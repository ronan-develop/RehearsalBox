<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AdminUserItem;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\UserAdminServiceInterface;
use App\Service\Exception\UserAdminRuleException;
use App\Service\Exception\UserNotFoundException;
use App\Service\Exception\UserValidationException;

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

        try {
            $user = $this->userAdminService->create(trim((string) $email), (string) $displayName, $role, $groupIdValue);
        } catch (UserValidationException $e) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422);
        }

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

        try {
            $user = $this->userAdminService->setActive($userId, $active, $actor->id());
        } catch (UserNotFoundException) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        } catch (UserAdminRuleException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(self::userToArray($user));
    }

    public function unlock(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $userId = self::parseId($id);
        if ($userId === null) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }

        try {
            $user = $this->userAdminService->unlock($userId);
        } catch (UserNotFoundException) {
            return new JsonResponse(['error' => 'Utilisateur introuvable.'], 404);
        }

        return new JsonResponse(self::userToArray($user));
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
            'groups' => array_map(static fn (Group $g): array => ['id' => $g->id(), 'name' => $g->name()], $item->groups()),
        ];
    }
}
