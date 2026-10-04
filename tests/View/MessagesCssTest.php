<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #153 : points de mise en page de la messagerie qui ont déjà cassé à l'écran. */
final class MessagesCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/messages.css');
    }

    #[Test]
    public function testAHiddenUnreadBadgeStaysHiddenDespiteTheBadgeDisplayRule(): void
    {
        self::assertMatchesRegularExpression('/\.rb-messages-tab \.rb-badge\[hidden\]\s*\{[^}]*display:\s*none/', $this->css());
    }

    #[Test]
    public function testThreadActionsWrapInsteadOfBeingTruncatedOnSmallScreens(): void
    {
        self::assertMatchesRegularExpression('/\.rb-thread \.rb-modal-actions\s*\{[^}]*flex-wrap:\s*wrap/', $this->css());
    }

    #[Test]
    public function testMessageTextKeepsLineBreaksAndNeverOverflows(): void
    {
        self::assertMatchesRegularExpression('/\.rb-thread-message-body\s*\{[^}]*white-space:\s*pre-wrap[^}]*overflow-wrap:\s*anywhere/s', $this->css());
    }
}
