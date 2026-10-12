<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\LearningContent\Document\BunnyLearningDocumentStorage;
use App\LearningContent\Document\R2LearningDocumentStorage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Removes this request's verified temporary copies when the kernel terminates.
 * This does not run when the process is killed before terminate.
 */
final class LearningDocumentTempCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly R2LearningDocumentStorage $r2Storage,
        private readonly BunnyLearningDocumentStorage $bunnyStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(): void
    {
        $this->r2Storage->releaseVerifiedCopies();
        $this->bunnyStorage->releaseVerifiedCopies();
    }
}
