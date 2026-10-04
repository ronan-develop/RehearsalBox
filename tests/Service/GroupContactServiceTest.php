<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\GroupContactService;
use App\Tests\Support\RecordingMailer;
use App\Tests\RepositoryTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use PHPUnit\Framework\Attributes\Test;

final class GroupContactServiceTest extends RepositoryTestCase
{
    #[Test]
    public function testSendSendsEmailToGroupContactAddress(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $sender = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        $sentEmail = null;
        $mailer = new class($sentEmail) implements MailerInterface {
            public ?Email $sent = null;

            public function __construct(private mixed &$sentRef)
            {
            }

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent = $message;
                $this->sentRef = $message;
            }
        };

        $service = new GroupContactService($mailer, $groupRepository, 'no-reply@rehearsalbox.test');
        $service->send($group->id(), $sender->id(), $sender->email(), 'Bonjour, on peut échanger un créneau ?');

        self::assertNotNull($mailer->sent);
        self::assertSame(['contact@example.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $mailer->sent->getTo(),
        ));
        self::assertStringContainsString('Bonjour, on peut échanger un créneau ?', $mailer->sent->getTextBody());
        self::assertSame(['alice@rehearsalbox.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $mailer->sent->getReplyTo(),
        ));
    }

    #[Test]
    public function testSendUsesConfiguredFromAddressAndKeepsSenderAsReplyTo(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));

        $mailer = new class implements MailerInterface {
            public ?Email $sent = null;

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };

        $service = new GroupContactService($mailer, $groupRepository, 'no-reply@rehearsalbox.example');
        $service->send($group->id(), 1, 'alice@rehearsalbox.test', 'Bonjour');

        self::assertSame(['no-reply@rehearsalbox.example'], array_map(
            static fn ($address) => $address->getAddress(),
            $mailer->sent->getFrom(),
        ));
        self::assertSame(['alice@rehearsalbox.test'], array_map(
            static fn ($address) => $address->getAddress(),
            $mailer->sent->getReplyTo(),
        ));
    }

    #[Test]

    public function testSendWithUnknownGroupThrowsInvalidArgument(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $mailer = new class implements MailerInterface {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
            }
        };

        $service = new GroupContactService($mailer, $groupRepository, 'no-reply@rehearsalbox.test');

        $this->expectException(\InvalidArgumentException::class);

        $service->send(9999, 1, 'alice@rehearsalbox.test', 'Message');
    }

    #[Test]
    public function testTheContactMailHasABrandedHtmlVersionThatEscapesTheMessage(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $sender = $userRepository->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $mailer = new RecordingMailer();

        (new GroupContactService($mailer, $groupRepository, 'no-reply@rehearsalbox.test'))
            ->send($group->id(), $sender->id(), $sender->email(), '<script>alert(1)</script> On répète ?');

        $email = $mailer->sent[0];
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $email->getHtmlBody());
        self::assertStringContainsString('&lt;script&gt;', (string) $email->getHtmlBody());
        self::assertStringContainsString('#B27', (string) $email->getHtmlBody());
        self::assertStringContainsString('<script>alert(1)</script> On répète ?', (string) $email->getTextBody(), 'la version texte garde le message tel quel');
    }
}
