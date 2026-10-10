<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #327 : tout formulaire asynchrone passe par <rb-async-form> ; l'ancien contrat `data-async` n'existe plus, ni dans les gabarits ni dans le JS. */
final class AsyncFormMarkupTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === $extension && !str_ends_with($file->getFilename(), '.test.js')) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    #[Test]
    public function testNoTemplateNorScriptUsesTheOldDataAsyncContractAnymore(): void
    {
        foreach ([...$this->files('templates', 'php'), ...$this->files('public/assets/js', 'js')] as $file) {
            self::assertStringNotContainsString('data-async', (string) file_get_contents($file), $file);
            self::assertStringNotContainsString('core/forms.js', (string) file_get_contents($file), $file);
        }
    }

    #[Test]
    public function testEveryAsyncFormComponentWrapsExactlyOneFormAndHasAnEndpoint(): void
    {
        $total = 0;
        foreach ($this->files('templates', 'php') as $file) {
            $html = (string) file_get_contents($file);
            $opened = preg_match_all('/<rb-async-form\b((?:<\?.*?\?>|[^>])*)>/s', $html, $tags);
            self::assertSame($opened, substr_count($html, '</rb-async-form>'), $file . ' : balises déséquilibrées');
            foreach ($tags[1] as $attributes) {
                self::assertMatchesRegularExpression('/\bendpoint="\/api\/[^"]+/', $attributes, $file . ' : endpoint manquant');
                self::assertMatchesRegularExpression('/\bmethod="(POST|PATCH|PUT|DELETE)"/', $attributes, $file . ' : méthode manquante');
            }
            self::assertSame($opened, preg_match_all('/<rb-async-form\b(?:<\?.*?\?>|[^>])*>\s*<form\b/s', $html), $file . ' : le composant doit envelopper directement un <form>');
            $total += $opened;
        }

        self::assertSame(13, $total, 'les 13 formulaires asynchrones des gabarits');
    }

    #[Test]
    public function testTheLoginFormKeepsItsReturnPageOnTheComponentAndNeverOnTheForm(): void
    {
        $login = (string) file_get_contents(self::ROOT . '/templates/auth/login.php');

        self::assertMatchesRegularExpression('/<rb-async-form endpoint="\/api\/auth\/login" method="POST"<\?= !empty\(\$next\)/', $login);
        self::assertStringContainsString('<form>', $login);
    }
}
