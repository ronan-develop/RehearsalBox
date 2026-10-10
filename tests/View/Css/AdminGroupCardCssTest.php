<?php

declare(strict_types=1);

namespace App\Tests\View\Css;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #267 / #120 : la carte de groupe de l'administration (en-tête, formulaire d'édition, membres, pastilles de couleur) garde son contrat CSS. */
final class AdminGroupCardCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/assets/css/pages/admin.css');
    }

    /** Corps de TOUTES les règles dont le sélecteur (exact, éventuellement dans une liste séparée par des virgules) précède l'accolade. */
    private function block(string $selector, string $css): string
    {
        $pattern = '/(?:^|[}\s,])' . preg_quote($selector, '/') . '(?=\s*[,{])[^{}]*\{([^}]*)\}/s';
        preg_match_all($pattern, $css, $matches);

        return implode("\n", $matches[1]);
    }

    /** Corps de TOUS les blocs @media (min-width: 768px), sans les accolades des règles imbriquées (le fichier en contient plusieurs). */
    private function mediaTablet(string $css): string
    {
        $pattern = '/@media\s*\(min-width:\s*768px\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/s';
        preg_match_all($pattern, $css, $matches);

        return implode("\n", $matches[1]);
    }

    #[Test]
    public function testGroupCardHeadIsFlexWithSpacedItems(): void
    {
        $head = $this->block('.rb-group-card-head', $this->css());

        self::assertMatchesRegularExpression('/display:\s*flex/', $head);
        self::assertMatchesRegularExpression('/justify-content:\s*space-between/', $head);
        self::assertMatchesRegularExpression('/align-items:/', $head);
    }

    #[Test]
    public function testEditGroupFormIsGrid(): void
    {
        $form = $this->block('.rb-edit-group-form', $this->css());

        self::assertMatchesRegularExpression('/display:\s*grid/', $form);
    }

    #[Test]
    public function testHiddenAttributeIsNotOverriddenOnEditGroupForm(): void
    {
        $hidden = $this->block('.rb-edit-group-form[hidden]', $this->css());

        self::assertMatchesRegularExpression('/display:\s*none/', $hidden);
    }

    #[Test]
    public function testEditGroupFormHasTwoColumnsOnTablet(): void
    {
        $tablet = $this->mediaTablet($this->css());
        $form = $this->block('.rb-edit-group-form', $tablet);

        self::assertMatchesRegularExpression('/grid-template-columns:[^;}]*repeat\(2/', $form);
    }

    #[Test]
    public function testColorContactAndActionsSpanFullRowOnTablet(): void
    {
        $tablet = $this->mediaTablet($this->css());

        self::assertMatchesRegularExpression('/grid-column:\s*1\s*\/\s*-1/', $this->block('.rb-group-field-color', $tablet));
        self::assertMatchesRegularExpression('/grid-column:\s*1\s*\/\s*-1/', $this->block('.rb-group-field-contact', $tablet));
        self::assertMatchesRegularExpression('/grid-column:\s*1\s*\/\s*-1/', $this->block('.rb-group-form-actions', $tablet));
    }

    #[Test]
    public function testFormActionsAreRightAlignedFlex(): void
    {
        $actions = $this->block('.rb-group-form-actions', $this->css());

        self::assertMatchesRegularExpression('/display:\s*flex/', $actions);
        self::assertMatchesRegularExpression('/justify-content:\s*flex-end/', $actions);
    }

    #[Test]
    public function testPrimaryButtonInFormActionsIsNotFullWidth(): void
    {
        $button = $this->block('.rb-group-form-actions .rb-btn-primary', $this->css());

        self::assertMatchesRegularExpression('/width:\s*auto/', $button);
    }

    #[Test]
    public function testAddMemberRowIsFlexWithGrowingInput(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/display:\s*flex/', $this->block('.rb-add-member-row', $css));
        self::assertMatchesRegularExpression('/flex:\s*1/', $this->block('.rb-add-member-row .rb-input', $css));
    }

    #[Test]
    public function testGroupMembersHaveTopBorder(): void
    {
        $members = $this->block('.rb-group-members', $this->css());

        self::assertMatchesRegularExpression('/border-top:\s*1px solid var\(--rb-border\)/', $members);
    }

    #[Test]
    public function testColorSwatchesWrapAndHaveTouchSizedTargets(): void
    {
        $css = $this->css();
        $swatches = $this->block('.rb-color-hues', $css);
        $swatch = $this->block('.rb-color-swatch', $css);

        self::assertMatchesRegularExpression('/display:\s*flex/', $swatches);
        self::assertMatchesRegularExpression('/flex-wrap:\s*wrap/', $swatches);
        self::assertMatchesRegularExpression('/min-width:\s*44px/', $swatch);
        self::assertMatchesRegularExpression('/min-height:\s*44px/', $swatch);
    }

    #[Test]
    public function testColorSwatchPaintsItsColourFromCustomProperty(): void
    {
        $before = $this->block('.rb-color-swatch::before', $this->css());

        self::assertMatchesRegularExpression('/background:\s*var\(--swatch\)/', $before);
    }

    #[Test]
    public function testCheckedAndFocusedSwatchesHaveStyles(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/(?:^|[}\s,])\.rb-color-swatch\[aria-checked="true"\]::before\s*[{,]/', $css);
        self::assertMatchesRegularExpression('/(?:^|[}\s,])\.rb-color-swatch:focus-visible\s*[{,]/', $css);
    }

    #[Test]
    public function testOldAddMemberInputAndNativeColorInputRulesAreGone(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('.rb-add-member-form .rb-input', $css);
        self::assertStringNotContainsString('input[type="color"]', $css);
    }
}
