<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningContentType: string
{
    case TopicExplanation = 'topic_explanation';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Worksheet = 'worksheet';
    case Presentation = 'presentation';
    case Animation = 'animation';
    case Simulation = 'simulation';
    case EducationalGame = 'educational_game';
    case Interactive = 'interactive';
    case ExternalLink = 'external_link';
}
