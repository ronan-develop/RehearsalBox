<?php

declare(strict_types=1);

namespace App\Tests\Association\View;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;
use App\Association\Presenter\AssociationBoardView;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #478 : page admin du bureau, un bloc par poste, valeurs échappées. */
final class AssociationBoardTemplateTest extends TestCase
{
    private function render(array $posts): string
    {
        return (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/board/index', [
            'csrfToken' => 'token',
            'posts' => $posts,
            'currentUserRole' => UserRole::Admin,
        ]);
    }

    private function user(int $id, string $name): User
    {
        return new User($id, "u{$id}@x.test", 'hash', $name, UserRole::Musicien, true, 0, null);
    }

    #[Test]
    public function testThePresenterListsThePostsInOrderWithTheirHolderAndTheActiveAccounts(): void
    {
        $posts = (new AssociationBoardView())->posts(
            [BoardPost::Tresorier->value => new BoardHolder(BoardPost::Tresorier, 8, 'Bob Durand')],
            [$this->user(7, 'Alice'), $this->user(8, 'Bob')],
        );

        self::assertSame(['president', 'tresorier', 'secretaire'], array_column($posts, 'post'));
        self::assertSame(['', '8', ''], array_column($posts, 'userId'));
        self::assertSame(['', 'Bob Durand', ''], array_column($posts, 'fullName'));
        self::assertSame([['id' => '7', 'name' => 'Alice'], ['id' => '8', 'name' => 'Bob']], $posts[0]['options']);
    }

    #[Test]
    public function testEachPostHasItsBlockWithTheHolderSelectedAndTheNameEscaped(): void
    {
        $html = $this->render((new AssociationBoardView())->posts(
            [BoardPost::President->value => new BoardHolder(BoardPost::President, 7, '<b>Alice</b>')],
            [$this->user(7, '<i>Alice</i>')],
        ));

        foreach (['president', 'tresorier', 'secretaire'] as $post) {
            self::assertStringContainsString('data-board-post="' . $post . '"', $html);
        }
        self::assertStringContainsString('<option value="7" selected>', $html);
        self::assertStringContainsString('value="&lt;b&gt;Alice&lt;/b&gt;"', $html);
        self::assertStringNotContainsString('<b>Alice</b>', $html);
        self::assertSame(1, substr_count($html, 'data-action="vacate"') - substr_count($html, 'data-action="vacate" hidden'), 'seul le poste tenu propose de le vider');
    }

    #[Test]
    public function testNoInlineStyleNorInlineScript(): void
    {
        $html = $this->render((new AssociationBoardView())->posts([], []));

        self::assertStringNotContainsString('style=', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
    }
}
