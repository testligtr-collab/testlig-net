<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Exception\LearningContentException;
use App\Exception\LearningContentPackageException;
use App\LearningContent\Content\LearningContentDocument;
use App\LearningContent\Content\LearningContentDocumentValidator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads lesson.yaml and validates it with the typed-block rules.
 * The fixture bytes are the source of truth. This class does not rewrite them.
 */
final class LearningContentPackageDocument
{
    public function __construct(
        private readonly LearningContentDocumentValidator $validator,
    ) {
    }

    public function load(string $projectDir, LearningContentPackageTarget $target): LoadedLearningContentPackage
    {
        $lessonPath = $projectDir.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $target->directory)
            .\DIRECTORY_SEPARATOR.'lesson.yaml';
        if (!is_file($lessonPath)) {
            throw LearningContentPackageException::rejected();
        }

        $bytes = file_get_contents($lessonPath);
        if (!\is_string($bytes) || '' === $bytes) {
            throw LearningContentPackageException::rejected();
        }

        try {
            $parsed = Yaml::parse($bytes);
        } catch (ParseException) {
            throw LearningContentPackageException::rejected();
        }
        if (!\is_array($parsed)) {
            throw LearningContentPackageException::rejected();
        }

        /** @var array<string, mixed> $raw */
        $raw = $parsed;
        $this->assertIdentity($raw, $target);
        $document = $this->documentFrom($raw, $target);

        return new LoadedLearningContentPackage(
            $target,
            $document,
            hash('sha256', $bytes),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function assertIdentity(array $raw, LearningContentPackageTarget $target): void
    {
        $keys = array_keys($raw);
        sort($keys);
        $allowed = $target->allowedYamlKeys;
        sort($allowed);
        if ($keys !== $allowed) {
            throw LearningContentPackageException::rejected();
        }

        if (1 !== $raw['schema_version'] || 'draft' !== $raw['status']) {
            throw LearningContentPackageException::rejected();
        }
        if ($raw['package'] !== $target->packageSlug
            || $raw['subject_code'] !== $target->subjectCode
            || $raw['grade_level'] !== $target->gradeLevel
            || $raw['program_code'] !== $target->programCode
            || $raw['program_version'] !== $target->programVersion
            || (string) $raw['source_program_id'] !== $target->sourceProgramId
            || $raw['outcome_code'] !== $target->outcomeCode
            || $raw['official_code'] !== $target->officialCode
            || $raw['summary'] !== $target->summary
        ) {
            throw LearningContentPackageException::rejected();
        }

        foreach (['official_title', 'theme_title', 'curriculum_topic_code', 'catalog_topic_name', 'catalog_topic_slug', 'catalog_unit_slug'] as $key) {
            if (!\is_string($raw[$key]) || '' === trim($raw[$key])) {
                throw LearningContentPackageException::rejected();
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function documentFrom(array $raw, LearningContentPackageTarget $target): LearningContentDocument
    {
        $document = $raw['document'] ?? null;
        if (!\is_array($document)) {
            throw LearningContentPackageException::rejected();
        }

        /* @var array<string, mixed> $document */
        try {
            $loaded = LearningContentDocument::fromArray($document);
        } catch (\InvalidArgumentException) {
            throw LearningContentPackageException::rejected();
        }

        $blocks = $loaded->blocks;
        if (\count($blocks) !== \count($target->expectedBlocks)) {
            throw LearningContentPackageException::rejected();
        }

        foreach ($target->expectedBlocks as $index => $expected) {
            $block = $blocks[$index];
            if (($block['type'] ?? null) !== $expected['type']) {
                throw LearningContentPackageException::rejected();
            }
            if (isset($expected['variant']) && ($block['variant'] ?? null) !== $expected['variant']) {
                throw LearningContentPackageException::rejected();
            }
            if (isset($expected['items']) && (!\is_array($block['items'] ?? null) || \count($block['items']) !== $expected['items'])) {
                throw LearningContentPackageException::rejected();
            }
            $this->assertNoMarkup($block);
        }

        $first = $blocks[0];
        if (($first['text'] ?? null) !== $target->title || ($first['level'] ?? null) !== 2) {
            throw LearningContentPackageException::rejected();
        }

        try {
            $this->validator->validate($loaded);
        } catch (LearningContentException) {
            throw LearningContentPackageException::rejected();
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertNoMarkup(array $value): void
    {
        foreach ($value as $item) {
            if (\is_string($item)) {
                if (str_contains($item, '{{') || str_contains($item, '{%') || str_contains($item, '{#')) {
                    throw LearningContentPackageException::rejected();
                }

                continue;
            }
            if (\is_array($item)) {
                /* @var array<string, mixed> $item */
                $this->assertNoMarkup($item);
            }
        }
    }
}
