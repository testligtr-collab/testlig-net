<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InvalidUserTransitionException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Central authentication account.
 *
 * Global roles live here. Institution / classroom / teacher-student memberships
 * will be modelled as separate domain relations in later phases.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_normalized_email', columns: ['normalized_email'])]
#[ORM\Index(name: 'idx_users_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['normalizedEmail'], message: 'This email is already registered.')]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(name: 'normalized_email', length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $normalizedEmail;

    #[ORM\Column]
    #[Ignore]
    private string $password;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private string $lastName;

    /**
     * Stored Symfony role strings (without relying on RoleHierarchy expansion).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $globalRoles = [];

    #[ORM\Column(length: 32, enumType: UserStatus::class)]
    private UserStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $passwordChangedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 16)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 16)]
    #[Assert\Regex(pattern: '/^[a-z]{2}_[A-Z]{2}$/', message: 'Locale must look like tr_TR.')]
    private string $locale = 'tr_TR';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Assert\Timezone]
    private string $timezone = 'Europe/Istanbul';

    private function __construct(
        string $email,
        string $normalizedEmail,
        string $firstName,
        string $lastName,
        string $passwordHash,
        UserRole $initialRole,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->email = $email;
        $this->normalizedEmail = $normalizedEmail;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->password = $passwordHash;
        $this->status = UserStatus::PendingVerification;
        $now = new \DateTimeImmutable('now');
        $this->passwordChangedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->setGlobalRoles([$initialRole]);
    }

    /**
     * @internal prefer App\Service\UserFactory for application use
     */
    public static function create(
        string $email,
        string $normalizedEmail,
        string $firstName,
        string $lastName,
        string $passwordHash,
        UserRole $initialRole,
        ?Uuid $id = null,
    ): self {
        return new self($email, $normalizedEmail, $firstName, $lastName, $passwordHash, $initialRole, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    /**
     * @see UserInterface
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->normalizedEmail) {
            throw new \LogicException('User identifier (normalized email) must not be empty.');
        }

        return $this->normalizedEmail;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->globalRoles;
        $roles[] = UserRole::User->value;
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    /**
     * @return list<UserRole>
     */
    public function getGlobalRoleEnums(): array
    {
        $roles = [];
        foreach ($this->globalRoles as $role) {
            $roles[] = UserRole::from($role);
        }

        return $roles;
    }

    /**
     * @param list<mixed> $roles
     */
    public function setGlobalRoles(array $roles): void
    {
        $values = [];
        foreach ($roles as $role) {
            if (!$role instanceof UserRole) {
                throw InvalidUserTransitionException::forRole('Only UserRole enum values are allowed.');
            }
            if (UserRole::User === $role) {
                continue;
            }
            $values[] = $role->value;
        }

        $values = array_values(array_unique($values));
        sort($values);
        $this->globalRoles = $values;
        $this->touch();
    }

    public function addGlobalRole(UserRole $role): void
    {
        if (UserRole::User === $role) {
            return;
        }

        $roles = $this->getGlobalRoleEnums();
        $roles[] = $role;
        $this->setGlobalRoles($roles);
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function transitionTo(UserStatus $status): void
    {
        if (!$this->status->canTransitionTo($status)) {
            throw InvalidUserTransitionException::forStatus($this->status, $status);
        }

        $this->status = $status;
        $this->touch();
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailVerified(?\DateTimeImmutable $at = null): void
    {
        $this->emailVerifiedAt = $at ?? new \DateTimeImmutable('now');
        $this->touch();
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(?\DateTimeImmutable $at = null): void
    {
        $this->lastLoginAt = $at ?? new \DateTimeImmutable('now');
        $this->touch();
    }

    public function getPasswordChangedAt(): \DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->touch();
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
        $this->touch();
    }

    #[Ignore]
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
        $this->passwordChangedAt = new \DateTimeImmutable('now');
        $this->touch();
    }

    public function eraseCredentials(): void
    {
        // No transient plain-text credentials are stored on the entity.
    }

    public function __toString(): string
    {
        return $this->normalizedEmail;
    }

    /**
     * @return array{id: string, email: string}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'email' => $this->normalizedEmail,
        ];
    }

    /**
     * @param array{id: string, email: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = Uuid::fromString($data['id']);
        $this->normalizedEmail = $data['email'];
        $this->email = $data['email'];
        $this->password = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->globalRoles = [];
        $this->status = UserStatus::PendingVerification;
        $now = new \DateTimeImmutable('@0');
        $this->passwordChangedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
}
