<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class PhpTemplateRendererTest extends TestCase
{
    public function testRenderInterpolatesGivenData(): void
    {
        $renderer = new PhpTemplateRenderer(__DIR__ . '/../fixtures/templates');

        $html = $renderer->render('greeting', ['name' => 'Alice']);

        self::assertStringContainsString('Bonjour Alice', $html);
    }

    public function testRenderEscapesHtmlInGivenData(): void
    {
        $renderer = new PhpTemplateRenderer(__DIR__ . '/../fixtures/templates');

        $html = $renderer->render('greeting', ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
