<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Service\AssemblyBoardRoster;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : le bureau (un compte par poste) vient de la configuration locale ; un poste non configuré reste libre (tout compte actif). */
final class AssemblyBoardRosterTest extends TestCase
{
    private function user(int $id, string $email, bool $active = true): User
    {
        return new User($id, $email, 'hash', "Compte {$id}", UserRole::Musicien, $active, 0, null);
    }

    #[Test]
    public function testAnUnconfiguredRosterLeavesEveryPostFree(): void
    {
        $roster = new AssemblyBoardRoster([]);
        $users = [$this->user(1, 'a@x.test'), $this->user(2, 'b@x.test')];

        foreach (BoardPost::cases() as $post) {
            self::assertFalse($roster->isRestricted($post));
            self::assertSame($users, $roster->candidates($post, $users));
            self::assertTrue($roster->allows($post, $users[1]));
        }
    }

    #[Test]
    public function testAConfiguredPostOffersOnlyItsHolderIgnoringCaseAndSpaces(): void
    {
        $roster = new AssemblyBoardRoster(['president' => '  Pres@X.test ', 'tresorier' => 'tres@x.test', 'secretaire' => '']);
        $pres = $this->user(1, 'pres@x.test');
        $tres = $this->user(2, 'tres@x.test');
        $other = $this->user(3, 'autre@x.test');
        $users = [$pres, $tres, $other];

        self::assertTrue($roster->isRestricted(BoardPost::President));
        self::assertSame([$pres], $roster->candidates(BoardPost::President, $users));
        self::assertSame([$tres], $roster->candidates(BoardPost::Tresorier, $users));
        self::assertFalse($roster->isRestricted(BoardPost::Secretaire));
        self::assertSame($users, $roster->candidates(BoardPost::Secretaire, $users));
        self::assertTrue($roster->allows(BoardPost::President, $pres));
        self::assertFalse($roster->allows(BoardPost::President, $tres));
        self::assertFalse($roster->allows(BoardPost::Tresorier, $other));
    }

    #[Test]
    public function testAConfiguredPostWhoseAccountIsMissingOffersNobody(): void
    {
        $roster = new AssemblyBoardRoster(['president' => 'absent@x.test']);

        self::assertSame([], $roster->candidates(BoardPost::President, [$this->user(1, 'a@x.test')]));
        self::assertFalse($roster->allows(BoardPost::President, $this->user(1, 'a@x.test')));
    }

    #[Test]
    public function testPostsWithTheWrongTypeOfConfigurationAreTreatedAsUnconfigured(): void
    {
        $roster = new AssemblyBoardRoster(['president' => ['x'], 'tresorier' => 12, 'inconnu' => 'a@x.test']);

        self::assertFalse($roster->isRestricted(BoardPost::President));
        self::assertFalse($roster->isRestricted(BoardPost::Tresorier));
    }

    #[Test]
    public function testAHolderIsNamedByTheConfigurationOnlyWhenTheAccountMatches(): void
    {
        $roster = new AssemblyBoardRoster(
            ['president' => 'pres@x.test', 'tresorier' => 'tres@x.test'],
            ['president' => '  Prénom Nom ', 'tresorier' => '', 'secretaire' => 'Sans titulaire'],
        );
        $pres = $this->user(1, 'PRES@x.test');
        $other = $this->user(3, 'autre@x.test');

        self::assertSame('Prénom Nom', $roster->nameFor(BoardPost::President, $pres));
        self::assertNull($roster->nameFor(BoardPost::President, $other), 'un autre compte n\'hérite pas du nom');
        self::assertNull($roster->nameFor(BoardPost::Tresorier, $this->user(2, 'tres@x.test')), 'nom vide : pseudo du compte');
        self::assertNull($roster->nameFor(BoardPost::Secretaire, $other), 'poste libre : le nom configuré est ignoré');
        self::assertSame(['president' => 'Prénom Nom'], $roster->names());
    }
}
