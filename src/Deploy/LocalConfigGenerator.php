<?php

declare(strict_types=1);

namespace App\Deploy;

final class LocalConfigGenerator
{
    private const REQUIRED = [
        'PROD_DB_HOST',
        'PROD_DB_PORT',
        'PROD_DB_DATABASE',
        'PROD_DB_USER',
        'PROD_DB_PASSWORD',
        'MAILER_DSN',
        'MAILER_FROM',
    ];

    /** @param array<string, string> $env */
    public function render(array $env): string
    {
        foreach (self::REQUIRED as $name) {
            if (($env[$name] ?? '') === '') {
                throw new \InvalidArgumentException("Variable manquante ou vide : {$name}");
            }
        }

        $config = [
            'debug' => false,
            'db' => [
                'host' => $env['PROD_DB_HOST'],
                'port' => $env['PROD_DB_PORT'],
                'name' => $env['PROD_DB_DATABASE'],
                'user' => $env['PROD_DB_USER'],
                'password' => $env['PROD_DB_PASSWORD'],
            ],
            'mailer' => [
                'dsn' => $env['MAILER_DSN'],
                'from' => $env['MAILER_FROM'],
            ],
        ];

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    }

    /** @param array<string, string> $env */
    public function write(string $path, array $env): void
    {
        $content = $this->render($env);

        $previousUmask = umask(0177);
        try {
            file_put_contents($path, $content);
            chmod($path, 0600);
        } finally {
            umask($previousUmask);
        }
    }
}
