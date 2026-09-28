<?php

declare(strict_types=1);

namespace App\Twig;

use App\EventSubscriber\ResponseSecurityPolicySubscriber;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspNonceExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly AssetMapperInterface $assets,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
            new TwigFunction('mapped_asset', $this->mappedAsset(...)),
        ];
    }

    public function mappedAsset(string $logicalPath): string
    {
        $path = $this->assets->getPublicPath($logicalPath);
        if (null === $path || '' === $path) {
            throw new \RuntimeException('Mapped asset is missing.');
        }

        return $path;
    }

    public function nonce(): string
    {
        $nonce = $this->requests->getCurrentRequest()?->attributes->get(ResponseSecurityPolicySubscriber::NONCE_ATTRIBUTE);

        return \is_string($nonce) ? $nonce : '';
    }
}
