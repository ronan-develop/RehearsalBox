<?php

declare(strict_types=1);

namespace App\Entity;

final class GroupDocument
{
    public function __construct(
        private readonly int $id,
        private readonly int $groupId,
        private readonly string $originalName,
        private readonly string $storedName,
        private readonly string $mimeType,
        private readonly int $sizeBytes,
        private readonly int $uploadedByUserId,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function storedName(): string
    {
        return $this->storedName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function uploadedByUserId(): int
    {
        return $this->uploadedByUserId;
    }
}
