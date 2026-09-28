<?php

declare(strict_types=1);

namespace App\Twig;

use App\EventSubscriber\ResponseSecurityPolicySubscriber;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspNonceExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
        ];
    }

    public function nonce(): string
    {
        $nonce = $this->requests->getCurrentRequest()?->attributes->get(ResponseSecurityPolicySubscriber::NONCE_ATTRIBUTE);

        return \is_string($nonce) ? $nonce : '';
    }
}
