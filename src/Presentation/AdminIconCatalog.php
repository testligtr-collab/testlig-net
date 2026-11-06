<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Server-side icon ids for the admin shell. Callers never accept an icon name from a request.
 */
final class AdminIconCatalog
{
    public const DEFAULT = 'default';

    /**
     * @var list<string>
     */
    private const ALLOWED = [
        'dashboard',
        'system',
        'users',
        'institutions',
        'calendar',
        'catalog',
        'learning_contents',
        'questions',
        'tests',
        'classrooms',
        'payments',
        'webhooks',
        'reconciliations',
        'audit',
        'account',
        'media_page',
        'media_pdf',
        'media_play',
        'media_cloud',
        'archive',
        self::DEFAULT,
    ];

    public function resolve(string $name): string
    {
        return \in_array($name, self::ALLOWED, true) ? $name : self::DEFAULT;
    }

    /**
     * @return list<string>
     */
    public function allowed(): array
    {
        return self::ALLOWED;
    }
}
