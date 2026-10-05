<?php

declare(strict_types=1);

namespace App\Entity;

/** « Vu par » : qui, parmi les autres membres, a lu mon dernier message. */
final class SeenReceipt
{
    /** @param list<string> $names noms des lecteurs, par ordre alphabétique */
    public function __construct(
        private readonly int $messageId,
        private readonly array $names,
        private readonly int $total,
    ) {
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }

    /** Nombre d'autres personnes concernées par la conversation (hors moi). */
    public function total(): int
    {
        return $this->total;
    }
}
