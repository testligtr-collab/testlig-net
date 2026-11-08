# Testlig proje geçmişi ekleri — 2026-11-01

Ana rapor: [testlig-proje-gecmisi-ve-mevcut-durum-2026-11-01.md](testlig-proje-gecmisi-ve-mevcut-durum-2026-11-01.md).

Bu dosya indeksdir. Üretim kabulü eklemez. Sayılar 2026-11-01 incelemesinde sayılan dosya veya API kayıtlarıdır.

## Önemli commit indeksi

Tam liste 876 commit değildir. Kök ve örnekler:

| SHA | Tarih (+0300 değilse yazıldı) | Konu |
|---|---|---|
| `c12397fd367b` | 2026-06-21T08:07:05Z | web-projem en eski: Okulblog öğrenci paneli. Ayrı depo |
| `73c9c86dc156a8faf3a1aafb314df1e4140d84a5` | 2026-09-07 18:19 +0300 | testlig-net kök, ebeveynsiz |
| `458a321330d7f5e61bc6757be146aa0b542d5873` | 2026-09-07 19:44 +0300 | Docker doğrulama |
| `188666972594e69611cb01cbac084bc156cb7f17` | 2026-09-07 20:55 +0300 | Merge PR #1 CI veritabanı |
| `e258ca7c5a81d0ee16eab2062ec9b7735f8875d4` | 2026-09-07 21:47 +0300 | Merge PR #2 kimlik |
| `125c31a1d4fb2039654d521f59b1e99898f2b677` | 2026-09-08 10:30 +0300 | Merge PR #4 kayıt ve giriş |
| `add4a63fb163d1ffa286f0742c259ae2edde8d7d` | 2026-10-31 17:14 +0300 | `origin/main`, Merge PR #185 |
| `fceeefd9c4ba43b356d632e9f04590003090574a` | 2026-10-31 20:09 +0300 | PR #186 HEAD, main’de değil |

`origin/main` merge sayıları: 2026-09 = 88, 2026-10 = 94. Tag yok.

## PR indeksi

Doğrulananlar:

| PR | Durum | Not |
|---|---|---|
| #1 | Merge 2026-09-07 | CI test veritabanı. Git log |
| #2 | Merge 2026-09-07 | Kimlik. Git log |
| #3 | Merge 2026-09-07 | Satır sonu. Git log |
| #4 | Merge 2026-09-08 | Kayıt/giriş. Git log |
| #101 | OPEN | Ürün envanteri belgesi |
| #103 | MERGED 2026-10-06T15:16:49Z | Erişim politikası salt okunur envanter workflow |
| #104 | MERGED 2026-10-06T17:53:21Z | Test detayında politika yönetimi |
| #149 | OPEN | ClamAV / soru görseli |
| #151 | OPEN | Haber |
| #157 | OPEN | lsphp83 security apply |
| #168 | OPEN | OLS refresh |
| #183 | Merge, main `6e1a7b37` | Soru editörü. CI 41097371646, deploy 41101471456 |
| #184 | Merge, ara main `3397200e` | Test listesi. Deploy 41198335381 |
| #185 | Merge, main `add4a63` | Test editörü. CI 41209234805, deploy 41211221505 |
| #186 | OPEN, draft | Test detayı. HEAD `fceeefd`. CI 41223148645. Coverage 41291864363 |

Diğer numaralar bu turda tek tek açılmadı. Yol haritası metnindeki #111, #112, #114 tarihsel belgededir; bu turda `gh pr view` ile yeniden çekilmedi. **Belgede belirtiliyor.**

## Migration indeksi

60 dosya. Üretimde uygulandığı doğrulanmadı. Adlar:

- Version20260907180510
- Version20260908081322
- Version20260908143000
- Version20260908160000
- Version20260908200000
- Version20260909120000
- Version20260909180000
- Version20260909190000
- Version20260909200000
- Version20260910120000
- Version20260910200000
- Version20260910300000
- Version20260910400000
- Version20260910500000
- Version20260910600000
- Version20260910700000
- Version20260910800000
- Version20260910900000
- Version20260911120000
- Version20260911200000
- Version20260912120000
- Version20260912130000
- Version20260912140000
- Version20260912150000
- Version20260912160000
- Version20260912170000
- Version20260913120000
- Version20260913130000
- Version20260913140000
- Version20260914120000
- Version20260914120100
- Version20260914130000
- Version20260915120000
- Version20260920140000
- Version20260921120000
- Version20260921150000
- Version20260921180000
- Version20260921190000
- Version20260923120000
- Version20260923180000
- Version20260924120000
- Version20260925120000
- Version20260925193000
- Version20260925220000
- Version20260926120000
- Version20260926153000
- Version20260926180000
- Version20260927120000
- Version20260927140000
- Version20261007120000
- Version20261011140000
- Version20261011180000
- Version20261011200000
- Version20261012160000
- Version20261020143000
- Version20261020180000
- Version20261024120000
- Version20261025160000
- Version20261026190000
- Version20261027120000

## Özellik kaydı indeksi

`docs/roadmap/features.json`, `updated_at` 2026-10-31, baseline `2a615f1113126ad5b023b4a2893f35e021c5ec7d`, aktif yalnız `X10-admin-navigation`.

Durum sayıları: present 21, partial 20, planned 25, decision_needed 5, blocked 2.

| Kimlik | Durum |
|---|---|
| M01-email-registration | present |
| M01-applications | partial |
| M01-trial | planned |
| M02-email-login | present |
| M02-member-code | planned |
| M02-sms | partial |
| M02-oauth | planned |
| M02-guest-claim | planned |
| M03-user-admin | present |
| M03-bulk-import | planned |
| M03-group-transition | planned |
| M03-module-permissions | partial |
| M04-workspace | present |
| M05-branches | partial |
| M05-bank-data | decision_needed |
| M06-institution-classrooms | present |
| M06-academic-years | present |
| M06-independent-classes | decision_needed |
| M07-teacher-workspace | present |
| M07-profile | partial |
| M07-student-notes | planned |
| M07-editorial-panels | partial |
| M08-student-learning | present |
| M08-continue-test | present |
| M08-lesson-progress | planned |
| M09-parent-link | present |
| M09-parent-activity | partial |
| M10-curriculum | partial |
| M11-platform-questions | present |
| M11-csv-import | present |
| M11-institution-questions | present |
| M11-ai-import | planned |
| M12-platform-tests | present |
| M12-institution-authoring | present |
| M12-classroom-delivery | present |
| M12-entitlement | present |
| M13-results | present |
| M13-analytics | partial |
| M13-manual-review | partial |
| M13-export | planned |
| M14-live-lessons | planned |
| M15-commerce-core | present |
| M15-checkout | partial |
| M15-coupons | planned |
| M16-sms-provider | planned |
| M17-ai-provider | planned |
| M18-cloud-storage | partial |
| M18-local-pdf | present |
| M18-personal-files | decision_needed |
| M19-documents | partial |
| M20-video-library | partial |
| M21-simulations | planned |
| M22-games | planned |
| M23-qna | planned |
| M24-messaging | planned |
| M25-notifications | planned |
| M26-support | planned |
| M27-news | partial |
| M28-seo | partial |
| M29-cms | partial |
| M30-mobile-api | planned |
| M31-traffic-ads | decision_needed |
| X01-homework | planned |
| X02-study-tools | planned |
| X03-gamification | planned |
| X04-guidance | decision_needed |
| X05-flutter | planned |
| X06-quality | partial |
| X07-mobile-acceptance | blocked |
| X08-classroom-pilot | blocked |
| X09-roadmap-governance | present |
| X10-admin-navigation | partial |
| X11-php-runtime-inventory | partial |

## Route / modül örnekleri

Tam route dökümü değildir. `#[Route]` taraması en az 285 eşleşme verdi; hepsi rapora yazılmadı.

| Modül | Örnek |
|---|---|
| Ana sayfa | `GET /` `HomeController` |
| Haber | `GET /haberler` |
| Öğrenci | `/ogrenci`, `/ogrenci/testler`, `/ogrenci/dersler` |
| Öğretmen | `/ogretmen/profil` |
| Kurum | `/kurum/sorular/...` |
| Yönetim kullanıcı | `/yonetim/kullanicilar` |
| Öğretmen başvurusu | `/yonetim/ogretmen-basvurulari` |
| Kurum yönetimi | `/yonetim/kurumlar` |
| Şube | `/yonetim/subeler` |
| Akademik yıl | `/yonetim/akademik-yillar` |
| Müfredat | `/yonetim/programlar` |
| Soru | `/yonetim/sorular` |
| Test | `/yonetim/testler` |

Controller PHP dosyası: 57. Entity PHP dosyası: 105.

## Test alanı indeksi

- PHP dosyası `tests/` : 315. Metot sayısı değil.
- Playwright spec `browser/tests/` : 21.
- CI job adları: Quality & tests, Browser acceptance.
- Ayrı workflow: Roadmap coverage (`coverage`).

## İncelenen belgeler

- `AGENTS.md`
- `README.md` (güncel kodla çelişen giriş)
- `docs/product-roadmap.md`
- `docs/roadmap/features.json`
- `docs/architecture.md` başlık düzeyinde tam okuma değil
- `docs/ops-vds-ssh.md` SSH satırı
- `docs/ui-design-system.md` ve üç UI guideline dosya adı
- `.github/workflows/ci.yml`, `deploy.yml`
- `composer.json`, `composer.lock` Symfony sürüm satırı
- `src/Enum/UserRole.php`, `ResourceAccessClass.php`
- `src/Entity/User.php` e-posta ve parola alanları
- `src/Service/EntitlementAccessGate.php`, `StudentAssessmentPractice.php` imza
- `src/Controller/Admin/AdminTestController.php` route bloğu

## Çelişkili veya doğrulanamayan kayıtlar

| Kayıt | Sorun |
|---|---|
| README “iş modülü yok” | Controller’lar var |
| README Docker hedefi | AGENTS CI’yı tercih eder. İkisi de duruyor |
| Baseline SHA `2a615f11…` | Güncel main `add4a63`. Baseline kasıtlı eski |
| Eski not “testlig-net clamav dalında” | 2026-11-01 okumasında dal `feature/m12-admin-test-detail` |
| Deploy success | Sunucu diskindeki SHA bu turda okunmadı |
| web-projem 2026-06-21 | İlk kod satırı değil; ilk görülen git kaydı |
| Yol haritasındaki bazı eski PR numaraları | Bu turda GitHub’dan yeniden açılmadı |
| Cursor sohbetlerinin tamamı | Ürün kanıtı olarak kullanılmadı |
