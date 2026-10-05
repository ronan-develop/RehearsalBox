<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationMessage;
use App\Entity\SeenReceipt;

/**
 * Textes d'affichage de la messagerie (#183), rendus par le serveur : heures et jours dans le fuseau de l'application
 * (les dates sont stockées en UTC), « écrit… », « Vu par », lignes système, aperçus. Remplace la logique qui vivait dans le
 * JS : il n'y a plus qu'un seul endroit qui formate.
 */
final class ConversationFormatter
{
    public function __construct(private readonly \DateTimeZone $displayTimezone)
    {
    }

    public function timezone(): \DateTimeZone
    {
        return $this->displayTimezone;
    }

    public function time(\DateTimeImmutable $date): string
    {
        return $date->setTimezone($this->displayTimezone)->format('H:i');
    }

    /** Heure aujourd'hui, « Hier », sinon jour/mois : date d'une ligne de la liste des conversations. */
    public function listDate(\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        $label = $this->dayLabel($date, $now);

        return match (true) {
            $label === "Aujourd'hui" => $this->time($date),
            $label === 'Hier' => 'Hier',
            default => $date->setTimezone($this->displayTimezone)->format('d/m'),
        };
    }

    /** « Aujourd'hui », « Hier » ou jj/mm/aaaa, selon le jour calendaire local. */
    public function dayLabel(\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        $day = $date->setTimezone($this->displayTimezone)->format('Y-m-d');
        $today = $now->setTimezone($this->displayTimezone);

        return match ($day) {
            $today->format('Y-m-d') => "Aujourd'hui",
            $today->modify('-1 day')->format('Y-m-d') => 'Hier',
            default => $date->setTimezone($this->displayTimezone)->format('d/m/Y'),
        };
    }

    /** @param list<string> $names */
    public function typingText(array $names): string
    {
        $count = count($names);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return "{$names[0]} écrit…";
        }
        if ($count === 2) {
            return "{$names[0]} et {$names[1]} écrivent…";
        }
        $others = $count - 2;

        return "{$names[0]}, {$names[1]} et {$others} autre" . ($others > 1 ? 's' : '') . ' écrivent…';
    }

    /** « Vu par » sous mon dernier message : noms s'ils sont peu, sinon un décompte. */
    public function seenText(?SeenReceipt $seen): string
    {
        if ($seen === null || $seen->total() === 0) {
            return '';
        }
        $names = $seen->names();
        if ($names === []) {
            return 'Envoyé';
        }
        if (count($names) <= 2) {
            return 'Vu par ' . implode(' et ', $names);
        }

        return 'Vu par ' . count($names) . ' sur ' . $seen->total();
    }

    /** Ligne système (renommage…) : le texte stocké est à la 3e personne ; pour moi « Vous avez … ». */
    public function systemLine(ConversationMessage $message, bool $mine): string
    {
        return $mine
            ? 'Vous ' . preg_replace('/\Aa /', 'avez ', $message->body())
            : $message->authorName() . ' ' . $message->body();
    }

    /** Aperçu d'une ligne de liste : « Vous : … », « Bob : … » ou la ligne système. */
    public function preview(ConversationMessage $message, bool $mine): string
    {
        if ($message->isSystem()) {
            return $this->systemLine($message, $mine);
        }

        return ($mine ? 'Vous' : $message->authorName()) . ' : ' . $message->body();
    }
}
