<?php

declare(strict_types=1);

namespace App\Question\Import;

interface QuestionCsvImportCatalog
{
    /**
     * @param list<string> $subjectCodes
     * @param list<string> $outcomeCodes
     * @param list<string> $questionCodes
     */
    public function lookup(array $subjectCodes, array $outcomeCodes, array $questionCodes): QuestionCsvImportLookup;
}
