<?php

declare(strict_types=1);

namespace App\LearningContent\StudentView;

use App\Dto\StudentContent\StudentContentBlockView;

/**
 * Fail-closed normalizer: sealed revision JSON → student block views.
 *
 * Unknown/malformed/media blocks are skipped (no payload leak). Does not trust
 * Twig callers with raw arrays.
 */
final class StudentContentBlockNormalizer
{
    private const UI_TYPES = [
        StudentContentBlockView::TYPE_HEADING,
        StudentContentBlockView::TYPE_PARAGRAPH,
        StudentContentBlockView::TYPE_LIST,
        StudentContentBlockView::TYPE_QUOTE,
        StudentContentBlockView::TYPE_MATH,
        StudentContentBlockView::TYPE_CALLOUT,
    ];

    private const CALLOUT_VARIANTS = ['info', 'warning', 'tip', 'note'];

    /**
     * @param array<mixed> $structuredContent
     *
     * @return list<StudentContentBlockView>
     */
    public function normalize(array $structuredContent): array
    {
        $blocks = $structuredContent['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }

        $views = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
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
        if (!\is_string($type) || !\in_array($type, self::UI_TYPES, true)) {
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
