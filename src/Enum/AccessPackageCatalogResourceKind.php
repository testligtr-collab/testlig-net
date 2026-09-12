<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessPackageCatalogResourceKind: string
{
    case LearningContent = 'learning_content';
    case Assessment = 'assessment';
}
