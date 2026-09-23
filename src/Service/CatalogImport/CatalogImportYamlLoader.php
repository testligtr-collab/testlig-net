<?php

declare(strict_types=1);

namespace App\Service\CatalogImport;

use App\Dto\CatalogSourceAttribution;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Util\HttpsUrl;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Strict YAML loader for catalog MEB fixtures (unknown keys rejected).
 */
final class CatalogImportYamlLoader
{
    private const ROOT_KEYS = ['schema_version', 'source', 'subject'];
    private const SOURCE_KEYS = ['program_id', 'version', 'program_url', 'pdf_url'];
    private const SUBJECT_KEYS = ['name', 'grade_level', 'position', 'source_code', 'units'];
    private const UNIT_KEYS = ['name', 'source_code', 'occurrence', 'position', 'topics'];
    private const TOPIC_KEYS = ['name', 'position', 'source_code'];

    public function loadFile(string $absolutePath): CatalogImportDocument
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw CatalogException::invalidInput('Fixture dosyası okunamıyor.');
        }
        $raw = file_get_contents($absolutePath);
        if (!\is_string($raw) || '' === trim($raw)) {
            throw CatalogException::invalidInput('Fixture dosyası boş.');
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw CatalogException::invalidInput('YAML ayrıştırılamadı: '.$e->getMessage());
        }
        if (!\is_array($parsed)) {
            throw CatalogException::invalidInput('YAML kökü bir nesne olmalıdır.');
        }

        return $this->hydrate($parsed);
    }

    /**
     * @param array<mixed> $data
     */
    public function hydrate(array $data): CatalogImportDocument
    {
        $this->assertOnlyKeys($data, self::ROOT_KEYS, 'kök');
        $schemaVersion = $data['schema_version'] ?? null;
        if (1 !== $schemaVersion) {
            throw CatalogException::invalidInput('schema_version yalnız 1 desteklenir.');
        }
        $source = $data['source'] ?? null;
        if (!\is_array($source)) {
            throw CatalogException::invalidInput('source zorunludur.');
        }
        $this->assertOnlyKeys($source, self::SOURCE_KEYS, 'source');
        $programId = $this->requireNonEmptyString($source, 'program_id', 16);
        $version = $this->requireNonEmptyString($source, 'version', CatalogSourceAttribution::VERSION_MAX);
        $programUrl = $this->requireHttpsUrl($source, 'program_url');
        $pdfUrl = $this->requireHttpsUrl($source, 'pdf_url');
        if (CatalogImportDocument::EXPECTED_PROGRAM_ID !== $programId) {
            throw CatalogException::invalidInput('program_id beklenen TTKB PID ile eşleşmiyor.');
        }
        if (CatalogImportDocument::EXPECTED_SOURCE_VERSION !== $version) {
            throw CatalogException::invalidInput('source.version beklenen TYMM-2026 olmalıdır.');
        }
        if (CatalogImportDocument::EXPECTED_PROGRAM_URL !== $programUrl) {
            throw CatalogException::invalidInput('program_url beklenen TTKB URL ile eşleşmiyor.');
        }
        if (CatalogImportDocument::EXPECTED_PDF_URL !== $pdfUrl) {
            throw CatalogException::invalidInput('pdf_url beklenen TTKB PDF URL ile eşleşmiyor.');
        }

        $subject = $data['subject'] ?? null;
        if (!\is_array($subject)) {
            throw CatalogException::invalidInput('subject zorunludur.');
        }
        $this->assertOnlyKeys($subject, self::SUBJECT_KEYS, 'subject');
        $subjectName = $this->requireName($subject, 'name', CatalogSubject::NAME_MIN, CatalogSubject::NAME_MAX);
        $gradeRaw = $subject['grade_level'] ?? null;
        if (!\is_int($gradeRaw) || 1 !== $gradeRaw) {
            throw CatalogException::invalidInput('Bu fixture yalnız grade_level=1 destekler.');
        }
        $grade = GradeLevel::from($gradeRaw);
        $subjectPosition = $this->requireNonNegativeInt($subject, 'position');
        $subjectSourceCode = $this->requireSourceCode($subject, 'source_code');
        $unitsRaw = $subject['units'] ?? null;
        if (!\is_array($unitsRaw) || [] === $unitsRaw) {
            throw CatalogException::invalidInput('subject.units boş olamaz.');
        }

        $units = [];
        $seenUnitKeys = [];
        $seenUnitPositions = [];
        foreach ($unitsRaw as $i => $unitRaw) {
            if (!\is_array($unitRaw)) {
                throw CatalogException::invalidInput(\sprintf('units[%s] nesne olmalıdır.', (string) $i));
            }
            $this->assertOnlyKeys($unitRaw, self::UNIT_KEYS, 'unit');
            $unitName = $this->requireName($unitRaw, 'name', CatalogUnit::NAME_MIN, CatalogUnit::NAME_MAX);
            $unitCode = $this->requireSourceCode($unitRaw, 'source_code');
            $occurrence = $this->requireOccurrence($unitRaw);
            $unitPosition = $this->requirePositiveInt($unitRaw, 'position');
            $unitKey = $unitCode.'@'.$occurrence;
            if (isset($seenUnitKeys[$unitKey])) {
                throw CatalogException::invalidInput('Yinelenen ünite kaynak kimliği: '.$unitKey);
            }
            $seenUnitKeys[$unitKey] = true;
            if (isset($seenUnitPositions[$unitPosition])) {
                throw CatalogException::invalidInput('Yinelenen ünite sırası: '.$unitPosition);
            }
            $seenUnitPositions[$unitPosition] = true;

            $topicsRaw = $unitRaw['topics'] ?? null;
            if (!\is_array($topicsRaw) || [] === $topicsRaw) {
                throw CatalogException::invalidInput('Her ünitede en az bir topic zorunludur.');
            }
            $topics = [];
            $seenTopicCodes = [];
            $seenTopicPositions = [];
            foreach ($topicsRaw as $j => $topicRaw) {
                if (!\is_array($topicRaw)) {
                    throw CatalogException::invalidInput(\sprintf('topics[%s] nesne olmalıdır.', (string) $j));
                }
                $this->assertOnlyKeys($topicRaw, self::TOPIC_KEYS, 'topic');
                $topicName = $this->requireName($topicRaw, 'name', CatalogTopic::NAME_MIN, CatalogTopic::NAME_MAX);
                $topicPosition = $this->requirePositiveInt($topicRaw, 'position');
                if (isset($seenTopicPositions[$topicPosition])) {
                    throw CatalogException::invalidInput('Yinelenen konu sırası ünite içinde: '.$topicPosition);
                }
                $seenTopicPositions[$topicPosition] = true;
                $explicitTopicCode = $topicRaw['source_code'] ?? null;
                if (null === $explicitTopicCode) {
                    // Stable fallback when MEB does not assign a code to içerik çerçevesi items:
                    // IC:{unit_code}@{unit_occurrence}:{topic_position}
                    $topicCode = \sprintf('IC:%s@%d:%d', $unitCode, $occurrence, $topicPosition);
                } else {
                    if (!\is_string($explicitTopicCode)) {
                        throw CatalogException::invalidInput('topic.source_code string olmalıdır.');
                    }
                    $topicCode = trim($explicitTopicCode);
                }
                if (\strlen($topicCode) > CatalogSourceAttribution::CODE_MAX) {
                    throw CatalogException::invalidInput('topic source_code çok uzun.');
                }
                if (isset($seenTopicCodes[$topicCode])) {
                    throw CatalogException::invalidInput('Yinelenen konu kaynak kimliği: '.$topicCode);
                }
                $seenTopicCodes[$topicCode] = true;
                $topics[] = new CatalogImportTopicNode($topicName, $topicPosition, $topicCode);
            }
            usort($topics, static fn (CatalogImportTopicNode $a, CatalogImportTopicNode $b): int => $a->position <=> $b->position);
            $units[] = new CatalogImportUnitNode($unitName, $unitCode, $occurrence, $unitPosition, $topics);
        }
        usort($units, static fn (CatalogImportUnitNode $a, CatalogImportUnitNode $b): int => $a->position <=> $b->position);
        $expectedPositions = range(1, \count($units));
        $actualPositions = array_map(static fn (CatalogImportUnitNode $u): int => $u->position, $units);
        if ($expectedPositions !== $actualPositions) {
            throw CatalogException::invalidInput('Ünite position değerleri 1..N ardışık olmalıdır (işleniş sırası).');
        }

        return new CatalogImportDocument(
            $programId,
            $version,
            $programUrl,
            $pdfUrl,
            $subjectName,
            $grade,
            $subjectPosition,
            $subjectSourceCode,
            $units,
        );
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(array $data, array $allowed, string $label): void
    {
        foreach (array_keys($data) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowed, true)) {
                throw CatalogException::invalidInput(\sprintf('Bilinmeyen alan (%s): %s', $label, (string) $key));
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function requireNonEmptyString(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw CatalogException::invalidInput($key.' string zorunludur.');
        }
        $trimmed = trim($value);
        if ('' === $trimmed || \strlen($trimmed) > $max) {
            throw CatalogException::invalidInput($key.' geçersiz uzunlukta.');
        }

        return $trimmed;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireHttpsUrl(array $data, string $key): string
    {
        $value = $this->requireNonEmptyString($data, $key, CatalogSourceAttribution::URL_MAX);
        if (!HttpsUrl::isValid($value, CatalogSourceAttribution::URL_MAX)) {
            throw CatalogException::invalidInput($key.' https URL olmalıdır.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireName(array $data, string $key, int $min, int $max): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw CatalogException::invalidInput($key.' string zorunludur.');
        }
        $trimmed = trim($value);
        $len = mb_strlen($trimmed, 'UTF-8');
        if ($len < $min || $len > $max) {
            throw CatalogException::invalidInput(\sprintf('%s uzunluğu %d–%d olmalıdır.', $key, $min, $max));
        }

        return $trimmed;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireSourceCode(array $data, string $key): string
    {
        $value = $this->requireNonEmptyString($data, $key, CatalogSourceAttribution::CODE_MAX);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@#-]*$/', $value)) {
            throw CatalogException::invalidInput($key.' biçimi geçersiz.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireOccurrence(array $data): int
    {
        $value = $data['occurrence'] ?? null;
        if (!\is_int($value) || $value < CatalogSourceAttribution::OCCURRENCE_MIN || $value > CatalogSourceAttribution::OCCURRENCE_MAX) {
            throw CatalogException::invalidInput('occurrence pozitif tamsayı olmalıdır.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function requireNonNegativeInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) || $value < 0) {
            throw CatalogException::invalidInput($key.' sıfır veya pozitif tamsayı olmalıdır.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function requirePositiveInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) || $value < 1) {
            throw CatalogException::invalidInput($key.' pozitif tamsayı olmalıdır.');
        }

        return $value;
    }
}
