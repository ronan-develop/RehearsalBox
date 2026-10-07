<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Dashboard\RequestKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #303 : chaque nature de demande désigne son gabarit de carte ; le tableau de bord n'a plus à tester le type de la ligne. */
final class RequestCardTemplatesTest extends TestCase
{
    #[Test]
    public function testEveryRequestKindHasItsOwnCardTemplate(): void
    {
        foreach (RequestKind::cases() as $kind) {
            self::assertFileExists(__DIR__ . '/../../templates/dashboard/_request-card-' . $kind->value . '.php', $kind->name);
        }
    }

    #[Test]
    public function testTheDashboardPicksTheCardFromTheKindWithoutTestingTheClass(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../templates/dashboard/index.php');

        self::assertStringContainsString("'/_request-card-' . \$item->kind()->value . '.php'", $template);
        self::assertStringNotContainsString('instanceof', $template);
    }
}
