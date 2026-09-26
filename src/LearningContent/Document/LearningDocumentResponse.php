<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Entity\LearningDocumentAsset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams a verified PDF. Symfony's BinaryFileResponse serves Range requests.
 */
final class LearningDocumentResponse
{
    public function __construct(
        private readonly LearningDocumentStorage $storage,
    ) {
    }

    public function inline(LearningDocumentAsset $asset): BinaryFileResponse
    {
        $this->storage->read($asset->getStorageKey(), $asset->getContentSha256());
        $response = new BinaryFileResponse($this->storage->absolutePath($asset->getStorageKey()));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->getOriginalName());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setPrivate();

        return $response;
    }
}
