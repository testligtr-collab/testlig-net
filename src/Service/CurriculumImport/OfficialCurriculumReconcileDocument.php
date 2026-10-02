<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Exception\CurriculumImportException;

/**
 * Closed fixture for the published TYMM-2026 grade-1 Matematik program.
 *
 * @phpstan-type OutcomeSpec array{position: int, code: string, officialCode: string, description: string}
 * @phpstan-type TopicSpec array{position: int, code: string, title: string, outcome: OutcomeSpec}
 * @phpstan-type ThemeSpec array{position: int, code: string, officialThemeCode: string, occurrence: int, title: string, tymmUnitId: int, tymmUrl: string, topics: list<TopicSpec>}
 */
final class OfficialCurriculumReconcileDocument
{
    public const SUBJECT_CODE = 'matematik';
    public const PROGRAM_CODE = 'mat_grade1_tymm';
    public const SOURCE_VERSION = 'TYMM-2026';
    public const PROGRAM_ID = '2339';
    public const PDF_SHA256 = '249D9FF5A9D7AAB3BE853F112C395A29A776DECE302D4A474EA23B2453A82FF0';
    public const PDF_BYTES = 3772188;
    public const EXPECTED_OUTCOMES = 19;
    public const EXPECTED_THEMES = 7;
    public const PILOT_UNIT_CODE = 'mat_1_3_occ1';
    public const PILOT_TOPIC_CODE = 'uzamsal_iliskiler';
    public const PILOT_OUTCOME_CODE = 'mat_1_3_1';
    public const PILOT_OFFICIAL_CODE = 'MAT.1.3.1';
    public const PILOT_DESCRIPTION = 'Hedefe ulaşmak için mesafeleri ve yönleri içeren yönergeleri çözümleyebilme';
    public const PILOT_THEME_POSITION = 5;
    public const FIXTURE_SHA256 = 'ffc4ce9cb8b54639da30cec8e78f2d59de983b112cee23f341c4fc91512fbc02';

    /**
     * @param list<ThemeSpec> $themes
     */
    private function __construct(
        public readonly string $subjectCode,
        public readonly string $programCode,
        public readonly string $programVersion,
        public readonly int $gradeLevel,
        /** @var list<ThemeSpec> */
        public readonly array $themes,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $source = self::mapping($raw, 'source');
        self::same($source, 'program_id', self::PROGRAM_ID);
        self::same($source, 'version', self::SOURCE_VERSION);
        self::same($source, 'pdf_sha256', self::PDF_SHA256);
        if (self::PDF_BYTES !== ($source['pdf_bytes'] ?? null)) {
            throw CurriculumImportException::invalidInput('Official PDF byte length does not match.');
        }
        if ('2026-10-02' !== ($source['verified_on'] ?? null)) {
            throw CurriculumImportException::invalidInput('Official verification date does not match.');
        }
        foreach (['program_url', 'pdf_url', 'tymm_url'] as $key) {
            $url = $source[$key] ?? null;
            if (!\is_string($url) || !str_starts_with($url, 'https://')) {
                throw CurriculumImportException::invalidInput('Official source URL is missing.');
            }
        }

        if (self::SUBJECT_CODE !== ($raw['subject_code'] ?? null)) {
            throw CurriculumImportException::invalidInput('Official reconcile subject is not matematik.');
        }
        if (self::EXPECTED_OUTCOMES !== ($raw['expected_outcome_count'] ?? null)) {
            throw CurriculumImportException::invalidInput('Official reconcile expected outcome count is not 19.');
        }
        $program = self::mapping($raw, 'program');
        self::same($program, 'code', self::PROGRAM_CODE);
        self::same($program, 'version', self::SOURCE_VERSION);
        if (1 !== ($program['grade_level'] ?? null)) {
            throw CurriculumImportException::invalidInput('Official reconcile grade is not 1.');
        }

        $themes = self::themes($raw['themes'] ?? null);
        $document = new self(self::SUBJECT_CODE, self::PROGRAM_CODE, self::SOURCE_VERSION, 1, $themes);
        $document->assertPilotRow();

        return $document;
    }

    public function assertPilotRow(): void
    {
        $found = false;
        foreach ($this->themes as $theme) {
            if (self::PILOT_UNIT_CODE !== $theme['code']) {
                continue;
            }
            if (self::PILOT_THEME_POSITION !== $theme['position'] || 'Nesnelerin Geometrisi (1)' !== $theme['title']) {
                throw CurriculumImportException::invalidInput('Pilot theme identity does not match the official fixture.');
            }
            foreach ($theme['topics'] as $topic) {
                if (self::PILOT_TOPIC_CODE !== $topic['code']) {
                    continue;
                }
                $outcome = $topic['outcome'];
                if (self::PILOT_OUTCOME_CODE !== $outcome['code']
                    || self::PILOT_OFFICIAL_CODE !== $outcome['officialCode']
                    || self::PILOT_DESCRIPTION !== $outcome['description']) {
                    throw CurriculumImportException::invalidInput('Pilot outcome text does not match the official fixture.');
                }
                $found = true;
            }
        }
        if (!$found) {
            throw CurriculumImportException::invalidInput('Pilot outcome is missing from the official fixture.');
        }
    }

    /**
     * @return list<OutcomeSpec>
     */
    public function outcomes(): array
    {
        $rows = [];
        foreach ($this->themes as $theme) {
            foreach ($theme['topics'] as $topic) {
                $rows[] = $topic['outcome'];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function mapping(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if (!\is_array($value)) {
            throw CurriculumImportException::invalidInput('Official fixture is missing '.$key.'.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function same(array $raw, string $key, string $expected): void
    {
        if ($expected !== ($raw[$key] ?? null)) {
            throw CurriculumImportException::invalidInput('Official fixture '.$key.' does not match the closed target.');
        }
    }

    /**
     * @return list<ThemeSpec>
     */
    private static function themes(mixed $raw): array
    {
        if (!\is_array($raw) || self::EXPECTED_THEMES !== \count($raw)) {
            throw CurriculumImportException::invalidInput('Official fixture must contain 7 themes.');
        }
        $themes = [];
        $positions = [];
        $codes = [];
        $occurrences = [];
        $outcomeCodes = [];
        $officialCodes = [];
        foreach ($raw as $themeRaw) {
            if (!\is_array($themeRaw)) {
                throw CurriculumImportException::invalidInput('Official theme row is invalid.');
            }
            $position = self::position($themeRaw['position'] ?? null);
            $code = self::code($themeRaw['code'] ?? null, 'Theme code');
            $official = self::themeCode($themeRaw['official_theme_code'] ?? null);
            $occurrence = self::position($themeRaw['occurrence'] ?? null);
            $title = self::text($themeRaw['title'] ?? null, 180);
            $unitId = $themeRaw['tymm_unit_id'] ?? null;
            $url = $themeRaw['tymm_url'] ?? null;
            if (!\is_int($unitId) || $unitId < 1 || !\is_string($url) || !str_starts_with($url, 'https://tymm.meb.gov.tr/')) {
                throw CurriculumImportException::invalidInput('Official theme source page is missing.');
            }
            if (isset($positions[$position]) || isset($codes[$code])) {
                throw CurriculumImportException::invalidInput('Official theme position or code is duplicated.');
            }
            $pair = $official.'#'.$occurrence;
            if (isset($occurrences[$pair])) {
                throw CurriculumImportException::invalidInput('Official theme occurrence is duplicated.');
            }
            $positions[$position] = true;
            $codes[$code] = true;
            $occurrences[$pair] = true;
            $topics = self::topics($themeRaw['topics'] ?? null, $outcomeCodes, $officialCodes);
            $themes[] = [
                'position' => $position,
                'code' => $code,
                'officialThemeCode' => $official,
                'occurrence' => $occurrence,
                'title' => $title,
                'tymmUnitId' => $unitId,
                'tymmUrl' => $url,
                'topics' => $topics,
            ];
        }
        if (self::EXPECTED_OUTCOMES !== \count($outcomeCodes)) {
            throw CurriculumImportException::invalidInput('Official fixture outcome count is not 19.');
        }
        $seen = array_keys($positions);
        sort($seen);
        if ($seen !== range(1, self::EXPECTED_THEMES)) {
            throw CurriculumImportException::invalidInput('Official theme positions must be 1 through 7.');
        }

        usort($themes, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return $themes;
    }

    /**
     * @param array<string, true> $outcomeCodes
     * @param array<string, true> $officialCodes
     *
     * @return list<TopicSpec>
     */
    private static function topics(mixed $raw, array &$outcomeCodes, array &$officialCodes): array
    {
        if (!\is_array($raw) || [] === $raw) {
            throw CurriculumImportException::invalidInput('Official theme has no topics.');
        }
        $topics = [];
        $positions = [];
        $codes = [];
        foreach ($raw as $topicRaw) {
            if (!\is_array($topicRaw)) {
                throw CurriculumImportException::invalidInput('Official topic row is invalid.');
            }
            $position = self::position($topicRaw['position'] ?? null);
            $code = self::code($topicRaw['code'] ?? null, 'Topic code');
            if (isset($positions[$position]) || isset($codes[$code])) {
                throw CurriculumImportException::invalidInput('Official topic position or code is duplicated.');
            }
            $positions[$position] = true;
            $codes[$code] = true;
            $outcome = self::outcome($topicRaw['outcome'] ?? null, $outcomeCodes, $officialCodes);
            $topics[] = [
                'position' => $position,
                'code' => $code,
                'title' => self::text($topicRaw['title'] ?? null, 180),
                'outcome' => $outcome,
            ];
        }
        usort($topics, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return $topics;
    }

    /**
     * @param array<string, true> $outcomeCodes
     * @param array<string, true> $officialCodes
     *
     * @return OutcomeSpec
     */
    private static function outcome(mixed $raw, array &$outcomeCodes, array &$officialCodes): array
    {
        if (!\is_array($raw)) {
            throw CurriculumImportException::invalidInput('Official outcome row is invalid.');
        }
        $code = self::code($raw['code'] ?? null, 'Outcome code');
        $official = $raw['official_code'] ?? null;
        if (!\is_string($official) || 1 !== preg_match('/^MAT\.1\.[1-4]\.[1-9]$/', $official)) {
            throw CurriculumImportException::invalidInput('Official outcome code format is invalid.');
        }
        $domain = str_replace('.', '_', strtolower($official));
        if ($domain !== $code) {
            throw CurriculumImportException::invalidInput('Domain code does not match the official outcome code.');
        }
        if (isset($outcomeCodes[$code]) || isset($officialCodes[$official])) {
            throw CurriculumImportException::invalidInput('Official outcome code is duplicated.');
        }
        $outcomeCodes[$code] = true;
        $officialCodes[$official] = true;

        return [
            'position' => self::position($raw['position'] ?? null),
            'code' => $code,
            'officialCode' => $official,
            'description' => self::text($raw['description'] ?? null, 500),
        ];
    }

    private static function position(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw CurriculumImportException::invalidInput('Official position must be a positive integer.');
        }

        return $value;
    }

    private static function code(mixed $value, string $label): string
    {
        if (!\is_string($value) || 1 !== preg_match('/^[a-z0-9_]{2,64}$/', $value)) {
            throw CurriculumImportException::invalidInput($label.' must be lowercase snake_case.');
        }

        return $value;
    }

    private static function themeCode(mixed $value): string
    {
        if (!\is_string($value) || 1 !== preg_match('/^MAT\.1\.[1-4]$/', $value)) {
            throw CurriculumImportException::invalidInput('Official theme code is invalid.');
        }

        return $value;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!\is_string($value)) {
            throw CurriculumImportException::invalidInput('Official text is missing.');
        }
        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ('' === $text || mb_strlen($text) > $max) {
            throw CurriculumImportException::invalidInput('Official text is empty or too long.');
        }

        return $text;
    }
}
