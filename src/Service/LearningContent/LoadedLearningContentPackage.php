<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\LearningContent\Content\LearningContentDocument;

final class LoadedLearningContentPackage
{
    public function __construct(
        public readonly LearningContentPackageTarget $target,
        public readonly LearningContentDocument $document,
        public readonly string $fixtureChecksum,
    ) {
    }
}
