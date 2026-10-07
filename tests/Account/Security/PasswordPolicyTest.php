<?php

declare(strict_types=1);

namespace App\Tests\Account\Security;

use App\Account\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function testAcceptsAPasswordOfExactlyTheMinimumLength(): void
    {
        self::assertNull((new PasswordPolicy())->violation('rouge-17-bleu'));
        self::assertNull((new PasswordPolicy())->violation('Tr0ubad0r&'), 'dix caractères pile');
    }

    #[Test]
    public function testRejectsAPasswordShorterThanTheMinimumLength(): void
    {
        self::assertSame(
            'Le mot de passe doit faire au moins 10 caractères.',
            (new PasswordPolicy())->violation('Tr0ubad0r'),
        );
    }

    #[Test]
    public function testRejectsAnEmptyPassword(): void
    {
        self::assertNotNull((new PasswordPolicy())->violation(''));
    }

    #[Test]
    public function testTheLengthCountsCharactersNotBytes(): void
    {
        // Dix lettres accentuées DIFFÉRENTES = dix caractères (vingt octets) : acceptées ; neuf = refusées.
        self::assertNull((new PasswordPolicy())->violation('éàèùçôîâêû'));
        self::assertSame('Le mot de passe doit faire au moins 10 caractères.', (new PasswordPolicy())->violation('éàèùçôîâê'));
    }

    #[Test]
    public function testRefusesWhatTheHashingWouldSilentlyTruncate(): void
    {
        $policy = new PasswordPolicy();

        self::assertNull($policy->violation(str_repeat('a1', 36)), '72 octets : la limite de bcrypt, acceptés');
        self::assertSame(
            'Le mot de passe ne doit pas dépasser 72 octets (environ 72 caractères sans accent).',
            $policy->violation(str_repeat('a1', 36) . 'x'),
        );
        self::assertNotNull($policy->violation(str_repeat('é', 37)), '74 octets : refusés même si 37 caractères');
    }

    /** @return iterable<string, array{string}> */
    public static function trivialPasswords(): iterable
    {
        yield 'le classique' => ['password123'];
        yield 'classique en majuscules' => ['PASSWORD123'];
        yield 'suite de chiffres' => ['1234567890'];
        yield 'clavier azerty' => ['azertyuiop'];
        yield 'clavier qwerty' => ['qwertyuiop'];
        yield 'mot de passe en français' => ['motdepasse1'];
        yield 'un seul caractère répété' => ['aaaaaaaaaaaa'];
        yield 'chiffres répétés' => ['0000000000'];
    }

    #[Test]
    #[DataProvider('trivialPasswords')]
    public function testRefusesTheMostCommonPasswordsWhateverTheirCase(string $trivial): void
    {
        self::assertSame(
            'Ce mot de passe est trop courant : choisissez-en un moins prévisible.',
            (new PasswordPolicy())->violation($trivial),
        );
    }

    #[Test]
    public function testALongPassphraseIsWelcome(): void
    {
        self::assertNull((new PasswordPolicy())->violation('trois chats dansent sous la pluie'));
    }
}
