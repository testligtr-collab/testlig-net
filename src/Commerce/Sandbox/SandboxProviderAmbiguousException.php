<?php

declare(strict_types=1);

namespace App\Commerce\Sandbox;

/**
 * Typed ambiguous provider outcome — must not be coerced into a failed settlement.
 */
final class SandboxProviderAmbiguousException extends \RuntimeException
{
}
