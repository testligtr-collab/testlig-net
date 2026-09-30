<?php

declare(strict_types=1);

namespace App\Question\Import;

/**
 * UTF-8 CSV reader. Uses fgetcsv / str_getcsv; it does not split fields by hand.
 *
 * @phpstan-type CsvRecord array{line: int, values: array<string, string>, column_error: bool}
 */
final class QuestionCsvParser
{
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 200;
    public const MAX_FIELD_CHARS = 4000;

    /**
     * @var list<string>
     */
    public const HEADERS = [
        'code',
        'grade_level',
        'subject_code',
        'learning_outcome_code',
        'stem',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
        'explanation',
    ];

    /**
     * @return list<CsvRecord>
     */
    public function parse(string $bytes): array
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_TOO_LARGE);
        }
        if (str_contains($bytes, "\0")) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_NUL);
        }
        if (str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "\xD0\xCF\x11\xE0")) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_BINARY);
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if ('' === $bytes || '' === trim($bytes)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_EMPTY);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_ENCODING);
        }
        $trimmed = ltrim($bytes);
        if (str_starts_with($trimmed, '<')) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_BINARY);
        }

        $delimiter = $this->delimiter($bytes);
        $handle = fopen('php://temp', 'rb+');
        if (false === $handle) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_BINARY);
        }
        fwrite($handle, $bytes);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!\is_array($header) || self::HEADERS !== $header) {
            fclose($handle);
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_HEADER);
        }

        $records = [];
        $line = 1;
        while (false !== ($row = fgetcsv($handle, 0, $delimiter, '"', '\\'))) {
            ++$line;
            if ($this->isBlank($row)) {
                continue;
            }
            if (\count($records) >= self::MAX_ROWS) {
                fclose($handle);
                throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_TOO_MANY);
            }
            $values = [];
            if (\count($row) !== \count(self::HEADERS)) {
                $values = array_fill_keys(self::HEADERS, '');
                $codeCell = $row[0] ?? null;
                $values['code'] = \is_string($codeCell) ? trim($codeCell) : '';
                $records[] = ['line' => $line, 'values' => $values, 'column_error' => true];
                continue;
            }
            foreach (self::HEADERS as $index => $name) {
                $cell = $row[$index];
                $values[$name] = \is_string($cell) ? trim($cell) : '';
            }
            $records[] = ['line' => $line, 'values' => $values, 'column_error' => false];
        }
        fclose($handle);

        if ([] === $records) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_NO_ROWS);
        }

        return $records;
    }

    /**
     * MAT.1.3.1 and mat_1_3_1 both become the stored snake_case code.
     * Names and slugs are not accepted.
     */
    public function normalizeCatalogCode(string $raw): ?string
    {
        $value = trim($raw);
        if ('' === $value || str_contains($value, ' ') || preg_match('/[<>]/', $value)) {
            return null;
        }
        $value = strtolower(str_replace(['.', '-'], '_', $value));
        if (1 !== preg_match('/^[a-z0-9_]{2,64}$/', $value)) {
            return null;
        }

        return $value;
    }

    public function normalizeQuestionCode(string $raw): ?string
    {
        $value = strtolower(trim($raw));
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $value)) {
            return null;
        }
        $formatted = substr($value, 0, 8).'-'.substr($value, 8, 4).'-'.substr($value, 12, 4).'-'.substr($value, 16, 4).'-'.substr($value, 20);
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($formatted);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $code = str_replace('-', '', $uuid->toRfc4122());

        return $code === $value ? $value : null;
    }

    private function delimiter(string $bytes): string
    {
        $line = preg_split("/\r\n|\n|\r/", $bytes, 2)[0] ?? '';
        $comma = str_getcsv($line, ',', '"', '\\');
        $semicolon = str_getcsv($line, ';', '"', '\\');
        $commaOk = self::HEADERS === $comma;
        $semicolonOk = self::HEADERS === $semicolon;
        if ($commaOk && $semicolonOk) {
            throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_DELIMITER);
        }
        if ($commaOk) {
            return ',';
        }
        if ($semicolonOk) {
            return ';';
        }

        throw new QuestionCsvImportException(QuestionCsvImportException::FILE, QuestionCsvMessages::FILE_HEADER);
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (\is_string($cell) && '' !== trim($cell)) {
                return false;
            }
        }

        return true;
    }
}
