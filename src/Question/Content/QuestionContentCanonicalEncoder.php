<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Canonical JSON encoder for content hashing (recursive ksort).
 */
final class QuestionContentCanonicalEncoder
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public function encode(array $data): string
    {
        return json_encode($this->canonicalize($data), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if (!$isList) {
            ksort($value);
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }

        return $out;
    }
}
