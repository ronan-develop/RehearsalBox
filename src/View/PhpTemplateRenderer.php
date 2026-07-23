<?php

declare(strict_types=1);

namespace App\View;

require_once __DIR__ . '/helpers.php';

final class PhpTemplateRenderer implements TemplateRendererInterface
{
    public function __construct(private readonly string $templatesDirectory)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $path = "{$this->templatesDirectory}/{$template}.php";

        $renderer = function (string $__path, array $__data): string {
            extract($__data);
            ob_start();
            require $__path;

            return (string) ob_get_clean();
        };

        return $renderer($path, $data);
    }
}
