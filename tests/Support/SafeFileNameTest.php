<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SafeFileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Nom de fichier affiché : jamais de chemin, de caractère de contrôle ni d'inversion du sens d'écriture ; borné, extension conservée. */
final class SafeFileNameTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function cases(): iterable
    {
        yield 'nom simple' => ['releve.pdf', 'releve.pdf'];
        yield 'accents' => ['relevé de novembre.pdf', 'relevé de novembre.pdf'];
        yield 'chemin Unix' => ['../../etc/passwd', 'passwd'];
        yield 'chemin Windows' => ['C:\\Users\\x\\releve.pdf', 'releve.pdf'];
        yield 'mélange' => ['..\\../x/y.pdf', 'y.pdf'];
        yield 'octet nul' => ["rele\0ve.pdf", 'releve.pdf'];
        yield 'retour à la ligne' => ["a.pdf\r\nSet-Cookie: x=1", 'a.pdfSet-Cookie: x=1'];
        yield 'inversion du sens' => ["facture\u{202E}fdp.exe", 'facturefdp.exe'];
        yield 'espaces autour' => ['  releve.pdf  ', 'releve.pdf'];
        yield 'vide' => ['', 'repli'];
        yield 'que des points' => ['...', 'repli'];
        yield 'que des contrôles' => ["\n\r\t", 'repli'];
        yield 'dossier seul' => ['dossier/', 'repli'];
    }

    #[Test]
    #[DataProvider('cases')]
    public function testTheNameIsCleaned(string $input, string $expected): void
    {
        self::assertSame($expected, SafeFileName::clean($input, 150, 'repli'));
    }

    #[Test]
    public function testALongNameIsCutWithoutBreakingACharacterAndKeepsItsExtension(): void
    {
        $name = SafeFileName::clean(str_repeat('é', 400) . '.pdf', 150, 'repli');

        self::assertSame(150, mb_strlen($name));
        self::assertStringEndsWith('.pdf', $name);
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    #[Test]
    public function testAnAbsurdExtensionIsNotKept(): void
    {
        $name = SafeFileName::clean(str_repeat('a', 300) . '.' . str_repeat('x', 40), 150, 'repli');

        self::assertSame(150, mb_strlen($name));
    }
}
