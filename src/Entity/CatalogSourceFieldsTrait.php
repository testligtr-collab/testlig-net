<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\CatalogSourceAttribution;

/**
 * Shared MEB/TYMM provenance columns for catalog entities.
 */
trait CatalogSourceFieldsTrait
{
    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }

    public function getSourceVersion(): ?string
    {
        return $this->sourceVersion;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function getSourceOccurrence(): int
    {
        return $this->sourceOccurrence;
    }

    /**
     * @internal prefer CatalogWriteService
     */
    public function assignSourceAttribution(CatalogSourceAttribution $source, \DateTimeImmutable $now): void
    {
        $this->applySourceAttribution($source);
        $this->updatedAt = $now;
    }

    private function applySourceAttribution(CatalogSourceAttribution $source): void
    {
        if ($source->isEmpty()) {
            $this->sourceCode = null;
            $this->sourceVersion = null;
            $this->sourceUrl = null;
            $this->sourceOccurrence = 1;

            return;
        }
        $this->sourceCode = null !== $source->code ? trim($source->code) : null;
        $this->sourceVersion = null !== $source->version ? trim($source->version) : null;
        $this->sourceUrl = null !== $source->url ? trim($source->url) : null;
        $this->sourceOccurrence = $source->occurrence;
        CatalogSourceAttribution::assertValid($this->sourceCode, $this->sourceVersion, $this->sourceUrl, $this->sourceOccurrence);
    }

    private static function normalizeSourceAttribution(?CatalogSourceAttribution $source): CatalogSourceAttribution
    {
        return $source ?? CatalogSourceAttribution::none();
    }
}
