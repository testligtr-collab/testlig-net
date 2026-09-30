<?php

declare(strict_types=1);

namespace App\Question\Import;

/**
 * Downloadable sample. The example code is rejected by the importer.
 */
final class QuestionCsvTemplate
{
    public const EXAMPLE_CODE = '11111111111141118111111111111111';

    public const BODY = "\xEF\xBB\xBFcode,grade_level,subject_code,learning_outcome_code,stem,option_a,option_b,option_c,option_d,correct_option,explanation\r\n"
        .self::EXAMPLE_CODE.",1,ornek_ders,ornek_kazanim,\"ÖRNEK SATIR — bu satırı silin. Gerçek kazanım metni değildir.\",Örnek A,Örnek B,Örnek C,Örnek D,A,Örnek açıklama.\r\n";

    private function __construct()
    {
    }
}
