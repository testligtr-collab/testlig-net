<?php

declare(strict_types=1);

namespace App\Question\Import;

final class QuestionCsvMessages
{
    public const CREATE = 'Oluşturulacak.';
    public const SKIP = 'Mevcut kayıt, güncellenmedi.';
    public const CONFLICT = 'Bu kod dosyada yineleniyor.';
    public const EXAMPLE = 'Örnek şablon satırı içe aktarılmaz.';
    public const CODE_REQUIRED = 'Soru kodu zorunludur.';
    public const CODE_INVALID = 'Soru kodu 32 karakterlik küçük onaltılık kimlik olmalıdır.';
    public const GRADE_INVALID = 'Sınıf 1 ile 12 arasında bir tam sayı olmalıdır.';
    public const SUBJECT_REQUIRED = 'Ders kodu zorunludur.';
    public const SUBJECT_INVALID = 'Ders kodu geçerli değil.';
    public const SUBJECT_MISSING = 'Ders kodu bulunamadı.';
    public const SUBJECT_INACTIVE = 'Ders aktif değil.';
    public const OUTCOME_REQUIRED = 'Kazanım kodu zorunludur.';
    public const OUTCOME_INVALID = 'Kazanım kodu geçerli değil.';
    public const OUTCOME_MISSING = 'Kazanım bulunamadı.';
    public const OUTCOME_INACTIVE = 'Kazanım aktif değil.';
    public const OUTCOME_MISMATCH = 'Kazanım seçilen ders ve sınıfla eşleşmiyor.';
    public const OUTCOME_AMBIGUOUS = 'Kazanım kodu birden fazla kayıtla eşleşiyor.';
    public const STEM_REQUIRED = 'Soru kökü zorunludur.';
    public const FIELD_TOO_LONG = 'Alan domain sınırından önce reddedildi.';
    public const MARKUP = 'Metin düz olmalıdır; HTML kabul edilmez.';
    public const OPTION_REQUIRED = 'En az iki seçenek zorunludur.';
    public const OPTION_GAP = 'Seçenekler arasında boşluk bırakılamaz.';
    public const OPTION_DUPLICATE = 'Seçenek metinleri tekrar edemez.';
    public const CORRECT_INVALID = 'Doğru seçenek yalnızca A, B, C veya D olabilir.';
    public const CORRECT_MISSING = 'Doğru seçenek dolu bir seçeneğe karşılık gelmelidir.';
    public const COLUMN_COUNT = 'Satırın sütun sayısı başlıkla eşleşmiyor.';

    public const FILE_EMPTY = 'Dosya boş.';
    public const FILE_ENCODING = 'Dosya geçerli UTF-8 değil.';
    public const FILE_NUL = 'Dosyada geçersiz boş bayt var.';
    public const FILE_BINARY = 'Yalnız CSV kabul edilir.';
    public const FILE_TOO_LARGE = 'Dosya 1 MiB sınırını aşıyor.';
    public const FILE_HEADER = 'Başlık satırı beklenen kolonlarla birebir eşleşmiyor.';
    public const FILE_DELIMITER = 'Ayırıcı virgül veya noktalı virgül olmalı ve dosyada tek tip olmalıdır.';
    public const FILE_TOO_MANY = 'Dosyada en fazla 200 veri satırı olabilir.';
    public const FILE_NO_ROWS = 'Dosyada veri satırı yok.';

    private function __construct()
    {
    }
}
