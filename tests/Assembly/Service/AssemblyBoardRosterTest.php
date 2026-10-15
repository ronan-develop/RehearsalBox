<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Service\AssemblyBoardRoster;
use App\Association\Entity\BoardHolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361, #478 : le bureau (un compte et un nom par poste) vient de l'administration ; un poste sans titulaire reste libre (tout compte actif). */
final class AssemblyBoardRosterTest extends TestCase
{
    private function user(int $id, bool $active = true): User
    {
        return new User($id, "u{$id}@x.test", 'hash', "Compte {$id}", UserRole::Musicien, $active, 0, null);
    }

    #[Test]
    public function testARosterWithoutHoldersLeavesEveryPostFree(): void
    {
        $roster = new AssemblyBoardRoster([]);
        $users = [$this->user(1), $this->user(2)];

        foreach (BoardPost::cases() as $post) {
            self::assertFalse($roster->isRestricted($post));
            self::assertSame($users, $roster->candidates($post, $users));
            self::assertTrue($roster->allows($post, $users[1]));
            self::assertNull($roster->nameFor($post, $users[0]));
        }
        self::assertSame([], $roster->names());
    }

    #[Test]
    public function testAPostWithAHolderOffersOnlyThatAccount(): void
    {
        $roster = new AssemblyBoardRoster([
            new BoardHolder(BoardPost::President, 1, 'Prénom Nom'),
            new BoardHolder(BoardPost::Tresorier, 2, 'Autre Nom'),
        ]);
        $users = [$this->user(1), $this->user(2), $this->user(3)];

        self::assertTrue($roster->isRestricted(BoardPost::President));
        self::assertSame([$users[0]], $roster->candidates(BoardPost::President, $users));
        self::assertSame([$users[1]], $roster->candidates(BoardPost::Tresorier, $users));
        self::assertFalse($roster->isRestricted(BoardPost::Secretaire));
        self::assertSame($users, $roster->candidates(BoardPost::Secretaire, $users));
        self::assertTrue($roster->allows(BoardPost::President, $users[0]));
        self::assertFalse($roster->allows(BoardPost::President, $users[1]));
        self::assertFalse($roster->allows(BoardPost::Tresorier, $users[2]));
    }

    #[Test]
    public function testAHolderWhoseAccountIsNotAmongTheActiveOnesOffersNobody(): void
    {
        $roster = new AssemblyBoardRoster([new BoardHolder(BoardPost::President, 9, 'Parti')]);

        self::assertSame([], $roster->candidates(BoardPost::President, [$this->user(1)]));
        self::assertFalse($roster->allows(BoardPost::President, $this->user(1)));
    }

    #[Test]
    public function testTheConfiguredNameIsGivenOnlyToTheHolderAccount(): void
    {
        $roster = new AssemblyBoardRoster([new BoardHolder(BoardPost::President, 1, 'Prénom Nom')]);

        self::assertSame('Prénom Nom', $roster->nameFor(BoardPost::President, $this->user(1)));
        self::assertNull($roster->nameFor(BoardPost::President, $this->user(3)), 'un autre compte n\'hérite pas du nom');
        self::assertNull($roster->nameFor(BoardPost::Secretaire, $this->user(1)), 'poste sans titulaire');
        self::assertSame(['president' => 'Prénom Nom'], $roster->names());
    }
}
