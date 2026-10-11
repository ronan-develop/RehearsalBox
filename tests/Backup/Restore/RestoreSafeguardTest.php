<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Backup\Restore\RestoreSafeguard;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Doubles\ThrowingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #424 : la restauration par le web est fermée par défaut ; chaque demande acceptée alerte par e-mail le compte qui la lance. */
final class RestoreSafeguardTest extends TestCase
{
    private function owner(): User
    {
        return new User(7, 'owner@rehearsalbox.test', 'hash', 'Propriétaire', UserRole::Admin, true, 0, null);
    }

    #[Test]
    public function testItReportsWhetherRestoringFromTheWebIsEnabled(): void
    {
        self::assertTrue((new RestoreSafeguard(true, TestMailbox::of(new RecordingMailer())))->isEnabled());
        self::assertFalse((new RestoreSafeguard(false, TestMailbox::of(new RecordingMailer())))->isEnabled());
    }

    #[Test]
    public function testTheAccountOwnerIsAlertedByEmailWithTheBackupAndTheTime(): void
    {
        $mailer = new RecordingMailer();

        (new RestoreSafeguard(true, TestMailbox::of($mailer)))->announce($this->owner(), 'db-20261019030000.sql.gz', new \DateTimeImmutable('2026-10-20 10:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertCount(1, $mailer->sent);
        self::assertSame('owner@rehearsalbox.test', $mailer->sent[0]->getTo()[0]->getAddress());
        $body = (string) $mailer->sent[0]->getTextBody();
        self::assertStringContainsString('db-20261019030000.sql.gz', $body);
        self::assertStringContainsString('20/10/2026', $body);
        self::assertStringContainsString('10:00', $body);
        self::assertStringNotContainsString('token=', $body, 'aucun lien d\'action');
    }

    #[Test]
    public function testAMailFailureNeverBlocksTheRestoration(): void
    {
        (new RestoreSafeguard(true, TestMailbox::of(new ThrowingMailer())))->announce($this->owner(), 'db-20261019030000.sql.gz', new \DateTimeImmutable('2026-10-20 10:00:00'));

        $this->expectNotToPerformAssertions();
    }
}
