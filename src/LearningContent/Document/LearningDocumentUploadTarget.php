<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Enum\LearningDocumentStorageProvider;

final class LearningDocumentUploadTarget
{
    public function __construct(
        private readonly LearningDocumentByteStore $store,
        private readonly LearningDocumentStorageProvider $provider,
    ) {
    }

    public function store(): LearningDocumentByteStore
    {
        return $this->store;
    }

    public function provider(): LearningDocumentStorageProvider
    {
        return $this->provider;
    }
}
