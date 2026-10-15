<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Assembly\Entity\BoardPost;

/**
 * Qui peut tenir chaque poste du bureau (#361). Le titulaire de chaque poste est désigné par l'adresse de son compte dans la
 * configuration locale (`assembly.board`, jamais dans le dépôt public) : la liste du formulaire ne propose que lui, et le serveur
 * refuse tout autre compte. Un poste sans adresse configurée reste libre (tout compte actif) ; un poste dont le compte n'existe
 * pas ou n'est plus actif n'offre personne (échec fermé : jamais un autre compte à sa place).
 */
final class AssemblyBoardRoster
{
    /** @var array<string, string> adresse du titulaire (minuscules, rognée) par valeur de BoardPost */
    private readonly array $holders;

    /** @param array<array-key, mixed> $emails adresse du compte titulaire par valeur de BoardPost ; vide ou invalide = poste libre */
    public function __construct(array $emails)
    {
        $holders = [];
        foreach (BoardPost::cases() as $post) {
            $email = $emails[$post->value] ?? null;
            if (is_string($email) && trim($email) !== '') {
                $holders[$post->value] = mb_strtolower(trim($email));
            }
        }
        $this->holders = $holders;
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
        return !$this->isRestricted($post) || mb_strtolower($user->email()) === $this->holders[$post->value];
    }
}
