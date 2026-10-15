<?php

declare(strict_types=1);

namespace App\Tests\Assembly\View;

use App\Account\Entity\UserRole;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : gabarits admin des comptes rendus d'AG (liste, édition du brouillon, lecture seule d'un compte validé). */
final class AssemblyFormTemplatesTest extends TestCase
{
    /** @param array<string, mixed> $form */
    private function renderEdit(array $form): string
    {
        return (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/assemblies/edit', [
            'csrfToken' => 'token',
            'form' => $form,
            'currentUserRole' => UserRole::Admin,
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function draft(array $overrides = []): array
    {
        return array_merge([
            'id' => 3,
            'revision' => 2,
            'version' => 1,
            'isDraft' => true,
            'title' => 'Assemblée générale 2026',
            'statusLabel' => 'Brouillon',
            'successorId' => null,
            'kind' => 'ordinaire',
            'heldOn' => '2026-10-01',
            'location' => 'Local',
            'openedAt' => '19:00',
            'closedAt' => '21:00',
            'quorum' => '',
            'moralReport' => '',
            'financialReport' => '',
            'otherBusiness' => '',
            'board' => [
                'president' => ['label' => 'Président', 'userId' => '1', 'options' => [['id' => '1', 'name' => 'Alice']]],
                'tresorier' => ['label' => 'Trésorier', 'userId' => '', 'options' => [['id' => '2', 'name' => 'Bob']]],
                'secretaire' => ['label' => 'Secrétaire', 'userId' => '', 'options' => [['id' => '1', 'name' => 'Alice'], ['id' => '2', 'name' => 'Bob']]],
            ],
            'users' => [['id' => '1', 'name' => 'Alice']],
            'attendances' => ['present', 'excuse', 'represente'],
            'attendees' => [
                ['userId' => '', 'name' => '<script>x</script>', 'attendance' => 'present', 'proxyHolder' => ''],
            ],
            'agenda' => [],
            'resolutions' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validated(array $overrides = []): array
    {
        return $this->draft(array_merge([
            'isDraft' => false,
            'statusLabel' => 'Validé',
        ], $overrides));
    }

    #[Test]
    public function testADraftRendersTheFormWithEscapedAttendeeNameAndRowTemplates(): void
    {
        $html = $this->renderEdit($this->draft());

        self::assertStringContainsString('<rb-assembly-form', $html);
        self::assertStringContainsString('data-row-template="attendees"', $html);
        self::assertStringContainsString('data-action="save"', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
    }

    #[Test]
    public function testEachBoardSelectOffersOnlyItsOwnAccountsAndPreselectsTheHolder(): void
    {
        $html = $this->renderEdit($this->draft());

        preg_match_all('#<select id="assembly-board-(\w+)"[^>]*>(.*?)</select>#s', $html, $selects, PREG_SET_ORDER);
        $options = [];
        foreach ($selects as [, $post, $inner]) {
            preg_match_all('#<option value="(\d*)"( selected)?>#', $inner, $found, PREG_SET_ORDER);
            $options[$post] = array_map(static fn (array $o): string => $o[1] . (isset($o[2]) ? '*' : ''), $found);
        }

        self::assertSame(['', '1*'], $options['president']);
        self::assertSame(['', '2'], $options['tresorier']);
        self::assertSame(['', '1', '2'], $options['secretaire']);
        self::assertStringNotContainsString('>Bob</option>', substr($html, (int) strpos($html, 'assembly-board-president'), 300));
    }

    #[Test]
    public function testAValidatedAssemblyHasNoFormButThePrintLink(): void
    {
        $html = $this->renderEdit($this->validated());

        self::assertStringNotContainsString('<rb-assembly-form', $html);
        self::assertStringContainsString('href="/admin/assemblies/3/print"', $html);
        self::assertStringContainsString('data-assembly-correct', $html);
        self::assertStringContainsString('data-action="open-correct"', $html);
    }

    #[Test]
    public function testAValidatedAssemblyWithASuccessorShowsTheLinkInsteadOfTheCorrectionBlock(): void
    {
        $html = $this->renderEdit($this->validated(['successorId' => 9]));

        self::assertStringNotContainsString('data-assembly-correct', $html);
        self::assertStringContainsString('href="/admin/assemblies/9"', $html);
        self::assertStringContainsString('Une correction existe : voir la version suivante', $html);
    }

    #[Test]
    public function testTheIndexLinksEachRowToItsUrlAndOffersCreation(): void
    {
        $html = (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/assemblies/index', [
            'csrfToken' => 'token',
            'rows' => [
                ['id' => 5, 'title' => 'AG 2026', 'date' => '2026-10-01', 'status' => 'Brouillon', 'version' => 1, 'url' => '/admin/assemblies/5'],
            ],
            'currentUserRole' => UserRole::Admin,
        ]);

        self::assertStringContainsString('href="/admin/assemblies/5"', $html);
        self::assertStringContainsString('data-action="create"', $html);
        self::assertStringContainsString('Aucun compte rendu.', $html);
        self::assertStringContainsString('href="/admin/texts"', $html, 'accès aux statuts et textes de l\'association (#472)');
    }

    #[Test]
    public function testNoOutputContainsAnInlineStyleOrAnInlineScript(): void
    {
        $outputs = [
            $this->renderEdit($this->draft()),
            $this->renderEdit($this->validated()),
            (new PhpTemplateRenderer(__DIR__ . '/../../../templates'))->render('admin/assemblies/index', [
                'csrfToken' => 'token',
                'rows' => [],
                'currentUserRole' => UserRole::Admin,
            ]),
        ];

        foreach ($outputs as $html) {
            self::assertStringNotContainsString('style=', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        }
    }
}
