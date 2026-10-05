# Soru paketi içe aktarma

Bu yol yalnız depodaki onaylı `questions.csv` fixture’ları içindir. UI CSV importer’ın yerine geçen genel bir upload API değildir. Tarayıcı dosya seçicisi veya credential koruması gevşetilmez.

Komut: `app:question:import-package`. Aktör e-postası yalnız `TESTLIG_CONTENT_ACTOR_EMAIL` ortam değişkeninden okunur.

## Allowlist

Şu an kabul edilen tek paket:

`data/content/tymm-2026/grade-1/matematik/mat-1-3-3`

Fixture SHA-256, beş soru kodu, `C-A-D-B-C` cevap dizisi, `matematik` / sınıf `1` / `mat_1_3_3` ve UTF-8 BOM koda sabittir. CSV veya checksum değişirse import fail-closed durur.

## Modlar

1. `verify` (varsayılan): paket, aktör, subject, outcome ve CSV kimliğini kontrol eder. Yazmaz.
2. `dry-run`: planı transaction içinde hesaplar, rollback eder. `plan_fingerprint` basar.
3. `apply`: `--expected-plan-fingerprint` 64 hex olmalı ve yeniden hesaplanan parmak iziyle birebir eşleşmelidir. Yalnız eksik kodlar için Draft `Question` + Draft revision + ayrı `QuestionAnswerKey` oluşturur.

İkinci apply, beş kod da özdeş Draft ise noop’tur. Duplicate satır yazılmaz. Mevcut soru üzerine overwrite yoktur. Hard delete yoktur.

## SoD

Import review submit, seal veya publish yapmaz. Assessment / test item bağlamaz. Öğrenci attempt yazmaz. İnceleme ve yayın ayrı adımlardır.

Puan alanı soru satırında yoktur; paket her soruyu assessment’a bağlanırken 1 puan olarak tasarlar. Domain tipi `single_choice`’tır.

## Production

Ops workflow: `.github/workflows/ops-question-package-import.yml`. Deploy bu importu çalıştırmaz. Production verify / dry-run / apply ayrı açık onay ister. Secret stdin `read` protokolü, tek `ssh testlig-vds`, `ConnectionAttempts=1`, concurrency `testlig-vds-ssh`.

## Audit

Başarılı apply `questions_bulk_imported` yazar: `package_key`, `fixture_checksum`, `operation`, `question_count`, `created_count`, `source`, `reason_code`. `QuestionManager` ayrıca her oluşturulan soru için `question_created` yazar. E-posta, kök metin, seçenek, açıklama, doğru seçenek, answer key, UUID ve secret yazılmaz.
