<?php

declare(strict_types=1);

namespace App\Association\Service;

use App\Account\Entity\User;
use App\Account\Repository\UserRepositoryInterface;
use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;
use App\Association\Exception\AssociationBoardNotFoundException;
use App\Association\Exception\AssociationBoardValidationException;
use App\Association\Repository\AssociationBoardRepositoryInterface;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Titulaires du bureau (#478), réservés aux administrateurs actifs : un compte actif et un prénom et nom par poste. Le poste vient de
 * l'adresse (valeur de BoardPost, sinon 404) ; un même compte ne tient pas deux postes (le compte rendu les liste par compte). Les
 * écritures sont des remplacements du poste seul, donc sans conflit de révision. Le journal ne reçoit que des identifiants.
 */
final class AssociationBoardService
{
    public const NAME_MAX = 120;

    private const NAME_FORBIDDEN = '/[\x00-\x1F\x7F]/u';

    public function __construct(
        private readonly AdminAccess $access,
        private readonly AssociationBoardRepositoryInterface $board,
        private readonly UserRepositoryInterface $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, BoardHolder> titulaires par valeur de BoardPost */
    public function holders(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->board->all();
    }

    /** @return list<User> comptes actifs proposés pour un poste */
    public function accounts(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return array_values(array_filter($this->users->findAll(), static fn (User $user): bool => $user->isActive()));
    }

    /**
     * @param array<string, mixed> $input `userId` (entier) et `fullName`
     * @throws AssociationBoardNotFoundException poste inconnu
     * @throws AssociationBoardValidationException compte ou nom refusé, ou compte déjà titulaire d'un autre poste
     */
    public function assign(User $actor, string $post, array $input, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $boardPost = BoardPost::tryFrom($post) ?? throw new AssociationBoardNotFoundException();

        $errors = [];
        $userId = $this->account($input['userId'] ?? null, $boardPost, $errors);
        $name = $this->name($input['fullName'] ?? null, $errors);
        if ($errors !== [] || $userId === null || $name === null) {
            throw new AssociationBoardValidationException($errors);
        }

        $this->board->assign($boardPost, $userId, $name, $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Association : bureau modifié', ['actor' => $actor->id(), 'post' => $boardPost->value, 'user' => $userId]);
    }

    /** @throws AssociationBoardNotFoundException poste inconnu */
    public function vacate(User $actor, string $post): void
    {
        $this->access->assertAdmin($actor);
        $boardPost = BoardPost::tryFrom($post) ?? throw new AssociationBoardNotFoundException();

        $this->board->vacate($boardPost);
        $this->logger->info('Association : poste du bureau vidé', ['actor' => $actor->id(), 'post' => $boardPost->value]);
    }

    /** @param array<string, string> $errors */
    private function account(mixed $value, BoardPost $post, array &$errors): ?int
    {
        $user = is_int($value) && $value > 0 ? $this->users->findById($value) : null;
        if ($user === null || !$user->isActive()) {
            $errors['userId'] = 'Choisissez un compte actif.';

            return null;
        }
        foreach ($this->board->all() as $held) {
            if ($held->userId === $user->id() && $held->post !== $post) {
                $errors['userId'] = 'Ce compte tient déjà le poste de ' . mb_strtolower($held->post->label()) . '.';

                return null;
            }
        }

        return $user->id();
    }

    /** @param array<string, string> $errors */
    private function name(mixed $value, array &$errors): ?string
    {
        $clean = is_string($value) ? trim($value) : '';
        if ($clean === '') {
            $errors['fullName'] = 'Le prénom et nom sont obligatoires.';
        } elseif (mb_strlen($clean) > self::NAME_MAX) {
            $errors['fullName'] = 'Ce champ ne peut pas dépasser ' . self::NAME_MAX . ' caractères.';
        } elseif (preg_match(self::NAME_FORBIDDEN, $clean) === 1) {
            $errors['fullName'] = 'Ce champ doit tenir sur une ligne, sans caractère de contrôle.';
        } else {
            return $clean;
        }

        return null;
    }
}
