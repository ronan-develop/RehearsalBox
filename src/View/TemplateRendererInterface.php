<?php

declare(strict_types=1);

namespace App\View;

interface TemplateRendererInterface
{
    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string;
}
