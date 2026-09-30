<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * URL form of the post-login matrix used when no saved target path is accepted.
 */
final class StudentLoginRedirector
{
    public function __construct(
        private readonly PostLoginDestinationResolver $destinations,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function defaultPathFor(User $user): string
    {
        return $this->destinations->resolve($user, null)->location($this->urlGenerator);
    }
}
