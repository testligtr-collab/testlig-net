# M18 ClamAV soket grubu — sonraki operasyon planı

Bu belge 18 Ekim 2026 ölçümünün kaydıdır ve henüz uygulanmamış bir operasyon planıdır. Ölçüm: https://github.com/testligtr-collab/testlig-net/actions/runs/39144577065 SHA `3aa786cc9dcfaac1f2205149d1ede18c60aec63a`. Bu plan workflow, grup, ACL, env, OLS, servis veya tarama değiştirmez.

## Ölçülen durum

Site hesabının UID, ad ve sahip eşleşmeleri yes. `identity_command_status=0`, `identity_helper_started=yes`, `identity_failure=none`. POSIX `process_euid=5002`, `process_egid=5002`, `process_group=5002`, `process_identity_match=yes`. `access_probe_core=disabled`. `identity_caller_continues=yes`.

Soket `/run/clamd.scan/clamd.sock`: `clamscan:virusgroup`, mod `660`. Üst dizin `/run/clamd.scan`: `clamscan:virusgroup`, mod `710`. Uygulama soket okuma/yazma no. Üst dizin okuma/yazma/geçiş no. İmza dizini okuma yes, yazma no, geçiş yes. `main.cvd`, `daily.cld` ve `bytecode.cvd` okuma yes, yazma no. PING ve VERSION denenmedi.

Bu CLI ölçümüdür. Web veya SELinux kabulü değildir. `loaded_main=unproven` ve `loaded_bytecode=unproven` durur. 17 Ekim 2026 tek web isteği `euid=5002`, `egid=5002`, `groups=[5002]` olarak kalır. Bu plan o kaydı yeni gruplarla yeniden yazmaz.

## DAC değerlendirmesi

Mod `660` grup `virusgroup` için sokette okuma ve yazma verir; other vermez. Mod `710` aynı grup için üst dizinde yalnız geçiş verir; okuma ve yazma vermez. Ölçülen süreç yalnız grup `5002` olduğu için other bitleri soket okuma/yazmayı ve üst dizin geçişini kapatır. Bu, ölçülen red ile uyumludur.

Yalnız `testl3865` hesabının mevcut `virusgroup` grubuna eklenmesi, yeni bir CLI sürecinin ek grupları o GID’yi taşıdığında DAC için yeterlidir: sokette okuma ve yazma, üst dizinde geçiş beklenir. Üst dizinde okuma ve yazma no kalmalıdır. Bilinen soket yolu için dizin listeleme gerekmez. Bu yeterlilik tahmini canlı bağlantı kanıtı değildir. SELinux ve çalışan web işçisi bu tahminden ayrıdır.

İmza dosyaları zaten okunabilir ve yazılamaz. Bu operasyon onların izinlerini veya ACL’lerini değiştirmez.

## Yazmadan durma koşulları

Operasyon başında ölçülür. GID varsayılmaz. Aşağıdakilerden biri uymazsa hiçbir üyelik yazılmaz:

- `virusgroup` tek bir sayısal GID döndürür. Ad veya sayı önceden sabitlenmez.
- Soket sahibi `clamscan`, grubu `virusgroup`, modu `660` kalır.
- Üst dizin sahibi `clamscan`, grubu `virusgroup`, modu `710` kalır.
- Soket yolu `/run/clamd.scan/clamd.sock` kalır.
- `testl3865` için yeni bir `runuser -u testl3865` süreci, grup seçeneği olmadan, hâlâ `5002` / `5002` / `[5002]` döndürür. Farklı bir önceki grup kümesi bu planın kapsamı dışındadır.
- Hesap zaten `virusgroup` üyesiyse üyelik eklenmez ve rollback o üyeliğe dokunmaz.

Soket modu `666` yapılmaz. TCP, genel erişim ve `antivirus_can_scan_system` açılmaz.

## Üyelik ve rollback

Yazmadan önce `testl3865` grup üyeliği kaydedilir. Yazma yalnız bu hesabı mevcut `virusgroup` grubuna ekler. Başka hesap, soket, dizin, imza dosyası, ACL veya servis değişmez.

Rollback yalnız bu operasyonun eklediği `virusgroup` üyeliğini kaldırır. Önceki kayıtta üyelik yoksa kaldırma sonrası üyelik önceki kayda eşit olmalıdır. Önceki kayıtta üyelik varsa bu operasyon onu eklemediği için kaldırmaz. Kısmi doğrulama başarısızsa işlem başarı sayılmaz.

## Mevcut kimlik kapısı

`clamav_access_probe_recorded_identity_matches()` ve uzak betikteki `recorded_group=5002` tam `[5002]` bekler. `virusgroup` eklenince yeni CLI sürecinin grup kümesi bu kapıyı kapatır. DAC, PING ve VERSION o kapıdan sonra gelmez. Bu PR kapıyı gevşetmez.

Sonraki CLI kabulü ayrı ve açık bir değişikliktir. O değişiklik, tarihî web kaydını değiştirmeden, aynı yeni CLI sürecinde şunları doğrular:

- etkili UID `5002`
- etkili GID `5002`
- ek gruplar sıra bağımsız olarak yalnız önceki ölçülen gruplar artı bu operasyonun başında ölçülen `virusgroup` GID’si

Başka GID varsa veya ölçülen GID yoksa soket açılmaz. Doğrulama `runuser -u testl3865` ile grup seçeneği olmadan başlayan yeni süreçte yapılır. Yeni CLI grup listesi güncel web işçisi kanıtı sayılmaz. Çalışan web işçilerinin yeni grubu aldığı varsayılmaz.

## Bu operasyonun açmadığı kapsam

İlk izin operasyonu `QUESTION_IMAGE_CLAMAV_SOCKET` ve `QUESTION_IMAGE_CLAMAV_DATABASE` ayarlarını açmaz, OLS’i yenilemez ve gerçek varlık taramaz. Sonraki kabul yalnız aynı yeni CLI sürecinde kimlik doğrulaması ve `nPING` / `nVERSION` olur. `INSTREAM` yoktur. Yüklenmiş main/bytecode kanıtı ve web/SELinux kabulü açık kalır. M18 partial kalır.
