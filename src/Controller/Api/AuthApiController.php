<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Service\Contract\AuthServiceInterface;

final class AuthApiController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');
        $displayName = (string) $request->body('displayName', '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Adresse email invalide.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Le mot de passe doit faire au moins 8 caractères.';
        }
        if ($displayName === '' || strlen($displayName) > 100) {
            $errors['displayName'] = 'Nom affiché requis (100 caractères maximum).';
        }
        if ($errors === [] && $this->userRepository->findByEmail($email) !== null) {
            $errors['email'] = 'Un compte existe déjà avec cet email.';
        }

        if ($errors !== []) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $errors], 422);
        }

        $user = $this->userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: $this->passwordHasher->hash($password),
            displayName: $displayName,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        return new JsonResponse(['id' => $user->id(), 'email' => $user->email()], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');

        $user = $this->authService->attempt($email, $password);

        if ($user === null) {
            return new JsonResponse(['error' => 'Identifiants invalides.'], 401);
        }

        return new JsonResponse(['id' => $user->id(), 'displayName' => $user->displayName()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return new JsonResponse(['status' => 'ok']);
    }
}
