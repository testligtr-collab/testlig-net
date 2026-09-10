<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

/**
 * Canonical JSON encoding for attempt answer plaintext and AAD.
 */
final class AttemptAnswerCanonicalizer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        $normalized = $this->normalize($payload);
        try {
            return json_encode(
                $normalized,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Unable to canonicalize attempt answer payload.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Unable to decode attempt answer payload.', 0, $e);
        }
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Attempt answer payload must decode to an object.');
        }

        /* @var array<string, mixed> $decoded */
        return $this->normalize($decoded);
    }

    public function buildAssociatedData(
        string $attemptId,
        string $attemptItemId,
        string $userId,
        int $encryptionVersion,
    ): string {
        return $this->encode([
            'attemptId' => $attemptId,
            'attemptItemId' => $attemptItemId,
            'encryptionVersion' => $encryptionVersion,
            'userId' => $userId,
        ]);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalize(array $value): array
    {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = \is_array($item) ? $this->normalize($item) : $item;
            }

            return $out;
        }

        $keys = array_keys($value);
        sort($keys, \SORT_STRING);
        $out = [];
        foreach ($keys as $key) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('Canonical payload keys must be strings.');
            }
            $item = $value[$key];
            $out[$key] = \is_array($item) ? $this->normalize($item) : $item;
        }

        return $out;
    }
}
