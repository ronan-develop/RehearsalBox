<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;

/**
 * Qui peut tenir chaque poste du bureau (#361, #478). Le titulaire de chaque poste est réglé dans l'administration (`/admin/board`) :
 * la liste du formulaire ne propose que son compte, et le serveur refuse tout autre compte. Un poste sans titulaire reste libre (tout
 * compte actif) ; un titulaire dont le compte n'est plus actif n'offre personne (échec fermé : jamais un autre compte à sa place).
 * Son prénom et nom remplace le nom du compte dans la liste et dans le compte rendu.
 */
final class AssemblyBoardRoster
{
    /** @var array<string, BoardHolder> titulaire par valeur de BoardPost */
    private readonly array $holders;

    /** @param iterable<BoardHolder> $holders */
    public function __construct(iterable $holders)
    {
        $byPost = [];
        foreach ($holders as $holder) {
            $byPost[$holder->post->value] = $holder;
        }
        $this->holders = $byPost;
    }

    /** @return array<string, string> prénom et nom des titulaires, par poste (valeur de BoardPost) */
    public function names(): array
    {
        return array_map(static fn (BoardHolder $holder): string => $holder->fullName, $this->holders);
    }

    /** Le prénom et nom du titulaire si ce compte tient le poste, sinon null (nom du compte). */
    public function nameFor(BoardPost $post, User $user): ?string
    {
        return $this->isRestricted($post) && $this->allows($post, $user) ? $this->holders[$post->value]->fullName : null;
    }

    public function isRestricted(BoardPost $post): bool
    {
        return isset($this->holders[$post->value]);
    }

    /**
     * @param list<User> $activeUsers
     * @return list<User>
     */
    public function candidates(BoardPost $post, array $activeUsers): array
    {
        if (!$this->isRestricted($post)) {
            return $activeUsers;
        }

        return array_values(array_filter($activeUsers, fn (User $user): bool => $this->allows($post, $user)));
    }

    public function allows(BoardPost $post, User $user): bool
    {
        return !$this->isRestricted($post) || $user->id() === $this->holders[$post->value]->userId;
    }
}
