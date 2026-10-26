<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Distinct curriculum write risks. Publish is not implied by manage.
 */
enum CurriculumOperation: string
{
    case View = 'view';
    case Manage = 'manage';
    case Publish = 'publish';
    case Archive = 'archive';
}
