<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\DuplicateEmailException;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Single entry point for creating User accounts.
 *
 * Domain memberships (school, classroom, etc.) are intentionally out of scope.
 */
final class UserFactory
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly PersonNameNormalizer $nameNormalizer,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function create(
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        UserRole $initialRole,
        ?Uuid $id = null,
    ): User {
        if ($initialRole->isPrivilegedBootstrapRole()) {
            throw InvalidUserTransitionException::privilegedBootstrapRole($initialRole);
        }

        $allowed = UserRole::assignableAtCreation();
        $allowed[] = UserRole::User;
        if (!\in_array($initialRole, $allowed, true)) {
            throw InvalidUserTransitionException::forRole(\sprintf('Role "%s" is not assignable at creation.', $initialRole->value));
        }

        $emails = $this->emailNormalizer->normalizePair($email);
        if ($this->users->existsWithNormalizedEmail($emails['normalizedEmail'])) {
            throw new DuplicateEmailException($emails['normalizedEmail']);
        }

        $user = User::create(
            email: $emails['email'],
            normalizedEmail: $emails['normalizedEmail'],
            firstName: $this->nameNormalizer->normalize($firstName, 'firstName'),
            lastName: $this->nameNormalizer->normalize($lastName, 'lastName'),
            passwordHash: '!',
            initialRole: $initialRole,
            id: $id ?? new UuidV7(),
        );

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        return $user;
    }

    public function createAndPersist(
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        UserRole $initialRole,
        ?Uuid $id = null,
    ): User {
        $user = $this->create($email, $plainPassword, $firstName, $lastName, $initialRole, $id);
        $this->users->save($user);

        return $user;
    }
}
