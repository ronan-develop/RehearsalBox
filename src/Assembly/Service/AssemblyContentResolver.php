<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Repository\UserRepositoryInterface;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardMember;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Exception\AssemblyValidationException;

/** Transforme la saisie validée (identifiants de comptes) en contenu à enregistrer (noms relus en base et recopiés) (#361). */
final class AssemblyContentResolver
{
    private const NAME_MAX = 120;

    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * Relit les noms en base. Un compte inactif ou introuvable est refusé, sauf s'il figure déjà dans le contenu enregistré
     * (une correction recopie le bureau et les présences d'une version validée, dont un titulaire a pu être désactivé depuis).
     */
    public function resolve(AssemblyDraft $draft, AssemblyContent $current): AssemblyContent
    {
        $already = [];
        foreach ($current->board as $member) {
            $already[$member->userId] = true;
        }
        foreach ($current->attendees as $attendee) {
            if ($attendee->userId !== null) {
                $already[$attendee->userId] = true;
            }
        }

        $errors = [];
        $name = function (int $userId, string $field) use ($already, &$errors): string {
            $user = $this->users->findById($userId);
            if ($user === null || (!$user->isActive() && !isset($already[$userId]))) {
                $errors[$field] = 'Ce compte n\'existe pas ou n\'est plus actif.';

                return '';
            }

            return mb_substr($user->displayName(), 0, self::NAME_MAX);
        };

        $board = [];
        foreach (BoardPost::cases() as $post) {
            $userId = $draft->boardUserIds[$post->value] ?? null;
            if ($userId !== null) {
                $board[$post->value] = new BoardMember($post, $userId, $name($userId, 'board.' . $post->value));
            }
        }
        $attendees = [];
        foreach ($draft->attendees as $i => $row) {
            $attendees[] = new Attendee(
                $row['userId'],
                $row['userId'] !== null ? $name($row['userId'], "attendees.{$i}.name") : (string) $row['name'],
                $row['attendance'],
                $row['proxyHolder'],
            );
        }
        if ($errors !== []) {
            throw new AssemblyValidationException($errors);
        }

        return new AssemblyContent($board, $attendees, $draft->agenda, $draft->resolutions);
    }
}
