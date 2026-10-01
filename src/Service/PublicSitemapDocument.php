<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Sitemap XML. Callers pass absolute URLs that were already built from published slugs.
 */
final class PublicSitemapDocument
{
    /**
     * @param list<array{loc: string, lastmod: string|null}> $urls
     */
    public static function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.self::escape($url['loc']).'</loc>';
            if (null !== $url['lastmod'] && '' !== $url['lastmod']) {
                $xml .= '<lastmod>'.self::escape($url['lastmod']).'</lastmod>';
            }
            $xml .= "</url>\n";
        }

        return $xml."</urlset>\n";
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
