<?php

declare(strict_types=1);

namespace App\Support;

/** Plage de jour (heure locale) pendant laquelle les relances par e-mail peuvent partir : de 9 h à 20 h (20 h exclue). */
final class DaytimeWindow
{
    public const START_HOUR = 9;
    public const END_HOUR = 20;

    public static function contains(\DateTimeImmutable $now, \DateTimeZone $localTimezone): bool
    {
        $hour = (int) $now->setTimezone($localTimezone)->format('G');

        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }
}
