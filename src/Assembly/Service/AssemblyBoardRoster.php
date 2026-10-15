<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Assembly\Entity\BoardPost;

/**
 * Qui peut tenir chaque poste du bureau (#361). Le titulaire de chaque poste est désigné par l'adresse de son compte dans la
 * configuration locale (`assembly.board`, jamais dans le dépôt public) : la liste du formulaire ne propose que lui, et le serveur
 * refuse tout autre compte. Un poste sans adresse configurée reste libre (tout compte actif) ; un poste dont le compte n'existe
 * pas ou n'est plus actif n'offre personne (échec fermé : jamais un autre compte à sa place). Le prénom et nom du titulaire
 * (`assembly.board_names`, lui aussi hors dépôt) remplace le nom du compte dans la liste et dans le compte rendu.
 */
final class AssemblyBoardRoster
{
    /** @var array<string, string> adresse du titulaire (minuscules, rognée) par valeur de BoardPost */
    private readonly array $holders;

    /** @var array<string, string> prénom et nom du titulaire d'un poste restreint, par valeur de BoardPost */
    private readonly array $names;

    /**
     * @param array<array-key, mixed> $emails adresse du compte titulaire par valeur de BoardPost ; vide ou invalide = poste libre
     * @param array<array-key, mixed> $names prénom et nom du titulaire par valeur de BoardPost ; vide, invalide ou poste libre = nom du compte
     */
    public function __construct(array $emails, array $names = [])
    {
        $holders = [];
        $fullNames = [];
        foreach (BoardPost::cases() as $post) {
            $email = $emails[$post->value] ?? null;
            if (is_string($email) && trim($email) !== '') {
                $holders[$post->value] = mb_strtolower(trim($email));
                $name = $names[$post->value] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    $fullNames[$post->value] = trim($name);
                }
            }
        }
        $this->holders = $holders;
        $this->names = $fullNames;
    }

    /** @return array<string, string> prénom et nom configurés, pour les seuls postes restreints */
    public function names(): array
    {
        return $this->names;
    }

    /** Le prénom et nom configuré du poste si ce compte en est le titulaire, sinon null (nom du compte). */
    public function nameFor(BoardPost $post, User $user): ?string
    {
        return isset($this->names[$post->value]) && $this->allows($post, $user) ? $this->names[$post->value] : null;
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
