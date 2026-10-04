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

    #[Test]
    public function testStylesheetsAndScriptsAreRevalidatedOnEveryLoad(): void
    {
        $content = $this->htaccess();

        // app.js importe d'autres modules par chemin relatif : on ne peut pas les versionner
        // un par un, donc CSS et JS sont revalidés à chaque chargement (#150).
        self::assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>\s*<FilesMatch "\\\.\(css\|js\|mjs\)\$">\s*Header set Cache-Control "no-cache"\s*<\/FilesMatch>\s*<\/IfModule>/',
            $content,
        );
    }

    #[Test]
    public function testCacheHeadersDoNotTouchFontsOrImages(): void
    {
        $content = $this->htaccess();

        self::assertStringNotContainsString('woff2', $content);
        self::assertStringNotContainsString('png', $content);
    }
}
