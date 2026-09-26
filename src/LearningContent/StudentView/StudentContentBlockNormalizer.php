<?php

declare(strict_types=1);

namespace App\LearningContent\StudentView;

use App\Dto\StudentContent\StudentContentBlockView;
use App\LearningContent\Document\VideoEmbed;
use App\LearningContent\Document\VideoUrlParser;
use Symfony\Component\Uid\Uuid;

/**
 * Fail-closed normalizer: sealed revision JSON → student block views.
 *
 * Unknown/malformed/media blocks are skipped (no payload leak). Does not trust
 * Twig callers with raw arrays.
 */
final class StudentContentBlockNormalizer
{
    private const CALLOUT_VARIANTS = ['info', 'warning', 'tip', 'note'];

    /**
     * @param array<mixed>                                                   $structuredContent
     * @param (\Closure(int, string, string): ?StudentContentBlockView)|null $documentAt
     *
     * @return list<StudentContentBlockView>
     */
    public function normalize(array $structuredContent, ?\Closure $documentAt = null): array
    {
        $blocks = $structuredContent['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }

        $views = [];
        foreach (array_values($blocks) as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ('document' === ($block['type'] ?? null)) {
                $view = $this->normalizeDocument($block, $index, $documentAt);
                if ($view instanceof StudentContentBlockView) {
                    $views[] = $view;
                }
                continue;
            }
            if ('video' === ($block['type'] ?? null)) {
                $view = $this->normalizeVideo($block);
                if ($view instanceof StudentContentBlockView) {
                    $views[] = $view;
                }
                continue;
            }
            $view = $this->normalizeBlock($block, allowCallout: true);
            if ($view instanceof StudentContentBlockView) {
                $views[] = $view;
            }
        }

        return $views;
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeBlock(array $block, bool $allowCallout): ?StudentContentBlockView
    {
        $type = $block['type'] ?? null;
        if (!\is_string($type)) {
            return null;
        }

        if (StudentContentBlockView::TYPE_CALLOUT === $type) {
            return $allowCallout ? $this->normalizeCallout($block) : null;
        }
        if (StudentContentBlockView::TYPE_HEADING === $type) {
            return $this->normalizeHeading($block);
        }
        if (StudentContentBlockView::TYPE_PARAGRAPH === $type) {
            return $this->normalizeParagraph($block);
        }
        if (StudentContentBlockView::TYPE_LIST === $type) {
            return $this->normalizeList($block);
        }
        if (StudentContentBlockView::TYPE_QUOTE === $type) {
            return $this->normalizeQuote($block);
        }
        if (StudentContentBlockView::TYPE_MATH === $type) {
            return $this->normalizeMath($block);
        }

        return null;
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeHeading(array $block): ?StudentContentBlockView
    {
        $level = $block['level'] ?? null;
        $text = $block['text'] ?? null;
        if (!\is_int($level) || $level < 1 || $level > 3) {
            return null;
        }
        if (!$this->isSafePlainText($text)) {
            return null;
        }

        return StudentContentBlockView::heading($level, $text);
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeParagraph(array $block): ?StudentContentBlockView
    {
        $text = $block['text'] ?? null;
        if (!$this->isSafePlainText($text)) {
            return null;
        }

        return StudentContentBlockView::paragraph($text);
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeList(array $block): ?StudentContentBlockView
    {
        $items = $block['items'] ?? null;
        if (!\is_array($items) || [] === $items || \count($items) > 50) {
            return null;
        }

        $style = $block['style'] ?? StudentContentBlockView::LIST_UNORDERED;
        if (!\is_string($style)
            || !\in_array($style, [StudentContentBlockView::LIST_UNORDERED, StudentContentBlockView::LIST_ORDERED], true)
        ) {
            return null;
        }

        $safeItems = [];
        foreach ($items as $item) {
            if (!$this->isSafePlainText($item)) {
                return null;
            }
            $safeItems[] = $item;
        }

        return StudentContentBlockView::list($safeItems, $style);
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeQuote(array $block): ?StudentContentBlockView
    {
        $text = $block['text'] ?? null;
        if (!$this->isSafePlainText($text)) {
            return null;
        }

        return StudentContentBlockView::quote($text);
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeMath(array $block): ?StudentContentBlockView
    {
        $latex = $block['latex'] ?? null;
        if (!$this->isSafePlainText($latex)) {
            return null;
        }

        return StudentContentBlockView::math($latex);
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeCallout(array $block): ?StudentContentBlockView
    {
        $variant = $block['variant'] ?? 'info';
        if (!\is_string($variant) || !\in_array($variant, self::CALLOUT_VARIANTS, true)) {
            return null;
        }

        $children = $block['blocks'] ?? null;
        if (!\is_array($children) || [] === $children || \count($children) > 20) {
            return null;
        }

        $childViews = [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                return null;
            }
            $view = $this->normalizeBlock($child, allowCallout: false);
            if (!$view instanceof StudentContentBlockView) {
                return null;
            }
            $childViews[] = $view;
        }

        return StudentContentBlockView::callout($childViews, $variant);
    }

    /**
     * @param array<mixed>                                                   $block
     * @param (\Closure(int, string, string): ?StudentContentBlockView)|null $documentAt
     */
    private function normalizeDocument(array $block, int $index, ?\Closure $documentAt): ?StudentContentBlockView
    {
        if (!$documentAt instanceof \Closure) {
            return null;
        }
        $assetId = $block['assetId'] ?? null;
        $label = $block['label'] ?? null;
        if (!\is_string($assetId) || !Uuid::isValid($assetId) || !$this->isSafePlainText($label)) {
            return null;
        }

        try {
            return $documentAt($index, $assetId, $label);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed> $block
     */
    private function normalizeVideo(array $block): ?StudentContentBlockView
    {
        $provider = $block['provider'] ?? null;
        $id = $block['providerVideoId'] ?? null;
        if (!\is_string($provider) || !\is_string($id) || !VideoUrlParser::isProviderId($provider, $id)) {
            return null;
        }
        $title = $block['title'] ?? '';
        $description = $block['description'] ?? '';
        if (!\is_string($title) || !\is_string($description)) {
            return null;
        }
        if ('' !== trim($title) && !$this->isSafePlainText($title)) {
            return null;
        }
        if ('' !== trim($description) && !$this->isSafePlainText($description)) {
            return null;
        }
        $visibleTitle = '' !== trim($title) ? trim($title) : 'Video';

        return StudentContentBlockView::video($visibleTitle, (new VideoEmbed($provider, $id))->playerUrl(), trim($description));
    }

    private function isSafePlainText(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }
        $text = trim($value);
        if ('' === $text) {
            return false;
        }

        $lower = strtolower($text);
        if (str_contains($text, '<')
            || str_contains($text, '>')
            || str_contains($lower, 'javascript:')
            || str_contains($lower, '<script')
            || str_contains($lower, '<iframe')
            || str_contains($lower, 'data:')
            || preg_match('/\bon\w+\s*=/i', $text)
            || preg_match('#https?://#i', $text)
        ) {
            return false;
        }

        return true;
    }
}
