<?php

declare(strict_types=1);

namespace App\Twig;

use App\Presentation\AdminStatusLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminUiExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminStatusLabels $statusLabels,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_status', $this->statusLabels->present(...)),
        ];
    }
}
