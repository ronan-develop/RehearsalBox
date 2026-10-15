<?php

declare(strict_types=1);

namespace App\Assembly\Presenter;

use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardMember;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Entity\ResolutionOutcome;
use App\Support\FrenchDate;

/**
 * Compte rendu d'AG (#361) mis en phrases types pour la page imprimable. Gabarit fixe : aucun texte n'est inventé. Toutes les
 * chaînes sont du texte brut, non échappé : c'est le gabarit qui échappe à l'affichage.
 */
final class AssemblyMinutesView
{
    /**
     * @return array{
     *     title: string,
     *     associationName: string,
     *     statusBanner: ?string,
     *     versionLine: ?string,
     *     opening: string,
     *     quorum: ?string,
     *     board: list<array{label: string, name: string}>,
     *     counts: string,
     *     present: list<string>,
     *     represented: list<string>,
     *     excused: list<string>,
     *     agenda: list<array{number: int, title: string, discussion: ?string}>,
     *     resolutions: list<array{number: int, text: string, votes: string, outcome: ?string}>,
     *     noResolution: ?string,
     *     moralReport: ?string,
     *     financialReport: ?string,
     *     otherBusiness: ?string,
     *     closing: string,
     *     signatures: list<array{label: string, name: string}>,
     * }
     */
    public function build(Assembly $assembly, AssemblyContent $content, string $associationName, ?Assembly $successor): array
    {
        return [
            'title' => "Compte rendu de l'assemblée générale " . $assembly->kind->label(),
            'associationName' => $associationName,
            'statusBanner' => self::statusBanner($assembly, $successor),
            'versionLine' => self::versionLine($assembly),
            'opening' => self::opening($assembly),
            'quorum' => self::quorum($assembly),
            'board' => self::board($content),
            'counts' => self::counts($content->attendees),
            'present' => self::namesWith($content->attendees, Attendance::Present),
            'represented' => self::represented($content->attendees),
            'excused' => self::namesWith($content->attendees, Attendance::Excuse),
            'agenda' => self::agenda($content),
            'resolutions' => self::resolutions($content),
            'noResolution' => $content->resolutions === [] ? "Aucune résolution n'a été soumise au vote." : null,
            'moralReport' => $assembly->moralReport,
            'financialReport' => $assembly->financialReport,
            'otherBusiness' => $assembly->otherBusiness,
            'closing' => self::closing($assembly),
            'signatures' => [
                self::signature($content, BoardPost::President),
                self::signature($content, BoardPost::Secretaire),
            ],
        ];
    }

    private static function statusBanner(Assembly $assembly, ?Assembly $successor): ?string
    {
        if ($assembly->isDraft()) {
            return 'BROUILLON — document non validé';
        }

        return $successor === null ? null : 'Version remplacée par la version ' . $successor->version;
    }

    private static function versionLine(Assembly $assembly): ?string
    {
        if ($assembly->isDraft() || $assembly->validatedAt === null) {
            return null;
        }
        $validated = FrenchDate::long($assembly->validatedAt);
        if ($assembly->version === 1) {
            return "Version 1, validée le {$validated}.";
        }

        $line = "Version {$assembly->version}, validée le {$validated}, remplace la version " . ($assembly->version - 1) . '.';

        return $assembly->correctionReason === null ? $line : $line . ' Motif : ' . $assembly->correctionReason . '.';
    }

    private static function opening(Assembly $assembly): string
    {
        $opening = "L'assemblée générale {$assembly->kind->label()} s'est tenue le " . FrenchDate::long($assembly->heldOn);
        if (($assembly->location ?? '') !== '') {
            $opening .= ' à ' . $assembly->location;
        }
        $opening .= '.';
        if ($assembly->openedAt !== null) {
            $opening .= ' La séance est ouverte à ' . self::time($assembly->openedAt) . '.';
        }

        return $opening;
    }

    private static function quorum(Assembly $assembly): ?string
    {
        return match ($assembly->quorumReached) {
            true => 'Le quorum est atteint.',
            false => "Le quorum n'est pas atteint.",
            null => null,
        };
    }

    /** @return list<array{label: string, name: string}> */
    private static function board(AssemblyContent $content): array
    {
        $board = [];
        foreach (BoardPost::cases() as $post) {
            $member = self::member($content, $post);
            if ($member !== null) {
                $board[] = ['label' => $post->label(), 'name' => $member->name];
            }
        }

        return $board;
    }

    /** @return array{label: string, name: string} */
    private static function signature(AssemblyContent $content, BoardPost $post): array
    {
        $member = self::member($content, $post);

        return ['label' => $post->label(), 'name' => $member === null ? '' : $member->name];
    }

    private static function member(AssemblyContent $content, BoardPost $post): ?BoardMember
    {
        return $content->board[$post->value] ?? null;
    }

    /** @param list<Attendee> $attendees */
    private static function counts(array $attendees): string
    {
        $present = count(self::namesWith($attendees, Attendance::Present));
        $represented = count(self::namesWith($attendees, Attendance::Represente));
        $excused = count(self::namesWith($attendees, Attendance::Excuse));

        return self::plural($present, 'présent', 'présents') . ', '
            . self::plural($represented, 'représenté', 'représentés') . ', '
            . self::plural($excused, 'excusé', 'excusés') . '.';
    }

    /**
     * @param list<Attendee> $attendees
     * @return list<string>
     */
    private static function namesWith(array $attendees, Attendance $attendance): array
    {
        $names = [];
        foreach ($attendees as $attendee) {
            if ($attendee->attendance === $attendance) {
                $names[] = $attendee->name;
            }
        }

        return $names;
    }

    /**
     * @param list<Attendee> $attendees
     * @return list<string>
     */
    private static function represented(array $attendees): array
    {
        $names = [];
        foreach ($attendees as $attendee) {
            if ($attendee->attendance === Attendance::Represente) {
                $names[] = $attendee->proxyHolder === null
                    ? $attendee->name
                    : "{$attendee->name} (pouvoir à {$attendee->proxyHolder})";
            }
        }

        return $names;
    }

    /** @return list<array{number: int, title: string, discussion: ?string}> */
    private static function agenda(AssemblyContent $content): array
    {
        $agenda = [];
        foreach ($content->agenda as $i => $item) {
            $agenda[] = ['number' => $i + 1, 'title' => $item->title, 'discussion' => $item->discussion];
        }

        return $agenda;
    }

    /** @return list<array{number: int, text: string, votes: string, outcome: ?string}> */
    private static function resolutions(AssemblyContent $content): array
    {
        $resolutions = [];
        foreach ($content->resolutions as $i => $resolution) {
            $resolutions[] = [
                'number' => $i + 1,
                'text' => $resolution->text,
                'votes' => "Pour : {$resolution->for} — Contre : {$resolution->against} — Abstentions : {$resolution->abstain}",
                'outcome' => match ($resolution->outcome) {
                    ResolutionOutcome::Adoptee => 'La résolution est adoptée.',
                    ResolutionOutcome::Rejetee => 'La résolution est rejetée.',
                    null => null,
                },
            ];
        }

        return $resolutions;
    }

    private static function closing(Assembly $assembly): string
    {
        $closing = "L'ordre du jour étant épuisé, la séance est levée";

        return $assembly->closedAt === null ? $closing . '.' : $closing . ' à ' . self::time($assembly->closedAt) . '.';
    }

    /** « 18:30 » → « 18 h 30 », « 09:05 » → « 9 h 05 ». */
    private static function time(string $hhmm): string
    {
        return sprintf('%d h %s', (int) substr($hhmm, 0, 2), substr($hhmm, 3, 2));
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count > 1 ? $plural : $singular);
    }
}
