<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Dto\AdminBranchCreateRequest;
use App\Entity\Institution;
use App\Entity\InstitutionBranch;
use App\Entity\User;
use App\Enum\InstitutionBranchKind;
use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\InstitutionBranchCreator;
use App\Service\InstitutionCreator;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Uid\Uuid;

final class AdminBranchPanelHttpTest extends WebTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    public function testClosedRolesCannotOpenTheBranchScreens(): void
    {
        $client = static::createClient();
        $client->request('GET', '/yonetim/subeler');
        self::assertResponseRedirects('/giris');

        $student = $this->user('branch_http_student@example.com', UserRole::Student);
        $teacher = $this->user('branch_http_teacher@example.com', UserRole::Teacher);
        $manager = $this->user('branch_http_manager@example.com', UserRole::InstitutionManager);
        $admin = $this->user('branch_http_admin@example.com', UserRole::Admin);
        foreach ([$student, $teacher, $manager, $admin] as $user) {
            $this->authenticate($client, $user->getEmail());
            foreach (['/yonetim/subeler', '/yonetim/subeler/yeni'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testInvalidCreateKeepsFieldsAndDoesNotWrite(): void
    {
        $client = static::createClient();
        $actor = $this->user('branch_http_form_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_http_form_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Http Form');
        $this->authenticate($client, $actor->getEmail());
        $before = $this->branchCount();
        $audits = $this->auditTotal();

        $client->request('POST', '/yonetim/subeler', ['admin_branch_create' => [
            'name' => 'Yarim Kayit',
            'code' => 'YAR-01',
        ]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->branchCount());

        $crawler = $client->request('GET', '/yonetim/subeler/yeni?kurum='.$institution->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Yeni şube / kampüs');
        $form = $crawler->selectButton('Kaydı oluştur')->form();
        $form['admin_branch_create[name]'] = 'Yarim Kayit';
        $form['admin_branch_create[code]'] = '';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('admin_branch_create[name]', 'Yarim Kayit');
        self::assertSelectorTextContains('body', 'Kurum içi kod gerekli.');
        self::assertSame($before, $this->branchCount());
        self::assertSame($audits, $this->auditTotal());
    }

    public function testCreateEditAndForeignInstitutionFieldStayInsideTheOriginalInstitution(): void
    {
        $client = static::createClient();
        $actor = $this->user('branch_http_write_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_http_write_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Http Home');
        $other = $this->institution($actor, $owner, 'Branch Http Foreign');
        $this->authenticate($client, $actor->getEmail());

        $crawler = $client->request('GET', '/yonetim/subeler/yeni?kurum='.$institution->getId()->toRfc4122());
        $form = $crawler->selectButton('Kaydı oluştur')->form();
        $form['admin_branch_create[institutionId]'] = $institution->getId()->toRfc4122();
        $form['admin_branch_create[name]'] = 'Http Sube';
        $form['admin_branch_create[code]'] = 'HTTP-01';
        $form['admin_branch_create[kind]'] = InstitutionBranchKind::Branch->value;
        $form['admin_branch_create[reasonCode]'] = AdminBranchCreateRequest::REASON_ONBOARDING;
        $confirm = $form['admin_branch_create[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        $branch = $this->findByCode($institution, 'HTTP-01');
        self::assertSame(InstitutionBranchStatus::Pending, $branch->getStatus());

        $token = $form['admin_branch_create[_token]'];
        self::assertInstanceOf(FormField::class, $token);
        $client->request('POST', '/yonetim/subeler', ['admin_branch_create' => [
            '_token' => $token->getValue(),
            'institutionId' => $institution->getId()->toRfc4122(),
            'name' => 'Kopya',
            'code' => 'http-01',
            'kind' => InstitutionBranchKind::Campus->value,
            'reasonCode' => AdminBranchCreateRequest::REASON_ONBOARDING,
            'confirm' => '1',
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bu kod bu kurumda zaten kullanılıyor.');
        self::assertSame(1, $this->countCode($institution, 'HTTP-01'));

        $foreign = $this->institution($actor, $owner, 'Branch Http Same Code');
        $this->creator()->create($actor, $foreign->getId(), 'Ayni Kod', 'HTTP-01', InstitutionBranchKind::Branch, 'operator_setup');
        self::assertSame(1, $this->countCode($foreign, 'HTTP-01'));

        $detail = $client->request('GET', '/yonetim/subeler/'.$branch->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Erişim kapalı. Bağlı kurum aktif değil.');
        $edit = $detail->selectButton('Bilgileri kaydet')->form();
        $edit['admin_branch_update[name]'] = 'Http Sube Yeni';
        $edit['admin_branch_update[code]'] = 'HTTP-02';
        $edit['admin_branch_update[kind]'] = InstitutionBranchKind::Campus->value;
        $edit['admin_branch_update[reasonCode]'] = 'record_correction';
        $editConfirm = $edit['admin_branch_update[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $editConfirm);
        $editConfirm->tick();
        $values = $edit->getPhpValues();
        $values['admin_branch_update']['institutionId'] = $other->getId()->toRfc4122();
        $client->request('POST', '/yonetim/subeler/'.$branch->getId()->toRfc4122(), $values);
        self::assertResponseRedirects();
        $fresh = $this->reload($branch->getId());
        self::assertSame('Http Sube Yeni', $fresh->getName());
        self::assertSame('HTTP-02', $fresh->getCode());
        self::assertSame($institution->getId()->toRfc4122(), $fresh->getInstitution()->getId()->toRfc4122());
    }

    public function testSummaryIgnoresTheFilterAndParentClosureBlocksActivation(): void
    {
        $client = static::createClient();
        $actor = $this->user('branch_http_list_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_http_list_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Http List');
        $branch = $this->creator()->create($actor, $institution->getId(), 'Liste Sube', 'LST-01', InstitutionBranchKind::Branch, 'institution_onboarding');
        $this->authenticate($client, $actor->getEmail());

        $client->request('GET', '/yonetim/subeler');
        self::assertResponseIsSuccessful();
        $totals = $this->summary($client);
        $client->request('GET', '/yonetim/subeler', ['q' => 'olmayan-kod']);
        self::assertResponseIsSuccessful();
        self::assertSame($totals, $this->summary($client));
        self::assertSelectorTextContains('body', 'Filtrelere uygun kayıt bulunamadı.');

        $page = $client->request('GET', '/yonetim/subeler/'.$branch->getId()->toRfc4122());
        $status = $page->selectButton('Durumu kaydet')->form();
        $status['admin_branch_status[reasonCode]'] = 'lifecycle_change';
        $statusConfirm = $status['admin_branch_status[confirm]'];
        self::assertInstanceOf(ChoiceFormField::class, $statusConfirm);
        $statusConfirm->tick();
        $values = $status->getPhpValues();
        $values['admin_branch_status']['action'] = 'activate';
        $client->request('POST', '/yonetim/subeler/'.$branch->getId()->toRfc4122().'/durum', $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Bağlı kurum aktif değilken kayıt aktifleştirilemez.');
        self::assertSame(InstitutionBranchStatus::Pending, $this->reload($branch->getId())->getStatus());
        self::assertSame(0, $this->auditCount('institution_branch_status_changed', $branch->getId()->toRfc4122()));
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

    private function user(string $email, UserRole $role): User
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Soyad', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
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
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);

        return $creator->create($actor, $owner, $name, InstitutionType::School, 'platform_setup');
    }

    private function creator(): InstitutionBranchCreator
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $creator = static::getContainer()->get(InstitutionBranchCreator::class);
        self::assertInstanceOf(InstitutionBranchCreator::class, $creator);

        return $creator;
    }

    /**
     * @return array<string, string>
     */
    private function summary(KernelBrowser $client): array
    {
        $values = [];
        $client->getCrawler()->filter('[data-branch-summary]')->each(static function ($node) use (&$values): void {
            $values[(string) $node->attr('data-branch-summary')] = trim($node->text());
        });

        return $values;
    }

    private function branchCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_branches');
    }

    private function countCode(Institution $institution, string $code): int
    {
        return (int) $this->em()->createQuery(
            'SELECT COUNT(b.id) FROM App\Entity\InstitutionBranch b WHERE IDENTITY(b.institution) = :institution AND b.normalizedCode = :code',
        )
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('code', $code)
            ->getSingleScalarResult();
    }

    private function findByCode(Institution $institution, string $code): InstitutionBranch
    {
        $branch = $this->em()->createQuery(
            'SELECT b FROM App\Entity\InstitutionBranch b WHERE IDENTITY(b.institution) = :institution AND b.normalizedCode = :code',
        )
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('code', $code)
            ->getOneOrNullResult();
        self::assertInstanceOf(InstitutionBranch::class, $branch);

        return $branch;
    }

    private function reload(Uuid $id): InstitutionBranch
    {
        $em = $this->em();
        $em->clear();
        $branch = $em->find(InstitutionBranch::class, $id);
        self::assertInstanceOf(InstitutionBranch::class, $branch);

        return $branch;
    }

    private function auditTotal(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
    }

    private function auditCount(string $action, string $branchId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
            [$action, '%'.$branchId.'%'],
        );
    }

    private function em(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
