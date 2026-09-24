<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Enum\GradeLevel;
use App\Exception\CurriculumImportException;

/**
 * Validated pilot curriculum fixture document (TYMM learning outcome slice).
 */
final class CurriculumPilotImportDocument
{
    public const EXPECTED_SCHEMA_VERSION = 1;

    public const EXPECTED_SOURCE_VERSION = 'TYMM-2026';

    /**
     * @param non-empty-string $subjectCode
     * @param non-empty-string $programCode
     * @param non-empty-string $programName
     * @param non-empty-string $programVersion
     * @param non-empty-string $unitCode
     * @param non-empty-string $unitTitle
     * @param non-empty-string $unitOfficialThemeCode
     * @param non-empty-string $topicCode
     * @param non-empty-string $topicTitle
     * @param non-empty-string $outcomeCode
     * @param non-empty-string $outcomeOfficialCode
     * @param non-empty-string $outcomeDescription
     * @param non-empty-string $sourceProgramId
     * @param non-empty-string $sourceProgramUrl
     */
    public function __construct(
        public readonly string $subjectCode,
        public readonly string $programCode,
        public readonly string $programName,
        public readonly string $programVersion,
        public readonly GradeLevel $gradeLevel,
        public readonly string $unitCode,
        public readonly string $unitTitle,
        public readonly string $unitOfficialThemeCode,
        public readonly int $unitPosition,
        public readonly string $topicCode,
        public readonly string $topicTitle,
        public readonly int $topicPosition,
        public readonly string $outcomeCode,
        public readonly string $outcomeOfficialCode,
        public readonly string $outcomeDescription,
        public readonly int $outcomePosition,
        public readonly bool $publish,
        public readonly string $sourceProgramId,
        public readonly string $sourceProgramUrl,
        public readonly ?string $sourcePdfUrl,
        public readonly ?string $sourceTymmThemeUrl,
    ) {
    }

    /**
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $schema = $raw['schema_version'] ?? null;
        if (self::EXPECTED_SCHEMA_VERSION !== $schema) {
            throw CurriculumImportException::invalidInput('Unsupported curriculum fixture schema_version.');
        }

        $source = $raw['source'] ?? null;
        if (!\is_array($source)) {
            throw CurriculumImportException::invalidInput('Fixture source block is required.');
        }
        $sourceVersion = self::requireNonEmptyString($source, 'version', 'source.version');
        if (self::EXPECTED_SOURCE_VERSION !== $sourceVersion) {
            throw CurriculumImportException::invalidInput('Fixture source.version must be TYMM-2026.');
        }

        $program = $raw['program'] ?? null;
        $unit = $raw['unit'] ?? null;
        $topic = $raw['topic'] ?? null;
        $outcome = $raw['outcome'] ?? null;
        if (!\is_array($program) || !\is_array($unit) || !\is_array($topic) || !\is_array($outcome)) {
            throw CurriculumImportException::invalidInput('program/unit/topic/outcome blocks are required.');
        }

        $gradeRaw = $program['grade_level'] ?? null;
        if (!\is_int($gradeRaw) && !(\is_string($gradeRaw) && ctype_digit($gradeRaw))) {
            throw CurriculumImportException::invalidInput('program.grade_level must be an integer.');
        }
        $gradeLevel = GradeLevel::tryFrom((int) $gradeRaw);
        if (!$gradeLevel instanceof GradeLevel) {
            throw CurriculumImportException::invalidInput('program.grade_level is not a supported GradeLevel.');
        }

        $publish = $raw['publish'] ?? true;
        if (!\is_bool($publish)) {
            throw CurriculumImportException::invalidInput('publish must be a boolean.');
        }

        return new self(
            subjectCode: self::requireSnakeCode($raw, 'subject_code', 'subject_code'),
            programCode: self::requireSnakeCode($program, 'code', 'program.code'),
            programName: self::requireNonEmptyString($program, 'name', 'program.name'),
            programVersion: self::requireNonEmptyString($program, 'version', 'program.version'),
            gradeLevel: $gradeLevel,
            unitCode: self::requireSnakeCode($unit, 'code', 'unit.code'),
            unitTitle: self::requireNonEmptyString($unit, 'title', 'unit.title'),
            unitOfficialThemeCode: self::requireNonEmptyString($unit, 'official_theme_code', 'unit.official_theme_code'),
            unitPosition: self::requirePositiveInt($unit, 'position', 'unit.position'),
            topicCode: self::requireSnakeCode($topic, 'code', 'topic.code'),
            topicTitle: self::requireNonEmptyString($topic, 'title', 'topic.title'),
            topicPosition: self::requirePositiveInt($topic, 'position', 'topic.position'),
            outcomeCode: self::requireSnakeCode($outcome, 'code', 'outcome.code'),
            outcomeOfficialCode: self::requireNonEmptyString($outcome, 'official_code', 'outcome.official_code'),
            outcomeDescription: self::requireNonEmptyString($outcome, 'description', 'outcome.description'),
            outcomePosition: self::requirePositiveInt($outcome, 'position', 'outcome.position'),
            publish: $publish,
            sourceProgramId: self::requireNonEmptyString($source, 'program_id', 'source.program_id'),
            sourceProgramUrl: self::requireNonEmptyString($source, 'program_url', 'source.program_url'),
            sourcePdfUrl: self::optionalString($source, 'pdf_url'),
            sourceTymmThemeUrl: self::optionalString($source, 'tymm_theme_url'),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return non-empty-string
     */
    private static function requireNonEmptyString(array $data, string $key, string $label): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw CurriculumImportException::invalidInput($label.' must be a non-empty string.');
        }
        $value = trim($value);
        if ('' === $value) {
            throw CurriculumImportException::invalidInput($label.' must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return non-empty-string
     */
    private static function requireSnakeCode(array $data, string $key, string $label): string
    {
        $value = strtolower(self::requireNonEmptyString($data, $key, $label));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $value) || \strlen($value) < 2 || \strlen($value) > 64) {
            throw CurriculumImportException::invalidInput($label.' must be lowercase snake_case [a-z0-9_]{2,64}.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function requirePositiveInt(array $data, string $key, string $label): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            throw CurriculumImportException::invalidInput($label.' must be a positive integer.');
        }
        $int = (int) $value;
        if ($int < 1) {
            throw CurriculumImportException::invalidInput($label.' must be a positive integer.');
        }

        return $int;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw CurriculumImportException::invalidInput($key.' must be a string when present.');
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
