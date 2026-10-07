<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

interface AlertRepositoryInterface
{
    public function lastSentAt(AlertType $type): ?\DateTimeImmutable;

    public function markSent(AlertType $type, \DateTimeImmutable $at): void;
}
