<?php

declare(strict_types=1);

namespace App\Twig;

use App\Presentation\AdminFilterActivity;
use App\Presentation\AdminStatusLabels;
use App\Presentation\PaymentAttemptListLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminUiExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminStatusLabels $statusLabels,
        private readonly AdminFilterActivity $filterActivity,
        private readonly PaymentAttemptListLabels $paymentAttemptListLabels,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_status', $this->statusLabels->present(...)),
            new TwigFunction('admin_filters_active', $this->filterActivity->isActive(...)),
            new TwigFunction('admin_payment_status', $this->paymentAttemptListLabels->status(...)),
            new TwigFunction('admin_payment_environment', $this->paymentAttemptListLabels->environment(...)),
        ];
    }
}
