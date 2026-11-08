# Testlig proje geçmişi ve mevcut durum

Rapor zamanı: 2026-11-01 12:24 Europe/Istanbul (09:24 UTC) başlangıç; açık adres kontrolü aynı saat diliminde, yaklaşık 12:30. Bu dosya inceleme çıktısıdır. Uygulama kodu, yapılandırma, veritabanı ve üretim bu rapor için değiştirilmedi.

Durum sınıfları: **Doğrulandı**, **Belgede belirtiliyor**, **Çıkarım**, **Doğrulanamadı**.

Yaşam döngüsü adımları birbirinin yerine geçmez: planlandı, kodlandı, testi var, testi geçti, CI başarılı, PR açık, main, deploy, üretimde davranış doğrulandı.

## İçindekiler

1. [Yönetici özeti](#1-yönetici-özeti)
2. [Kapsam ve kaynak envanteri](#2-raporun-kapsamı-ve-kaynak-envanteri)
3. [Başlangıç ve eski projeden geçiş](#3-projenin-başlangıcı-ve-eski-projeden-geçiş)
4. [Kronoloji](#4-başlangıçtan-bugüne-kronoloji)
5. [Ürün hedefleri ve kapsam](#5-ürün-hedefleri-ve-kapsam)
6. [Teknoloji ve mimari](#6-teknoloji-ve-mimari)
7. [Veri modeli ve migration](#7-veri-modeli-ve-migration-geçmişi)
8. [Kimlik, roller ve güvenlik](#8-kimlik-roller-ve-güvenlik)
9. [Müfredat ve öğrenme içeriği](#9-müfredat-ve-öğrenme-içeriği)
10. [Soru, test ve çözüm](#10-soru-havuzu-testler-ve-çözüm-akışı)
11. [Kurum, sınıf, üyelik ve atama](#11-kurum-sınıf-üyelik-ve-atama)
12. [Erişim politikaları ve haklar](#12-erişim-politikaları-ve-pakethak-sistemi)
13. [Öğrenci paneli ve Devam et](#13-öğrenci-paneli-ve-devam-et-kartı)
14. [Yönetim ve diğer paneller](#14-yönetim-paneli-ve-diğer-paneller)
15. [Tasarım kararları](#15-tasarım-ve-kullanıcı-deneyimi-kararları)
16. [Testler ve kalite](#16-testler-ve-kalite-kontrolleri)
17. [GitHub, CI/CD ve üretim](#17-github-cicd-ve-üretim-geçmişi)
18. [Kararlar ve yön değişiklikleri](#18-önemli-kararlar-ve-yön-değişiklikleri)
19. [Durum tablosu](#19-tamamlanan-kısmen-tamamlanan-ve-başlanmayan-işler)
20. [Açık sorunlar ve riskler](#20-açık-sorunlar-teknik-borç-ve-riskler)
21. [Son çalışma noktası](#21-son-çalışma-noktası)
22. [Öncelikli devam planı](#22-öncelikli-devam-planı)
23. [Devir özeti](#23-devir-özeti)
24. [Ekler](#24-ekler)

## 1. Yönetici özeti

Testlig, web üzerinden öğrenci, veli, öğretmen, kurum ve platform yönetimi için geliştirilen bir eğitim platformudur. **Doğrulandı:** GitHub deposu açıklaması “Testlig yeni nesil eğitim platformu” (`testligtr-collab/testlig-net`, oluşturulma 2026-09-07T17:22:20Z). **Belgede belirtiliyor:** ileride Flutter istemcisi hedeflenir (`README.md` satır 7; `features.json` içinde `X05-flutter` durumu `planned`). Ayrı mobil uygulamanın kodu bu incelemede bulunmadı.

Erişilebilen en eski geliştirme kaydı bu depo değildir. Eski depo `web-projem` içinde en eski commit 2026-06-21 11:07 Europe/Istanbul, mesajı “Okulblog: ogrenci paneli…” ve “Symfony uygulamasinin ilk surum kontrolu” der. Bu, git’e alınan ilk anı gösterir; daha eski, git dışı kodlama **Doğrulanamadı**. `testlig-net` kök commit’i ebeveynsizdir ve 2026-09-07 18:19 +0300 tarihli “initialize Testlig platform foundation” kaydıdır. **Çıkarım:** V2, V1 git geçmişinin devamı olarak değil, aynı ürün sahibinin yeni deposu olarak kurulmuştur. Ayrıntı [bölüm 3](#3-projenin-başlangıcı-ve-eski-projeden-geçiş).

Bugün ürün, kimlik, kurum/sınıf, müfredat kataloğu, soru, mühürlü test, öğrenci çözümü, erişim politikası ve yönetim kabuğu olan bir Symfony uygulamasıdır. Üretim sitesi bu rapor sırasında HTTP ile ayaktadır; giriş yapmadan yönetim ve öğrenci yolları `/giris` adresine döner. Bu, oturum açmış kullanıcı akışının üretimde doğrulandığı anlamına gelmez. Ayrıntı [bölüm 17](#17-github-cicd-ve-üretim-geçmişi).

Tamamlanma yüzdesi verilmez. `features.json` 73 kayıt tutar (21 `present`, 20 `partial`, 25 `planned`, 5 `decision_needed`, 2 `blocked`). Bu sayılar kodun üretim kabulü değildir. Kayıtların anlamı [bölüm 19](#19-tamamlanan-kısmen-tamamlanan-ve-başlanmayan-işler).

Çalışır olduğu bu raporda ayrıca doğrulananlar:

- `origin/main` SHA `add4a63fb163d1ffa286f0742c259ae2edde8d7d`, 2026-10-31 17:14 +0300, PR #185 merge. CI run [41209234805](https://github.com/testligtr-collab/testlig-net/actions/runs/41209234805) success. Deploy run [41211221505](https://github.com/testligtr-collab/testlig-net/actions/runs/41211221505) success. Release dizin adı bu turda logdan yeniden okunmadı.
- 2026-11-01 12:30 civarı: `https://testlig.net/` 200, `/giris` 200, `/yonetim` ve `/ogrenci` 302 ile `https://testlig.net/giris`. Yalnız durum kodu ölçüldü.

En önemli tamamlanmamış işler [bölüm 20](#20-açık-sorunlar-teknik-borç-ve-riskler) ve [bölüm 22](#22-öncelikli-devam-planı) içindedir. Kısa liste: PR #186 taslak ve main’de değil; X10 yönetim navigasyonu `partial` ve tek aktif özellik kimliği; M18 bulut/ClamAV `partial` ve PR #149 açık; mobil üretim kabulü ve gerçek kurum pilotu `blocked`; ödeme checkout, SMS, OAuth, canlı ders, oyun, mesaj ve mobil API `planned` veya `partial`.

İlerlemeyi teknik olarak kilitleyen tek bir kırmızı CI, bu HEAD için görülmedi. Operasyonel sınır vardır: deploy SSH’si `VDS_SSH_PAUSED` ve `VDS_AUTO_DEPLOY` değişkenlerine bağlıdır; başarısız SSH yeniden denenmez (`docs/ops-vds-ssh.md`). Bu değişkenlerin bugünkü değerleri bu turda okunmadı.

Devralacak kişi önce [bölüm 23](#23-devir-özeti) dosya listesini, sonra main SHA ile açık PR farkını okumalıdır. `README.md` hâlâ “yalnızca temiz proje altyapısı” der; bu cümle güncel kodla çelişir ve güncel gerçek kabul edilmez.

## 2. Raporun kapsamı ve kaynak envanteri

İncelenen çalışma kopyası: `C:\xampp\htdocs\testlig-net-question-editor-layout`. Kök aynı yoldur. Remote: `https://github.com/testligtr-collab/testlig-net.git`. Shallow değil (`git rev-parse --is-shallow-repository` = false). Yerel HEAD `fceeefd9c4ba43b356d632e9f04590003090574a`, 2026-10-31 20:09 +0300. `git branch --show-current` boştu; `git status -sb` çıktısı `HEAD (no branch)`. `git status --short` başlangıçta boştu. Bu kopya `feature/m12-admin-test-detail` dalına bağlı değildir; aynı SHA dalın ucudur. Checkout yapılmadı.

Ayrı yol `C:\xampp\htdocs\testlig-net` bu turda salt okunur bakıldı. Dal adı `feature/m12-admin-test-detail`, kısa SHA `fceeefd`. Eski notlardaki “clamav dalında kalır” cümlesi bu anda doğru değildir. O kopya bu raporla değiştirilmedi.

Yerel `testlig-net` geçmişi 876 commit (`git rev-list --count HEAD`, bu HEAD). `origin/main` 2026-09 ve 2026-10’da 88 + 94 merge commit içerir. Tag listesi boştu. `gh release list` boş döndü; GitHub Release nesnesi yok. Üretim release’i workflow içindeki dizin aktivasyonudur, GitHub Release değildir.

| Kaynak | İncelendi mi? | Tarih/kapsam | Erişim sınırlaması | Güvenilirlik |
|---|---|---|---|---|
| Bu çalışma kopyasının git geçmişi | Evet | 2026-09-07 … 2026-10-31, 876 commit, shallow değil | Detached HEAD | Doğrudan kayıt |
| `origin/main` | Evet | SHA `add4a63…` | Fetch yapılmadı; mevcut remote ref | Doğrudan kayıt |
| GitHub `testlig-net` PR, CI, deploy | Evet | Açık PR’lar, son deploy’lar, PR #103 #104 #186 | Tüm tarihsel run’lar tek tek açılmadı | Doğrudan kayıt |
| `web-projem` | Kısmen | API ile 476 commit sayfası; en eski ve birkaç 2026-08 commit | Tam ağaç klonlanmadı, kod diff’i okunmadı | Dolaylı; başka depo |
| `docs/` ve `AGENTS.md` | Evet | Yol haritası, mimari, UI, ops | Belgeler tarihsel cümle içerir | Belge; kodla çelişebilir |
| `docs/roadmap/features.json` | Evet | `updated_at` 2026-10-31, 73 özellik | Her özelliğin kaynağı yeniden açılmadı | Belge + kısmi kod doğrulaması |
| `composer.json` / `composer.lock` | Evet | PHP `>=8.2`, Symfony kısıtı `7.4.*`, kilit `v7.4.18` | Bu makinede PHP sürümü çalıştırılmadı | Kilit dosyası tanımlı sürüm; çalışan runtime değil |
| `src/`, `migrations/`, `tests/`, `.github/workflows/` | Evet, örnekleme | Entity 105 dosya, migration 60, controller 57, PHP test dosyası 315, browser spec 21 | Her dosya satır satır okunmadı | Dosya varlığı doğrulandı; davranış ancak açılan yerde |
| Üretim HTTP | Evet, dar | 2026-11-01, dört URL, yalnız durum kodu | Gövde, oturum ve veri okunmadı | Ulaşılabilirlik; ürün kabulü değil |
| Üretim SSH, MariaDB, release dizini | Hayır | — | Bu görev SSH yasakladı | Doğrulanamadı |
| Cursor sohbetlerinin tamamı | Hayır | Arama indeksi birkaç eski başlık döndürdü; ürün iddiası için okunmadı | Birebir sohbet dökümü değil | Erişilemeyen tarihçe sayılır |
| `README.md` “aşama 1, iş modülü yok” | Okundu | Dosyanın güncel hali | Güncel kodla çelişiyor | Güncel gerçek değil |

Bu raporda çalıştırılan komutlar salt okunurdur: `git status/log/rev-parse`, `gh` okuma, `features.json` sayımı, `curl` durum kodu. PHPUnit, Docker, migration ve workflow tetiklenmedi.

## 3. Projenin başlangıcı ve eski projeden geçiş

**Erişilebilen en eski geliştirme kaydı:** `web-projem` commit `c12397fd367b`, tarih 2026-06-21T08:07:05Z (11:07 +0300). Mesaj, Symfony uygulamasının ilk sürüm kontrolü olarak öğrenci paneli, profil, karne, sınıf sıralaması ve admin üye formunu sayar. **Doğrulandı** API kaydı olarak. Bunun insan eliyle yazılan ilk satır olduğu **Doğrulanamadı**.

`web-projem` GitHub’da 2026-09-07T11:53:25Z oluşturulmuş, public, son push 2026-09-07T12:19:02Z. Commit API `rel=last` sayfası 476. Aynı gün 12:17Z commit’i “mevcut localhost projesini inceleme için aktar” der. **Çıkarım:** Ağustos ve Haziran commit’leri, depo oluşturulmadan önceki yerel geçmişin sonradan itilmiş halidir.

Örneklenen ara kayıtlar, tam envanter değildir:

- 2026-08-08 `a699f5c53b0b` soru editörü checkpoint; `482aab2159cf` MathLive/JSXGraph editörü.
- 2026-08-08 `3e226333eb2f` “Toplama Oyunu” görsel teması.
- 2026-08-16 `353ed2da5b39` ve `ba3427edd1b2` V20 ana sayfa.

`testlig-net` GitHub oluşturma 2026-09-07T17:22:20Z. İlk commit `73c9c86dc156a8faf3a1aafb314df1e4140d84a5` ebeveynsizdir (`git rev-list --parents` yalnız bu SHA’yı verdi). `git log --grep=web-projem` eşleşme vermedi. **Doğrulandı:** V2, V1 commit zincirinin child’ı değildir. Ortak sahip ve ürün adı dışında kod taşıması bu turda diff ile **Doğrulanamadı**.

`docs/product-roadmap.md` (10 Ekim 2026 karşılaştırması) V1 kaynağını “Yapıştırılan metin.txt” ve işlevsel mimari harita olarak tanımlar; V1 deposunu açmadığını yazar. O belge, V1’de PHPUnit failure ve şema uyumsuzluğu bulunduğunu da söyler. **Belgede belirtiliyor.** Bu rapor V1 kodunu açmadığı için o hatalar yeniden ölçülmedi.

Sıfırdan kurulan V2 omurgası ilk günlerde görünür: foundation, Docker doğrulama commit’i `458a321`, CI veritabanı adı PR #1, kimlik PR #2, kayıt/giriş PR #4. Ayrıntı [bölüm 4](#4-başlangıçtan-bugüne-kronoloji).

## 4. Başlangıçtan bugüne kronoloji

Ham commit dökümü [ekler](testlig-proje-gecmisi-ekler-2026-11-01.md) içindedir. Aşağıdaki dönemler merge sayıları ve örnek SHA’larla sınırlıdır. Her satırın “bugünkü durumu” main’deki izidir; üretim kabulü ayrıca yazılır.

| Dönem | Yapılan iş | Amaç | Sonuç | Kanıt | Bugünkü durumu |
|---|---|---|---|---|---|
| 2026-06-21 … 2026-09-07 | Okulblog/V1: öğrenci, karne, soru editörü, oyun, V20 ana sayfa | Mevcut localhost ürününü kayda almak | Ayrı depoda 476 commit; 7 Eylül’de push durmuş | `web-projem` API | V2 git ebeveyni değil. Ürün karşılıklarının bir kısmı V2’de yeniden yazılmış, bir kısmı `planned` |
| 2026-09-07 akşam | V2 foundation, Docker doğrulama, CI DB adı, kimlik, satır sonu | Yeni platform iskeleti | PR #1, #2, #3 merge | `73c9c86`, `458a321`, `1886669`, `e258ca7`, `fe300b5` | İskelet büyüdü. README hâlâ bu günün “modül yok” cümlesini taşır |
| 2026-09-08 | Kayıt, giriş, e-posta doğrulama, parola reset | Hesap güvenliği | PR #4 merge `125c31a` | git log | Kod `present` (`M01`, `M02-email-login`). Üretimde form gönderimi bu turda yapılmadı |
| 2026-09 geri kalanı | İçerik, soru, test, kurum iskeleti | Ürün domain’i | Main’de 88 merge | `git log origin/main --merges` ay grubu | Entity ve migration dosyaları duruyor. Ay içi her merge ayrı üretim kabulü değildir |
| 2026-10-06 | Erişim politikası envanteri ve yönetim formu | Yayın ile erişimi ayırmak | PR #103 ve #104 MERGED | PR JSON `mergedAt` | Kod duruyor: `AdminTestController` `/erisim`, `EntitlementAccessGate`. Üretim politika kaydı ölçülmedi |
| 2026-10-10 civarı | V1/V2 yol haritası | Yapılmış işi yeniden yazmamak | Belge `docs/product-roadmap.md`; baseline SHA `2a615f11…` | Belge + `features.json` | Baseline hâlâ bu SHA. Main o günden beri ilerledi |
| 2026-10 | Yönetim ekranları, katalog, kurum yazarlığı, medya, X10 | Yönetim kabuğu ve içerik | Main’de 94 merge | git log | X10 hâlâ `partial` ve tek `active_feature_ids` |
| 2026-10-30 | Soru editörü yerleşimi PR #183 | Yönetim soru ekranı | Main `6e1a7b37`, CI [41097371646](https://github.com/testligtr-collab/testlig-net/actions/runs/41097371646), deploy [41101471456](https://github.com/testligtr-collab/testlig-net/actions/runs/41101471456) | gh run list | Deploy success. Canlı editörün bu turda yeniden gezildiği **Doğrulanamadı** |
| 2026-10-31 | Test listesi PR #184, test editörü PR #185 | Admin test yazımı | Main `add4a63`, CI [41209234805](https://github.com/testligtr-collab/testlig-net/actions/runs/41209234805), deploy [41211221505](https://github.com/testligtr-collab/testlig-net/actions/runs/41211221505) | gh | Main ve son başarılı deploy bu SHA |
| 2026-10-31 … 2026-11-01 | Test detayı düzeni PR #186 | GET detay sunumu | Taslak, main’de değil | PR #186, HEAD `fceeefd` | CI üç kontrol pass. Deploy yok |

Docker: ikinci V2 commit “verify Docker development environment”. `README.md` Docker’ı geliştirme için önerir ve hedef PHP’yi imajdaki 8.3 olarak yazar. `AGENTS.md` kullanıcı istemedikçe Docker/XAMPP açılmamasını ve GitHub CI’yı tercih etmeyi söyler. **Doğrulandı** metin çelişkisi. Docker’ın hangi tarihte bırakıldığı tek bir commit ile **Doğrulanamadı**; güncel çalışma kuralı CI’dır. CI PHP 8.3 kurar (`.github/workflows/ci.yml` “Setup PHP 8.3”).

## 5. Ürün hedefleri ve kapsam

Kaynaklar: `docs/product-roadmap.md` V1 karşılaştırması, `UserRole` enum, controller route’ları. Enum’da rol olması panelin bittiği anlamına gelmez.

| Hedef | Kaynak | Mevcut uygulama | Eksik kalan | Durum |
|---|---|---|---|---|
| Öğrenci paneli | V1 M08; route `/ogrenci` | Dashboard, ders, test, profil, veli bağlantısı controller’ları var | Ders ilerlemesi `planned` (`M08-lesson-progress`) | Kod var. Üretim oturumu **Doğrulanamadı** |
| Veli paneli | V1 M09 | `ParentDashboardController` belgede anılır; `M09-parent-link` `present` | Etkinlik, mesaj (`M09-parent-activity` `partial`) | Belge + kayıt. Bu turda controller satırı yeniden açılmadı |
| Öğretmen paneli | V1 M07 | `/ogretmen/profil`, kurum sınıf sonuçları | Not, mesaj, canlı ders | `partial` / `planned` |
| Uzman öğretmen paneli | V1 `/panel/head` ve `/panel/expert` ayrımı | `ROLE_EXPERT_TEACHER` enum’da. Ayrı dashboard **bulunamadı** | Özel kuyruk | `M07-editorial-panels` `partial` |
| Başöğretmen paneli | Aynı | `ROLE_HEAD_TEACHER` enum’da. Ayrı panel bu taramada yok | Özel panel | Rol var, panel tamamı değil |
| Kurum paneli | V1 M04 | `/kurum` soru yazarlığı ve workspace | Başvuru zinciri kısmi | `M04-workspace` `present`, `M01-applications` `partial` |
| Moderatör paneli | V1 modül izinleri | `/yonetim/rol-yetkileri` belgede üç yazma izni | Diğer modül izinleri açık | `M03-module-permissions` `partial` |
| Admin / SuperAdmin | V1 admin | `/yonetim/...` çok sayıda controller | X10 navigasyon `partial` | Kod geniş. Üretimde her ekran gezilmedi |
| Mobil ve tablet | X07, X10 | CSS ve sentetik browser 390/768 | Production kabul `blocked` | CI görüntüsü canlı kabul değildir |
| Ayrı mobil uygulama | README, `X05-flutter` `planned` | API/JWT ürünü yol haritasında yok denir | M30 | **Belgede belirtiliyor** |
| Ana sayfa | `HomeController` `/` | HTTP 200 | CMS/slider yönetimi değil | Ulaşılabilirlik doğrulandı; içerik envanteri değil |

## 6. Teknoloji ve mimari

| Katman | Tanımlı | Kilit / yapılandırma | Çalıştığı doğrulanan runtime |
|---|---|---|---|
| PHP | `composer.json` `>=8.2`. README hedef 8.3 ve “yerelde 8.2 sınırlı” | CI adımı “Setup PHP 8.3” | Bu makinede `php -v` çalıştırılmadı. Üretim PHP sürümü **Doğrulanamadı** |
| Symfony | Kısıt `7.4.*` | `symfony/framework-bundle` `v7.4.18` (`composer.lock` satır 4055) | Sürüm kilidi. Süreç içi `bin/console` çalıştırılmadı |
| MariaDB | CI imajı `mariadb:10.11` | Test DSN `serverVersion=10.11.10-MariaDB` | Yalnız CI yapılandırması. Üretim şeması bu turda sorgulanmadı |
| Redis | `predis/predis` `^3.6`, `ext-redis` platform `6.1.0`, CI `redis:7-alpine` | Test `MESSENGER_TRANSPORT_DSN: sync://` | Üretimde Redis’in gerçekten iş kuyruğu olduğu **Doğrulanamadı** |
| HTTP / şablon | Twig, AssetMapper, Stimulus | `symfony/asset-mapper` `7.4.*` | Ayrıntılı bundle listesi `composer.json` |
| Depolama | AWS SDK, yerel PDF, R2/Bunny adları belgede | `M18-cloud-storage` `partial` | Canlı R2/Bunny kabulü bu turda yok |

Dizin: `src/` PSR-4 `App\`, `tests/` `App\Tests\`, `migrations/`, `templates/`, `assets/`, `browser/tests/`, `docs/`. Katman örneği: `Controller` HTTP, `Service` (`EntitlementAccessGate`, `StudentAssessmentPractice`), `Entity`, `Enum`, `Presentation` (`TestWorkflowProgress`). Tam katman diyagramı her sınıfa uygulanarak denetlenmedi.

UUID: route gereksinimleri `[0-9a-fA-F-]{36}` ve `Requirement::UUID`. **Doğrulandı** örnek controller’larda.

Tarih: entity alanları `\DateTimeImmutable`. CI `TZ: UTC`. Uygulama `config/` içinde `Europe/Istanbul` araması eşleşmedi. Üretim saat dilimi **Doğrulanamadı**.

Messenger, lock, rate limiter ve mailer paketleri `composer.json` içinde. CI mail `null://null`, lock `flock`, messenger `sync://`. Üretim DSN’leri okunmadı; gizli dosya dökülmedi.

`README.md` satır 1–8 güncel ürünü anlatmaz. Güvenilir giriş `docs/architecture.md` ve `docs/product-roadmap.md` başlıklarıdır; onlar da kendi tarihlerini taşır.

## 7. Veri modeli ve migration geçmişi

105 `src/Entity/*.php` dosyası ve 60 `migrations/Version*.php` dosyası sayıldı. Dosya sayısı tablo sayısı değildir; guard entity’leri de sayıldı. Üretimde hangi migration’ın uygulandığı **Doğrulanamadı** (SSH ve DB yok).

| Grup | Örnek entity | İlişki / kural | Migration | Eksik |
|---|---|---|---|---|
| Kimlik | `User`, `ResetPasswordRequest`, `PhoneVerificationClaim`, `SecurityAuditEvent` | E-posta `normalizedEmail` unique | `Version20260907180510` en erken dosya adı | Üretim satırı ölçülmedi |
| Müfredat | `CurriculumProgram`, `CurriculumUnit`, `CurriculumTopic`, `CurriculumLearningOutcome`, `Subject` | Pedagojik ağaç | Ekim 2026 dosyaları var | Tüm kademelerde içerik doluluğu ölçülmedi |
| Katalog | `CatalogSubject`, `CatalogUnit`, `CatalogTopic`, `CatalogTopicLesson`, `CatalogTopicAssessment` | Yerleşim navigasyondur; gövde değildir | İlgili Version dosyaları listede | Karışık yerleşim durumu ürün kuralı olarak kodda ayrı rozet ister; üretim verisi yok |
| Öğrenme içeriği | `LearningContent`, `LearningContentRevision`, `LearningContentPublication`, `LearningDocumentAsset` | Revision + yayın ayrımı | Ekim migration’ları | M18 kısmi |
| Soru | `Question`, `QuestionRevision`, `QuestionRevisionOption`, `QuestionAnswerKey` | Sürüme bağlı cevap anahtarı | Eylül–Ekim | Anahtarın detay ekranında açılmaması testlerle aranır; üretim sızıntısı ölçülmedi |
| Test | `Assessment`, `AssessmentRevision`, `AssessmentSection`, `AssessmentItem`, `AssessmentPublication` | Mühürlü revision. Madde, kayıt anındaki soru revision’ına bağlanır | Eylül–Ekim | PR #186 bu zinciri değiştirmez; main’de değil |
| Çözüm | `AssessmentAttempt`, `AssessmentAttemptItem`, `AssessmentAttemptAnswer`, `AssessmentItemScore`, `AssessmentScoringRun` | Aktif attempt guard entity’leri var | Dosya var | Üretim attempt ölçülmedi |
| Kurum | `Institution`, `InstitutionMembership`, `InstitutionBranch`, `AcademicYear`, `Classroom`, davet ve enrollment | Üyelik rolü global rolden ayrı | Dosya var | Banka verisi `decision_needed` |
| Atama | `AssessmentDelivery`, `AssessmentDeliveryRecipient` | Kurum teslimatı platform denemesinden ayrı | Dosya var | Pilot `blocked` |
| Hak | `AccessPackage`, `AccessLicense`, `AssessmentAccessPolicy`, grant entity’leri | Politika yokluğu enum değeri değildir | Dosya var | Üretim politika sayısı yok |
| Ticaret | `CommerceOrder`, `PaymentAttempt`, `PaymentWebhookInboxEvent` | Çekirdek var; checkout kısmi | Dosya var | Canlı tahsilat **Doğrulanamadı** |

İlk migration dosya adı `migrations/Version20260907180510.php`. Son görülen adlar arasında `Version20261027120000.php` vardır. Adın tarihi, üretim uygulama tarihi değildir.

## 8. Kimlik, roller ve güvenlik

`User` (`src/Entity/User.php`): `normalizedEmail` unique, parola alanı hash olarak saklanır; yorum “already-hashed” ve `PasswordManager` der. `passwordChangedAt` vardır. Hasher `config/packages/security.yaml` içinde `algorithm: auto`. Auto’nun seçtiği algoritma bu turda çözülmedi.

`UserRole` (`src/Enum/UserRole.php` satır 13–24): `ROLE_USER`, `ROLE_STUDENT`, `ROLE_PARENT`, `ROLE_TEACHER`, `ROLE_EXPERT_TEACHER`, `ROLE_HEAD_TEACHER`, `ROLE_INSTITUTION_MANAGER`, `ROLE_MODERATOR`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`. Kurum üyeliği `InstitutionMembershipRole` ile ayrıdır (`teacher`, `student`). **Doğrulandı** enum metni. Hiyerarşinin her geçişi voter testleriyle tek tek yeniden koşulmadı.

Kayıt yolları `RegistrationFlow`: `ogrenci`, `veli`, `ogretmen`. Başvuru göndermek rol vermek değildir. **Belgede belirtiliyor** (`product-roadmap.md`); `TeacherApplicationManager` satırı bu turda açılmadı.

Route örnekleri: `/giris` HTTP 200 anonim. `/yonetim` ve `/ogrenci` anonimde 302 `/giris`. Bu, her yönetim alt yolunun aynı kapıdan geçtiğini kanıtlamaz; yalnız ölçülen iki path.

CSRF: test ve yerleşim formlarında token id’leri kodda (`test_editor`, `test_placement_archive_…`). Symfony form/CSRF paketi yüklü. “Bütün POST’lar CSRF’li” hükmü verilmez.

Kurum kapsamı: platform ve institution scope kod ve belgede ayrılır. Bu rapor her voter’ı yürütmedi.

Audit: `SecurityAuditEvent` entity ve `SecurityAuditAction` enum var. GET detayın audit yazmaması PR #186 test niyetidir; o test CI’da PR dalında geçti, main deploy’una dahil değil.

Öğrenci cevapları: `QuestionAnswerKey` ayrı entity. Attempt şifreleme anahtarı CI ortam değişkeni olarak workflow dosyasında durur. Değer rapora yazılmadı. Üretim anahtarının durumu **Doğrulanamadı**.

“Tam güvenli” denmez. Doğrulananlar: rol enum’u, e-posta tekilliği, hash alanının düz parola gibi belgelenmemesi, anonim yönetim/öğrenci yönlendirmesi, CI güvenlik adımı adının workflow’da olması (`Security audit`).

## 9. Müfredat ve öğrenme içeriği

Model [bölüm 7](#7-veri-modeli-ve-migration-geçmişi). Yönetim route örnekleri `AdminCurriculumController`: `/yonetim/programlar`, ders/kademe/sınıf erişimi, ünite/konu/kazanım yeniden adlandırma.

TYMM / MAT.1.3.3: dal adları `docs/mat-1-3-3-audit-checkpoint`, `docs/mat-1-3-3-assessment-publish-checkpoint`, `feature/tymm-g1-mat-1-3-3-content` remote’da durur. İçeriklerinin main’e işlenip işlenmediği bu turda her dal için diff alınmadı. Yol haritası MAT.1.3.3’ü tarihsel pilot olarak anar. Üretimdeki kazanım satır sayısı **ölçülmedi**; eski envanter sayısı yazılmaz.

İçerik akışı: revision, yayın, katalog yerleşimi ayrı kavramlar. `CatalogTopicLesson` ve `CatalogTopicAssessment` yerleşimdir. Yayınlamak ile paketi içeri aktarmak ayrı workflow’dur: `ops-catalog-import.yml`, `ops-learning-content-package-import.yml`, `ops-curriculum-pilot-import.yml` dosyaları vardır. Bu turda çalıştırılmadılar.

Checksum / idempotency: ticaret ve paket import belgelerinde geçer (`docs/question-package-import.md` dosya adı). Algoritma satırı bu turda açılmadı. **Dosya var; işleyiş satır satır doğrulanmadı.**

Platform / kurum: kurum soru route’u `/kurum/sorular/...` (`InstitutionQuestionAuthoringController`). Platform soru `/yonetim/sorular`.

## 10. Soru havuzu, testler ve çözüm akışı

Soru türü bu raporda tek bir enum dosyası taranarak kapatılmadı. Yol haritası platform tek seçim, CSV ve paket importunu `present` sayar (`M11-*`). AI import `planned`.

Yönetim soru route’ları `AdminQuestionController`: liste, yeni, göster, düzenle, önizleme, incelemeye gönder, taslağa döndür, yayınla, arşivle, görsel tara.

Test route’ları `AdminTestController`:

- GET `/yonetim/testler`, `/yeni`, `/{id}`, `/{id}/duzenle`, `/{id}/gorunum`
- POST oluşturma/düzenleme, `/erisim`, `/yerlesim`, yerleşim yayın/arşiv, `/incelemeye-gonder`, `/taslaga-dondur`, `/yayinla`, `/arsivle`

Öğrenci: `StudentAssessmentController` prefix `/ogrenci/testler`. Çözüm servisi `StudentAssessmentPractice`. Devam kartı `continueCard` [bölüm 13](#13-öğrenci-paneli-ve-devam-et-kartı).

Yayın koşulları `TestWorkflowProgress` içindedir: taslakta gönder, incelemede yazar yayınlayamaz, yayıncı yayınlar, yayında arşiv, arşivde geri açma yok. Bu sınıf PR #186 dalında mevcuttur ve main `add4a63` üzerinde de önceki test işinden gelir; satır farkı bu turda main’e karşı tam diff olarak yapıştırılmadı. Yazar/yayıncı ayrılığı belgede ve test adlarında geçer. PR #186 PHPUnit’i bu kuralı `fceeefd` CI’da geçirdi. Main deploy’u #185’tir; detay yerleşimi üretimde yoktur.

Tekrarlı istek: attempt ve yayın guard entity adları var (`AssessmentAttemptActiveGuard`). Davranış testi bu turda yeniden koşulmadı.

## 11. Kurum, sınıf, üyelik ve atama

Route kanıtı: `AdminInstitutionController` `/yonetim/kurumlar`, `AdminAcademicYearController` `/yonetim/akademik-yillar`, `AdminBranchController` `/yonetim/subeler`, `InstitutionWorkspaceController` kurum içi öğrenciler, `InstitutionQuestionAuthoringController` `/kurum`.

`AssessmentDelivery` ve `AssessmentDeliveryRecipient` entity dosyaları var. Alıcı uygunluğu, pencere ve iptalin başlamış denemeye etkisi `product-roadmap.md` ve servislerde anlatılır. Bu rapor her kuralı yürütmedi. `X08-classroom-pilot` durumu `blocked`: “Gerçek kurum öğretmen sonuç pilotu”. **Belgede belirtiliyor.** Pilotun üretimde yapılmadığı bu etiketle uyumludur; ayrıca canlı veri sayılmadı.

Bağımsız öğretmen sınıfı `M06-independent-classes` `decision_needed`. Kurum sınıfı ile aynı ürün sayılmaz.

## 12. Erişim politikaları ve paket/hak sistemi

`ResourceAccessClass` yalnız `free` ve `entitlement_required` tutar (`src/Enum/ResourceAccessClass.php`). “Politika yok” üçüncü bir enum değeri değildir; politika satırının olmamasıdır. Arayüz metni “Politika tanımlanmamış” PR #186 şablonunda kullanılır. Main’deki metin bu turda ayrıca diff’lenmedi.

`EntitlementAccessGate` `src/Service/EntitlementAccessGate.php` satır 47. Platform denemesi ile kurum ataması farklı entity gruplarıdır (`AssessmentAccessPolicy` / `AssessmentDelivery`).

PR #103 MERGED 2026-10-06T15:16:49Z: “Add read-only assessment access policy inventory workflow”. PR #104 MERGED 2026-10-06T17:53:21Z: “Add admin assessment access policy management on test detail”. İkisi de açık değildir.

Ücretsiz erişimde açık onay (`confirm_free`) PR #186 ve mevcut form testlerinin konusu olarak kodda durur. Yetkisiz POST’un 403 olduğu iddiası, bu turda yeniden PHPUnit çalıştırılmadan, PR #186 CI’sının geçmesine dayanır. O CI main değildir.

Liste, detay ve çözümde politikanın aynı sonucu verdiği üretim verisiyle **Doğrulanamadı**.

## 13. Öğrenci paneli ve Devam et kartı

`StudentDashboardController` prefix `/ogrenci`. `StudentAssessmentPractice::continueCard(User $student, GradeLevel $grade): ?StudentContinueTestCard` satır 114. Seçim kuralının gövdesi bu turda satır satır yeniden okunmadı. Yol haritası kartı “gerçek sınava Devam et; ders ilerlemesi değildir” diye yazar. **Belgede belirtiliyor** ve metot varlığı **Doğrulandı**.

GET’in teslim etmemesi test niyeti olarak önceki teslimlerde ve PR #186 detay testinde geçer. `continueCard` için bu turda ayrı bir PHPUnit koşusu yok. Üretimde kartın göründüğü **Doğrulanamadı**. Anonim `/ogrenci` 302 `/giris` döndü.

## 14. Yönetim paneli ve diğer paneller

Menü linki işlev tamamlamak değildir. Aşağıdaki tablo route varlığıdır.

| Panel / modül | Route örneği | İşlev | Yetki | Test | Eksik |
|---|---|---|---|---|---|
| Genel yönetim kabuğu | `/yonetim` | Anonimde `/giris` | Oturum | HTTP 302 bu turda | Kabuğun üretim görüntüsü yok |
| Kullanıcı | `/yonetim/kullanicilar` | Liste, rol, durum | Admin controller | Dosya adı `AdminUserController` | Toplu import `planned` |
| Öğretmen başvurusu | `/yonetim/ogretmen-basvurulari` | Onay, ret, rol | POST route var | Dosya var | `M01-applications` `partial` |
| Kurum | `/yonetim/kurumlar` | CRUD, üye | Route var | Dosya var | Başvuru zinciri kısmi |
| Şube | `/yonetim/subeler` | Liste, oluştur, durum | Route var | Dosya var | `M05-branches` `partial` |
| Akademik yıl | `/yonetim/akademik-yillar` | Oluştur, aktifleştir | Route var | Dosya var | Üretim veri yok |
| Müfredat | `/yonetim/programlar` | Ağaç yönetimi | Route var | Dosya var | `M10-curriculum` `partial` |
| Soru | `/yonetim/sorular` | Yaşam döngüsü | Route var | Browser spec dosyaları var | Üretim yazımı bu turda yok |
| Test | `/yonetim/testler` | Liste ve editör main’de; detay düzeni PR #186 | Voter + workflow | PR #185 deploy; #186 değil | Detay yerleşimi üretimde yok |
| Haber | `/haberler` public; yönetim PR #151 açık | Metin haber | PR açık | `M27-news` `partial` | Merge edilmedi |
| Öğretmen profili | `/ogretmen/profil` | Form | Route var | Dosya var | Ayrı uzman paneli yok |
| Kurum yazar | `/kurum/sorular` | Soru yazma | Route var | Dosya var | Pilot blocked |
| Öğrenci | `/ogrenci`, `/ogrenci/testler`, `/ogrenci/dersler` | Panel ve çözme | Anonim 302 | Servis var | Üretim oturumu yok |
| Veli | Belgede `ParentDashboardController` | Bağlantı ve özet | Kayıt `present` | Bu turda satır açılmadı | Mesaj `planned` |

## 15. Tasarım ve kullanıcı deneyimi kararları

Belgeler: `docs/ui-design-system.md`, `docs/admin-ui-guidelines.md`, `docs/student-parent-ui-guidelines.md`, `docs/teacher-institution-ui-guidelines.md`. İçerikleri bu turda baştan sona okunmadı; dosya varlığı **Doğrulandı**.

V1 ana sayfasında mor/turuncu denemesi `web-projem` 2026-08-16 commit mesajlarında geçer (V20, mor header, turuncu ana sayfa). V2 yönetim kabuğu turkuaz aktif menü dilini PR #186 görsel kabul notlarında kullanır. O not CI ekran görüntüsüdür, üretim görüntüsü değildir.

Morpa Kampüs referansı bu turda depo içinde aranıp bulunan bir karar kaydı olarak açılmadı. **Doğrulanamadı.**

Responsive: browser acceptance Chromium ile sentetik genişlik kullanır. Gerçek telefon veya tablet bu turda kullanılmadı. `X07-mobile-acceptance` `blocked`.

Erişilebilirlik: V1 commit’i kontrastı anar. V2’de odak halkası PR #186 CSS’inde vardır. Üretimde klavye turu yapılmadı.

## 16. Testler ve kalite kontrolleri

Sayılar dosya sayısıdır. Test metodu veya assertion sayısı değildir.

- `tests/` altında 315 PHP dosyası.
- `browser/tests/` altında 21 `*.spec.ts`.

Alanlar dosya yerleşiminden: `tests/Controller`, `tests/Browser`, birim sınıflar `tests/` altında. Her dosyanın türü tek tek etiketlenmedi.

| Kontrol | Yapılandırma | Son doğrulanan sonuç | Tarih / SHA | Sınırlama |
|---|---|---|---|---|
| composer validate, security audit, PHP-CS-Fixer, PHPStan, container lint, Twig lint, migration, şema, PHPUnit | `.github/workflows/ci.yml` job `quality` | Main CI success [41209234805](https://github.com/testligtr-collab/testlig-net/actions/runs/41209234805) | 2026-10-31, `add4a63` | Adımlar yeşil işin içinde. Ayrı log satırı bu turda açılmadı |
| Browser acceptance | Aynı workflow job `browser-acceptance`, Chromium | Aynı main CI success | Aynı SHA | Sentetik veri. Canlı kabul değil |
| PR #186 Quality & tests ve Browser | Run [41223148645](https://github.com/testligtr-collab/testlig-net/actions/runs/41223148645) | pass | HEAD `fceeefd`, 2026-10-31 akşam CI | Main’de değil, deploy yok |
| Roadmap coverage | `.github/workflows/roadmap-coverage.yml`, çek adı `coverage` | PR #186 pass [41291864363](https://github.com/testligtr-collab/testlig-net/actions/runs/41291864363) | 2026-11-01, gövde düzenlemesi sonrası | Kod değiştirmedi. Özelliği bitirmez |
| Yerel PHPUnit | — | Bu turda çalıştırılmadı | — | — |

## 17. GitHub, CI/CD ve üretim geçmişi

Branch modeli: `main` ve `feature/*`, `docs/*`, `ops/*`. Açık PR’lar bu turda:

| PR | Başlık | Dal | Taslak | Güncelleme |
|---|---|---|---|---|
| [#186](https://github.com/testligtr-collab/testlig-net/pull/186) | Lay out the admin test detail from the current revision | `feature/m12-admin-test-detail` | Evet | 2026-11-01T09:09:28Z |
| [#168](https://github.com/testligtr-collab/testlig-net/pull/168) | OLS worker refresh | `feature/x06-ols-refresh` | Hayır | 2026-10-24 |
| [#157](https://github.com/testligtr-collab/testlig-net/pull/157) | lsphp83 security apply | `feature/x11-lsphp83-security-apply` | Hayır | 2026-10-21 |
| [#151](https://github.com/testligtr-collab/testlig-net/pull/151) | Text news management | `feature/m27-news` | Hayır | 2026-10-20 |
| [#149](https://github.com/testligtr-collab/testlig-net/pull/149) | Question image results and ClamAV plan | `feature/m18-clamav-app-connection-plan` | Hayır | 2026-10-19 |
| [#101](https://github.com/testligtr-collab/testlig-net/pull/101) | Product inventory and MAT.1.3.3 checkpoint | `docs/product-inventory-2026-10-06` | Hayır | 2026-10-06 |

CI: push ve pull_request `main`. `cancel-in-progress: true` yalnız CI grubunda. Deploy grubu `testlig-vds-ssh`, `cancel-in-progress: false`.

Deploy `.github/workflows/deploy.yml`: `workflow_run` CI success ve `push` ve `vars.VDS_AUTO_DEPLOY == 'true'` ve `vars.VDS_SSH_PAUSED != 'true'`. Ayrıca `workflow_dispatch` var; bu turda tetiklenmedi. Duraklatma işi SSH açmadan mesaj basar.

SSH istemci kuralı **Belgede belirtiliyor:** `docs/ops-vds-ssh.md` satır 30, `ConnectionAttempts 1`, parola yok, başarısız bağlantı yeniden denenmez. Üst üste oturumun IP engeli ürettiği olay bu turda bir incident kaydından yeniden okunmadı. **Doğrulanamadı** olarak olay; kural metni doğrulandı.

Son doğrulanan ayrı kimlikler:

| Katman | SHA / sonuç | Zaman |
|---|---|---|
| `origin/main` | `add4a63fb163d1ffa286f0742c259ae2edde8d7d` | commit 2026-10-31 17:14 +0300 |
| Main CI | success, run 41209234805 | 2026-10-31T14:14:50Z |
| Main deploy | success, run 41211221505, `workflow_run` | 2026-10-31T14:40:28Z |
| Release dizin adı | okunmadı | — |
| Açık iş HEAD | `fceeefd9c4ba43b356d632e9f04590003090574a` | main’den 5 commit ileride, merge yok |
| Üretim HTTP | `/` 200, `/giris` 200, `/yonetim` 302, `/ogrenci` 302 | 2026-11-01 ~12:30 +0300 |
| Üretim kodunun `add4a63` olduğu | Deploy success bunu hedefler | Sunucuda `rev-parse` yapılmadı. **Çıkarım:** başarılı deploy kaydı bu SHA’yı aktarmış olmalı; disk üstü kanıt bu turda yok |

GitHub Release ve tag yok.

## 18. Önemli kararlar ve yön değişiklikleri

| Karar | Tarih | Gerekçe | Kanıt | Bugünkü etki |
|---|---|---|---|---|
| V1 geçmişini yeni köke almamak | 2026-09-07 | Yeni platform foundation | Ebeveynsiz `73c9c86`; `web-projem` ayrı | Eski oyun/CMS kodu otomatik gelmez |
| Docker ile başlamak | 2026-09-07 | Geliştirme ortamı | Commit `458a321`; README | Belge durur |
| Günlük işi GitHub CI’ya almak | 2026-09-07’den beri, kural metni güncel | Tekrarlanabilir test | `ci.yml`; `AGENTS.md` | Docker bu görevde açılmaz |
| Yayın, erişim ve katalog ayrı | 2026-10-06 ve sonrası | Yanlış “yayın = ücretsiz” sonucu | PR #104; `ResourceAccessClass`; üç entity grubu | Politika yokluğu ücretsiz değildir |
| Yazar yayıncı olamaz | Test workflow | Görev ayrımı | `TestWorkflowProgress` | SuperAdmin dahil kod kuralı; üretim denemesi yok |
| Tek SSH, yeniden deneme yok | Ops belgesi | Bağlantı fırtınasını kesmek | `deploy.yml` concurrency; `ops-vds-ssh.md` | Pause değişkeni okunmadı |
| Yol haritası kimliği silinmez | 10 Ekim 2026 belge | Kapsam kaybını önlemek | `features.json` `schema_version` 1, baseline `2a615f11…` | Aktif iş yalnız `X10-admin-navigation` |
| X10 bitmeden yeni ürün modülü açmamak | `AGENTS.md` | Tek aktif iş | Belge | PR #186 X10’u `partial` bırakır |

## 19. Tamamlanan, kısmen tamamlanan ve başlanmayan işler

`features.json` durumu “kodlandı ve dar akış kayıtlı” iddiasıdır. Sütunlar bu incelemenin gördüğüdür. “Main” evet ise özellik kimliği main’deki dosyada durur; main SHA `add4a63` bu dosyayı içerir çünkü özellik dosyası bu HEAD’de de aynı kayıtla okundu ve PR #186 onu main’in üstüne ekler. PR #186’nın main’e girmediği ayrı satırdadır.

Tam 73 satır [ekler](testlig-proje-gecmisi-ekler-2026-11-01.md) dosyasındadır. Özet:

| İş alanı | Özellik | Kod kaydı | Test | Main | Deploy | Üretim kontrolü | Eksik |
|---|---|---|---|---|---|---|---|
| Hesap | E-posta kayıt/giriş | `present` | CI main yeşil, hedefli yeniden koşu yok | Kayıt main ağacında | Son deploy main | Yalnız `/giris` 200 | SMS, OAuth, üye kodu |
| Kurum | Workspace, sınıf, yıl | `present` / şube `partial` | Dosyalar var | Evet | Son deploy | Doğrulanamadı | Pilot blocked |
| Soru | Platform + kurum + CSV | `present` | Browser dosyaları var | Evet | #183 deploy | Bu turda yok | AI import planned |
| Test | Platform, kurum yazarlığı, teslim, hak | `present` | PR #186 ek testleri main’de değil | Çekirdek main’de | #185’e kadar | Detay düzeni yok | PR #186 taslak |
| Öğrenci | Öğrenme + devam kartı | Metot var; ilerleme `planned` | Dosya var | Evet | Son deploy | Anonim 302 | Ders ilerlemesi |
| Veli | Bağlantı | `present` | Bu turda açılmadı | Belge | Doğrulanamadı | Doğrulanamadı | Mesaj |
| Ticaret | Çekirdek `present`, checkout `partial` | Entity var | Dosya sayıldı | Evet | Doğrulanamadı | Doğrulanamadı | Canlı ödeme |
| Medya | Yerel PDF `present`, bulut `partial` | PR #149 açık | — | Kısmi | Doğrulanamadı | Doğrulanamadı | ClamAV bağlantısı |
| Yönetim kabuğu | X10 `partial` | Ekranlar var | Sentetik | Kısmi | Son görsel deploy’lar | Canlı kabul ayrı | Aktif iş bu |
| Mobil | API ve Flutter `planned`, kabul `blocked` | Ürün yok | — | Hayır | Hayır | Hayır | Başlanmadı |
| Oyun, Q&A, mesaj, bildirim, canlı ders, simülasyon | `planned` | V2 karşılığı yol haritasında yok | — | Hayır | Hayır | Hayır | V1’de iz varsa bile V2’ye gitmez |

## 20. Açık sorunlar, teknik borç ve riskler

| Bulgu | Etki | Kanıt | Önem | Önerilen çözüm |
|---|---|---|---|---|
| `README.md` ürünü “iş modülü yok” diye anlatır | Yeni geliştirici yanlış yere bakar | README satır 7–8 karşı `src/Controller` | Orta | README’yi güncellemek ayrı bir docs işidir; bu turda yapılmadı |
| Çalışma kopyası detached HEAD | Yanlışlıkla commit kayması | `git status -sb` | Orta | İşe dönünce dal adı bilinçli seçilsin. Bu rapor checkout yapmadı |
| `C:\xampp\htdocs\testlig-net` de aynı feature dalında | İki kopya aynı SHA’da. Eski “main’e alma” yasağı bu anda dal adı olarak durmuyor | `git -C` okuma | Orta | Hangi kopyanın kirli olduğu işe başlamadan yeniden bakılsın |
| PR #186 main’de değil | Detay ekranı üretimde yok | PR açık taslak | Ürün | Merge kararı ayrı görev. Bu rapor merge etmez |
| PR #149, #151, #157, #168 açık | Medya, haber, PHP güvenlik uygulama, OLS refresh bekliyor | `gh pr list` | Yüksek operasyon | Her biri kendi kabulü olmadan kapatılmamalı |
| X07 ve X08 `blocked` | Mobil üretim kabulü ve gerçek pilot yok | `features.json` | Ürün | Sentetik CI ile kapatılamaz |
| Üretim release dizini ve disk SHA okunmadı | Deploy success ile disk eşitliği çıkarım | Run 41211221505 | Orta | Sonraki yetkili turda tek SSH, retry yok |
| `VDS_SSH_PAUSED` değeri okunmadı | Otomatik deploy durmuş olabilir | Workflow koşulu | İnceleme ihtiyacı | Değişkeni yazmadan `gh variable list` ile bakılabilir |
| README Docker önerisi ile AGENTS CI kuralı | Yanlış ortam açılması | İki belge | Orta | Tek çalışma kuralı AGENTS |
| Politika “yok” enum’da yok | Yanlış eşleme ücretsiz sanabilir | `ResourceAccessClass` iki case | Ürün kuralı | Yokluk ile `free` karıştırılmamalı |
| Eski V1 oyun/CMS | V2’de karşılığı `planned` veya yok | web-projem mesajları + features | Kapsam | V1 kodunu kopyalamadan ürün kararı gerekir |

Varsayımsal güvenlik açığı üretilmedi.

## 21. Son çalışma noktası

Son ürün işi admin test detayıdır. PR [#186](https://github.com/testligtr-collab/testlig-net/pull/186) taslak. HEAD `fceeefd9c4ba43b356d632e9f04590003090574a`. Main’den commit’ler: detay şablonu, yol haritası satırı, tarayıcı fixture, yakalama yüksekliği, yerleşim arşiv seçicisi.

Geçen kontroller bu SHA’da: Quality & tests ve Browser acceptance run 41223148645; Roadmap coverage run 41291864363 (2026-11-01 gövde güncellemesi). Deploy yok. Üretim davranış yok. Merge yok.

Eski devir belgelerindeki “son durum” main’i daha eski SHA’larda bırakır (`product-roadmap.md` baseline `2a615f11…`, 10 Ekim). Güncel main `add4a63` o baseline’dan ileridedir. Baseline bilerek eski tutulur; kimlik silinmez.

Açık adım: detay ekranını incelemede bırakmak veya ayrıca istenirse merge ve tek deploy. Bu rapor ikisini de yapmaz.

## 22. Öncelikli devam planı

Süre tahmini yok. Gün tahmini dayanak olmadan yazılmaz.

### P0

Amaç: yanlış SHA’ya işlem yapılmasını engellemek. Bağımlılık: yok. Alan: git durumu, açık PR listesi. Kabul: işe başlayan kişi main `add4a63`, açık HEAD `fceeefd`, detached kopya ve `C:\xampp\htdocs\testlig-net` dalını yeniden okur. Doğrulama: `git status` ve `gh pr view 186`. Kod yazılmaz.

### P1

Amaç: PR #186 incelemesini bitirmek. Bağımlılık: P0. Alan: mevcut detay şablonu; yeni ekran yok. Kabul: üç kontrol aynı HEAD’de pass kalır, görsel fark listesi durur, merge ancak açık istek olursa. Doğrulama: mevcut run URL’leri. Yeniden CI tetiklemek zorunlu değildir.

Amaç değil: test listesini, editörü veya soru editörünü yeniden tasarlamak. Onlar main’de ve deploy kaydı var.

### P2

Sırayı `active_feature_ids` belirler: `X10-admin-navigation` `partial`. Sonraki yönetim ekranı ancak X10 kaydı ve ayrı görevle açılır. M18 PR #149 ve haber PR #151 kendi incelemeleridir; bu plana otomatik alınmaz.

### P3

README’nin aşama 1 cümlesini düzeltmek, `decision_needed` kayıtlarını ürün kararıyla `retired` veya tasarıma bağlamak, mobil kabulü gerçek cihazla yapmak. Bunlar blocked işi CI ile kapatmaz.

## 23. Devir özeti

Önce okunacaklar:

1. `AGENTS.md`
2. `docs/product-roadmap.md` ve `docs/roadmap/features.json`
3. `docs/architecture.md` ve alan mimarileri (`architecture-access-entitlement.md`, `architecture-learning-content.md`, `architecture-auth-membership-onboarding.md`)
4. `.github/workflows/ci.yml` ve `deploy.yml`
5. `docs/ops-vds-ssh.md`
6. Bu rapor ve [ekler](testlig-proje-gecmisi-ekler-2026-11-01.md)

Giriş noktaları: `src/Controller`, `src/Service/EntitlementAccessGate.php`, `src/Service/StudentAssessmentPractice.php`, `src/Presentation/TestWorkflowProgress.php`, `src/Entity/User.php`.

Komutlar: test için GitHub CI. Yerel PHPUnit bu projenin güncel kuralında yerel veritabanına karşı çalıştırılmaz. Docker açılmaz. Deploy SSH retry yok.

Güvenlik: gizli dosya dökülmez. `assessment.author` yayın yetkisi değildir. Politika yokluğu ücretsiz değildir. GET inceleme ekranı yazmaz.

Son doğrulanmış durum: main `add4a63` CI ve deploy success; üretim HTTP kapısı ayakta; PR #186 taslak ve deploy edilmedi; X10 partial; M12 present ama “sınav ürünü bitti” değildir.

İlk iş: P0 durum okuması, ardından PR #186’yı istenmeden merge etmemek.

Bilinmeyenler: üretim disk SHA’sı, release dizin adı, `VDS_*` değişken değerleri, üretim satır sayıları, canlı kullanıcı akışları, V1 ile V2 arasındaki dosya düzeyinde taşıma, Docker’ın bırakıldığı tek commit, tüm Cursor sohbetleri.

## 24. Ekler

Ayrıntılı indeksler: [testlig-proje-gecmisi-ekler-2026-11-01.md](testlig-proje-gecmisi-ekler-2026-11-01.md).
