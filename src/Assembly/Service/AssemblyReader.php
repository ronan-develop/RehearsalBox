<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Account\Repository\UserRepositoryInterface;
use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Repository\AssemblyContentRepositoryInterface;
use App\Assembly\Repository\AssemblyRepositoryInterface;

/** Lecture des comptes rendus d'AG, réservée aux administrateurs (#361). */
final class AssemblyReader
{
    public function __construct(
        private readonly AssemblyAccess $access,
        private readonly AssemblyRepositoryInterface $assemblies,
        private readonly AssemblyContentRepositoryInterface $contents,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    /** @return list<Assembly> la dernière version de chaque compte rendu */
    public function list(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->assemblies->listHeads();
    }

    /**
     * @return array{assembly: Assembly, content: AssemblyContent, successor: ?Assembly}
     * @throws AssemblyNotFoundException
     */
    public function minutes(User $actor, int $id): array
    {
        $this->access->assertAdmin($actor);
        $assembly = $this->assemblies->find($id) ?? throw new AssemblyNotFoundException();

        return ['assembly' => $assembly, 'content' => $this->contents->load($id), 'successor' => $this->assemblies->successorOf($id)];
    }

    /** @return list<User> les comptes actifs proposés pour le bureau et les présences */
    public function candidates(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return array_values(array_filter($this->users->findAll(), static fn (User $user): bool => $user->isActive()));
    }
}
