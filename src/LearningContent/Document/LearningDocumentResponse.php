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
        private readonly LearningDocumentStoreResolver $stores,
    ) {
    }

    public function inline(LearningDocumentAsset $asset): BinaryFileResponse
    {
        $store = $this->stores->forAsset($asset);
        $store->read($asset->getStorageKey(), $asset->getContentSha256());
        $response = new BinaryFileResponse($store->absolutePath($asset->getStorageKey()));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->getOriginalName());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setPrivate();

        return $response;
    }
}
