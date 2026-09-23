<?php

declare(strict_types=1);

namespace App\Dto;

use App\Exception\CatalogException;
use App\Util\HttpsUrl;

/**
 * Optional MEB/TYMM provenance for catalog rows (import identity).
 *
 * Identity: source_version + source_code + source_occurrence.
 */
final readonly class CatalogSourceAttribution
{
    public const CODE_MAX = 64;
    public const VERSION_MAX = 32;
    public const URL_MAX = 500;
    public const OCCURRENCE_MIN = 1;
    public const OCCURRENCE_MAX = 99;

    public function __construct(
        public ?string $code,
        public ?string $version,
        public ?string $url,
        public int $occurrence = 1,
    ) {
        self::assertValid($this->code, $this->version, $this->url, $this->occurrence);
    }

    public static function fromNullable(?string $code, ?string $version, ?string $url, int $occurrence = 1): self
    {
        return new self(
            self::normalizeOptionalString($code),
            self::normalizeOptionalString($version),
            self::normalizeOptionalString($url),
            $occurrence,
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, 1);
    }

    public function isEmpty(): bool
    {
        return null === $this->code && null === $this->version && null === $this->url;
    }

    public static function assertValid(?string $code, ?string $version, ?string $url, int $occurrence): void
    {
        if ($occurrence < self::OCCURRENCE_MIN || $occurrence > self::OCCURRENCE_MAX) {
            throw CatalogException::invalidInput(\sprintf(
                'source_occurrence %d–%d arasında olmalıdır.',
                self::OCCURRENCE_MIN,
                self::OCCURRENCE_MAX,
            ));
        }
        if (null !== $code) {
            if ('' === $code || \strlen($code) > self::CODE_MAX) {
                throw CatalogException::invalidInput('source_code geçersiz.');
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@#-]*$/', $code)) {
                throw CatalogException::invalidInput('source_code biçimi geçersiz.');
            }
        }
        if (null !== $version) {
            if ('' === $version || \strlen($version) > self::VERSION_MAX) {
                throw CatalogException::invalidInput('source_version geçersiz.');
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $version)) {
                throw CatalogException::invalidInput('source_version biçimi geçersiz.');
            }
        }
        if (null !== $url) {
            if ('' === $url || \strlen($url) > self::URL_MAX) {
                throw CatalogException::invalidInput('source_url geçersiz.');
            }
            if (!HttpsUrl::isValid($url, self::URL_MAX)) {
                throw CatalogException::invalidInput('source_url yalnız https URL olabilir.');
            }
        }
        if ((null === $code) xor (null === $version)) {
            throw CatalogException::invalidInput('source_code ve source_version birlikte set edilmelidir.');
        }
    }

    private static function normalizeOptionalString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
