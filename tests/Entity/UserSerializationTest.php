<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\UuidV7;

final class UserSerializationTest extends KernelTestCase
{
    public function testNativeSerializeRoundTripPreservesSecurityRelevantState(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);

        $plain = 'Plain-Password-123!';
        $user = $factory->create('native-ser@example.com', $plain, 'Native', 'User', UserRole::Teacher);
        $user->transitionTo(UserStatus::Active);

        $id = $user->getId()->toRfc4122();
        $identifier = $user->getUserIdentifier();
        $passwordHash = $user->getPassword();
        $roles = $user->getRoles();
        $status = $user->getStatus();

        $restored = unserialize(serialize($user));

        self::assertInstanceOf(\App\Entity\User::class, $restored);
        self::assertSame($id, $restored->getId()->toRfc4122());
        self::assertInstanceOf(UuidV7::class, $restored->getId());
        self::assertSame($identifier, $restored->getUserIdentifier());
        self::assertSame($passwordHash, $restored->getPassword());
        self::assertNotSame('', $restored->getPassword());
        self::assertSame($roles, $restored->getRoles());
        self::assertSame($status, $restored->getStatus());
        self::assertStringNotContainsString($plain, serialize($user));
    }

    public function testSymfonySerializerJsonOmitsPasswordAndHash(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);

        $plain = 'Plain-Password-123!';
        $user = $factory->create('json-ser@example.com', $plain, 'Json', 'User', UserRole::Student);
        $json = $serializer->serialize($user, 'json');

        self::assertStringNotContainsString('"password"', $json);
        self::assertStringNotContainsString($plain, $json);
        self::assertStringNotContainsString($user->getPassword(), $json);
        self::assertStringNotContainsString('globalRoles', $json);
        self::assertStringNotContainsString('pending_verification', $json);
    }

    public function testCreationTimestampsAreConsistent(): void
    {
        $user = \App\Entity\User::create(
            'timestamps@example.com',
            'timestamps@example.com',
            'Time',
            'Stamp',
            '!',
            UserRole::User,
        );

        self::assertSame($user->getCreatedAt(), $user->getUpdatedAt());
        self::assertSame($user->getCreatedAt(), $user->getPasswordChangedAt());
    }
}
