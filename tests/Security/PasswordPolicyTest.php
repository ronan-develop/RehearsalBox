<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function testAcceptsAPasswordOfExactlyTheMinimumLength(): void
    {
        self::assertNull((new PasswordPolicy())->violation('12345678'));
    }

    #[Test]
    public function testRejectsAPasswordShorterThanTheMinimumLength(): void
    {
        self::assertSame(
            'Le mot de passe doit faire au moins 8 caractères.',
            (new PasswordPolicy())->violation('1234567'),
        );
    }

    #[Test]
    public function testRejectsAnEmptyPassword(): void
    {
        self::assertNotNull((new PasswordPolicy())->violation(''));
    }

    #[Test]
    public function testCountsBytesLikeTheExistingRegistrationRule(): void
    {
        // La règle d'origine (strlen) compte des octets : « éééé » = 8 octets.
        self::assertNull((new PasswordPolicy())->violation('éééé'));
    }
}
