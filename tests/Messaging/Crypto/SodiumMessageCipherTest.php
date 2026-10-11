<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Crypto;

use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Crypto\SodiumMessageCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #171 : chiffrement au repos des messages (libsodium secretbox, nonce aléatoire, clés versionnées, rotation). */
final class SodiumMessageCipherTest extends TestCase
{
    private function key(string $seed): string
    {
        return hash('sha256', $seed, true);
    }

    /**
     * Altère un caractère du MILIEU du texte chiffré : tous ses bits sont des données. Le dernier caractère du base64 sans remplissage
     * porte des bits inutilisés que le décodage ignore : l'altérer ne changeait parfois aucun octet (≈ 6 % des exécutions, test instable).
     */
    private function tampered(string $stored): string
    {
        $position = intdiv(strlen($stored) + strpos($stored, ':') + 1, 2);
        $replacement = $stored[$position] === 'A' ? 'Q' : 'A';

        return substr($stored, 0, $position) . $replacement . substr($stored, $position + 1);
    }

    private function cipher(bool $allowPlaintext = false): SodiumMessageCipher
    {
        return new SodiumMessageCipher(['k1' => $this->key('un')], 'k1', $allowPlaintext);
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'court' => ['Bonjour'];
        yield 'vide' => [''];
        yield 'accents et emoji' => ["Répèt' à 19 h ? 🎸\nOK"];
        yield 'longueur maximale' => [str_repeat('é', 5000)];
        yield 'ressemble au format' => ['v1.k1:pas-un-chiffre'];
    }

    #[Test]
    #[DataProvider('texts')]
    public function testARoundTripGivesBackTheText(string $text): void
    {
        $cipher = $this->cipher();

        self::assertSame($text, $cipher->decrypt($cipher->encrypt($text)));
    }

    #[Test]
    public function testTheStoredValueIsVersionedWithTheKeyIdAndHidesTheText(): void
    {
        $stored = $this->cipher()->encrypt('Message secret');

        self::assertMatchesRegularExpression('/^v1\.k1:[A-Za-z0-9_-]+$/', $stored);
        self::assertStringNotContainsString('secret', $stored);
    }

    #[Test]
    public function testEveryEncryptionUsesANewNonce(): void
    {
        $cipher = $this->cipher();

        self::assertNotSame($cipher->encrypt('Pareil'), $cipher->encrypt('Pareil'));
    }

    #[Test]
    public function testATamperedValueIsRefused(): void
    {
        $cipher = $this->cipher();

        // Le nonce change à chaque chiffrement : on essaie beaucoup de valeurs, AUCUNE altération ne doit passer.
        for ($i = 0; $i < 200; ++$i) {
            $tampered = $this->tampered($cipher->encrypt('Message'));
            try {
                $cipher->decrypt($tampered);
                self::fail('Une valeur altérée a été acceptée : ' . $tampered);
            } catch (MessageCipherException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testTheWrongKeyIsRefused(): void
    {
        $stored = $this->cipher()->encrypt('Message');
        $other = new SodiumMessageCipher(['k1' => $this->key('autre')], 'k1');

        $this->expectException(MessageCipherException::class);
        $other->decrypt($stored);
    }

    #[Test]
    public function testAnUnknownKeyIdIsRefused(): void
    {
        $stored = (new SodiumMessageCipher(['k2' => $this->key('deux')], 'k2'))->encrypt('Message');

        $this->expectException(MessageCipherException::class);
        $this->cipher()->decrypt($stored);
    }

    #[Test]
    public function testPlaintextIsRefusedInStrictMode(): void
    {
        $this->expectException(MessageCipherException::class);
        $this->cipher(allowPlaintext: false)->decrypt('Bonjour en clair');
    }

    #[Test]
    public function testPlaintextIsReadAsIsDuringTheTransition(): void
    {
        $cipher = $this->cipher(allowPlaintext: true);

        self::assertSame('Bonjour en clair', $cipher->decrypt('Bonjour en clair'));
        self::assertSame('v1.0 est sorti', $cipher->decrypt('v1.0 est sorti'), 'un texte qui commence comme le format reste du clair');
    }

    #[Test]
    public function testATamperedValueIsStillRefusedDuringTheTransition(): void
    {
        $cipher = $this->cipher(allowPlaintext: true);

        for ($i = 0; $i < 200; ++$i) {
            $tampered = $this->tampered($cipher->encrypt('Message'));
            try {
                $cipher->decrypt($tampered);
                self::fail('Une valeur altérée a été acceptée pendant la transition : ' . $tampered);
            } catch (MessageCipherException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testOnlyAStoredValueOfTheRightShapeCountsAsEncrypted(): void
    {
        $cipher = $this->cipher(allowPlaintext: true);

        self::assertTrue($cipher->isEncrypted($cipher->encrypt('Message')));
        self::assertFalse($cipher->isEncrypted('Texte en clair'));
        self::assertFalse($cipher->isEncrypted('v1.k1:pas-un-chiffre'));
    }

    #[Test]
    public function testAValueIsCurrentOnlyWhenEncryptedWithTheCurrentKey(): void
    {
        $old = new SodiumMessageCipher(['k1' => $this->key('un')], 'k1');
        $rotated = new SodiumMessageCipher(['k1' => $this->key('un'), 'k2' => $this->key('deux')], 'k2', true);

        self::assertFalse($rotated->isCurrent('Texte en clair'));
        self::assertFalse($rotated->isCurrent($old->encrypt('Ancien')));
        self::assertTrue($rotated->isCurrent($rotated->encrypt('Neuf')));
    }

    #[Test]
    public function testAFormerKeyStillDecryptsAfterARotation(): void
    {
        $stored = (new SodiumMessageCipher(['k1' => $this->key('un')], 'k1'))->encrypt('Ancien message');
        $rotated = new SodiumMessageCipher(['k1' => $this->key('un'), 'k2' => $this->key('deux')], 'k2');

        self::assertSame('Ancien message', $rotated->decrypt($stored));
        self::assertStringStartsWith('v1.k2:', $rotated->encrypt('Neuf'));
    }

    #[Test]
    public function testInvalidKeyringsAreRefused(): void
    {
        foreach ([
            fn () => new SodiumMessageCipher([], 'k1'),
            fn () => new SodiumMessageCipher(['k1' => 'trop-court'], 'k1'),
            fn () => new SodiumMessageCipher(['k1' => $this->key('un')], 'k2'),
            fn () => new SodiumMessageCipher(['K 1!' => $this->key('un')], 'K 1!'),
        ] as $build) {
            try {
                $build();
                self::fail('Trousseau invalide accepté');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testErrorsNeverCarryTheTextOrTheKey(): void
    {
        $cipher = $this->cipher();
        $stored = $cipher->encrypt('Contenu confidentiel');

        try {
            (new SodiumMessageCipher(['k1' => $this->key('autre')], 'k1'))->decrypt($stored);
            self::fail('Refus attendu');
        } catch (MessageCipherException $e) {
            self::assertStringNotContainsString('confidentiel', $e->getMessage());
            self::assertStringNotContainsString(bin2hex($this->key('autre')), $e->getMessage());
            self::assertStringNotContainsString($stored, $e->getMessage());
        }
    }
}
