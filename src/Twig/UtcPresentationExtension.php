<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Time\UtcInstant;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats persisted UTC instants in the actor's presentation timezone.
 */
final class UtcPresentationExtension extends AbstractExtension
{
    private const DEFAULT_TIMEZONE = 'Europe/Istanbul';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('utc_present', $this->format(...)),
        ];
    }

    public function format(
        ?\DateTimeInterface $value,
        string $format = 'd.m.Y H:i',
        ?string $timezone = null,
    ): string {
        if (!$value instanceof \DateTimeInterface) {
            return '—';
        }

        $tz = $timezone ?? $this->resolveTimezone();

        return UtcInstant::formatForUser($value, $tz, $format);
    }

    private function resolveTimezone(): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $tz = $user->getTimezone();
            if ('' !== $tz) {
                return $tz;
            }
        }

        return self::DEFAULT_TIMEZONE;
    }
}
