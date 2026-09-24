<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Exception\CurriculumImportException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class CurriculumPilotImportYamlLoader
{
    public function loadFile(string $absolutePath): CurriculumPilotImportDocument
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw CurriculumImportException::invalidInput('Curriculum fixture file is missing or unreadable.');
        }

        try {
            $raw = Yaml::parseFile($absolutePath);
        } catch (ParseException $e) {
            throw CurriculumImportException::invalidInput('Curriculum fixture YAML is invalid: '.$e->getMessage());
        }

        if (!\is_array($raw)) {
            throw CurriculumImportException::invalidInput('Curriculum fixture root must be a mapping.');
        }

        /** @var array<mixed> $raw */
        return CurriculumPilotImportDocument::fromArray($raw);
    }
}
