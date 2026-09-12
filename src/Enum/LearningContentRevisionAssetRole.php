<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningContentRevisionAssetRole: string
{
    case Cover = 'cover';
    case Inline = 'inline';
    case PrimaryMedia = 'primary_media';
    case Attachment = 'attachment';
    case Thumbnail = 'thumbnail';
    case Subtitle = 'subtitle';
    case Transcript = 'transcript';
    case InteractivePackage = 'interactive_package';
}
