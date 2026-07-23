<?php

declare(strict_types=1);

namespace App\Container;

interface ContainerInterface
{
    public function set(string $id, callable $factory): void;

    public function get(string $id): mixed;
}
