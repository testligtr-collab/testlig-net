<?php

declare(strict_types=1);

namespace App\Question\Import;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Short-lived server-side import plan. The raw CSV is not stored in the
 * session or the database. Question text lives only in this private file
 * until apply or expiry, then the file is removed.
 *
 * @phpstan-type StoredRecord array{line: int, values: array<string, string>, column_error: bool}
 */
final class QuestionCsvImportPlanStore
{
    private const TTL_SECONDS = 900;

    private readonly string $directory;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly ClockInterface $clock,
    ) {
        $this->directory = $projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'question-imports';
    }

    /**
     * @param list<StoredRecord> $records
     * @param list<string>       $createCodes question codes the preview promised to create
     */
    public function save(string $ownerId, string $contentDigest, string $decisionDigest, array $records, array $createCodes): string
    {
        $this->ensureDirectory();
        $this->purgeExpired();
        $id = bin2hex(random_bytes(32));
        $payload = [
            'owner_id' => $ownerId,
            'content_digest' => $contentDigest,
            'decision_digest' => $decisionDigest,
            'expires_at' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
            'records' => $records,
            'create_codes' => $createCodes,
        ];
        $this->write($this->path($id), $payload);

        return $id;
    }

    /**
     * @return array{
     *     id: string,
     *     owner_id: string,
     *     content_digest: string,
     *     decision_digest: string,
     *     expires_at: int,
     *     records: list<StoredRecord>,
     *     create_codes: list<string>
     * }
     */
    public function load(string $id, string $ownerId): array
    {
        $this->assertId($id);
        $this->purgeExpired($id);
        if (is_file($this->consumedPath($id))) {
            throw new QuestionCsvImportException(QuestionCsvImportException::CONSUMED, 'Bu önizleme zaten kullanıldı.');
        }
        $path = $this->path($id);
        if (!is_file($path)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
        $payload = $this->read($path);
        if (!hash_equals($payload['record_digest'], hash('sha256', $this->canonical($payload['records'], $payload['create_codes'])))) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
        if (\strlen($payload['owner_id']) !== \strlen($ownerId) || !hash_equals($payload['owner_id'], $ownerId)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
        if ($payload['expires_at'] <= $this->clock->now()->getTimestamp()) {
            $this->delete($path);
            throw new QuestionCsvImportException(QuestionCsvImportException::EXPIRED, 'Önizlemenin süresi doldu. Dosyayı yeniden yükleyin.');
        }

        return [
            'id' => $id,
            'owner_id' => $payload['owner_id'],
            'content_digest' => $payload['content_digest'],
            'decision_digest' => $payload['decision_digest'],
            'expires_at' => $payload['expires_at'],
            'records' => $payload['records'],
            'create_codes' => $payload['create_codes'],
        ];
    }

    public function consume(string $id, string $ownerId): void
    {
        $this->assertId($id);
        $loaded = $this->load($id, $ownerId);
        $this->delete($this->path($id));
        $this->write($this->consumedPath($id), [
            'owner_id' => $ownerId,
            'expires_at' => $loaded['expires_at'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $path, array $payload): void
    {
        if (isset($payload['records'], $payload['create_codes'])) {
            /** @var list<array{line: int, values: array<string, string>, column_error: bool}> $records */
            $records = $payload['records'];
            /** @var list<string> $createCodes */
            $createCodes = $payload['create_codes'];
            $payload['record_digest'] = hash('sha256', $this->canonical($records, $createCodes));
        }
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $tmp = $path.'.'.bin2hex(random_bytes(4));
        if (false === file_put_contents($tmp, $json, \LOCK_EX)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme saklanamadı.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme saklanamadı.');
        }
    }

    /**
     * @return array{owner_id: string, content_digest: string, decision_digest: string, expires_at: int, records: list<array{line: int, values: array<string, string>, column_error: bool}>, create_codes: list<string>, record_digest: string}
     */
    private function read(string $path): array
    {
        $raw = file_get_contents($path);
        if (!\is_string($raw)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
        if (!\is_array($decoded)
            || !isset($decoded['owner_id'], $decoded['content_digest'], $decoded['decision_digest'], $decoded['expires_at'], $decoded['records'], $decoded['create_codes'], $decoded['record_digest'])
            || !\is_string($decoded['owner_id'])
            || !\is_string($decoded['content_digest'])
            || !\is_string($decoded['decision_digest'])
            || !\is_int($decoded['expires_at'])
            || !\is_string($decoded['record_digest'])
            || !\is_array($decoded['records'])
            || !\is_array($decoded['create_codes'])
        ) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }

        /** @var list<array{line: int, values: array<string, string>, column_error: bool}> $records */
        $records = [];
        foreach ($decoded['records'] as $record) {
            if (!\is_array($record) || !isset($record['line'], $record['values'], $record['column_error']) || !\is_int($record['line']) || !\is_array($record['values']) || !\is_bool($record['column_error'])) {
                throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
            }
            $values = [];
            foreach (QuestionCsvParser::HEADERS as $header) {
                $cell = $record['values'][$header] ?? null;
                if (!\is_string($cell)) {
                    throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
                }
                $values[$header] = $cell;
            }
            $records[] = ['line' => $record['line'], 'values' => $values, 'column_error' => $record['column_error']];
        }
        $createCodes = [];
        foreach ($decoded['create_codes'] as $code) {
            if (!\is_string($code) || 1 !== preg_match('/^[a-f0-9]{32}$/', $code)) {
                throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
            }
            $createCodes[] = $code;
        }

        return [
            'owner_id' => $decoded['owner_id'],
            'content_digest' => $decoded['content_digest'],
            'decision_digest' => $decoded['decision_digest'],
            'expires_at' => $decoded['expires_at'],
            'records' => $records,
            'create_codes' => $createCodes,
            'record_digest' => $decoded['record_digest'],
        ];
    }

    /**
     * @param list<array{line: int, values: array<string, string>, column_error: bool}> $records
     * @param list<string>                                                              $createCodes
     */
    private function canonical(array $records, array $createCodes): string
    {
        return json_encode(['records' => $records, 'create_codes' => $createCodes], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function purgeExpired(?string $keepId = null): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $now = $this->clock->now()->getTimestamp();
        $paths = glob($this->directory.\DIRECTORY_SEPARATOR.'*');
        if (!\is_array($paths)) {
            return;
        }
        foreach ($paths as $path) {
            if (!is_file($path) || (null !== $keepId && str_contains(basename($path), $keepId))) {
                continue;
            }
            $raw = file_get_contents($path);
            if (!\is_string($raw)) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                @unlink($path);
                continue;
            }
            if (\is_array($decoded) && isset($decoded['expires_at']) && \is_int($decoded['expires_at']) && $decoded['expires_at'] <= $now) {
                @unlink($path);
            }
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme saklanamadı.');
        }
    }

    private function assertId(string $id): void
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new QuestionCsvImportException(QuestionCsvImportException::PLAN, 'Önizleme bulunamadı.');
        }
    }

    private function path(string $id): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$id.'.json';
    }

    private function consumedPath(string $id): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$id.'.consumed.json';
    }

    private function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
