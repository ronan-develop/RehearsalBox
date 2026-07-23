<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SessionInterface;

/** Implémentation en mémoire de SessionInterface pour les tests — pas de $_SESSION réel. */
final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public bool $regenerated = false;

    public bool $destroyed = false;

    public function start(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerated = true;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }
}
