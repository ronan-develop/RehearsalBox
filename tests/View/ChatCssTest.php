<?php

declare(strict_types=1);

namespace App\Tests\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #169 : points de mise en page de la messagerie qui ont déjà cassé à l'écran ou qui protègent l'affichage. */
final class ChatCssTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../public/assets/css/pages/messages.css');
    }

    #[Test]
    public function testMobileShowsTheListOrTheThreadNeverBoth(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat\[data-view="list"\] \.rb-chat-main,\s*\.rb-chat\[data-view="thread"\] \.rb-chat-sidebar\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testDesktopShowsTheListAndTheThreadSideBySide(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*grid-template-columns:\s*minmax\(280px, 360px\)/s', $css);
        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*\.rb-chat \.rb-chat-back\s*\{[^}]*display:\s*none/s', $css, 'le retour mobile disparaît sur grand écran (spécificité supérieure à .rb-chat-icon-link, déclarée plus bas)');
    }

    #[Test]
    public function testHiddenBadgesAndBlocksStayHiddenDespiteTheirDisplayRules(): void
    {
        $css = $this->css();

        // Les badges masqués le sont par la règle globale `.rb-badge[hidden]` de base.css (#322), plus par une surcharge par page.
        self::assertMatchesRegularExpression('/\.rb-chat-thread\[hidden\]/', $css);
    }

    #[Test]
    public function testTheDeleteButtonAndTheTrashBlocksStayHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-delete\[hidden\]\s*\{[^}]*display:\s*none/', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-delete\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
        self::assertMatchesRegularExpression('/\.rb-trash \[hidden\]\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testMentionsAreHighlightedInTheFeedAndMarkedInTheList(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-mention--me\s*\{[^}]*background/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-message--mentioned \.rb-chat-bubble\s*\{[^}]*outline/s', $css, 'le surlignage ne dépend pas de la seule couleur');
        self::assertMatchesRegularExpression('/\.rb-chat-item-mention\s*\{[^}]*position:\s*absolute/s', $css);
    }

    #[Test]
    public function testTheMentionListFloatsAboveTheComposerAndStaysHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-mention-list\s*\{[^}]*position:\s*absolute[^}]*bottom:\s*100%/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-mention-list\[hidden\],\s*\.rb-chat-mention-notice\[hidden\]\s*\{[^}]*display:\s*none/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-mention-option\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
    }

    #[Test]
    public function testSwipeRevealsTheDeleteActionOnMobileAndDesktopKeepsAColumnOnTheRight(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-item\s*\{[^}]*touch-action:\s*pan-y/s', $css, 'le défilement vertical reste possible');
        self::assertMatchesRegularExpression('/\.rb-chat-item--open\s*\{[^}]*--swipe-x:\s*-88px/s', $css, 'même largeur que ACTION_WIDTH du script');
        self::assertMatchesRegularExpression('/\.rb-chat-item-delete\s*\{[^}]*position:\s*absolute/s', $css, 'mobile : sous la ligne, révélé par le glissement');
        self::assertMatchesRegularExpression('/@media \(min-width: 900px\)\s*\{[^@]*\.rb-chat-item-delete\s*\{[^}]*position:\s*static[^}]*width:\s*44px/s', $css, 'ordinateur : colonne de 44 px à droite');
        self::assertMatchesRegularExpression('/:has\(\.rb-chat-item-delete:focus-visible\)/', $css, 'le clavier révèle le bouton');
    }

    #[Test]
    public function testTheMuteBellIsAlwaysVisibleKeepsATouchTargetAndFollowsTheSwipedRow(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-item-mute\s*\{[^}]*width:\s*44px[^}]*height:\s*44px/s', $css, 'zone tactile de 44 px');
        self::assertMatchesRegularExpression('/rb-mute-toggle\s*\{[^}]*position:\s*absolute[^}]*translate\(var\(--swipe-x/s', $css, 'suit la ligne glissée, pas posée sur le bouton rouge');
        self::assertMatchesRegularExpression('/\.rb-chat-item-mute\[aria-pressed="true"\]\s+\.rb-chat-item-mute-slash\s*\{[^}]*display:\s*inline/s', $css, 'la cloche est barrée quand elle est en sourdine');
        self::assertMatchesRegularExpression('/\.rb-chat-item-mute:focus-visible/', $css, 'visible au clavier');
        // Le survol n'est jamais la seule voie ni un état collant sur iOS : :hover seulement sur les appareils à survol.
        self::assertMatchesRegularExpression('/@media \(hover: hover\)\s*\{[^@]*\.rb-chat-item-mute:hover/s', $css);
        self::assertDoesNotMatchRegularExpression('/@media \(hover: none\)\s*\{[^@]*\.rb-chat-item-mute\b[^}]*display:\s*none/s', $css, 'jamais masquée sur écran tactile');
    }

    #[Test]
    public function testTheSwipeWidthMatchesBetweenTheScriptAndTheStylesheet(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../public/assets/js/chat/swipe.js');

        self::assertMatchesRegularExpression('/ACTION_WIDTH = 88;/', $script);
        self::assertStringContainsString('--swipe-x: -88px', $this->css());
    }

    #[Test]
    public function testTheFeedIsAnchoredAtTheBottomByCssAlone(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*column-reverse/s', $this->css(), 'ouverture sur le dernier message sans flash ni JS');
    }

    #[Test]
    public function testTheNewMessagesIndicatorFloatsAboveTheStatusLineAndStaysHiddenWhenAsked(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-new-anchor\s*\{[^}]*position:\s*relative[^}]*height:\s*0/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-new-messages\s*\{[^}]*position:\s*absolute[^}]*min-height:\s*44px/s', $css, 'flotte, zone tactile suffisante');
        self::assertMatchesRegularExpression('/\.rb-chat-new-messages\[hidden\]\s*\{[^}]*display:\s*none/', $css);
    }

    #[Test]
    public function testPageTransitionsAreNativeAndRespectReducedMotion(): void
    {
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\)\s*\{\s*@view-transition\s*\{\s*navigation:\s*auto;/s', $this->css(), 'fondu natif entre pages, aucun script, désactivé pour qui réduit les animations');
    }

    #[Test]
    public function testEditingHasAHoverButtonAHiddenBannerAndATransparentBodyWrapper(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-body\s*\{[^}]*display:\s*contents/s', $css, 'le conteneur remplaçable ne change pas la mise en page');
        self::assertMatchesRegularExpression('/\.rb-chat-message:hover \.rb-chat-edit,\s*\.rb-chat-edit:focus-visible\s*\{[^}]*opacity:\s*1/s', $css, 'survol ou clavier');
        self::assertMatchesRegularExpression('/rb-message-actions\[data-side="left"\]\s*\{[^}]*right:\s*calc\(100% \+ 4px\)/s', $css, 'tactile : le crayon est à GAUCHE de ma bulle');
        self::assertMatchesRegularExpression('/\.rb-chat-editing\[hidden\]\s*\{[^}]*display:\s*none/', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-editing-cancel\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
    }

    #[Test]
    public function testTheTextOfMyEditableBubblesStaysSelectableAndTheFeedNeverScrollsSideways(): void
    {
        $css = $this->css();

        // #212 : plus d'appui long, donc plus de conflit avec la sélection de texte d'iOS : copier reste possible.
        self::assertStringNotContainsString('-webkit-touch-callout', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*overflow-x:\s*hidden/s', $css, 'le crayon attend hors écran');
    }

    #[Test]
    public function testMessageTextKeepsLineBreaksAndNeverOverflows(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-text\s*\{[^}]*white-space:\s*pre-wrap[^}]*overflow-wrap:\s*anywhere/s', $this->css());
    }

    #[Test]
    public function testTheThreadScrollsInsideItselfAndTheComposerRespectsTheSafeArea(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-messages\s*\{[^}]*overflow-y:\s*auto/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-form\s*\{[^}]*env\(safe-area-inset-bottom\)/s', $css);
        self::assertMatchesRegularExpression('/height:\s*100dvh/', $css, 'hauteur dynamique du viewport mobile (barre d\'adresse)');
    }

    #[Test]
    public function testTheComposerInheritsTheSiteFont(): void
    {
        self::assertMatchesRegularExpression('/\.rb-chat-form textarea\s*\{[^}]*font:\s*inherit/s', $this->css());
    }

    #[Test]
    public function testTheSenderBadgeUsesTheGroupColourWithAFallback(): void
    {
        // La pastille est la classe partagée `.rb-avatar` (base.css, #324) : couleur du groupe, repli neutre.
        $base = (string) file_get_contents(__DIR__ . '/../../public/assets/css/base.css');
        self::assertMatchesRegularExpression('/\.rb-avatar\s*\{[^}]*var\(--group-color, var\(--rb-accent-2\)\)/s', $base);
    }

    #[Test]
    public function testQuotingHasABubbleBlockAHoverButtonARevealedIconAndAComposerPreview(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.rb-chat-quote\s*\{[^}]*border-left:\s*3px solid var\(--rb-accent\)/s', $css, 'citation dans la bulle');
        self::assertMatchesRegularExpression('/\.rb-chat-quote-text\s*\{[^}]*-webkit-line-clamp:\s*2/s', $css, 'l\'aperçu reste court');
        self::assertMatchesRegularExpression('/\.rb-chat-message:hover \.rb-chat-quote-action,\s*\.rb-chat-quote-action:focus-visible\s*\{[^}]*opacity:\s*1/s', $css, 'survol ou clavier sur ordinateur');
        self::assertMatchesRegularExpression('/rb-message-actions\[data-side="right"\]\s*\{[^}]*left:\s*calc\(100% \+ 4px\)/s', $css, 'tactile : l\'icône est à DROITE de la bulle des autres');
        self::assertMatchesRegularExpression('/@media \(hover: none\)\s*\{[^@]*\.rb-chat-message > \.rb-chat-quote-action,[^{]*\{[^}]*display:\s*none/s', $css, 'tactile : les boutons de ligne (survol) ne sont pas affichés, le composant les remplace');
        self::assertMatchesRegularExpression('/\.rb-chat-quoting\[hidden\]\s*\{[^}]*display:\s*none/', $css, 'aperçu masqué par défaut');
        self::assertMatchesRegularExpression('/\.rb-chat-quoting-cancel\s*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
        self::assertMatchesRegularExpression('/@keyframes rb-chat-flash/', $css, 'le message cité clignote');
        self::assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^@]*\.rb-chat-message--flash \.rb-chat-bubble\s*\{[^}]*animation:\s*none/s', $css, 'sans animation si on les réduit');
    }

    #[Test]
    public function testTouchActionsAreAComponentInsertedAtTapNotAHiddenButtonRevealedByAClass(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('--swipe-x: var', substr($css, (int) strpos($css, '.rb-chat-message[data-message-id]')), 'plus de bulle qui suit le doigt');
        self::assertStringNotContainsString('rb-chat-message--swiping', $css);
        self::assertStringNotContainsString('rb-chat-message--dragging', $css);
        self::assertStringNotContainsString('rb-chat-message--actions', $css, 'plus de classe qui révèle un bouton caché (#257)');
        self::assertStringNotContainsString('pointer-events: none', substr($css, (int) strpos($css, 'rb-message-actions')), 'rien de caché à toucher : le composant n\'existe que quand il est ouvert');
        self::assertMatchesRegularExpression('/\.rb-chat-message\s*\{[^}]*position:\s*relative/s', $css, 'le composant se positionne par rapport à la ligne');
        self::assertMatchesRegularExpression('/rb-message-actions\s*\{[^}]*position:\s*absolute/s', $css);
        self::assertMatchesRegularExpression('/@media \(hover: none\)\s*\{[^@]*\.rb-chat-bubble\s*\{[^}]*-webkit-tap-highlight-color:\s*transparent/s', $css);
        self::assertMatchesRegularExpression('/\.rb-chat-message > rb-message-actions > \.rb-chat-quote-action[^{]*\{[^}]*min-height:\s*44px/s', $css, 'zone tactile suffisante');
    }

    /** CSS sans les at-rules (@media, @keyframes) de premier niveau : il ne reste que les règles qui s'appliquent PARTOUT. */
    private function unconditionalRules(): string
    {
        $css = $this->css();
        $out = '';
        $depth = 0;
        $skipping = false;
        for ($i = 0, $len = strlen($css); $i < $len; $i++) {
            $char = $css[$i];
            if ($depth === 0 && $char === '@') {
                $skipping = true;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0 && $skipping) {
                    $skipping = false;
                    continue;
                }
            }
            if (!$skipping) {
                $out .= $char;
            }
        }

        return $out;
    }

    #[Test]
    public function testHoverNeverRevealsTheMessageActionsOnTouchScreens(): void
    {
        // iOS laisse un survol « collant » après un tap : le crayon apparaîtrait sans la classe posée par rb-message-actions.js, alors que
        // le bouton tactile est alors pointer-events: none, et iOS n'envoie pas toujours « click » quand le survol change l'affichage (#257).
        $always = $this->unconditionalRules();

        self::assertStringNotContainsString('.rb-chat-message:hover .rb-chat-edit', $always);
        self::assertStringNotContainsString('.rb-chat-message:hover .rb-chat-quote-action', $always);
        self::assertMatchesRegularExpression('/@media \(hover: hover\)\s*\{[^@]*\.rb-chat-message:hover \.rb-chat-edit/s', $this->css(), 'le survol reste pour l\'ordinateur');
        self::assertMatchesRegularExpression('/@media \(hover: hover\)\s*\{[^@]*\.rb-chat-message:hover \.rb-chat-quote-action/s', $this->css());
    }
}
