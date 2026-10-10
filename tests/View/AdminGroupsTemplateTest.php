<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\Group\Entity\Group;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #267 / #120 : balisage de la carte de groupe (admin) et du formulaire de création, avec <rb-color-picker>. */
final class AdminGroupsTemplateTest extends TestCase
{
    /** @param list<Group> $groups */
    private function render(array $groups): string
    {
        return (new PhpTemplateRenderer(__DIR__ . '/../../templates'))->render('admin/groups/index', [
            'groups' => $groups,
            'impacts' => [],
            'csrfToken' => 'token',
            'currentUserRole' => null,
        ]);
    }

    /** Extrait la carte <article> du groupe donné. */
    private function cardOf(string $html, int $id): string
    {
        preg_match('/<article class="rb-group-card rb-card" data-group-id="' . $id . '">.*?<\/article>/s', $html, $match);

        return $match[0] ?? '';
    }

    #[Test]
    public function testEachGroupFieldHasAUniqueLabelledIdAndTheNameIsEscaped(): void
    {
        $html = $this->render([
            new Group(7, 'Rock <b>x</b>', 'Punk', '#b5654a', 'rock@example.test'),
            new Group(8, 'Jazz', null, null, 'jazz@example.test'),
        ]);

        foreach (['name', 'genre', 'colorHex', 'contactEmail', 'member-email'] as $field) {
            self::assertStringContainsString('<label for="group-7-' . $field . '">', $html);
            self::assertStringContainsString('id="group-7-' . $field . '"', $html);
        }
        self::assertStringContainsString('Rock &lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    #[Test]
    public function testColorFieldsUseTheColorPickerComponentAndNeverANativeColorInput(): void
    {
        $html = $this->render([
            new Group(7, 'Rock', 'Punk', '#b5654a', 'rock@example.test'),
            new Group(8, 'Jazz', null, null, 'jazz@example.test'),
        ]);

        self::assertSame(3, substr_count($html, '<rb-color-picker>'));
        self::assertStringNotContainsString('type="color"', $html);
    }

    #[Test]
    public function testMissingOrUnsafeColorFallsBackToTheDefaultColor(): void
    {
        $html = $this->render([new Group(8, 'Jazz', null, null, 'jazz@example.test')]);
        self::assertMatchesRegularExpression('/id="group-8-colorHex"[^>]*value="#cb824d"/', $html);

        $unsafe = $this->render([new Group(9, 'Metal', null, 'red;x', 'metal@example.test')]);
        self::assertMatchesRegularExpression('/id="group-9-colorHex"[^>]*value="#cb824d"/', $unsafe);
        self::assertStringNotContainsString('red;x', $unsafe);
    }

    #[Test]
    public function testEditAndDeleteButtonsSitInTheCardHeaderAndNullGenreHasNoGenreLine(): void
    {
        $html = $this->render([
            new Group(7, 'Rock', 'Punk', '#b5654a', 'rock@example.test'),
            new Group(8, 'Jazz', null, null, 'jazz@example.test'),
        ]);

        $header = '/<header class="rb-group-card-head">(.*?)<\/header>/s';
        preg_match($header, $this->cardOf($html, 8), $match8);
        self::assertStringContainsString('data-edit-group-button', $match8[1] ?? '');
        self::assertStringContainsString('data-delete-group-button', $match8[1] ?? '');

        self::assertStringNotContainsString('<p class="rb-group-genre">', $this->cardOf($html, 8));
        self::assertStringContainsString('<p class="rb-group-genre">Punk</p>', $this->cardOf($html, 7));
    }

    #[Test]
    public function testEachCardHasFormActionsMembersAndAddMemberRow(): void
    {
        $html = $this->render([
            new Group(7, 'Rock', 'Punk', '#b5654a', 'rock@example.test'),
            new Group(8, 'Jazz', null, null, 'jazz@example.test'),
        ]);

        foreach ([7, 8] as $id) {
            $card = $this->cardOf($html, $id);
            self::assertStringContainsString('class="rb-group-form-actions"', $card);
            self::assertStringContainsString('class="rb-group-members"', $card);
            self::assertStringContainsString('class="rb-add-member-row"', $card);
        }
    }
}
