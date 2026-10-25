<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InstitutionOperationException;

/**
 * Institution-local branch codes. The stored key is the uniqueness key.
 */
final class InstitutionBranchCodeNormalizer
{
    /**
     * @return array{code: string, normalizedCode: string}
     */
    public function normalize(string $code): array
    {
        $code = strtoupper(trim(preg_replace('/\s+/u', '', $code) ?? $code));
        if (1 !== preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/', $code)) {
            throw InstitutionOperationException::invalidInput('Branch code is invalid.');
        }

        return [
            'code' => $code,
            'normalizedCode' => $code,
        ];
    }
}
