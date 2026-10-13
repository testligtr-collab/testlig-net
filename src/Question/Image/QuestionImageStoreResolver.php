<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Enum\StoredMediaStorageProvider;
use App\Exception\QuestionException;
use App\LearningContent\Document\BunnyStorageConfiguration;

final class QuestionImageStoreResolver
{
    public function __construct(
        private readonly QuestionImageByteStore $local,
        private readonly BunnyQuestionImageClient $bunny,
        private readonly BunnyStorageConfiguration $configuration,
        private readonly string $uploadProvider,
    ) {
    }

    public function store(): QuestionImageByteStore
    {
        return $this->storeFor($this->provider());
    }

    public function provider(): StoredMediaStorageProvider
    {
        $provider = strtolower(trim($this->uploadProvider));
        if ('' === $provider || 'local' === $provider) {
            return StoredMediaStorageProvider::Local;
        }
        if ('bunny' === $provider) {
            if (!$this->configuration->isReady()) {
                throw QuestionException::imageRejected('question_image_store');
            }

            return StoredMediaStorageProvider::Bunny;
        }

        throw QuestionException::imageRejected('question_image_store');
    }

    public function storeFor(StoredMediaStorageProvider $provider): QuestionImageByteStore
    {
        if (StoredMediaStorageProvider::Local === $provider) {
            return $this->local;
        }
        if (StoredMediaStorageProvider::Bunny === $provider) {
            if (!$this->configuration->isReady()) {
                throw QuestionException::imageRejected('question_image_store');
            }

            return $this->bunny;
        }

        throw QuestionException::imageRejected('question_image_store');
    }
}
