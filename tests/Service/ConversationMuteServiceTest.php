<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\MysqlConversationMuteRepository;
use App\Repository\MysqlConversationRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationAccess;
use App\Service\ConversationMuteService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #210 : un participant met une conversation en sourdine ou la rétablit ; personne d'autre, aucun effet sur les autres. */
final class ConversationMuteServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private ConversationMuteService $service;
    private MysqlConversationMuteRepository $mutes;
    private int $conversationId;
    private int $aliceId;
    private int $bobId;
    private int $strangerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $stranger = $this->user('Carole');
        $a = $this->group('Alpha', $alice);
        $b = $this->group('Beta', $bob);
        $conversations = new MysqlConversationRepository($this->pdo);
        $this->conversationId = $conversations->create($a->id(), $b->id(), 'Fil', $this->now, $alice->id())->id();
        $this->mutes = new MysqlConversationMuteRepository($this->pdo);
        $this->service = new ConversationMuteService(new ConversationAccess($conversations, $this->groups), $this->mutes);
        [$this->aliceId, $this->bobId, $this->strangerId] = [$alice->id(), $bob->id(), $stranger->id()];
    }

    #[Test]
    public function testAParticipantMutesAndUnmutesTheirOwnSwitchOnly(): void
    {
        $this->service->mute($this->bobId, $this->conversationId);
        self::assertTrue($this->mutes->isMuted($this->conversationId, $this->bobId));
        self::assertFalse($this->mutes->isMuted($this->conversationId, $this->aliceId), 'les autres ne sont pas touchés');

        $this->service->unmute($this->bobId, $this->conversationId);
        self::assertFalse($this->mutes->isMuted($this->conversationId, $this->bobId));
    }

    #[Test]
    public function testMutingTwiceIsHarmless(): void
    {
        $this->service->mute($this->bobId, $this->conversationId);
        $this->service->mute($this->bobId, $this->conversationId);

        self::assertTrue($this->mutes->isMuted($this->conversationId, $this->bobId));
    }

    #[Test]
    public function testANonParticipantIsRefusedLikeAnUnknownConversation(): void
    {
        foreach ([[$this->strangerId, $this->conversationId], [$this->bobId, 9999]] as [$user, $conversation]) {
            try {
                $this->service->mute($user, $conversation);
                self::fail('un accès interdit ou inexistant doit être refusé');
            } catch (AccessDeniedException $e) {
                self::assertSame(ConversationAccess::DENIED, $e->getMessage(), 'même refus pour interdit et inexistant');
            }
        }
        self::assertFalse($this->mutes->isMuted($this->conversationId, $this->strangerId));
    }
}
