<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Enum\CommerceFailureReason;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminPaymentOperationsQuery;
use App\Service\Admin\AdminWebhookQueueQuery;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AdminPaymentOperationsQueryTest extends KernelTestCase
{
    use CommerceTestFixtures;

    protected function setUp(): void
    {
        $this->bootCommerce();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        try {
            $this->cleanupCommerce();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testPaginationBounds(): void
    {
        self::assertSame(1, AdminPagination::normalizePage(0));
        self::assertSame(25, AdminPagination::normalizePageSize(0));
        self::assertSame(100, AdminPagination::normalizePageSize(500));
        self::assertSame(0, AdminPagination::offset(1, 25));
        self::assertSame(50, AdminPagination::offset(3, 25));
    }

    public function testAdminDeniedPaymentQuery(): void
    {
        $admin = $this->scenario->activeUser('admin_q_admin@example.com', UserRole::Admin);
        $query = $this->scenario->service(AdminPaymentOperationsQuery::class);

        try {
            $query->listAttempts($admin->getId());
            self::fail('Expected unauthorized');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::Unauthorized, $e->getReason());
        } finally {
            $this->recoverDoctrine();
        }
    }

    public function testSuperAdminListAcceptsEmptyResult(): void
    {
        $sa = $this->scenario->superAdmin('admin_q_sa@example.com');
        $query = $this->scenario->service(AdminPaymentOperationsQuery::class);
        $result = $query->listAttempts($sa->getId(), ['page' => 1, 'page_size' => 10]);
        self::assertSame(1, $result->page);
        self::assertSame(10, $result->pageSize);
        self::assertSame([], $result->items);
    }

    public function testInvalidWebhookTabRejected(): void
    {
        $sa = $this->scenario->superAdmin('admin_q_wh_sa@example.com');
        $query = $this->scenario->service(AdminWebhookQueueQuery::class);

        try {
            $query->listByTab($sa->getId(), 'not_a_tab');
            self::fail('Expected invalid input');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::InvalidInput, $e->getReason());
        } finally {
            $this->recoverDoctrine();
        }
    }
}
