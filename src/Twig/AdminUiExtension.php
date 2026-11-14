<?php

declare(strict_types=1);

namespace App\Twig;

use App\Presentation\AdminFilterActivity;
use App\Presentation\AdminStatusLabels;
use App\Presentation\PaymentAttemptListLabels;
use App\Presentation\ReconciliationDiscrepancyListLabels;
use App\Presentation\ReconciliationRunListLabels;
use App\Presentation\SecurityAuditListLabels;
use App\Presentation\WebhookInboxListLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminUiExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminStatusLabels $statusLabels,
        private readonly AdminFilterActivity $filterActivity,
        private readonly PaymentAttemptListLabels $paymentAttemptListLabels,
        private readonly WebhookInboxListLabels $webhookInboxListLabels,
        private readonly ReconciliationRunListLabels $reconciliationRunListLabels,
        private readonly ReconciliationDiscrepancyListLabels $reconciliationDiscrepancyListLabels,
        private readonly SecurityAuditListLabels $securityAuditListLabels,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_status', $this->statusLabels->present(...)),
            new TwigFunction('admin_filters_active', $this->filterActivity->isActive(...)),
            new TwigFunction('admin_payment_status', $this->paymentAttemptListLabels->status(...)),
            new TwigFunction('admin_payment_environment', $this->paymentAttemptListLabels->environment(...)),
            new TwigFunction('admin_webhook_tab', $this->webhookInboxListLabels->tab(...)),
            new TwigFunction('admin_webhook_status', $this->webhookInboxListLabels->status(...)),
            new TwigFunction('admin_webhook_event_type', $this->webhookInboxListLabels->eventType(...)),
            new TwigFunction('admin_webhook_environment', $this->webhookInboxListLabels->environment(...)),
            new TwigFunction('admin_webhook_stale_note', $this->webhookInboxListLabels->staleLeaseNote(...)),
            new TwigFunction('admin_reconciliation_status', $this->reconciliationRunListLabels->status(...)),
            new TwigFunction('admin_reconciliation_mode', $this->reconciliationRunListLabels->mode(...)),
            new TwigFunction('admin_reconciliation_environment', $this->reconciliationRunListLabels->environment(...)),
            new TwigFunction('admin_reconciliation_outcome', $this->reconciliationDiscrepancyListLabels->outcome(...)),
            new TwigFunction('admin_reconciliation_action', $this->reconciliationDiscrepancyListLabels->action(...)),
            new TwigFunction('admin_reconciliation_provider_state', $this->reconciliationDiscrepancyListLabels->providerState(...)),
            new TwigFunction('admin_audit_action', $this->securityAuditListLabels->action(...)),
            new TwigFunction('admin_audit_actor_type', $this->securityAuditListLabels->actorType(...)),
            new TwigFunction('admin_audit_outcome', $this->securityAuditListLabels->outcome(...)),
        ];
    }
}
