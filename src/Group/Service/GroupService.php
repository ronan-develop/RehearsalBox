<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Entity\GroupUserRole;
use App\Group\Entity\Group;
use App\Group\Entity\LineupMember;
use App\Group\Entity\UpcomingShow;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Group\Service\GroupInputPolicy;
use App\Support\Slug;
use App\Group\Exception\GroupValidationException;
use App\Group\Service\GroupFilesPurgerInterface;
use App\Group\Service\GroupServiceInterface;

final class GroupService implements GroupServiceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly GroupFilesPurgerInterface $files = new NoGroupFilesPurger(),
        private readonly GroupInputPolicy $policy = new GroupInputPolicy(),
    ) {
    }

    /** @throws GroupValidationException nom, genre, couleur ou e-mail refusé, ou nom déjà pris (même adresse publique) */
    public function create(string $name, ?string $genre, ?string $colorHex, string $contactEmail): Group
    {
        [$name, $genre] = [$this->policy->normalizedName($name), $this->policy->normalizedOptional($genre)];
        $this->assertValid(null, $name, $genre, $colorHex, $contactEmail);

        return $this->groupRepository->save(new Group(0, $name, $genre, $colorHex, $contactEmail));
    }

    /** @throws GroupValidationException */
    public function update(int $groupId, string $name, ?string $genre, ?string $colorHex, string $contactEmail): Group
    {
        if ($this->groupRepository->findById($groupId) === null) {
            throw new \InvalidArgumentException("Groupe {$groupId} introuvable.");
        }
        [$name, $genre] = [$this->policy->normalizedName($name), $this->policy->normalizedOptional($genre)];
        $this->assertValid($groupId, $name, $genre, $colorHex, $contactEmail);

        return $this->groupRepository->save(new Group($groupId, $name, $genre, $colorHex, $contactEmail));
    }

    /** Les documents du groupe partent en cascade en base ; leurs FICHIERS sont listés avant, retirés après (jamais orphelins). */
    public function delete(int $groupId): void
    {
        $files = $this->files->filesOf($groupId);
        $this->groupRepository->delete($groupId);
        $this->files->remove($files);
    }

    public function addMemberByEmail(int $groupId, string $email): void
    {
        if ($this->groupRepository->findById($groupId) === null) {
            throw new \InvalidArgumentException("Groupe {$groupId} introuvable.");
        }
        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            throw new \InvalidArgumentException("Aucun compte n'existe avec l'email {$email}.");
        }

        $this->groupRepository->addMember($groupId, $user->id());
    }

    public function findAll(): array
    {
        return $this->groupRepository->findAll();
    }

    public function updateProfile(int $groupId, array $lineup, array $upcomingShows, int $actorUserId): Group
    {
        $this->assertActorIsManager($groupId, $actorUserId);

        $existing = $this->groupRepository->findById($groupId);
        if ($existing === null) {
            throw new \InvalidArgumentException("Groupe {$groupId} introuvable.");
        }
        $violations = $this->policy->profileViolations($lineup, $upcomingShows);
        if ($violations !== []) {
            throw new GroupValidationException($violations);
        }

        return $this->groupRepository->save(new Group(
            $existing->id(),
            $existing->name(),
            $existing->genre(),
            $existing->colorHex(),
            $existing->contactEmail(),
            $lineup,
            $upcomingShows,
        ));
    }

    /**
     * Valide le groupe et son unicité : deux groupes ne partagent ni leur nom ni leur adresse publique (`/groups/{slug}/space`,
     * dérivée du nom : « Nebula Sprawl » et « nebula-sprawl » donneraient la même, la seconde serait inaccessible).
     *
     * @throws GroupValidationException
     */
    private function assertValid(?int $groupId, string $name, ?string $genre, ?string $colorHex, string $contactEmail): void
    {
        $errors = $this->policy->groupViolations($name, $genre, $colorHex, $contactEmail);
        if (!isset($errors['name'])) {
            foreach ($this->groupRepository->findAll() as $other) {
                if ($other->id() !== $groupId && Slug::from($other->name()) === Slug::from($name)) {
                    $errors['name'] = 'Un groupe porte déjà ce nom (ou un nom trop proche).';
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new GroupValidationException($errors);
        }
    }

    private function assertActorIsManager(int $groupId, int $actorUserId): void
    {
        if ($this->groupRepository->roleOf($groupId, $actorUserId) !== GroupUserRole::Gestionnaire) {
            throw new AccessDeniedException("Vous n'êtes pas gestionnaire de ce groupe.");
        }
    }
}
