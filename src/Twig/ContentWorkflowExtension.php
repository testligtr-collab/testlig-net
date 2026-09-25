<?php

declare(strict_types=1);

namespace App\Twig;

use App\Presentation\ContentWorkflowLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ContentWorkflowExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentWorkflowLabels $labels,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('workflow_label', $this->label(...)),
        ];
    }

    public function label(string $value, string $kind): string
    {
        return $this->labels->label($kind, $value);
    }
}
