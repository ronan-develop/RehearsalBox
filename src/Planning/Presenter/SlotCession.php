<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

/** Un créneau fixe cédé ponctuellement à un autre groupe (#461) : qui l'occupe, quel jour, et la plage si l'échange est partiel. */
final class SlotCession
{
    public function __construct(
        private readonly string $groupName,
        private readonly \DateTimeImmutable $date,
        private readonly ?string $partialRange,
    ) {
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    /** « 19:00 – 20:30 » quand seule une partie du créneau est cédée ; null quand c'est tout le créneau. */
    public function partialRange(): ?string
    {
        return $this->partialRange;
    }
}
