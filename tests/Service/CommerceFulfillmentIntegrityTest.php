<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Access\EntitlementAuthorizationProjector;
use App\Commerce\CommerceOrderHasher;
use App\Entity\AccessPackageVersion;
use App\Entity\CommerceOrder;
use App\Entity\CommerceOrderItem;
use App\Entity\CommercialOffer;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\CommerceFailureReason;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\CommerceOrderStatus;
use App\Enum\CommercialOfferStatus;
use App\Enum\GradeLevel;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\SecurityAuditAction;
use App\Exception\CommerceException;
use App\Service\CommerceFulfillmentManager;
use App\Service\CommerceOrderManager;
use App\Service\CommercialOfferManager;
use App\Service\PaymentAttemptManager;
use App\Service\PaymentSettlementManager;
use App\Service\SubjectManager;
use App\Tests\Support\CommerceTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Fulfillment-time fresh entitlement graph + snapshot matrix regressions.
 *
 * Intentionally does NOT clear()/detach() managed entities after DBAL tampers — the
 * fulfillment path must follow DBAL projections, not the identity map.
 */
final class CommerceFulfillmentIntegrityTest extends KernelTestCase
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

    public function testGrantGraphTamperWithMatchingStoredHashesRejectsFulfillment(): void
    {
        [$order, $attempt, $operator, $version] = $this->capturedOneTimeWithVersion('fig1');
        $item = $this->freshItems($order)[0];
        $stored = $item->getPackagePolicySnapshotHash();
        self::assertSame($stored, $version->getPolicyHash());

        $extraSubject = $this->scenario->service(SubjectManager::class)->create(
            $operator,
            'fig1_extra_subj',
            'Extra '.$this->uniq(),
            'create_subject',
        );
        $this->tamperGrantGraphKeepingStoredHashes($version, $operator, $extraSubject->getId());

        // Managed entities still report the old equal hashes; DB graph has drifted.
        self::assertSame($stored, $version->getPolicyHash());
        self::assertSame($stored, $item->getPackagePolicySnapshotHash());

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig1-fulfill-0000000000', 'fulfill');
        });

        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testVersionPolicyHashTamperRejectsFulfillment(): void
    {
        [$order, $attempt, $operator, $version] = $this->capturedOneTimeWithVersion('fig2');
        $tampered = str_repeat('a', 64);
        $this->flipVersionToDraft($version);
        $this->em->getConnection()->executeStatement(
            'UPDATE access_package_versions SET policy_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$tampered, $version->getId()->toBinary()],
        );
        $this->flipVersionToActive($version, $operator);

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig2-fulfill-0000000000', 'fulfill');
        });
        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testOrderItemPackagePolicySnapshotTamperRejectsFulfillment(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig3');
        $item = $this->freshItems($order)[0];
        $this->rewriteDraftItemSnapshotHashes(
            $order,
            $item,
            $item->getOfferSnapshotHash(),
            str_repeat('b', 64),
        );

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig3-fulfill-0000000000', 'fulfill');
        });
        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testOfferSnapshotHashTamperRejectsFulfillment(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig4');
        $item = $this->freshItems($order)[0];
        $this->rewriteDraftItemSnapshotHashes(
            $order,
            $item,
            str_repeat('c', 64),
            $item->getPackagePolicySnapshotHash(),
        );

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig4-fulfill-0000000000', 'fulfill');
        });
        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testOrderHashTamperRejectsFulfillment(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig5');
        $this->em->getConnection()->executeStatement(
            "UPDATE commerce_orders SET status = 'draft' WHERE id = ?",
            [$order->getId()->toBinary()],
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE commerce_orders SET order_hash = ? WHERE id = ?',
            [str_repeat('d', 64), $order->getId()->toBinary()],
        );
        $this->em->getConnection()->executeStatement(
            "UPDATE commerce_orders SET status = 'awaiting_payment' WHERE id = ?",
            [$order->getId()->toBinary()],
        );

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig5-fulfill-0000000000', 'fulfill');
        });
        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testLicensePolicySnapshotMatrixRejectsMismatch(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig6');
        $item = $this->freshItems($order)[0];
        $version = $item->getPackageVersion();
        $fresh = $this->projector()->computeFreshPolicyHashForPackageVersion(
            $item->getPackage(),
            $version,
            $this->projector()->loadGrantGraph($version->getId()),
        );

        $license = \App\Entity\AccessLicense::createPending(
            $item->getPackage(),
            $version,
            \App\Enum\AccessLicenseLicenseeType::User,
            $order->getUser(),
            null,
            \App\Enum\AccessLicenseSourceType::Purchase,
            'fig6-ext-ref',
            $this->clock->now(),
            $this->clock->now()->add(new \DateInterval('P30D')),
            null,
            str_repeat('e', 64),
            $operator,
            $this->clock->now(),
        );

        $method = new \ReflectionMethod(CommerceFulfillmentManager::class, 'assertLicensePolicySnapshotIntact');
        try {
            $method->invoke($this->fulfillments(), $license, $version, $item, $fresh);
            self::fail('Expected CommerceException hash_mismatch');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::HashMismatch, $e->getReason());
        }

        // Successful path still aligns the full matrix.
        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'fig6-fulfill-0000000000',
            'fulfill',
        );
        $granted = $fulfillments[0]->getAccessLicense();
        self::assertNotNull($granted);
        self::assertSame($fresh, $granted->getPolicySnapshotHash());
        self::assertSame($fresh, $version->getPolicyHash());
        self::assertSame($fresh, $item->getPackagePolicySnapshotHash());
    }

    public function testStaleManagedVersionStillUsesDbalGrantGraph(): void
    {
        [$order, $attempt, $operator, $version] = $this->capturedOneTimeWithVersion('fig7');
        // Touch the managed version so it stays in the identity map with the pre-tamper state.
        self::assertNotSame('', $version->getPolicyHash());

        $extraSubject = $this->scenario->service(SubjectManager::class)->create(
            $operator,
            'fig7_extra_subj',
            'Extra '.$this->uniq(),
            'create_subject',
        );
        $this->tamperGrantGraphKeepingStoredHashes($version, $operator, $extraSubject->getId());

        // Deliberately keep the managed entity; do not clear()/detach().
        self::assertTrue($this->em->contains($version));

        $this->expectCommerceFailure(CommerceFailureReason::HashMismatch, function () use ($order, $attempt, $operator): void {
            $this->fulfillments()->fulfill($order, $attempt, $operator, 'fig7-fulfill-0000000000', 'fulfill');
        });
        $this->assertFulfillmentFullyRolledBack($order);
    }

    public function testRetiredOfferAfterSealStillFulfillsWhenSnapshotsIntact(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig8');
        $item = $this->freshItems($order)[0];
        $offer = $this->scenario->refresh(CommercialOffer::class, $item->getOffer()->getId());
        $retired = $this->scenario->service(CommercialOfferManager::class)->retire($offer, $operator, 'retire_offer');
        self::assertSame(CommercialOfferStatus::Retired, $retired->getStatus());

        $fulfillments = $this->fulfillments()->fulfill(
            $order,
            $attempt,
            $operator,
            'fig8-fulfill-0000000000',
            'fulfill',
        );
        self::assertCount(1, $fulfillments);
        self::assertSame(CommerceFulfillmentStatus::Completed, $fulfillments[0]->getStatus());
        self::assertSame(
            CommerceOrderStatus::Paid,
            $this->scenario->refresh(CommerceOrder::class, $order->getId())->getStatus(),
        );
    }

    public function testOrderHashPayloadCoversFulfillmentSnapshotFields(): void
    {
        [$order] = $this->capturedOneTimeOrder('fig9');
        $item = $this->freshItems($order)[0];
        $payload = $item->toHashPayload();

        self::assertSame($item->getOffer()->getId()->toRfc4122(), $payload['offerId']);
        self::assertSame($item->getPackage()->getId()->toRfc4122(), $payload['packageId']);
        self::assertSame($item->getPackageVersion()->getId()->toRfc4122(), $payload['packageVersionId']);
        self::assertSame($item->getQuantity(), $payload['quantity']);
        self::assertSame($item->getUnitPriceAmountMinor(), $payload['unitPriceAmountMinor']);
        self::assertSame($item->getTaxRateBasisPoints(), $payload['taxRateBasisPoints']);
        self::assertSame($item->getLineSubtotalAmountMinor(), $payload['lineSubtotalAmountMinor']);
        self::assertSame($item->getLineTaxAmountMinor(), $payload['lineTaxAmountMinor']);
        self::assertSame($item->getLineTotalAmountMinor(), $payload['lineTotalAmountMinor']);
        self::assertSame($item->getOfferSnapshotHash(), $payload['offerSnapshotHash']);
        self::assertSame($item->getPackagePolicySnapshotHash(), $payload['packagePolicySnapshotHash']);

        $hasher = $this->scenario->service(CommerceOrderHasher::class);
        $hasher->verify(
            $order->getOrderHash(),
            $order->getId(),
            $order->getPurchaserType(),
            $order->getUser()?->getId(),
            $order->getInstitution()?->getId(),
            $order->getCurrency(),
            $order->getSubtotalAmountMinor(),
            $order->getDiscountAmountMinor(),
            $order->getTaxAmountMinor(),
            $order->getGrandTotalAmountMinor(),
            [$payload],
            $order->getSchemaVersion(),
        );

        // Purchaser target type and currency are covered at the order envelope, not the line.
        self::assertSame($order->getPurchaserType()->value, $order->getPurchaserType()->value);
        self::assertSame($item->getCurrency(), $order->getCurrency());
        // Unit tax is re-checked against the live offer at fulfillment; line tax is in the hash.
        self::assertSame(
            $item->getUnitTaxAmountMinor(),
            $item->getOffer()->getPrice()->percentageOfBasisPoints($item->getTaxRateBasisPoints())->getAmountMinor(),
        );
    }

    public function testAuditMetadataOmitsIdempotencyKeyHash(): void
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeOrder('fig10');
        $key = 'fig10-fulfill-000000000';
        $this->fulfillments()->fulfill($order, $attempt, $operator, $key, 'fulfill');

        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT metadata FROM security_audit_events WHERE action IN (?, ?)',
            [
                SecurityAuditAction::CommerceFulfillmentCompleted->value,
                SecurityAuditAction::PaymentAttemptStarted->value,
            ],
        );
        self::assertNotSame([], $rows);
        foreach ($rows as $metadata) {
            $decoded = json_decode((string) $metadata, true);
            self::assertIsArray($decoded);
            self::assertArrayNotHasKey('idempotency_key_hash', $decoded);
            self::assertArrayNotHasKey('idempotency_key', $decoded);
            self::assertStringNotContainsString($key, (string) $metadata);
            self::assertStringNotContainsString('card', strtolower((string) $metadata));
            self::assertStringNotContainsString('cvv', strtolower((string) $metadata));
            self::assertStringNotContainsString('pan', strtolower((string) $metadata));
        }

        $storedHash = $this->em->getConnection()->fetchOne(
            'SELECT idempotency_key_hash FROM commerce_fulfillments LIMIT 1',
        );
        self::assertIsString($storedHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $storedHash);
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User, 3: AccessPackageVersion}
     */
    private function capturedOneTimeWithVersion(string $suffix): array
    {
        $sa = $this->scenario->superAdmin($suffix.'-sa@example.com');
        $version = $this->scenario->activeVersion($sa, $suffix);
        $offer = $this->scenario->activeOffer($sa, $version, $suffix.'_code', 10000, 2000);
        $buyer = $this->scenario->activeUser($suffix.'-buyer@example.com');
        $order = $this->orders()->createUserOrder(
            $buyer,
            $buyer,
            [['offer' => $offer, 'quantity' => 1]],
            'create_order',
        );
        $attempt = $this->captureAttempt(
            $this->startAttempt($order, $buyer, $suffix.'-key-000000000000000'),
            $sa,
            $suffix,
        );

        return [
            $this->scenario->refresh(CommerceOrder::class, $order->getId()),
            $attempt,
            $this->scenario->refresh(User::class, $sa->getId()),
            $this->scenario->refresh(AccessPackageVersion::class, $version->getId()),
        ];
    }

    /**
     * @return array{0: CommerceOrder, 1: PaymentAttempt, 2: User}
     */
    private function capturedOneTimeOrder(string $suffix): array
    {
        [$order, $attempt, $operator] = $this->capturedOneTimeWithVersion($suffix);

        return [$order, $attempt, $operator];
    }

    /**
     * @return list<CommerceOrderItem>
     */
    private function freshItems(CommerceOrder $order): array
    {
        return $this->scenario->service(\App\Service\CommerceFreshEntityLoader::class)
            ->findFreshOrderItems($order->getId());
    }

    private function tamperGrantGraphKeepingStoredHashes(
        AccessPackageVersion $version,
        User $actor,
        Uuid $extraSubjectId,
    ): void {
        $this->flipVersionToDraft($version);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO access_package_catalog_grants
                (id, version_id, resource_kind, subject_id, grade_level, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [
                (new UuidV7())->toBinary(),
                $version->getId()->toBinary(),
                AccessPackageCatalogResourceKind::LearningContent->value,
                $extraSubjectId->toBinary(),
                GradeLevel::Grade10->value,
            ],
        );
        $this->flipVersionToActive($version, $actor);
    }

    private function flipVersionToDraft(AccessPackageVersion $version): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE access_package_versions
             SET status = 'draft', activated_at = NULL, activated_by_id = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$version->getId()->toBinary()],
        );
    }

    private function flipVersionToActive(AccessPackageVersion $version, User $actor): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE access_package_versions
             SET status = 'active',
                 activated_at = UTC_TIMESTAMP(),
                 activated_by_id = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$actor->getId()->toBinary(), $version->getId()->toBinary()],
        );
    }

    private function rewriteDraftItemSnapshotHashes(
        CommerceOrder $order,
        CommerceOrderItem $item,
        string $offerSnapshotHash,
        string $packagePolicySnapshotHash,
    ): void {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE commerce_orders SET status = 'draft' WHERE id = ?",
            [$order->getId()->toBinary()],
        );
        $conn->executeStatement('DELETE FROM commerce_order_items WHERE id = ?', [$item->getId()->toBinary()]);
        $conn->executeStatement(
            'INSERT INTO commerce_order_items (
                id, order_id, offer_id, package_id, package_version_id, quantity, billing_type, billing_interval,
                currency, unit_price_amount_minor, unit_tax_amount_minor, tax_rate_basis_points,
                line_subtotal_amount_minor, line_tax_amount_minor, line_total_amount_minor,
                offer_snapshot_hash, package_policy_snapshot_hash, description_snapshot, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?
            )',
            [
                $item->getId()->toBinary(),
                $order->getId()->toBinary(),
                $item->getOffer()->getId()->toBinary(),
                $item->getPackage()->getId()->toBinary(),
                $item->getPackageVersion()->getId()->toBinary(),
                $item->getQuantity(),
                $item->getBillingType()->value,
                $item->getBillingInterval()?->value,
                $item->getCurrency(),
                $item->getUnitPriceAmountMinor(),
                $item->getUnitTaxAmountMinor(),
                $item->getTaxRateBasisPoints(),
                $item->getLineSubtotalAmountMinor(),
                $item->getLineTaxAmountMinor(),
                $item->getLineTotalAmountMinor(),
                $offerSnapshotHash,
                $packagePolicySnapshotHash,
                $item->getDescriptionSnapshot(),
                $item->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
        );

        $hasher = $this->scenario->service(CommerceOrderHasher::class);
        $newHash = $hasher->hash(
            $order->getId(),
            $order->getPurchaserType(),
            $order->getUser()?->getId(),
            $order->getInstitution()?->getId(),
            $order->getCurrency(),
            $order->getSubtotalAmountMinor(),
            $order->getDiscountAmountMinor(),
            $order->getTaxAmountMinor(),
            $order->getGrandTotalAmountMinor(),
            [[
                'offerId' => $item->getOffer()->getId()->toRfc4122(),
                'packageId' => $item->getPackage()->getId()->toRfc4122(),
                'packageVersionId' => $item->getPackageVersion()->getId()->toRfc4122(),
                'quantity' => $item->getQuantity(),
                'unitPriceAmountMinor' => $item->getUnitPriceAmountMinor(),
                'taxRateBasisPoints' => $item->getTaxRateBasisPoints(),
                'lineSubtotalAmountMinor' => $item->getLineSubtotalAmountMinor(),
                'lineTaxAmountMinor' => $item->getLineTaxAmountMinor(),
                'lineTotalAmountMinor' => $item->getLineTotalAmountMinor(),
                'offerSnapshotHash' => $offerSnapshotHash,
                'packagePolicySnapshotHash' => $packagePolicySnapshotHash,
            ]],
            $order->getSchemaVersion(),
        );
        $conn->executeStatement(
            'UPDATE commerce_orders SET order_hash = ? WHERE id = ?',
            [$newHash, $order->getId()->toBinary()],
        );
        $conn->executeStatement(
            "UPDATE commerce_orders SET status = 'awaiting_payment' WHERE id = ?",
            [$order->getId()->toBinary()],
        );
    }

    private function assertFulfillmentFullyRolledBack(CommerceOrder $order): void
    {
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM access_licenses'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM commerce_fulfillments WHERE status = 'completed'",
        ));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM commerce_fulfillments WHERE order_id = ?',
            [$order->getId()->toBinary()],
        ));
        $freshOrder = $this->scenario->refresh(CommerceOrder::class, $order->getId());
        self::assertSame(CommerceOrderStatus::AwaitingPayment, $freshOrder->getStatus());
        self::assertSame(0, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceFulfillmentCompleted->value));
        self::assertSame(0, $this->auditEvents()->countByAction(SecurityAuditAction::CommerceOrderPaid->value));
        self::assertSame(0, $this->auditEvents()->countByAction(SecurityAuditAction::AccessLicenseCreated->value));
    }

    private function startAttempt(CommerceOrder $order, User $buyer, string $idempotencyKey): PaymentAttempt
    {
        return $this->attempts()->start(
            $order,
            $buyer,
            'sandbox_provider',
            PaymentProviderEnvironment::Sandbox,
            $idempotencyKey,
            'start_payment',
        );
    }

    private function captureAttempt(PaymentAttempt $attempt, User $settlementActor, string $suffix): PaymentAttempt
    {
        $this->settlement()->recordAuthorized(
            $this->reloadAttempt($attempt),
            $settlementActor,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-auth-000000000000000',
            'authorize',
            'prov_pay_'.$suffix,
            'prov_auth_'.$suffix,
        );
        $this->settlement()->recordCaptured(
            $this->reloadAttempt($attempt),
            $settlementActor,
            $attempt->getAmount(),
            $this->clock->now(),
            $suffix.'-cap-0000000000000000',
            'capture',
            'prov_pay_'.$suffix,
        );

        return $this->reloadAttempt($attempt);
    }

    private function reloadAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        return $this->scenario->refresh(PaymentAttempt::class, $attempt->getId());
    }

    private function orders(): CommerceOrderManager
    {
        return $this->scenario->service(CommerceOrderManager::class);
    }

    private function attempts(): PaymentAttemptManager
    {
        return $this->scenario->service(PaymentAttemptManager::class);
    }

    private function settlement(): PaymentSettlementManager
    {
        return $this->scenario->service(PaymentSettlementManager::class);
    }

    private function fulfillments(): CommerceFulfillmentManager
    {
        return $this->scenario->service(CommerceFulfillmentManager::class);
    }

    private function projector(): EntitlementAuthorizationProjector
    {
        return $this->scenario->service(EntitlementAuthorizationProjector::class);
    }

    private function uniq(): string
    {
        return substr(str_replace('-', '', Uuid::v7()->toRfc4122()), 0, 8);
    }
}
