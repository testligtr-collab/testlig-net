<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Exception\CurriculumImportException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class OfficialCurriculumReconcileYamlLoader
{
    public function loadFile(string $absolutePath): OfficialCurriculumReconcileDocument
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw CurriculumImportException::invalidInput('Official curriculum fixture is missing or unreadable.');
        }
        $hash = hash_file('sha256', $absolutePath);
        if (false === $hash || OfficialCurriculumReconcileDocument::FIXTURE_SHA256 !== $hash) {
            throw CurriculumImportException::invalidInput('Official curriculum fixture checksum does not match.');
        }

        try {
            $raw = Yaml::parseFile($absolutePath);
        } catch (ParseException) {
            throw CurriculumImportException::invalidInput('Official curriculum fixture YAML is invalid.');
        }
        if (!\is_array($raw)) {
            throw CurriculumImportException::invalidInput('Official curriculum fixture root must be a mapping.');
        }

        return OfficialCurriculumReconcileDocument::fromArray($raw);
    }
}
