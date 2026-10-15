<?php

declare(strict_types=1);

namespace App\Tests\Association\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Association\Exception\AssociationTextChangedException;
use App\Association\Exception\AssociationTextNotFoundException;
use App\Association\Exception\AssociationTextValidationException;
use App\Association\Repository\MysqlAssociationTextRepository;
use App\Association\Service\AssociationTextInputPolicy;
use App\Association\Service\AssociationTextService;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #472 : petit CRUD réservé aux administrateurs, titre unique, modification sans écrasement entre deux administrateurs. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AssociationTextServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private AssociationTextService $service;
    private User $admin;
    private User $otherAdmin;
    private User $musician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->service = new AssociationTextService(new AdminAccess(), new AssociationTextInputPolicy(), new MysqlAssociationTextRepository($this->pdo), new NullLogger());
        $this->admin = $this->makeAdmin('Admin');
        $this->otherAdmin = $this->makeAdmin('Autre');
        $this->musician = $this->user('Musicien');
    }

    private function makeAdmin(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Admin, true, 0, null));
    }

    private function created(string $title = 'Statuts', string $body = "Article 1\nArticle 2"): int
    {
        return $this->service->create($this->admin, ['title' => $title, 'body' => $body], $this->now);
    }

    #[Test]
    public function testOnlyActiveAdministratorsCanDoAnything(): void
    {
        $id = $this->created();
        $gone = $this->users->save($this->makeAdmin('Parti')->withActive(false));
        $calls = [
            'list' => fn (User $u) => $this->service->list($u),
            'get' => fn (User $u) => $this->service->get($u, $id),
            'create' => fn (User $u) => $this->service->create($u, ['title' => 'X', 'body' => 'Y']),
            'update' => fn (User $u) => $this->service->update($u, $id, 0, ['title' => 'X', 'body' => 'Y']),
            'delete' => fn (User $u) => $this->service->delete($u, $id),
        ];

        foreach ([$this->musician, $gone] as $actor) {
            foreach ($calls as $name => $call) {
                try {
                    $call($actor);
                    self::fail("{$name} aurait dû être refusé");
                } catch (AccessDeniedException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $text = $this->service->get($this->admin, $id);
        self::assertSame('Statuts', $text->title);
        self::assertSame(0, $text->revision);
        self::assertCount(1, $this->service->list($this->admin));
    }

    #[Test]
    public function testCreateReadUpdateDeleteRoundTrip(): void
    {
        $id = $this->created('Statuts', "Article 1\r\nArticle <2>");

        $text = $this->service->get($this->admin, $id);
        self::assertSame("Article 1\nArticle <2>", $text->body);
        self::assertSame('Admin', $text->updatedByName);
        self::assertSame('2026-10-04', $text->updatedAt->format('Y-m-d'));

        $revision = $this->service->update($this->otherAdmin, $id, 0, ['title' => 'Statuts 2026', 'body' => 'Article 1 modifié'], new \DateTimeImmutable('2026-11-01 10:00:00'));
        self::assertSame(1, $revision);
        $after = $this->service->get($this->admin, $id);
        self::assertSame(['Statuts 2026', 'Article 1 modifié', 1, 'Autre'], [$after->title, $after->body, $after->revision, $after->updatedByName]);
        self::assertSame('2026-11-01', $after->updatedAt->format('Y-m-d'));

        $this->service->delete($this->admin, $id);
        self::assertSame([], $this->service->list($this->admin));
    }

    #[Test]
    public function testTheListIsOrderedByTitle(): void
    {
        $this->created('Règlement intérieur');
        $this->created('Statuts');
        $this->created('Annexe');

        self::assertSame(['Annexe', 'Règlement intérieur', 'Statuts'], array_map(static fn ($t): string => $t->title, $this->service->list($this->admin)));
    }

    #[Test]
    public function testATitleAlreadyTakenIsRefusedWhateverTheCaseOnCreateAndUpdate(): void
    {
        $this->created('Statuts');
        $other = $this->created('Règlement');

        foreach ([fn () => $this->created('STATUTS'), fn () => $this->service->update($this->admin, $other, 0, ['title' => 'statuts', 'body' => 'x'])] as $attempt) {
            try {
                $attempt();
                self::fail('Titre en double accepté');
            } catch (AssociationTextValidationException $e) {
                self::assertSame(['title'], array_keys($e->fields()));
            }
        }
        self::assertSame(0, $this->service->get($this->admin, $other)->revision);
        self::assertCount(2, $this->service->list($this->admin));
    }

    #[Test]
    public function testAnUpdateKeepingItsOwnTitleIsFine(): void
    {
        $id = $this->created('Statuts');

        self::assertSame(1, $this->service->update($this->admin, $id, 0, ['title' => 'Statuts', 'body' => 'Nouveau texte'], $this->now));
    }

    #[Test]
    public function testAStaleRevisionIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->created();
        $this->service->update($this->admin, $id, 0, ['title' => 'Statuts', 'body' => 'Premier'], $this->now);

        foreach ([0, 5, -1] as $stale) {
            try {
                $this->service->update($this->otherAdmin, $id, $stale, ['title' => 'Statuts', 'body' => 'Écrasement'], $this->now);
                self::fail('Révision périmée acceptée');
            } catch (AssociationTextChangedException) {
                $this->addToAssertionCount(1);
            }
        }
        $text = $this->service->get($this->admin, $id);
        self::assertSame(['Premier', 1, 'Admin'], [$text->body, $text->revision, $text->updatedByName]);
    }

    #[Test]
    public function testUnknownTextsAreNotFound(): void
    {
        foreach ([
            fn () => $this->service->get($this->admin, 999999),
            fn () => $this->service->update($this->admin, 999999, 0, ['title' => 'T', 'body' => 'B']),
            fn () => $this->service->delete($this->admin, 999999),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Texte inexistant accepté');
            } catch (AssociationTextNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAnInvalidInputWritesNothing(): void
    {
        $id = $this->created();

        try {
            $this->service->update($this->admin, $id, 0, ['title' => '', 'body' => '']);
            self::fail('Saisie vide acceptée');
        } catch (AssociationTextValidationException $e) {
            self::assertSame(['title', 'body'], array_keys($e->fields()));
        }
        self::assertSame(0, $this->service->get($this->admin, $id)->revision);
    }

    #[Test]
    public function testTheBodyIsStoredAsTypedLong(): void
    {
        $long = str_repeat("Une ligne de statuts très longue, avec des accents éàù et <balises>.\n", 1200); // ~82 000 caractères, > 64 Ko : il faut MEDIUMTEXT
        $id = $this->created('Long', $long);

        self::assertSame(rtrim($long), $this->service->get($this->admin, $id)->body);
    }
}
