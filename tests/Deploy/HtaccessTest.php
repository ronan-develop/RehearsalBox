<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtaccessTest extends TestCase
{
    private function htaccess(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/.htaccess');
    }

    #[Test]
    public function testForcesHttpsBehindProxyAndDirect(): void
    {
        $content = $this->htaccess();

        self::assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !https', $content);
        self::assertStringContainsString('RewriteCond %{HTTPS} !on', $content);
        self::assertStringContainsString('https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]', $content);
    }

    #[Test]
    public function testHttpsRedirectComesBeforeFrontControllerRewrite(): void
    {
        $content = $this->htaccess();

        self::assertLessThan(
            strpos($content, 'RewriteRule ^ index.php [L]'),
            strpos($content, 'R=301'),
        );
    }

    #[Test]
    public function testDisablesDirectoryListingAndHiddenFiles(): void
    {
        $content = $this->htaccess();

        self::assertStringContainsString('Options -Indexes', $content);
        self::assertStringContainsString('RewriteRule (^|/)\.(?!well-known/) - [F]', $content);
    }

    #[Test]
    public function testKeepsFrontControllerRewriteForNonExistingPaths(): void
    {
        $content = $this->htaccess();

        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -f [OR]', $content);
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $content);
    }
}
