<?php

declare(strict_types=1);

namespace App\Assembly\Presenter;

use App\Account\Entity\User;
use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Entity\Resolution;
use App\Support\FrenchDate;

/**
 * Données du formulaire d'un compte rendu : que des chaînes prêtes à remplir des champs (le gabarit les échappe). Un identifiant de
 * compte absent vaut ''. Les comptes proposés sont les comptes actifs ; un titulaire déjà enregistré mais désactivé reste affiché.
 */
final class AssemblyFormView
{
    /**
     * @param list<User> $candidates comptes actifs (présences)
     * @param array<string, list<User>> $boardCandidates comptes proposés par poste ; un poste absent propose tous les comptes actifs
     * @param array<string, string> $boardNames prénom et nom configurés du titulaire, par poste ; remplace le nom du compte dans la liste
     * @return array<string, mixed>
     */
    public function build(Assembly $assembly, AssemblyContent $content, array $candidates, array $boardCandidates, ?Assembly $successor, array $boardNames = []): array
    {
        $users = self::options($candidates);
        $known = array_column($users, 'name', 'id');
        foreach ($content->board as $member) {
            $known[(string) $member->userId] ??= $member->name;
        }
        foreach ($content->attendees as $attendee) {
            if ($attendee->userId !== null) {
                $known[(string) $attendee->userId] ??= $attendee->name;
            }
        }

        $options = [];
        foreach ($known as $id => $name) {
            $options[] = ['id' => (string) $id, 'name' => $name];
        }

        $board = [];
        foreach (BoardPost::cases() as $post) {
            $offered = self::options($boardCandidates[$post->value] ?? $candidates, isset($boardCandidates[$post->value]) ? ($boardNames[$post->value] ?? null) : null);
            $held = isset($content->board[$post->value]) ? (string) $content->board[$post->value]->userId : '';
            if ($held !== '' && !in_array($held, array_column($offered, 'id'), true)) {
                $offered[] = ['id' => $held, 'name' => $content->board[$post->value]->name]; // un ancien titulaire reste sélectionnable
            }
            $board[$post->value] = [
                'label' => $post->label(),
                'userId' => $held !== '' ? $held : (isset($boardCandidates[$post->value]) && count($offered) === 1 ? $offered[0]['id'] : ''),
                'options' => $offered,
            ];
        }

        return [
            'id' => $assembly->id,
            'revision' => $assembly->revision,
            'version' => $assembly->version,
            'isDraft' => $assembly->isDraft(),
            'title' => 'Assemblée générale ' . $assembly->kind->label() . ' du ' . $assembly->heldOn->format('d/m/Y'),
            'statusLabel' => $assembly->isDraft() ? 'Brouillon' : 'Validé le ' . FrenchDate::long($assembly->validatedAt ?? $assembly->heldOn),
            'successorId' => $successor?->id,
            'kind' => $assembly->kind->value,
            'heldOn' => $assembly->heldOn->format('Y-m-d'),
            'location' => $assembly->location ?? '',
            'openedAt' => $assembly->openedAt ?? '',
            'closedAt' => $assembly->closedAt ?? '',
            'quorum' => $assembly->quorumReached === null ? '' : ($assembly->quorumReached ? '1' : '0'),
            'moralReport' => $assembly->moralReport ?? '',
            'financialReport' => $assembly->financialReport ?? '',
            'otherBusiness' => $assembly->otherBusiness ?? '',
            'board' => $board,
            'users' => $options,
            'attendances' => array_map(static fn (Attendance $a): string => $a->value, Attendance::cases()),
            'attendees' => array_map(static fn (Attendee $a): array => [
                'userId' => $a->userId === null ? '' : (string) $a->userId,
                'name' => $a->userId === null ? $a->name : '',
                'attendance' => $a->attendance->value,
                'proxyHolder' => $a->proxyHolder ?? '',
            ], $content->attendees),
            'agenda' => array_map(static fn (AgendaItem $i): array => ['title' => $i->title, 'discussion' => $i->discussion ?? ''], $content->agenda),
            'resolutions' => array_map(static fn (Resolution $r): array => [
                'text' => $r->text,
                'for' => (string) $r->for,
                'against' => (string) $r->against,
                'abstain' => (string) $r->abstain,
                'outcome' => $r->outcome === null ? '' : $r->outcome->value,
            ], $content->resolutions),
        ];
    }

    /**
     * @param list<User> $users
     * @param string|null $name remplace le nom des comptes (liste restreinte à son titulaire)
     * @return list<array{id: string, name: string}>
     */
    private static function options(array $users, ?string $name = null): array
    {
        return array_map(static fn (User $user): array => ['id' => (string) $user->id(), 'name' => $name ?? $user->displayName()], $users);
    }
}
