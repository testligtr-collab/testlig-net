<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Entity\Assessment;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Exception\AssessmentException;

/**
 * Re-verifies stored publication manifest integrity without embedding secrets in exceptions.
 */
final class AssessmentPublicationIntegrityVerifier
{
    public function __construct(
        private readonly AssessmentManifestHasher $manifestHasher,
    ) {
    }

    public function verify(
        AssessmentPublication $publication,
        Assessment $assessment,
        AssessmentRevision $revision,
    ): void {
        if (!$publication->getAssessment()->getId()->equals($assessment->getId())) {
            throw AssessmentException::publicationInvalid();
        }
        if (!$publication->getAssessmentRevision()->getId()->equals($revision->getId())) {
            throw AssessmentException::publicationInvalid();
        }
        if (!$revision->getAssessment()->getId()->equals($assessment->getId())) {
            throw AssessmentException::publicationInvalid();
        }

        $storedHash = $publication->getManifestHash();
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $storedHash)) {
            throw AssessmentException::publicationInvalid();
        }

        $recomputed = $this->manifestHasher->hash($publication->getManifest());
        if (!hash_equals($storedHash, $recomputed)) {
            throw AssessmentException::publicationInvalid();
        }
    }
}
