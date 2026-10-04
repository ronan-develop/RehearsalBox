<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use App\Deploy\LocalConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalConfigGeneratorTest extends TestCase
{
    /** Valeur factice construite à l'exécution : caractères à échapper + partie aléatoire. */
    private string $password;

    protected function setUp(): void
    {
        $this->password = "pw'\"" . '$' . '\\' . bin2hex(random_bytes(6));
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'PROD_DB_HOST' => 'localhost',
            'PROD_DB_PORT' => '3306',
            'PROD_DB_DATABASE' => 'base_test',
            'PROD_DB_USER' => 'user_test',
            'PROD_DB_PASSWORD' => $this->password,
            'MAILER_DSN' => 'sendmail://default',
            'MAILER_FROM' => 'no-reply@example.test',
            'APP_URL' => 'https://app.example.test',
        ];
    }

    private function evaluate(string $php): array
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, $php);
        try {
            return require $file;
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function testRenderProducesPhpConfigWithDatabaseMailerAndDebugOff(): void
    {
        $config = $this->evaluate((new LocalConfigGenerator())->render($this->env()));

        self::assertFalse($config['debug']);
        self::assertSame('localhost', $config['db']['host']);
        self::assertSame('3306', $config['db']['port']);
        self::assertSame('base_test', $config['db']['name']);
        self::assertSame('user_test', $config['db']['user']);
        self::assertSame('sendmail://default', $config['mailer']['dsn']);
        self::assertSame('no-reply@example.test', $config['mailer']['from']);
        self::assertSame('https://app.example.test', $config['app']['base_url']);
    }

    #[Test]
    public function testRenderRequiresTheApplicationUrl(): void
    {
        $env = $this->env();
        unset($env['APP_URL']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_URL');

        (new LocalConfigGenerator())->render($env);
    }

    #[Test]
    public function testRenderEscapesSpecialCharactersInPassword(): void
    {
        $config = $this->evaluate((new LocalConfigGenerator())->render($this->env()));

        self::assertSame($this->password, $config['db']['password']);
    }

    #[Test]
    public function testRenderWithMissingVariableThrowsWithoutLeakingValues(): void
    {
        $env = $this->env();
        unset($env['PROD_DB_DATABASE']);

        try {
            (new LocalConfigGenerator())->render($env);
            self::fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('PROD_DB_DATABASE', $e->getMessage());
            self::assertStringNotContainsString('user_test', $e->getMessage());
            self::assertStringNotContainsString($this->password, $e->getMessage());
        }
    }

    #[Test]
    public function testRenderWithEmptyRequiredVariableThrows(): void
    {
        $env = $this->env();
        $env['PROD_DB_HOST'] = '';

        $this->expectException(\InvalidArgumentException::class);

        (new LocalConfigGenerator())->render($env);
    }

    #[Test]
    public function testWriteCreatesFileReadableOnlyByOwner(): void
    {
        $dir = sys_get_temp_dir() . '/rb-cfg-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $path = $dir . '/config.local.php';

        try {
            umask(0022);
            (new LocalConfigGenerator())->write($path, $this->env());

            self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            self::assertSame($this->password, (require $path)['db']['password']);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}
