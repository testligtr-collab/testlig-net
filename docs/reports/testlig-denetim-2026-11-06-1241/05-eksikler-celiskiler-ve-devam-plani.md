# Eksikler, çelişkiler ve devam planı

Durum sözlüğü: planlandı / belge / kod / test / aynı HEAD CI / ekran açıldı / PR / main / deploy / migration / canlı salt okunur / uçtan uca kabul. Bunlar birbirinin yerine geçmez.

## Bulgular

### F01 — X01 kaydı merge gerçeğini inkâr ediyor
- Tür: kanıt eksikliği
- Etki: main’deki `X01-homework` evidence son satırı “PR #195 incelemede; main merge/deploy/production kabulü YOK”. SHA `b09f51b` tam olarak #195 merge’idir ve deploy run 42028237826 success’tir. Okuyan kişi ürünün main’de olmadığını sanır.
- Kanıt: `git show b09f51b:docs/roadmap/features.json` son X01 evidence; `gh pr view 195` bu turda ayrıca açılmadı ama main merge mesajı #195’i adlandırıyor. #196 (`54df678`, OPEN) bu metni düzeltmek için duruyor ve main’de değil.
- Güven: yüksek
- Durum: açık
- Sonraki adım: #196’yı incelemede bırakıp metnin main’e alınmasına kullanıcı karar versin. Bu rapor onu merge etmez.
- Yetki: review/merge kararı. Production yazımı yok.
- Blok: kayıt doğruluğu. Canlı pilotu tek başına bloklamaz; pilot zaten öğretmen oturumu olmadan yapılamaz.

### F02 — Browser 500 exception hâlâ yok
- Tür: gerçek hata (kök neden bilinmiyor) + süreç
- Etki: run 42029032547 job 127250426079, checkout merge ref `282b9a1` (PR #196 HEAD `54df678` + main `b09f51b`). İlk fail `admin-institutions.spec.ts` ikinci `GET /yonetim/kurumlar` HTTP 500. 1440 viewport 200 ve Kurumlar başlığı görünmüştü. Sonraki `/giris` 500 ve CSRF locator timeout ikincil. `var/browser-server.log` o koşuda artifact değildi.
- #197 HEAD `041ca55` run 42052914409 Browser **SUCCESS** (2026-11-05T15:14:22Z–15:19:17Z). Artifact yalnız `browser-screenshots`. `browser-server-log` yok; adım `if: failure()`. Yeşil koşu kök neden çözümü değildir. Tekrar üretilemedi.
- Güven: yüksek (500 olayı ve yeşil tekrar). Exception metni: yok.
- Durum: açık, şu an ürün ilerlemesini bloke etmiyor (main Browser #195 koşusu 42023013725 de 41 passed idi).
- Sonraki adım: yeni tanı döngüsü açma. #197 merge kararı ayrı. Bir sonraki kırmızı Browser’da log artifact’ı ancak #197 main’deyse gelir.
- Production yazımı: hayır.

### F03 — Canlı ödev kabulü yok
- Tür: kanıt eksikliği
- Etki: Kodda definition, assignment, recipient snapshot, submission, evaluation ve `current_evaluation_id` var (#191–#195 main). Canlıda öğretmen oturumu doğrulanmadı. Öğrenci oturumunda ödev listesi boştu (önceki salt okunur bakış). Boş liste zincirin kabulü değildir. Atama tüm uygun öğrencileri snapshot’lar; alıcı sayısı söylenmedi.
- Güven: orta (önceki oturum gözlemi bu turda production’da yenilenmedi).
- Durum: açık. X01 partial ve X08-classroom-pilot blocked ile uyumlu.
- Sonraki adım: kullanıcı öğretmen girişi sağlar; salt okunur sınıf/enrollment/alıcı sayılır; yazma ayrıca onaylanır.
- Production yazımı: pilot evet, bu rapor hayır.
- Blok: canlı kabul iddiası. Kod geliştirme değil.

### F04 — Açık operasyon PR’ları ürün işi değil
- Tür: tarihsel / açık ops
- #149 OPEN `b3c3bf87` ClamAV/soru görseli. #157 OPEN `793ec53` lsphp83. #168 OPEN OLS refresh. #151 OPEN haber metni. #101 OPEN envanter belgesi.
- Bunlar X01’i sessizce iptal etmez. Merge/deploy bu turda yok.
- Blok: production PHP/OLS/ClamAV kabulü. Ödev kodunu bloke etmez.

### F05 — Belge içi zaman çelişkisi
- Tür: kanıt eksikliği
- `docs/architecture.md` akademik yıl bölümü “UI/controller yok” der (kuruluş metni). M06 status present ve admin sınıf/yıl ekranları sonraki PR’larla main’de. `docs/architecture-homework.md` §0.1d değerlendirmeyi kodda sayar; §3.4 başlığı hâlâ “henüz kodda yok” izlenimi verebilir. Güncel davranış için main kod + §0.1d esas; §3.4 başlığı tarihsel.
- Güven: yüksek (başlıklar okundu). Satır satır §3.4 gövdesi bu turda yeniden diff’lenmedi.
- Blok: hayır.

### F06 — Workspace göstergesi işi yanlış anlatır
- Tür: süreç
- Aktif kayıt X01. Görünen workspace `question-editor-layout` detached `fceeefd`. Birincil dal adı `feature/m12-admin-test-detail` ama HEAD aynı eski commit. #196 worktree `chore/x01-195-merge-evidence`. #197 worktree `ci/browser-server-log-artifact`.
- Blok: karışıklık. Kod hatası değil.

### F07 — Deploy #194 kırmızı, #195 yeşil
- Tür: operasyon kaydı
- Run 41888866082 failure, SHA `cc208c3c` (#194). Sonraki başarı farklı SHA `b09f51b`. Aynı SHA’ya ikinci deploy bu listede yok. Failure nedeni bu denetimde logdan çıkarılmadı: doğrulanamadı.
- Blok: #194 SHA’sının production’da kaldığı iddia edilemez. Güncel başarılı SHA #195’tir.

### F08 — Roadmap guard içerik doğrulamaz
- Tür: süreç notu
- #197 coverage SUCCESS. Guard ID/kayıt/PR satırı biçimini kontrol eder. “#195 merge yok” cümlesinin yanlışlığını yakalamaz. X01 hâlâ partial; present yapılmadı. Bu doğru.
- Blok: hayır.

### F09 — Baseline dışı tek ID
- Tür: kapsam notu
- 72 baseline feature main’de duruyor. Ek: `X11-php-runtime-inventory` partial. Kayıt silinmemiş.
- Blok: hayır.

### F10 — Present tamamlanmış ürün değildir
- Tür: tarihsel not
- M12 present, admin test ekranları main’de. Canlı kullanıcı kabulü bu denetimde yenilenmedi. M02-sms partial, OAuth/guest-claim planned. M14 canlı ders planned. M18 bulut partial, yerel PDF present. M30/X05 mobil planned. X07 ve X08 blocked.
- Blok: ilgili kabul iddiaları. Yeni hata değil.

## CI kök neden özeti (kapalı / açık)

| Konu | Sonuç | Durum |
|---|---|---|
| CSRF locator | 500 hata sayfası; CSRF kuralı kaldırılmadı | ikincil, açık olan 500 |
| scrollWidth uzun başlık | #192 düzeltmesi main’de | kapalı (bu turda test yeniden koşulmadı) |
| Evaluation FK/index | #195 composite FK testleri main’de | kapalı kod; prod migration bu turda sayılmadı |
| Radio width | #195 CSS main’de | kapalı kod |
| UTC/DST | #187 main’de | kapalı kod |
| Symfony advisory | #195 lock güncellemesi; “sonsuza kadar temiz” değil | tarihsel |
| Browser 500 | exception yok; #197’de tekrar yok | açık, bilinmiyor |
| Yanlış ağaç | F06 | açık süreç |

## Önerilen sıra (uygulanmadı)

1. Kullanıcı #196 metnini main’e alıp almayacağına karar versin. Çıktı: X01 evidence’in merge/deploy cümlesi `b09f51b` ve run 42028237826 ile aynı olsun. Canlı kabul hâlâ açık yazılsın. Yeni tanı PR’ı açılmasın.
2. #197 ayrı kalsın veya ayrıca review edilsin. Çıktı: bir sonraki Browser failure’da redakte log. Yeşil koşuyu çözüm saymamak.
3. Öğretmen oturumu ile salt okunur sınıf, yıl, üyelik, atama ve alıcı sayısı. Çıktı: pilot öncesi eksik listesi. Yazma yok.
4. Ancak o sayı doğrulanırsa ve kullanıcı yazma derse kısa pilot. Çıktı: definition, assignment, recipients, bir submission, bir evaluation. Silme vaadi yok.
5. #149, #157, #168 kendi ops yetkisiyle kalsın. Ödev işine bağlama.

## Kapsam kontrol listesi

- M01–M31: evet, main features.json modül kümesi tam. Ayrıntı durumları `03-feature-kapsam-matrisi.csv`.
- Baseline 72 ID: hepsi features’da. Eksik ID yok.
- PR sayfaları: `gh pr list --limit 300` 197 kayıt (#1–#197). Her gövde/Roadmap-IDs satırı tek tek okunmadı. CSV’de bu sınır yazılı.
- Son 7 gün main merge’leri: #182–#195 listelendi. Her CI job’u yeniden açılmadı.
- Worktree/staged: `04-worktree-ve-yerel-degisiklikler.md`.
- Açık ops ve X01/X10: korundu, status değiştirilmedi.
- Canlı kabul ve pilot: eksik, F03.
- Erişilemeyen: web-projem içeriği klonlanmadı (repo var, pushed_at 2026-09-07). #194 deploy failure nedeni okunmadı. Production bu turda açılmadı. Her PR’ın CI rollup’ı yok.
- İddialar: SHA, PR no, run id veya dosya yolu ile bağlı. Tahminle doldurulan hücre yok; boşluk “doğrulanamadı”.
