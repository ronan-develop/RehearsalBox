<?php

declare(strict_types=1);

namespace App\Association\Presenter;

use App\Account\Entity\User;
use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;

/** Données de la page « Bureau de l'association » (#478) : texte brut, le gabarit échappe. */
final class AssociationBoardView
{
    /**
     * @param array<string, BoardHolder> $holders titulaires par valeur de BoardPost
     * @param list<User> $accounts comptes actifs
     * @return list<array{post: string, label: string, userId: string, fullName: string, options: list<array{id: string, name: string}>}>
     */
    public function posts(array $holders, array $accounts): array
    {
        $options = array_map(static fn (User $user): array => ['id' => (string) $user->id(), 'name' => $user->displayName()], $accounts);
        $rows = [];
        foreach (BoardPost::cases() as $post) {
            $holder = $holders[$post->value] ?? null;
            $rows[] = [
                'post' => $post->value,
                'label' => $post->label(),
                'userId' => $holder === null ? '' : (string) $holder->userId,
                'fullName' => $holder->fullName ?? '',
                'options' => $options,
            ];
        }

        return $rows;
    }
}
