<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\SecurityAuditEvent;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\EventSubscriber\SecurityAuditImmutabilitySubscriber;
use App\Exception\SecurityAuditImmutableException;
use App\Exception\SecurityAuditMetadataException;
use App\Repository\SecurityAuditEventRepository;
use App\Service\SecurityAuditHashGenerator;
use App\Service\SecurityAuditMetadataSanitizer;
use App\Service\SecurityAuditRecorder;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class SecurityAuditRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SecurityAuditRecorder $recorder;
    private SecurityAuditEventRepository $events;
    private UserFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $recorder = $c->get(SecurityAuditRecorder::class);
        self::assertInstanceOf(SecurityAuditRecorder::class, $recorder);
        $this->recorder = $recorder;

        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;

        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;

        $this->recorder->resetRequestDedup();
    }

    public function testRecordsEventWithClockOccurredAt(): void
    {
        $user = $this->factory->createAndPersist('audit-ok@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $clock = static::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $before = \DateTimeImmutable::createFromInterface($clock->now())->modify('-2 seconds');

        $event = $this->recorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::UserRegistered,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            captureRequestHashes: false,
        ));

        self::assertInstanceOf(SecurityAuditEvent::class, $event);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $event->getOccurredAt()->getTimestamp());
        self::assertSame(SecurityAuditAction::UserRegistered, $event->getAction());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::UserRegistered->value));
    }

    public function testSubscriberBlocksUpdateAndRemove(): void
    {
        $event = SecurityAuditEvent::create(
            action: SecurityAuditAction::UserRegistered,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            occurredAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
            metadata: ['source' => 'test'],
        );
        $subscriber = new SecurityAuditImmutabilitySubscriber();

        $changeSet = ['outcome' => [SecurityAuditOutcome::Success, SecurityAuditOutcome::Failure]];
        try {
            $subscriber->preUpdate(new PreUpdateEventArgs($event, $this->em, $changeSet));
            self::fail('Expected update to be blocked');
        } catch (SecurityAuditImmutableException) {
        }

        try {
            $subscriber->preRemove(new PreRemoveEventArgs($event, $this->em));
            self::fail('Expected remove to be blocked');
        } catch (SecurityAuditImmutableException) {
        }
    }

    public function testCannotRemoveAuditEventViaEntityManager(): void
    {
        $user = $this->factory->createAndPersist('audit-del@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $event = $this->recorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::UserRegistered,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            captureRequestHashes: false,
        ));
        self::assertInstanceOf(SecurityAuditEvent::class, $event);

        try {
            $this->em->remove($event);
            $this->em->flush();
            self::fail('Expected immutable delete exception');
        } catch (SecurityAuditImmutableException) {
            self::ensureKernelShutdown();
        }
    }

    public function testUserDeleteNullsActorSubjectButKeepsEvent(): void
    {
        $user = $this->factory->createAndPersist('audit-keep@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $event = $this->recorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::LoginSucceeded,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $user,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            captureRequestHashes: false,
        ));
        self::assertInstanceOf(SecurityAuditEvent::class, $event);
        $eventId = $event->getId()->toBinary();

        $this->em->remove($user);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->events->find($event->getId());
        self::assertInstanceOf(SecurityAuditEvent::class, $reloaded);
        self::assertNull($reloaded->getActorUser());
        self::assertNull($reloaded->getSubjectUser());
        self::assertSame($eventId, $reloaded->getId()->toBinary());
    }

    public function testMetadataAllowlistAndForbiddenKeys(): void
    {
        $sanitizer = static::getContainer()->get(SecurityAuditMetadataSanitizer::class);
        self::assertInstanceOf(SecurityAuditMetadataSanitizer::class, $sanitizer);

        $clean = $sanitizer->sanitize([
            'Reason' => 'ok',
            'previous_roles' => ['ROLE_STUDENT'],
        ]);
        self::assertSame('ok', $clean['reason']);

        foreach (['password', 'plainPassword', 'token', 'secret', 'authorization', 'cookie', 'email', 'ip', 'userAgent', 'databaseUrl'] as $bad) {
            try {
                $sanitizer->sanitize([$bad => 'x']);
                self::fail('Expected forbidden key '.$bad);
            } catch (SecurityAuditMetadataException) {
            }
        }
    }

    public function testHashesDoNotStoreRawIpOrUserAgent(): void
    {
        $hasher = static::getContainer()->get(SecurityAuditHashGenerator::class);
        self::assertInstanceOf(SecurityAuditHashGenerator::class, $hasher);
        $ipHash = $hasher->hashIp('203.0.113.10');
        $uaHash = $hasher->hashUserAgent('Mozilla/5.0 TestAgent');
        self::assertNotNull($ipHash);
        self::assertNotNull($uaHash);
        self::assertStringNotContainsString('203.0.113.10', $ipHash);
        self::assertStringNotContainsString('Mozilla', $uaHash);

        $user = $this->factory->createAndPersist('audit-hash@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $event = SecurityAuditEvent::create(
            action: SecurityAuditAction::LoginSucceeded,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            occurredAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
            actorUser: $user,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            ipHash: $ipHash,
            userAgentHash: $uaHash,
        );
        $this->events->save($event);
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ip_hash, user_agent_hash, metadata FROM security_audit_events WHERE id = ?',
            [$event->getId()->toBinary()],
        );
        self::assertIsArray($row);
        self::assertStringNotContainsString('203.0.113.10', (string) $row['ip_hash']);
        self::assertStringNotContainsString('Mozilla', (string) $row['user_agent_hash']);
        self::assertStringNotContainsString('203.0.113.10', (string) $row['metadata']);
    }

    public function testSerializerDoesNotExposeHashes(): void
    {
        $user = $this->factory->createAndPersist('audit-ser@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $event = $this->recorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::UserRegistered,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            captureRequestHashes: false,
        ));
        self::assertInstanceOf(SecurityAuditEvent::class, $event);

        $serializer = static::getContainer()->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $json = $serializer->serialize($event, 'json');
        self::assertStringNotContainsString('ipHash', $json);
        self::assertStringNotContainsString('userAgentHash', $json);
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', $json);
        self::assertStringNotContainsString('Guclu-Parola', $json);
    }

    public function testDuplicateRecordInSameRequestIsSkipped(): void
    {
        $user = $this->factory->createAndPersist('audit-dup@example.com', 'Guclu-Parola-123!', 'A', 'B', UserRole::Student);
        $ctx = new SecurityAuditContext(
            action: SecurityAuditAction::UserRegistered,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            subjectUser: $user,
            metadata: ['source' => 'test'],
            correlationId: 'fixed-correlation',
            captureRequestHashes: false,
        );
        self::assertInstanceOf(SecurityAuditEvent::class, $this->recorder->record($ctx));
        self::assertNull($this->recorder->record($ctx));
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::UserRegistered->value));
    }

    protected function tearDown(): void
    {
        try {
            if (!$this->em->isOpen()) {
                self::ensureKernelShutdown();
                self::bootKernel();
                $em = static::getContainer()->get(EntityManagerInterface::class);
                self::assertInstanceOf(EntityManagerInterface::class, $em);
                $this->em = $em;
            }
            $connection = $this->em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['security_audit_events'])) {
                $connection->executeStatement('DELETE FROM security_audit_events');
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
