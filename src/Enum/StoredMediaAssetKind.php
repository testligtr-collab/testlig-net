<?php

declare(strict_types=1);

namespace App\Enum;

enum StoredMediaAssetKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Presentation = 'presentation';
    case Animation = 'animation';
    case InteractivePackage = 'interactive_package';
    case Subtitle = 'subtitle';
    case Transcript = 'transcript';
    case Thumbnail = 'thumbnail';
}
