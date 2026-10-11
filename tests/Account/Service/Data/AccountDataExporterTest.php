<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Data;

use App\Account\Repository\MysqlAccountDataRepository;
use App\Account\Service\Data\AccountDataExporter;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Support\TestMessageCipher;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #431 : « Télécharger mes données » : le profil, les groupes et ce que la personne a écrit ou demandé, sans aucun secret. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountDataExporterTest extends RepositoryTestCase
{
    use MessagingScenario;

    #[Test]
    public function testTheExportHoldsTheProfileTheGroupsAndEveryKindOfPersonalData(): void
    {
        $this->setUpScenario();
        $alice = $this->user('Alice');
        $this->group('Alpha', $alice);
        $exporter = new AccountDataExporter($this->users, new MysqlAccountDataRepository($this->pdo, TestMessageCipher::make()), new MockClock('2026-10-20 10:00:00 UTC'));

        $export = $exporter->export($alice->id());

        self::assertSame('2026-10-20T10:00:00+00:00', $export['generatedAt']);
        self::assertSame(['id' => $alice->id(), 'email' => 'alice@rehearsalbox.test', 'displayName' => 'Alice', 'role' => 'musicien', 'active' => true], $export['account']);
        self::assertSame('Alpha', $export['groups'][0]['group']);
        foreach (['messages', 'bookings', 'slotRequests'] as $key) {
            self::assertSame([], $export[$key], $key);
        }
    }

    #[Test]
    public function testNoSecretEverLeavesInTheExport(): void
    {
        $this->setUpScenario();
        $alice = $this->user('Alice');
        $exporter = new AccountDataExporter($this->users, new MysqlAccountDataRepository($this->pdo, TestMessageCipher::make()), new MockClock());

        $json = (string) json_encode($exporter->export($alice->id()));

        self::assertStringNotContainsString('hash', strtolower($json));
        self::assertStringNotContainsString('password', strtolower($json));
        self::assertStringNotContainsString('session', strtolower($json));
    }

    #[Test]
    public function testAnUnknownAccountHasNothingToExport(): void
    {
        $this->setUpScenario();
        $exporter = new AccountDataExporter($this->users, new MysqlAccountDataRepository($this->pdo, TestMessageCipher::make()), new MockClock());

        $this->expectException(\App\Account\Exception\UserNotFoundException::class);
        $exporter->export(999999);
    }
}
