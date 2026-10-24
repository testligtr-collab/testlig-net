<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\AdminInstitutionCreateRequest;
use App\Dto\AdminInstitutionStatusRequest;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\InstitutionRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionCreator;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Uid\Uuid;

final class AdminInstitutionPanelHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testListSummaryStaysStableAndMatchesDetailMembershipCount(): void
    {
        $client = static::createClient();
        $sa = $this->user('admin_inst_panel_list_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('admin_inst_panel_list_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($sa, $owner, 'Admin Inst Panel Liste');
        $this->authenticate($client, $sa->getEmail());

        $client->request('GET', '/yonetim/kurumlar');
        self::assertResponseIsSuccessful();
        $before = $this->summary($client);
        self::assertSelectorExists('[data-institution-summary="total"]');
        self::assertStringContainsString('Kurum adı veya slug', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Görsel tasarım örneği', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('kısa kod', (string) $client->getResponse()->getContent());

        $client->request('GET', '/yonetim/kurumlar', ['q' => 'admin-inst-panel-liste']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Admin Inst Panel Liste');
        $rowMembers = $this->rowMemberCount($client, 'Admin Inst Panel Liste');
        $client->request('GET', '/yonetim/kurumlar/'.$institution->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame($rowMembers, $this->text($client, '[data-institution-members]'));
        self::assertSame('1', $this->text($client, '[data-institution-owners]'));
        self::assertSelectorTextContains('body', 'Beklemede');
        self::assertSelectorTextContains('body', 'Türkçe');
        self::assertSelectorTextContains('body', 'Europe/Istanbul');

        $client->request('GET', '/yonetim/kurumlar', ['status' => 'active', 'q' => 'admin-inst-panel-yok']);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->summary($client));
        self::assertSelectorTextContains('body', 'Filtrelere uygun kurum bulunamadı.');
        self::assertStringNotContainsString('Admin Inst Panel Liste', (string) $client->getResponse()->getContent());
    }

    public function testOwnerSearchAndCreateGuardsLeaveNoPartialRow(): void
    {
        $client = static::createClient();
        $sa = $this->user('admin_inst_panel_guard_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('admin_inst_panel_guard_owner@example.com', UserRole::Teacher, 'Paneladay');
        $ineligible = $this->user('admin_inst_panel_guard_plain@example.com', UserRole::Teacher, 'Paneladay', false);
        $admin = $this->user('admin_inst_panel_guard_admin@example.com', UserRole::Admin);
        $this->authenticate($client, $sa->getEmail());

        $client->request('GET', '/yonetim/kurumlar/sahip-adaylari', ['q' => 'a']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $empty = $this->json($client);
        self::assertSame(['results' => []], $empty);

        $client->request('GET', '/yonetim/kurumlar/sahip-adaylari', ['q' => 'Paneladay']);
        $payload = $this->json($client);
        $results = $payload['results'] ?? null;
        self::assertIsArray($results);
        self::assertLessThanOrEqual(8, \count($results));
        $emails = [];
        foreach ($results as $row) {
            self::assertIsArray($row);
            self::assertSame(['id', 'name', 'email'], array_keys($row));
            self::assertIsString($row['email']);
            $emails[] = $row['email'];
        }
        self::assertContains($owner->getEmail(), $emails);
        self::assertNotContains($ineligible->getEmail(), $emails);

        $client->request('POST', '/yonetim/kurumlar', [
            'admin_institution_create' => [
                'name' => 'Admin Inst Panel Csrf',
                'type' => 'school',
                'ownerUserId' => $owner->getId()->toRfc4122(),
                'reasonCode' => AdminInstitutionCreateRequest::REASON_ONBOARDING,
                'confirm' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countNamed('Admin Inst Panel Csrf'));

        $crawler = $client->request('GET', '/yonetim/kurumlar/yeni');
        $form = $crawler->selectButton('Kurumu oluştur')->form();
        $form['admin_institution_create[name]'] = 'Admin Inst Panel Onaysiz';
        $form['admin_institution_create[type]'] = $this->optionValue($crawler, 'admin_institution_create_type', 'Okul');
        $form['admin_institution_create[ownerUserId]'] = $owner->getId()->toRfc4122();
        $form['admin_institution_create[reasonCode]'] = AdminInstitutionCreateRequest::REASON_ONBOARDING;
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('admin_institution_create[name]', 'Admin Inst Panel Onaysiz');
        self::assertInputValueSame('admin_institution_create[ownerUserId]', $owner->getId()->toRfc4122());
        self::assertSelectorTextContains('body', 'Kurum oluşturmayı onaylamanız gerekir.');
        self::assertSame(0, $this->countNamed('Admin Inst Panel Onaysiz'));

        $this->submitCreate($client, 'Admin Inst Panel Uygunsuz', $ineligible->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Seçilen hesap aktif ve doğrulanmış bir kullanıcı değil.');
        self::assertSame(0, $this->countNamed('Admin Inst Panel Uygunsuz'));

        $client->restart();
        $this->authenticate($client, $admin->getEmail());
        $client->request('GET', '/yonetim/kurumlar/sahip-adaylari', ['q' => 'Paneladay']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/yonetim/kurumlar/yeni');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateUsesCreatorThenRateLimitStopsAnotherRow(): void
    {
        $client = static::createClient();
        $sa = $this->user('admin_inst_panel_create_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('admin_inst_panel_create_owner@example.com', UserRole::Teacher);
        $this->authenticate($client, $sa->getEmail());

        $this->submitCreate($client, 'Admin Inst Panel Ilk', $owner->getId()->toRfc4122());
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/yonetim/kurumlar/[0-9a-fA-F-]{36}$#', $location);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Kurum oluşturuldu.');
        self::assertSelectorTextContains('body', 'Beklemede');
        $institutionId = $this->institutionIdByName('Admin Inst Panel Ilk');
        self::assertSame(1, $this->activeMemberships($institutionId));
        self::assertSame('pending', $this->institutionStatus($institutionId));
        self::assertSame(1, $this->auditCount('institution_created', $institutionId));
        self::assertSame(1, $this->auditCount('institution_member_added', $institutionId));

        $this->submitCreate($client, 'Admin Inst Panel Ilk', $owner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bu ad veya slug zaten kullanılıyor.');
        self::assertSame(1, $this->countNamed('Admin Inst Panel Ilk'));
        self::assertSame(1, $this->activeMemberships($institutionId));

        for ($i = 2; $i <= 4; ++$i) {
            $this->submitCreate($client, 'Admin Inst Panel Hiz '.$i, $owner->getId()->toRfc4122());
            self::assertResponseRedirects();
        }
        $this->submitCreate($client, 'Admin Inst Panel Hiz Altinci', $owner->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->countNamed('Admin Inst Panel Hiz Altinci'));
    }

    public function testStatusTransitionRejectsStaleActionAndAdminWrite(): void
    {
        $client = static::createClient();
        $sa = $this->user('admin_inst_panel_status_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('admin_inst_panel_status_owner@example.com', UserRole::Teacher);
        $admin = $this->user('admin_inst_panel_status_admin@example.com', UserRole::Admin);
        $institution = $this->institution($sa, $owner, 'Admin Inst Panel Durum');
        $id = $institution->getId()->toRfc4122();
        $this->authenticate($client, $sa->getEmail());

        $crawler = $client->request('GET', '/yonetim/kurumlar/'.$id);
        self::assertResponseIsSuccessful();
        $actions = $this->optionValues($crawler, 'admin_institution_status_action');
        self::assertContains(AdminInstitutionStatusRequest::ACTION_ACTIVATE, $actions);
        self::assertNotContains(AdminInstitutionStatusRequest::ACTION_SUSPEND, $actions);
        $token = $this->statusToken($crawler);

        $client->request('POST', '/yonetim/kurumlar/'.$id.'/durum', [
            'admin_institution_status' => [
                'action' => AdminInstitutionStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminInstitutionStatusRequest::REASON_POLICY,
                'confirm' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects('/yonetim/kurumlar/'.$id);
        self::assertSame(InstitutionStatus::Pending->value, $this->institutionStatus($id));
        self::assertSame(0, $this->auditCount('institution_status_changed', $id));

        $client->request('POST', '/yonetim/kurumlar/'.$id.'/durum', [
            'admin_institution_status' => [
                'action' => AdminInstitutionStatusRequest::ACTION_ACTIVATE,
                'reasonCode' => AdminInstitutionStatusRequest::REASON_LIFECYCLE,
                'confirm' => '1',
                '_token' => $this->statusToken($client->request('GET', '/yonetim/kurumlar/'.$id)),
            ],
        ]);
        self::assertResponseRedirects('/yonetim/kurumlar/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Kurum durumu güncellendi.');
        self::assertSame(InstitutionStatus::Active->value, $this->institutionStatus($id));

        $client->request('POST', '/yonetim/kurumlar/'.$id.'/durum', [
            'admin_institution_status' => [
                'action' => AdminInstitutionStatusRequest::ACTION_ACTIVATE,
                'reasonCode' => AdminInstitutionStatusRequest::REASON_LIFECYCLE,
                'confirm' => '1',
                '_token' => $this->statusToken($client->request('GET', '/yonetim/kurumlar/'.$id)),
            ],
        ]);
        self::assertResponseRedirects('/yonetim/kurumlar/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Durum formu geçersiz');
        self::assertSame(InstitutionStatus::Active->value, $this->institutionStatus($id));
        self::assertSame(1, $this->auditCount('institution_status_changed', $id));

        $client->restart();
        $this->authenticate($client, $admin->getEmail());
        $client->request('GET', '/yonetim/kurumlar/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="admin_institution_status"]');
        self::assertStringNotContainsString('Durumu kaydet', (string) $client->getResponse()->getContent());
        $client->request('POST', '/yonetim/kurumlar/'.$id.'/durum', [
            'admin_institution_status' => [
                'action' => AdminInstitutionStatusRequest::ACTION_SUSPEND,
                'reasonCode' => AdminInstitutionStatusRequest::REASON_POLICY,
                'confirm' => '1',
                '_token' => 'not-a-token',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(InstitutionStatus::Active->value, $this->institutionStatus($id));
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $connection = $em->getConnection();
            if ($connection->createSchemaManager()->tablesExist(['institutions'])) {
                $connection->executeStatement(
                    "DELETE im FROM institution_memberships im INNER JOIN institutions i ON i.id = im.institution_id WHERE i.normalized_name LIKE 'admin inst panel%'",
                );
                $connection->executeStatement(
                    "DELETE FROM institutions WHERE normalized_name LIKE 'admin inst panel%'",
                );
            }
            if ($connection->createSchemaManager()->tablesExist(['users'])) {
                $connection->executeStatement(
                    "DELETE FROM users WHERE normalized_email LIKE 'admin_inst_panel_%@example.com'",
                );
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function submitCreate(KernelBrowser $client, string $name, string $ownerId): void
    {
        $crawler = $client->request('GET', '/yonetim/kurumlar/yeni');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Kurumu oluştur')->form();
        $form['admin_institution_create[name]'] = $name;
        $form['admin_institution_create[type]'] = $this->optionValue($crawler, 'admin_institution_create_type', 'Okul');
        $form['admin_institution_create[ownerUserId]'] = $ownerId;
        $form['admin_institution_create[reasonCode]'] = AdminInstitutionCreateRequest::REASON_ONBOARDING;
        $confirm = $form['admin_institution_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
    }

    private function optionValue(Crawler $crawler, string $id, string $label): string
    {
        foreach ($crawler->filter('#'.$id.' option') as $option) {
            if ($option instanceof \DOMElement && str_contains($option->textContent, $label)) {
                return $option->getAttribute('value');
            }
        }
        self::fail('Missing option '.$label);
    }

    /**
     * @return list<string>
     */
    private function optionValues(Crawler $crawler, string $id): array
    {
        $values = [];
        foreach ($crawler->filter('#'.$id.' option') as $option) {
            if (!$option instanceof \DOMElement) {
                continue;
            }
            $value = $option->getAttribute('value');
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function statusToken(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="admin_institution_status[_token]"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    /**
     * @return array{total: string, active: string, pending: string, memberships: string}
     */
    private function summary(KernelBrowser $client): array
    {
        return [
            'total' => $this->text($client, '[data-institution-summary="total"]'),
            'active' => $this->text($client, '[data-institution-summary="active"]'),
            'pending' => $this->text($client, '[data-institution-summary="pending"]'),
            'memberships' => $this->text($client, '[data-institution-summary="memberships"]'),
        ];
    }

    private function text(KernelBrowser $client, string $selector): string
    {
        $node = $client->getCrawler()->filter($selector);
        self::assertGreaterThan(0, $node->count(), $selector);

        return trim($node->text());
    }

    private function rowMemberCount(KernelBrowser $client, string $name): string
    {
        $row = $client->getCrawler()->filter('tr')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), $name),
        );
        self::assertGreaterThan(0, $row->count());

        return trim($row->filter('td[data-label="Aktif üye"]')->text());
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        /* @var array<string, mixed> $decoded */

        return $decoded;
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/giris');
        $client->submit($crawler->selectButton('Giriş yap')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();
    }

    private function user(string $email, UserRole $role, string $lastName = 'Sahip', bool $eligible = true): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', $lastName, $initial);
        if ($eligible) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
            $user->transitionTo(UserStatus::Active);
        }
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function institution(User $actor, User $owner, string $name): Institution
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $freshActor = $users->findOneById($actor->getId());
        $freshOwner = $users->findOneById($owner->getId());
        self::assertInstanceOf(User::class, $freshActor);
        self::assertInstanceOf(User::class, $freshOwner);
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);

        return $creator->create($freshActor, $freshOwner, $name, InstitutionType::School, 'platform_setup');
    }

    private function countNamed(string $name): int
    {
        return (int) $this->entityManager()->createQuery('SELECT COUNT(i.id) FROM App\Entity\Institution i WHERE i.name = :name')
            ->setParameter('name', $name)
            ->getSingleScalarResult();
    }

    private function institutionIdByName(string $name): string
    {
        return $this->findNamed($name)->getId()->toRfc4122();
    }

    private function institutionStatus(string $id): string
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $institutions = static::getContainer()->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        $institution = $institutions->findOneById(Uuid::fromString($id));
        self::assertInstanceOf(Institution::class, $institution);

        return $institution->getStatus()->value;
    }

    private function activeMemberships(string $institutionId): int
    {
        return (int) $this->entityManager()->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\InstitutionMembership m WHERE IDENTITY(m.institution) = :institution AND m.status = :status',
        )
            ->setParameter('institution', Uuid::fromString($institutionId), 'uuid')
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->getSingleScalarResult();
    }

    private function auditCount(string $action, string $institutionId): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
            [$action, '%'.$institutionId.'%'],
        );
    }

    private function findNamed(string $name): Institution
    {
        $found = $this->entityManager()->getRepository(Institution::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Institution::class, $found);

        return $found;
    }

    private function entityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
