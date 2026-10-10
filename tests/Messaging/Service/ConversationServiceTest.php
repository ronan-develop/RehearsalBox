<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service;

use App\Database\TransactionRunner;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\ConversationRepositoryInterface as Box;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Messaging\Repository\Notice\MysqlConversationNoticeRepository;
use App\Messaging\Notification\ConversationNotifier;
use App\Messaging\Service\ConversationService;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use Symfony\Component\Mailer\MailerInterface;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Messaging\Exception\ConversationValidationException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\ConversationWorld;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use App\Tests\Scenarios\TestMailbox;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationServiceTest extends RepositoryTestCase
{
    use ConversationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    // --- Démarrer ------------------------------------------------------------------------------

    #[Test]
    public function testStartWithoutTitleShowsTheLabelOfBothGroupsToBothSides(): void
    {
        [$alice, $bob, $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), " Salut,\nOn échange ? ");

        self::assertNull($conversation->title());
        $thread = $this->reader->open($bob->id(), $conversation->id());
        self::assertSame('Alpha ↔ Beta', $thread->displayTitle());
        self::assertSame(["Salut,\nOn échange ?"], array_map(static fn ($m) => $m->body(), $thread->messages()));
        self::assertSame('Alice', $thread->messages()[0]->authorName());
    }

    #[Test]
    public function testStartWithATitleTrimsItAndBlankMeansNoTitle(): void
    {
        [$alice, , $a, $b] = $this->world();

        $titled = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut', '  Concert du 12 ');
        $blank = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut', '   ');

        self::assertSame('Concert du 12', $titled->title());
        self::assertNull($blank->title());
    }

    #[Test]
    public function testStartRefusesToSpeakForAGroupThePersonIsNotInTalkToItselfOrToAnUnknownGroup(): void
    {
        [$alice, , $a, $b] = $this->world();
        $messages = [];

        foreach ([[$b->id(), $a->id()], [$a->id(), $a->id()], [$a->id(), 9999]] as [$from, $to]) {
            try {
                $this->service->start($alice->id(), $from, $to, 'Message');
                self::fail('refus attendu');
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages), 'refus indiscernables');
        self::assertSame([], $this->reader->listFor($alice->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testStartValidatesTitleAndMessageAndCreatesNothingOnRefusal(): void
    {
        [$alice, , $a, $b] = $this->world();

        $cases = [
            'message' => ['   ', null],
            'message ' => [str_repeat('x', 5001), null],
            'title' => ['Message', str_repeat('x', 151)],
            'title ' => ['Message', "Titre\x00caché"],
        ];
        foreach ($cases as $field => [$body, $title]) {
            try {
                $this->service->start($alice->id(), $a->id(), $b->id(), $body, $title);
                self::fail("devrait refuser ({$field})");
            } catch (ConversationValidationException $e) {
                self::assertArrayHasKey(trim($field), $e->fields());
            }
        }
        self::assertSame([], $this->reader->listFor($alice->id(), Box::BOX_ACTIVE), 'rien n\'est créé en cas de refus');
    }

    #[Test]
    public function testMaximumLengthsAreAccepted(): void
    {
        [$alice, , $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), str_repeat('é', 5000), str_repeat('é', 150));

        self::assertSame(150, mb_strlen((string) $conversation->title()));
    }

    // --- E-mail au contact du groupe visé (#180) ----------------------------------------------------------

    private function serviceWithMailer(MailerInterface $mailer): ConversationService
    {
        return new ConversationService(
            new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()),
            new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()),
            new MysqlConversationPresenceRepository($this->pdo),
            $this->groups,
            new TransactionRunner($this->pdo),
            $this->clock,
            notifier: new ConversationNotifier(TestMailbox::of($mailer), new MysqlConversationNoticeRepository($this->pdo)),
        );
    }

    #[Test]
    public function testStartingAConversationEmailsTheTargetGroupContactOnceAndOnlyThat(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->groups->addMember($b->id(), $this->user('Zoe')->id());
        $mailer = new RecordingMailer();

        $conversation = $this->serviceWithMailer($mailer)->start($alice->id(), $a->id(), $b->id(), 'Texte très confidentiel', 'Titre confidentiel');

        self::assertCount(1, $mailer->sent, 'un seul e-mail, pas un par membre');
        self::assertSame(['beta@rehearsalbox.test'], array_map(static fn ($x) => $x->getAddress(), $mailer->sent[0]->getTo()), 'le contact du groupe visé');
        $text = (string) $mailer->sent[0]->getTextBody();
        self::assertStringContainsString('Alice', $text);
        self::assertStringContainsString('Alpha', $text);
        self::assertStringContainsString('/messages/' . $conversation->id(), $text);
        self::assertStringNotContainsString('confidentiel', $text . $mailer->sent[0]->getHtmlBody() . $mailer->sent[0]->getSubject());
    }

    #[Test]
    public function testRefusedStartsSendNoEmail(): void
    {
        [$alice, , $a, $b] = $this->world();
        $mailer = new RecordingMailer();
        $service = $this->serviceWithMailer($mailer);

        foreach ([
            fn () => $service->start($alice->id(), $b->id(), $a->id(), 'Message'),
            fn () => $service->start($alice->id(), $a->id(), $a->id(), 'Message'),
            fn () => $service->start($alice->id(), $a->id(), $b->id(), '   '),
            fn () => $service->start($alice->id(), $a->id(), $b->id(), 'Message', str_repeat('x', 151)),
        ] as $attempt) {
            try {
                $attempt();
            } catch (AccessDeniedException | ConversationValidationException) {
            }
        }

        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAMailTransportFailureNeverBlocksTheConversation(): void
    {
        [$alice, , $a, $b] = $this->world();

        $conversation = $this->serviceWithMailer(new FailingMailer())->start($alice->id(), $a->id(), $b->id(), 'Salut');

        self::assertCount(1, $this->reader->open($alice->id(), $conversation->id())->messages(), 'le message est bien envoyé');
    }

    #[Test]
    public function testRepliesDoNotSendTheImmediateEmail(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $mailer = new RecordingMailer();
        $service = $this->serviceWithMailer($mailer);
        $conversation = $service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        $service->reply($bob->id(), $conversation->id(), 'Réponse');
        $service->reply($alice->id(), $conversation->id(), 'Encore');

        self::assertCount(1, $mailer->sent, 'seul le premier message prévient le groupe (les relances sont un autre mécanisme)');
    }

    // --- Accès ------------------------------------------------------------------------------------

    #[Test]
    public function testAnOutsiderGetsTheSameRefusalAsForAMissingThread(): void
    {
        [$alice, , $a, $b] = $this->world();
        $outsider = $this->user('Carol');
        $this->group('Gamma', null, $outsider);
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        $calls = [
            fn () => $this->reader->open($outsider->id(), $conversation->id()),
            fn () => $this->reader->poll($outsider->id(), $conversation->id(), 0),
            fn () => $this->service->reply($outsider->id(), $conversation->id(), 'Intrus'),
            fn () => $this->service->rename($outsider->id(), $conversation->id(), 'Piraté'),
            fn () => $this->reader->typing($outsider->id(), $conversation->id()),
            fn () => $this->reader->open($alice->id(), 9999),
        ];
        $messages = [];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('devrait être refusé');
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages), 'messages indiscernables');
        $thread = $this->reader->open($alice->id(), $conversation->id());
        self::assertCount(1, $thread->messages(), 'aucun message ajouté');
        self::assertNull($thread->conversation()->title(), 'aucun renommage');
    }

    #[Test]
    public function testAMemberWhoLeftTheGroupLosesAccess(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->groups->removeMember($b->id(), $bob->id());

        $this->expectException(AccessDeniedException::class);
        $this->reader->open($bob->id(), $conversation->id());
    }

    // --- Répondre, non lu --------------------------------------------------------------

    #[Test]
    public function testAnyMemberOfEitherGroupCanReplyAndTheReplyIsUnreadForTheOthers(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $drummer = $this->user('Dave');
        $this->groups->addMember($a->id(), $drummer->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->sleep(60);

        $this->service->reply($bob->id(), $conversation->id(), 'Réponse de Bob');

        self::assertSame(1, $this->reader->unreadCount($drummer->id()), 'un autre membre du groupe A voit le non-lu');
        self::assertSame(1, $this->reader->unreadCount($alice->id()));
        self::assertSame(0, $this->reader->unreadCount($bob->id()), 'répondre suppose avoir lu');
        $this->clock->sleep(60);
        $this->reader->open($drummer->id(), $conversation->id());
        self::assertSame(0, $this->reader->unreadCount($drummer->id()), 'ouvrir le fil le marque lu');
        self::assertSame(1, $this->reader->unreadCount($alice->id()), 'la lecture est par personne');
    }

    #[Test]
    public function testReplyValidatesTheMessage(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        $this->expectException(ConversationValidationException::class);
        $this->service->reply($alice->id(), $conversation->id(), "  \n ");
    }

    // --- Citer un message (#214) ---------------------------------------------------------------------

    #[Test]
    public function testAReplyCanQuoteAMessageOfTheSameConversation(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Jeudi à 20h ?');
        $first = $this->reader->open($bob->id(), $conversation->id())->messages()[0];

        $reply = $this->service->reply($bob->id(), $conversation->id(), 'Oui !', [], $first->id());

        self::assertSame($first->id(), $reply->quote()?->messageId());
        self::assertSame('Alice', $reply->quote()->authorName());
        self::assertSame('Jeudi à 20h ?', $reply->quote()->excerpt());
        self::assertSame($first->id(), $this->lastOf($this->reader->open($alice->id(), $conversation->id())->messages())->quote()?->messageId());
    }

    #[Test]
    public function testOneMayQuoteOneOwnMessage(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $first = $this->reader->open($alice->id(), $conversation->id())->messages()[0];

        $reply = $this->service->reply($alice->id(), $conversation->id(), 'Je précise', [], $first->id());

        self::assertSame($first->id(), $reply->quote()?->messageId());
    }

    #[Test]
    public function testAMessageOfAnotherConversationCannotBeQuoted(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $carole = $this->user('Carole');
        $c = $this->group('Gamma', '#00aa00', $carole);
        $mine = $this->service->start($alice->id(), $a->id(), $b->id(), 'Notre fil');
        $other = $this->service->start($carole->id(), $c->id(), $b->id(), 'Fil secret de Carole');
        $secret = $this->reader->open($carole->id(), $other->id())->messages()[0];

        try {
            $this->service->reply($alice->id(), $mine->id(), 'Je cite un message que je ne devrais pas voir', [], $secret->id());
            self::fail('citer un message d’une autre conversation doit être refusé');
        } catch (AccessDeniedException $e) {
            self::assertSame('Accès refusé.', $e->getMessage());
        }
        self::assertCount(1, $this->reader->open($alice->id(), $mine->id())->messages(), 'rien n’est enregistré');
    }

    #[Test]
    public function testAnUnknownMessageAndASystemLineCannotBeQuotedAndRefuseTheSameWay(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $this->service->rename($alice->id(), $conversation->id(), 'Nouveau titre');
        $messages = $this->reader->open($alice->id(), $conversation->id())->messages();
        $system = $this->lastOf($messages);
        self::assertTrue($system->isSystem());

        foreach ([9999, $system->id()] as $id) {
            try {
                $this->service->reply($alice->id(), $conversation->id(), 'Citation invalide', [], $id);
                self::fail('une citation invalide doit être refusée');
            } catch (AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function testQuotingMentionsNobodyAndStillValidatesTheReply(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $first = $this->reader->open($bob->id(), $conversation->id())->messages()[0];

        $this->expectException(ConversationValidationException::class);
        $this->service->reply($bob->id(), $conversation->id(), "  \n ", [], $first->id());
    }

    // --- Titre ----------------------------------------------------------------------------------------

    #[Test]
    public function testAnyMemberCanRenameAndTheFilGetsASystemLine(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->sleep(60);

        $this->service->rename($bob->id(), $conversation->id(), '  Concert du 12 ');

        self::assertSame(1, $this->reader->unreadCount($alice->id()), 'les autres voient le renommage');
        $thread = $this->reader->open($alice->id(), $conversation->id());
        self::assertSame('Concert du 12', $thread->displayTitle());
        $system = $this->lastOf($thread->messages());
        self::assertTrue($system->isSystem());
        self::assertSame('Bob', $system->authorName());
        self::assertSame('a renommé la conversation « Concert du 12 »', $system->body());
    }

    #[Test]
    public function testRemovingTheTitleRestoresTheLabelAndRenamingToTheSameTitleIsANoOp(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message', 'Concert');

        $this->service->rename($alice->id(), $conversation->id(), 'Concert');
        self::assertCount(1, $this->reader->open($alice->id(), $conversation->id())->messages(), 'même titre : aucune ligne');

        $this->service->rename($alice->id(), $conversation->id(), '  ');
        $thread = $this->reader->open($alice->id(), $conversation->id());
        self::assertSame('Alpha ↔ Beta', $thread->displayTitle());
        self::assertSame('a retiré le titre de la conversation', $this->lastOf($thread->messages())->body());
    }

    #[Test]
    public function testRenameValidatesTheTitle(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        try {
            $this->service->rename($alice->id(), $conversation->id(), str_repeat('x', 151));
            self::fail('refus attendu');
        } catch (ConversationValidationException $e) {
            self::assertArrayHasKey('title', $e->fields());
        }
        self::assertNull($this->reader->open($alice->id(), $conversation->id())->conversation()->title());
    }

    // --- Limite d'envois ------------------------------------------------------------------------------

    #[Test]
    public function testSendingIsLimitedPerHourAndPerAuthor(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message 1');
        for ($i = 2; $i <= ConversationService::MAX_MESSAGES_PER_HOUR; $i++) {
            $this->service->reply($alice->id(), $conversation->id(), "Message {$i}");
        }

        try {
            $this->service->reply($alice->id(), $conversation->id(), 'De trop');
            self::fail('limite attendue');
        } catch (ConversationRateLimitException) {
        }
        try {
            $this->service->start($alice->id(), $a->id(), $b->id(), 'Encore');
            self::fail('limite attendue aussi pour un nouveau fil');
        } catch (ConversationRateLimitException) {
        }

        $this->service->reply($bob->id(), $conversation->id(), 'Bob peut répondre');
        $this->clock->sleep(3601);
        $this->service->reply($alice->id(), $conversation->id(), 'Une heure plus tard');
        $this->addToAssertionCount(1);
    }
}
