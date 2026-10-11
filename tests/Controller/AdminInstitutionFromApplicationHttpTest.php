<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\AdminInstitutionCreateRequest;
use App\Entity\Institution;
use App\Entity\InstitutionApplication;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\InstitutionApplicationManager;
use App\Service\InstitutionCreator;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use App\Service\UserStatusManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AdminInstitutionFromApplicationHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private string $pendingA = '';

    private string $pendingB = '';

    protected function setUp(): void
    {
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testCreatedInstitutionColumnIsUniqueAndRestrictsDelete(): void
    {
        $this->withKernel(function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $meta = $em->getClassMetadata(InstitutionApplication::class);
            $association = $meta->getAssociationMapping('createdInstitution');
            self::assertInstanceOf(ToOneOwningSideMapping::class, $association);
            $join = $association->joinColumns[0];
            self::assertSame('created_institution_id', $join->name);
            self::assertSame('id', $join->referencedColumnName);
            self::assertTrue($join->nullable);
            self::assertSame('RESTRICT', $join->onDelete);
            $unique = $meta->table['uniqueConstraints']['uniq_inst_app_created_institution'] ?? null;
            self::assertIsArray($unique);
            self::assertSame(['created_institution_id'], $unique['columns']);
            self::assertSame('uuid', $em->getClassMetadata(Institution::class)->getFieldMapping('id')->type);

            $columns = $em->getConnection()->fetchAllAssociative(
                "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND ((TABLE_NAME = 'institutions' AND COLUMN_NAME = 'id')
                     OR (TABLE_NAME = 'institution_applications' AND COLUMN_NAME = 'created_institution_id'))",
            );
            $types = [];
            foreach ($columns as $column) {
                $types[$column['TABLE_NAME'].'.'.$column['COLUMN_NAME']] = [
                    'type' => $column['COLUMN_TYPE'],
                    'nullable' => $column['IS_NULLABLE'],
                ];
            }
            self::assertSame($types['institutions.id']['type'], $types['institution_applications.created_institution_id']['type']);
            self::assertSame('YES', $types['institution_applications.created_institution_id']['nullable']);

            $foreignKey = $em->getConnection()->fetchAssociative(
                'SELECT rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
                 FROM information_schema.KEY_COLUMN_USAGE kcu
                 INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                   AND rc.TABLE_NAME = kcu.TABLE_NAME
                   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                   AND kcu.TABLE_NAME = \'institution_applications\'
                   AND kcu.COLUMN_NAME = \'created_institution_id\'
                   AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            );
            self::assertIsArray($foreignKey);
            self::assertSame('RESTRICT', $foreignKey['DELETE_RULE']);
            self::assertSame('institutions', $foreignKey['REFERENCED_TABLE_NAME']);
            self::assertSame('id', $foreignKey['REFERENCED_COLUMN_NAME']);
            $uniqueIndex = $em->getConnection()->fetchAssociative(
                'SELECT INDEX_NAME, NON_UNIQUE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = \'institution_applications\'
                   AND COLUMN_NAME = \'created_institution_id\'
                   AND NON_UNIQUE = 0',
            );
            self::assertIsArray($uniqueIndex);
            self::assertSame('uniq_inst_app_created_institution', $uniqueIndex['INDEX_NAME']);
            self::assertSame(0, (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM institution_applications WHERE id IN (?, ?) AND created_institution_id IS NOT NULL',
                [Uuid::fromString($this->pendingA)->toBinary(), Uuid::fromString($this->pendingB)->toBinary()],
            ));

            $connection = $em->getConnection();
            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'UPDATE institution_applications SET created_institution_id = ? WHERE id = ?',
                    [Uuid::v7()->toBinary(), Uuid::fromString($this->pendingA)->toBinary()],
                );
                self::fail('A missing institution id must be rejected by the foreign key.');
            } catch (ForeignKeyConstraintViolationException) {
                $connection->rollBack();
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }

            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $institution = $creator->create(
                $this->user('instc-sa@example.com'),
                $this->user('instc-a@example.com'),
                'Instc Bag Bütünlük Okulu',
                InstitutionType::Other,
                AdminInstitutionCreateRequest::REASON_OPERATOR_SETUP,
            );
            $institutionId = $institution->getId()->toBinary();
            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'UPDATE institution_applications SET created_institution_id = ? WHERE id = ?',
                    [$institutionId, Uuid::fromString($this->pendingA)->toBinary()],
                );
                $connection->executeStatement(
                    'UPDATE institution_applications SET created_institution_id = ? WHERE id = ?',
                    [$institutionId, Uuid::fromString($this->pendingB)->toBinary()],
                );
                self::fail('One institution cannot be linked from two applications.');
            } catch (UniqueConstraintViolationException) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        });

        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertNull($this->linkedInstitutionId($this->pendingB));
    }

    public function testApprovalDoesNotCreateAndExplicitCreateUsesTheApplication(): void
    {
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $before = $this->snapshot();
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->submit($client->getCrawler()->selectButton('Onayla')->form());
        $client->followRedirect();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertSame($before, $this->snapshot());
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instc-a@example.com'));
        $detail = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Başvuru onayı kurum, üyelik veya rol vermez.', $detail);
        self::assertStringContainsString('>Kurum oluştur<', $detail);

        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        self::assertSame($before, $this->snapshot());
        $token = $this->csrf($client, 'institution_application_create_institution');
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
            'name' => 'Baska Ad',
            'type' => 'other',
        ]);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->followRedirect();

        $created = $this->createdRow($this->pendingA);
        self::assertSame('Ada <b>Okulu', $created['name']);
        self::assertSame(InstitutionType::School->value, $created['type']);
        self::assertSame('pending', $created['status']);
        self::assertSame([['role' => 'owner', 'status' => 'active']], $this->memberships($created['id']));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertNull($this->linkedInstitutionId($this->pendingB));
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instc-a@example.com'));
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instc-b@example.com'));
        $reason = 'institution_application_'.str_replace('-', '', $this->pendingA);
        self::assertSame(1, $this->auditReasonCount('institution_created', $reason));
        self::assertSame(1, $this->auditReasonCount('institution_member_added', $reason));
        self::assertSame($before['institutions'] + 1, $this->snapshot()['institutions']);
        self::assertSame($before['memberships'] + 1, $this->snapshot()['memberships']);
        self::assertSame($before['institution_status_changed'], $this->snapshot()['institution_status_changed']);
        self::assertSame($before['role_changed'], $this->snapshot()['role_changed']);
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Bu başvurudan kurum oluşturuldu.', $page);
        self::assertStringContainsString('pending', $page);
        self::assertStringNotContainsString('>Kurum oluştur<', $page);

        $linked = $this->linkedInstitutionId($this->pendingA);
        $afterCreate = $this->snapshot();
        $this->withKernel(static function () use ($linked): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                'UPDATE institutions SET name = ?, normalized_name = ?, slug = ? WHERE id = ?',
                ['Yeniden Ad', 'yeniden ad', 'yeniden-ad-instc', Uuid::fromString((string) $linked)->toBinary()],
            );
        });
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        self::assertStringNotContainsString('>Kurum oluştur<', (string) $client->getResponse()->getContent());
        $this->postCreate($client, $this->pendingA);
        $client->followRedirect();
        self::assertSame($linked, $this->linkedInstitutionId($this->pendingA));
        self::assertSame($afterCreate, $this->snapshot());
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertNull($this->linkedInstitutionId($this->pendingB));
    }

    public function testDuplicateNameDoesNotAttachAnUnrelatedInstitution(): void
    {
        $this->approve($this->pendingA);
        $this->withKernel(function (): void {
            $creator = static::getContainer()->get(InstitutionCreator::class);
            self::assertInstanceOf(InstitutionCreator::class, $creator);
            $creator->create(
                $this->user('instc-sa@example.com'),
                $this->user('instc-b@example.com'),
                'Ada <b>Okulu',
                InstitutionType::School,
                AdminInstitutionCreateRequest::REASON_ONBOARDING,
            );
        });
        $before = $this->snapshot();
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $this->postCreate($client, $this->pendingA);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->followRedirect();

        self::assertStringContainsString('Kurum oluşturulamadı. Yazım yapılmadı.', (string) $client->getResponse()->getContent());
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->auditReasonCount('institution_created', 'institution_application_'.str_replace('-', '', $this->pendingA)));
    }

    public function testPendingRejectedWithdrawnAndSupersededApplicationsAreRefused(): void
    {
        $withdrawn = $this->openApplication('instc-withdrawn@example.com', 'Deniz', 'Kara');
        $superseded = $this->openApplication('instc-superseded@example.com', 'Ege', 'Kara');
        $this->withKernel(function () use ($withdrawn, $superseded): void {
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $applications->markRejected($this->user('instc-sa@example.com'), Uuid::fromString($this->pendingB), 'incomplete_documents');
            $applications->withdraw($this->user('instc-withdrawn@example.com'), Uuid::fromString($withdrawn));
            $row = $em->find(InstitutionApplication::class, Uuid::fromString($superseded));
            self::assertInstanceOf(InstitutionApplication::class, $row);
            $row->markSuperseded(new \DateTimeImmutable('now'));
            $em->flush();
        });
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $before = $this->snapshot();

        foreach ([
            $this->pendingA => OnboardingApplicationStatus::Pending->value,
            $this->pendingB => OnboardingApplicationStatus::Rejected->value,
            $withdrawn => OnboardingApplicationStatus::Withdrawn->value,
            $superseded => OnboardingApplicationStatus::Superseded->value,
        ] as $id => $status) {
            $this->postCreate($client, $id);
            self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$id);
            self::assertSame($status, $this->applicationStatus($id));
            self::assertNull($this->linkedInstitutionId($id));
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testUnauthorizedActorsAndSuspendedSuperAdminCannotCreate(): void
    {
        $this->approve($this->pendingA);
        $this->assertCreateDenied('instc-admin@example.com');
        $this->assertCreateDenied('instc-teacher@example.com');
        $this->assertCreateDenied('instc-a@example.com');

        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $client->request('GET', '/hesabim');
        $token = $this->csrf($client, 'institution_application_create_institution');
        $this->setStatus('instc-sa@example.com', UserStatus::Suspended);
        $before = $this->snapshot();
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/giris');
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertSame($before, $this->snapshot());
    }

    public function testSuspendedAndUnverifiedApplicantsAreRefused(): void
    {
        $unverified = $this->openApplication('instc-unverified@example.com', 'Pelin', 'Ak');
        $this->approve($this->pendingA);
        $this->approve($unverified);
        $this->withKernel(function (): void {
            $status = static::getContainer()->get(UserStatusManager::class);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(UserStatusManager::class, $status);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $status->suspend($this->user('instc-a@example.com'), $this->user('instc-sa@example.com'), 'review_hold');
            $em->getConnection()->executeStatement(
                'UPDATE users SET email_verified_at = NULL WHERE email = ?',
                ['instc-unverified@example.com'],
            );
        });
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $before = $this->snapshot();
        $this->postCreate($client, $this->pendingA);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingA);
        $this->postCreate($client, $unverified);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$unverified);

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($unverified));
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertNull($this->linkedInstitutionId($unverified));
        self::assertSame($before, $this->snapshot());
    }

    public function testBrokenReferenceMissingConfirmAndInvalidCsrfWriteNothing(): void
    {
        $this->approve($this->pendingA);
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        $token = $this->csrf($client, 'institution_application_create_institution');
        $before = $this->snapshot();
        $missing = Uuid::v7()->toRfc4122();

        $client->request('POST', '/yonetim/kurum-basvurulari/not-a-uuid/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$missing.'/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
            'name' => 'Baska Ad',
        ]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/kurum-olustur', [
            '_token' => 'invalid',
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/kurum-olustur', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/yonetim/kurum-basvurulari/'.$this->pendingA);
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$this->pendingA);
        self::assertResponseIsSuccessful();

        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Pending->value, $this->applicationStatus($this->pendingB));
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertNull($this->linkedInstitutionId($this->pendingB));
        self::assertSame($before, $this->snapshot());
    }

    public function testExistingAdminInstitutionFormStaysIndependent(): void
    {
        $this->approve($this->pendingA);
        $ownerId = $this->withKernel(fn (): string => $this->user('instc-a@example.com')->getId()->toRfc4122());
        $client = $this->browser();
        $this->login($client, 'instc-sa@example.com');
        $before = $this->snapshot();
        $crawler = $client->request('GET', '/yonetim/kurumlar/yeni');
        $form = $crawler->selectButton('Kurumu oluştur')->form();
        $form['admin_institution_create[name]'] = 'Instc Form Okulu';
        // Choice values are indexes; School is the first InstitutionType case.
        $form['admin_institution_create[type]'] = '0';
        $form['admin_institution_create[ownerUserId]'] = $ownerId;
        $form['admin_institution_create[reasonCode]'] = 'institution_onboarding';
        $confirm = $form['admin_institution_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertStringContainsString('Kurum oluşturuldu.', (string) $client->getResponse()->getContent());
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertSame(OnboardingApplicationStatus::Approved->value, $this->applicationStatus($this->pendingA));
        self::assertSame($before['institutions'] + 1, $this->snapshot()['institutions']);
        self::assertSame(1, $this->auditReasonCount('institution_created', 'institution_onboarding'));
        self::assertSame(0, $this->auditReasonCount('institution_created', 'institution_application_'.str_replace('-', '', $this->pendingA)));
        $row = $this->withKernel(static function (): array {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $institution = $em->getConnection()->fetchAssociative(
                'SELECT id, status, type FROM institutions WHERE name = ?',
                ['Instc Form Okulu'],
            );
            self::assertIsArray($institution);

            return $institution;
        });
        self::assertSame('pending', $row['status']);
        self::assertSame('school', $row['type']);
        self::assertSame([['role' => 'owner', 'status' => 'active']], $this->memberships((string) $row['id']));
        self::assertNotContains(UserRole::InstitutionManager->value, $this->roles('instc-a@example.com'));
    }

    private function assertCreateDenied(string $email): void
    {
        $client = $this->browser();
        $this->login($client, $email);
        $client->request('GET', '/hesabim');
        self::assertResponseIsSuccessful();
        $token = $this->csrf($client, 'institution_application_create_institution');
        $before = $this->snapshot();
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$this->pendingA.'/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->linkedInstitutionId($this->pendingA));
        self::assertSame($before, $this->snapshot());
    }

    private function approve(string $id): void
    {
        $this->withKernel(function () use ($id): void {
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
            $applications->markApproved($this->user('instc-sa@example.com'), Uuid::fromString($id), null);
        });
    }

    private function openApplication(string $email, string $first, string $last): string
    {
        return $this->withKernel(function () use ($email, $first, $last): string {
            $this->createActive($email, UserRole::Student, $first, $last);
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);

            return $applications->submit($this->user($email), $first.' Okulu', InstitutionType::TutoringCenter)->getId()->toRfc4122();
        });
    }

    private function postCreate(KernelBrowser $client, string $id): void
    {
        $client->request('GET', '/yonetim/kurum-basvurulari/'.$id);
        $token = $this->csrf($client, 'institution_application_create_institution');
        $client->request('POST', '/yonetim/kurum-basvurulari/'.$id.'/kurum-olustur', [
            '_token' => $token,
            'confirm' => '1',
        ]);
    }

    private function seed(): void
    {
        $this->withKernel(function (): void {
            $this->createActive('instc-sa@example.com', UserRole::SuperAdmin, 'Selin', 'Aydın');
            $this->createActive('instc-admin@example.com', UserRole::Admin, 'Kerem', 'Demir');
            $this->createActive('instc-teacher@example.com', UserRole::Teacher, 'Ece', 'Koç');
            $this->createActive('instc-a@example.com', UserRole::Student, 'Ada', 'Yılmaz');
            $this->createActive('instc-b@example.com', UserRole::Student, 'Bora', 'Yılmaz');
            $applications = static::getContainer()->get(InstitutionApplicationManager::class);
            self::assertInstanceOf(InstitutionApplicationManager::class, $applications);
            $this->pendingA = $applications->submit($this->user('instc-a@example.com'), 'Ada <b>Okulu', InstitutionType::School)->getId()->toRfc4122();
            $this->pendingB = $applications->submit($this->user('instc-b@example.com'), 'Bora Kurs', InstitutionType::CourseCenter)->getId()->toRfc4122();
        });
    }

    private function createActive(string $email, UserRole $role, string $first, string $last): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, $first, $last, $initial);
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $lifecycle->markEmailVerifiedAndActivate($user);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->flush();
        }
    }

    private function purge(): void
    {
        $this->withKernel(static function (): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            $ids = $connection->fetchFirstColumn("SELECT id FROM users WHERE email LIKE 'instc-%'");
            if ([] === $ids) {
                return;
            }
            $institutionIds = $connection->fetchFirstColumn(
                'SELECT institution_id FROM institution_memberships WHERE user_id IN (?)
                 UNION
                 SELECT created_institution_id FROM institution_applications WHERE user_id IN (?) AND created_institution_id IS NOT NULL',
                [$ids, $ids],
                [ArrayParameterType::BINARY, ArrayParameterType::BINARY],
            );
            $connection->executeStatement('DELETE FROM institution_applications WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            $connection->executeStatement('DELETE FROM institution_memberships WHERE user_id IN (?)', [$ids], [ArrayParameterType::BINARY]);
            if ([] !== $institutionIds) {
                $connection->executeStatement('DELETE FROM institutions WHERE id IN (?)', [$institutionIds], [ArrayParameterType::BINARY]);
            }
            $connection->executeStatement('DELETE FROM security_audit_events WHERE actor_user_id IN (?) OR subject_user_id IN (?)', [$ids, $ids], [ArrayParameterType::BINARY, ArrayParameterType::BINARY]);
            $connection->executeStatement('DELETE FROM users WHERE email LIKE ?', ['instc-%']);
        });
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        return [
            'role_changed' => $this->auditCount('role_changed'),
            'institution_created' => $this->auditCount('institution_created'),
            'institution_member_added' => $this->auditCount('institution_member_added'),
            'institution_member_role_changed' => $this->auditCount('institution_member_role_changed'),
            'institution_status_changed' => $this->auditCount('institution_status_changed'),
            'institutions' => (int) $this->scalar('SELECT COUNT(*) FROM institutions'),
            'memberships' => (int) $this->scalar('SELECT COUNT(*) FROM institution_memberships'),
        ];
    }

    private function applicationStatus(string $id): string
    {
        return (string) $this->scalar('SELECT status FROM institution_applications WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
    }

    private function linkedInstitutionId(string $id): ?string
    {
        $value = $this->withKernel(static function () use ($id): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchOne(
                'SELECT created_institution_id FROM institution_applications WHERE id = ?',
                [Uuid::fromString($id)->toBinary()],
            );
        });
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return Uuid::fromBinary($value)->toRfc4122();
    }

    /**
     * @return array{id: string, name: string, type: string, status: string}
     */
    private function createdRow(string $applicationId): array
    {
        $row = $this->withKernel(static function () use ($applicationId): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchAssociative(
                'SELECT i.id, i.name, i.type, i.status
                 FROM institutions i
                 INNER JOIN institution_applications a ON a.created_institution_id = i.id
                 WHERE a.id = ?',
                [Uuid::fromString($applicationId)->toBinary()],
            );
        });
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return [
            'id' => Uuid::fromBinary($row['id'])->toRfc4122(),
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * @return list<array{role: string, status: string}>
     */
    private function memberships(string $institutionId): array
    {
        $rows = $this->withKernel(static function () use ($institutionId): array {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchAllAssociative(
                'SELECT role, status FROM institution_memberships WHERE institution_id = ? ORDER BY role',
                [Uuid::fromString($institutionId)->toBinary()],
            );
        });
        $memberships = [];
        foreach ($rows as $row) {
            $memberships[] = ['role' => (string) $row['role'], 'status' => (string) $row['status']];
        }

        return $memberships;
    }

    private function auditCount(string $action): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM security_audit_events WHERE action = ?', [$action]);
    }

    private function auditReasonCount(string $action, string $reason): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata, \'$.reason_code\')) = ?',
            [$action, $reason],
        );
    }

    /**
     * @return list<string>
     */
    private function roles(string $email): array
    {
        $roles = $this->withKernel(fn (): array => $this->user($email)->getRoles());

        return array_values(array_filter($roles, static fn (mixed $role): bool => \is_string($role)));
    }

    private function setStatus(string $email, UserStatus $status): void
    {
        $this->withKernel(static function () use ($email, $status): void {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $em->getConnection()->executeStatement(
                'UPDATE users SET status = ? WHERE email = ?',
                [$status->value, $email],
            );
        });
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): int|string
    {
        $value = $this->withKernel(static function () use ($sql, $params): mixed {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);

            return $em->getConnection()->fetchOne($sql, $params);
        });

        return \is_int($value) || \is_string($value) ? $value : '';
    }

    private function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function withKernel(callable $callback): mixed
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            return $callback();
        } finally {
            self::ensureKernelShutdown();
        }
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
    }

    private function csrf(KernelBrowser $client, string $intention): string
    {
        $session = $client->getRequest()->getSession();
        $stack = $client->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($client->getRequest());
        try {
            $tokens = $client->getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);
            $value = $tokens->getToken($intention)->getValue();
            $session->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }
}
