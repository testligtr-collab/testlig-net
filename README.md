# Testlig
#
# Eğitim platformu — Symfony 7.4 LTS tabanlı modüler monolit altyapısı (Aşama 1).

## Amaç

Testlig; web ve ileride Flutter mobil istemcileri için ortak bir eğitim platformu backend’i olacaktır.
Bu depo şu an yalnızca temiz proje altyapısını içerir (iş modülleri sonraki aşamalarda eklenir).

## Gereksinimler

| Bileşen | Hedef |
|---------|--------|
| PHP | 8.3 (Docker imajı) |
| Composer | 2.x |
| Symfony | 7.4 LTS |
| MariaDB | 10.11 |
| Redis | 7.x (Compose) |
| Docker / Compose | Geliştirme için önerilir |

Yerel makinede PHP 8.2 ile sınırlı komutlar çalıştırılabilir; tam hedef çalışma zamanı Docker içindeki PHP 8.3’tür.

## Ortam değişkenleri

```powershell
copy .env.example .env.local
# .env.local içinde APP_SECRET, MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD ve DATABASE_URL değerlerini ayarlayın
```

- `.env` — güvenli geliştirme varsayılanları (commit edilir)
- `.env.example` — açıklamalı şablon
- `.env.local` — gerçek yerel değerler (commit edilmez)
- `.env.test` — PHPUnit için ayrı test yapılandırması

## Docker ile kurulum

```powershell
cd C:\xampp\htdocs\testlig-net
copy .env.example .env.local
# .env.local düzenleyin, ardından:
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php bin/console doctrine:database:create --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

Uygulama: http://localhost:8080
Sağlık kontrolü: http://localhost:8080/health
Mailpit (doğrulama e-postaları): http://localhost:8025

### Zaman dilimi (UTC persistence)

- Database DATETIME alanları **UTC** saklanır.
- PHP runtime (`date.timezone`), Doctrine bağlantısı (`SET time_zone = '+00:00'`) ve MariaDB session/default timezone UTC’dir.
- `User::$timezone` (varsayılan `Europe/Istanbul`) yalnız **gösterim** tercihidir; UTC → kullanıcı timezone dönüşümü presentation katmanında yapılır (`App\Time\UtcInstant`).
- İstemci timestamp’i güvenlik/expiry kararının kaynağı değildir.
- Production’da DB connection session timezone `+00:00` zorunlu ayarlanmalıdır (middleware + INIT_COMMAND ile sağlanır).

Kayıt: http://localhost:8080/kayit · Giriş: http://localhost:8080/giris · Hesap: http://localhost:8080/hesabim
Şifremi unuttum: http://localhost:8080/sifremi-unuttum · Parola değiştir: http://localhost:8080/hesabim/sifre-degistir
Öğrenci kurulum: http://localhost:8080/ogrenci/kurulum · Panel: http://localhost:8080/ogrenci · Profil: http://localhost:8080/ogrenci/profil
Öğrenci dersler: http://localhost:8080/ogrenci/dersler · Yönetim müfredat: http://localhost:8080/yonetim/mufredat

Public kayıt yalnızca **öğrenci** (`ROLE_STUDENT`) oluşturur; e-posta doğrulanana kadar giriş yapılamaz.
Doğrulanmış öğrenci ilk girişte `/ogrenci/kurulum` ile kısa profil kurulumundan geçer; tamamlanınca `/ogrenci` sade paneline yönlendirilir.
Öğrenci ders kataloğu (`CatalogSubject` → `CatalogUnit` → `CatalogTopic`) yalnız **yayımlanmış** hiyerarşiyi gösterir; Stage 2.7 kurum müfredatı (`CurriculumProgram`) ayrıdır. Otomatik production seed yoktur.

### MEB katalog içe aktarma (TYMM)

Resmî fixture örneği: `data/catalog/meb/tymm-2026/grade-1-matematik.yaml`  
Kaynak: TTKB PID=2339, sürüm `TYMM-2026` (program + DÖP PDF URL’leri fixture içinde sabittir).

```powershell
# Dry-run (varsayılan; DB yazmaz)
php bin/console app:catalog:import --file=data/catalog/meb/tymm-2026/grade-1-matematik.yaml

# Draft satırları yaz (publish etmez)
php bin/console app:catalog:import --file=data/catalog/meb/tymm-2026/grade-1-matematik.yaml --apply

# Mevcut source kimliğinde ad/sıra/url güncelle (opsiyonel)
php bin/console app:catalog:import --file=data/catalog/meb/tymm-2026/grade-1-matematik.yaml --apply --update-existing

# TYMM pilot curriculum outcome (LearningContent primary-outcome form). Dry-run default.
php bin/console app:curriculum:import-pilot-outcome --file=data/curriculum/meb/tymm-2026/grade-1-matematik-uzamsal-iliskiler.yaml
php bin/console app:curriculum:import-pilot-outcome --file=data/curriculum/meb/tymm-2026/grade-1-matematik-uzamsal-iliskiler.yaml --apply
```

- Idempotency: `source_version` + `source_code` + `source_occurrence` (aynı MEB kodunun tekrarlayan temaları `occurrence` ile ayrılır).
- Hata → tek transaction rollback; paralel import `app.catalog.import` kilidi ile engellenir.
- Import asla publish/archive/delete yapmaz.
- Curriculum pilot import: natural keys program `(subject, grade, code, version)` + unit/topic/outcome codes; requires active Subject + SuperAdmin; does **not** create LearningContent or placements.

### MEB katalog yayınlama (ağaç)

Bottom-up atomik yayın (`topics` → `units` → `subject`). Varsayılan preflight/dry-run; yalnız `--apply` yazar.

```powershell
php bin/console app:catalog:publish-tree --source-version=TYMM-2026 --subject-code=MAT --expected-subjects=1 --expected-units=7 --expected-topics=19
php bin/console app:catalog:publish-tree --source-version=TYMM-2026 --subject-code=MAT --expected-subjects=1 --expected-units=7 --expected-topics=19 --apply
```

- Beklenen sayılar zorunlu güvenlik kilidi; sapma → yazma yok.
- Yalnız draft yayımlanır; archived / yanlış sürüm / yanlış sınıf → durur.
- **Publish tree:** `app:catalog:publish-tree` — zorunlu `--expected-subjects/units/topics`; varsayılan dry-run; `--apply` ile topics→units→subject; archived/yanlış sürüm/sınıf reddi; tek TX + `app.catalog.publish-tree` lock; tamamen published → no-op.
- **Topic lesson placement (domain):** `CatalogTopic` → N `CatalogTopicLesson` → 1 `LearningContent`. Placement = navigation only; content lifecycle stays in Stage 2.15. Explicit `CatalogSubject.canonical_subject_id` → `subjects` (no name/slug auto-map). No production seed / lesson body in this slice.
- **Admin content workspace:** `/yonetim/icerikler` — LearningContent list/detail/create (platform drafts). Draft revision block editor at `/yonetim/icerikler/{id}/revision` (typed fields only; admin preview at `.../revision/taslak-gorunum`). Lifecycle on detail: submit-for-review / return-to-draft / publish / archive (POST+CSRF+PRG; note=`reason_code`; Moderator return only; publish requires access policy first + SoD). Catalog subject show maps canonical Subject by UUID only (unmap blocked when placements/LC bound). Access policy fail-closed by default; Free requires explicit confirm + audit (“Bu içerik ücretsiz erişime açılacak”).
- **Admin placement bind:** Catalog topic show `/yonetim/mufredat/konu/{id}` — placement list + create (published LC) / publish (confirm) / archive via `CatalogTopicLessonManager`. Also create-from-LC-detail. Duplicates (slug/position/content) flash; no student body in admin.
- **Student topic page:** Published topic cards link to `/ogrenci/dersler/{subjectSlug}/{unitSlug}/{topicSlug}`. `StudentTopicContentQuery` returns topic meta + AND-gated placements with normalized typed block views (no revision UUID / storageKey / raw JSON). Empty placements show “Bu konu için öğrenme adımları hazırlanıyor.” `/ogrenci/*` → `Cache-Control: no-store, private`.
- Production ops: `ops-catalog-publish-tree.yml` (yalnız workflow_dispatch).

Çıkış yalnızca `POST /cikis` (CSRF zorunlu). Giriş hataları generic mesaj kullanır (hesap durumu ifşa edilmez).
Parola sıfırlama yalnızca **active** hesaplara e-posta gönderir; public cevap her durumda aynıdır.
Süresi dolmuş reset kayıtları: `docker compose exec app php bin/console reset-password:remove-expired`
Mailpit UI yalnızca localhost’ta dinler (`127.0.0.1:8025`).

### Öğrenci ilk giriş ve panel temeli

- `StudentProfile` ↔ `User` bire bir; sınıf (1–12), isteğe bağlı okul/şehir/öğrenme hedefi; doğum tarihi/telefon/adres yok.
- Onboarding tamamlanmadan `/ogrenci` ve `/ogrenci/profil` kurulum sayfasına yönlendirir; tamamlanmış kurulum paneline döner.
- Güvenli `_target_path` korunur; varsayılan login hedefi öğrenci için panel/kurulum, diğer roller için `/hesabim`.
- Dersler: `/ogrenci/dersler` (sınıf seviyesine göre published katalog). Sınavlar / öğrenme araçları henüz “Yakında”.
- Hesap/parola `/hesabim` altında kalır.
- Ayrıntı: `docs/architecture-auth-membership-onboarding.md` (öğrenci panel dilimi); katalog: `docs/architecture.md`.

### Security audit ve SUPER_ADMIN bootstrap (Aşama 2.4)

- Kritik güvenlik olayları `security_audit_events` tablosuna **append-only** yazılır (okuma paneli/API yok).
- Ham IP / User-Agent saklanmaz; `AUDIT_HASH_KEY` ile HMAC. Production’da benzersiz anahtar kullanın; commit etmeyin.
- İlk SUPER_ADMIN (yalnızca kontrollü kurulum):

```powershell
# .env.local içinde geçici olarak:
# ALLOW_SUPER_ADMIN_BOOTSTRAP=1
docker compose exec -e ALLOW_SUPER_ADMIN_BOOTSTRAP=1 app php bin/console app:user:bootstrap-super-admin --email=admin@example.com --confirm
# Parola hidden input ile sorulur; CLI argümanı olarak vermeyin.
# İşlem bitince ALLOW_SUPER_ADMIN_BOOTSTRAP=0 yapın.
```

### Kurum / üyelik omurgası (Aşama 2.5)

- Global `ROLE_TEACHER` / `ROLE_INSTITUTION_MANAGER` vb. **otomatik kurum erişimi vermez**.
- Erişim: active `Institution` + active `InstitutionMembership` (veya active+verified SUPER_ADMIN override).
- Suspended / archived / doğrulanmamış hesaplar kurum işlemi yapamaz (SUPER_ADMIN görünse bile).
- Bu aşamada UI / public kurum kaydı / davet yok; yalnızca domain servisleri.
- Kurum oluşturma: internal `InstitutionCreator` (SUPER_ADMIN).

### Akademik yıl / sınıf (Aşama 2.6)

- `AcademicYear` / `Classroom` / öğretmen ataması / öğrenci enrollment domain omurgası (UI yok).
- Kurumda tek active yıl: activate önceki active yılı aynı TX’de kapatır (`institution_active_academic_year_guards`).
- Transfer: eski enrollment biter, yeni satır oluşur (tarihçe korunur).
- Üyelik rolleri: owner / manager / teacher / staff / **student** (owner+manager student atayabilir).
- Yetki: `ClassroomVoter` + DBAL snapshot; global roller tek başına sınıf erişimi vermez.
- DB tenant/guard tutarlılığı: composite UNIQUE + composite FK (migration + `AcademicClassroomCompositeForeignKeyListener`).
- Aktif assignment/enrollment varken membership rol/status değişimi typed conflict ile reddedilir.
- `changeCapacity` aktif enrollment sayısının altına inemez.

### Müfredat / ders (Aşama 2.7)

- Platform-global `Subject` + versioned `CurriculumProgram` / unit / topic (max depth 2); UI/API yok.
- Topic sibling position DB garantisi: generated `position_scope_id` (nil-UUID sentinel for roots) + `UNIQUE(unit_id, position_scope_id, position)`.
- Kurum `ClassroomCourse` (sınıf+ders+yayınlı müfredat) ve `CourseTeacherAssignment` + active guard tabloları.
- Published müfredat yapısal olarak immutable; yeni sürüm `cloneAsNewVersion` ile draft kopyalanır. Retired tekrar publish edilemez.
- Yetki: `CurriculumVoter` (published VIEW her active+verified kullanıcıya; manage/publish/retire SUPER_ADMIN), `ClassroomCourseVoter` (owner/manager tam; course teacher VIEW+CURRICULUM_VIEW; homeroom VIEW+CURRICULUM_VIEW+TEACHERS_VIEW; staff VIEW).
- Composite FK’ler: `CurriculumCourseCompositeForeignKeyListener` + `CompositeForeignKeySchemaHelper` (2.6 listener ince kaldı).
- Aktif course teacher assignment, membership suspend/end/role değişimini de bloklar.

### Soru bankası / kazanım (Aşama 2.8)

- `CurriculumLearningOutcome` topic altında; `UNIQUE(program, code)` + `UNIQUE(topic, position)`; yalnız draft curriculum’da mutate; `cloneAsNewVersion` LO’ları yeni UUID ile kopyalar.
- Versioned `Question` + immutable `QuestionRevision` / options / isolated `QuestionAnswerKey` / alignments + primary alignment guard.
- Scope: `platform` | `institution` (CHECK + servis); published içerik düzenlenmez — yeni revision.
- Public `contentHash` answer içermez (oracle-safe). Answer bütünlüğü `QUESTION_ANSWER_INTEGRITY_KEY` ile HMAC-SHA256 (`answer_integrity_hmac`, serializer dışı); publish’te `hash_equals` + reason `answer_integrity_failed` (generic mesaj; `answer_invalid`’dan ayrı). HMAC şifreleme değildir; at-rest encryption ertelendi.
- Production key: ≥32 byte; placeholder/`change_me`/`not_for_production`/`test_`/`ci_` yasak. APP_SECRET fallback yok.
- Append-only: ORM listener + MariaDB BEFORE UPDATE/DELETE triggers (`trg_question_*`). DELETE trigger’larda session bypass yok (`Version20260909200000`); MariaDB FK cascade child DELETE trigger’ları çalıştırmaz. Test cleanup: `DELETE FROM questions` (CASCADE) — `QuestionBankDbCleanup`. Uygulamada question hard-delete yok (yalnız archive); testler fixture wipe için hard-delete edebilir.
- Primary alignment: STORED `primary_revision_scope_id` UNIQUE + guard `must_be_primary` composite FK.
- Structured JSON content; HTML/script yok; `sourceReference` opaque (URL/path yok).
- Lifecycle: draft → in_review → published → archived; review separation; publish fresh hydration.
- Kilit: snapshot → Institution? → Subject → Curriculum → Question → Users → Revision.
- Yetki: `QuestionVoter` + DBAL snapshot; ADMIN/MODERATOR otomatik publish yok.
- UI/API/sınav motoru yok.
- Migrations: `Version20260909180000` + `Version20260909190000` (tarihsel bypass yalnızca bu dosyada) + `Version20260909200000` (bypass-free DELETE + HMAC hex CHECK).

### Sınav / deneme tanımı (Aşama 2.9)

- Stable `Assessment` + sealed immutable `AssessmentRevision` / `AssessmentSection` / `AssessmentItem` + append-only `AssessmentPublication` (public manifest + SHA-256).
- Puanlar `DECIMAL` string + bcmath (PHP float yok). Manifest cevap/HMAC/e-posta/secret içermez.
- Sealed revision: bundle sonrası `is_sealed` 0→1; sealed’a section/item INSERT trigger reddeder. Bypass/session değişkeni yok.
- Test cleanup: `DELETE FROM assessments` (CASCADE) — `AssessmentDbCleanup`. Pointer NULL UPDATE yok; production trigger publication varken published pointer temizlemeyi reddeder. Uygulamada hard-delete yok (archive); testler fixture wipe için parent DELETE kullanır.
- Kilit: snapshot → Institution? → Assessment → Subjects → Questions → QuestionRevisions → Users → Revision/sections/items → Publication.
- Yetki: `AssessmentVoter` (VIEW/CREATE/REVISE/SUBMIT/REVIEW/PUBLISH/ARCHIVE); review separation; ADMIN/MODERATOR otomatik publish yok.
- Delivery / attempt / scoring / result / UI / API yok. Multi-process concurrency testi yok. Migration: `Version20260910120000` + `Version20260910200000` + `Version20260910300000` + `Version20260910400000`.

### Sınav atama / delivery (Aşama 2.10)

- `AssessmentDelivery` + immutable `AssessmentDeliveryRecipient` snapshot; `AssessmentDeliveryManager` / `AssessmentDeliveryAccessGate`.
- Audience: institution | classroom | student; lifecycle draft→active|cancelled, active→closed|cancelled.
- Aktivasyonda eligible öğrenciler materialize edilir; transfer eski snapshot’ı silmez; sonradan katılan otomatik eklenmez (`addEligibleRecipient` kontrollü).
- Access gate: fresh user/membership/institution/window/publication integrity; `attemptQuotaMustBeChecked=true` (kota Stage 2.11’de uygulanır).
- Yetki: `AssessmentDeliveryVoter` (Owner/Manager full; Teacher yalnız atanmış sınıf; Student ACCESS_SELF).
- Scoring / result / UI / API yok. Migration: `Version20260910500000`.

### Sınav attempt / cevap (Aşama 2.11)

- `AssessmentAttempt` + materialize `AssessmentAttemptItem` + `AssessmentAttemptActiveGuard` + şifreli `AssessmentAttemptAnswer` (XChaCha20-Poly1305; plaintext yok).
- Lifecycle: start → saveAnswer (autosave + `client_revision`) → submit | expire | cancel (owner/manager).
- Yetki: `AssessmentAttemptVoter` (Student START/VIEW/SAVE/SUBMIT; Owner/Manager VIEW+CANCEL; Teacher VIEW).
- Scoring / result / UI / API yok. Migration: `Version20260910700000`. Test cleanup: `AssessmentAttemptDbCleanup` delivery’den önce.

### Sınav puanlama / sonuç (Aşama 2.12)

- Versioned `AssessmentScoringRun` + `AssessmentItemScore` + append-only `AssessmentManualGradeDecision` + `AssessmentResultRelease` (tek aktif released guard).
- Policy: `testlig_default_v1` (bcmath; float yok). Otomatik: single/multiple/true_false/numeric; short_answer accepted list veya manual_pending.
- Öğrenci yalnız active release + `StudentResultView` (cevap anahtarı/ciphertext yok). Regrade yeni run; eski release sessizce değişmez.
- UI/controller/API/PDF/raporlama yok. Migration: `Version20260910900000`.

### Sonuç inceleme politikası (Aşama 2.13)

- Versioned `AssessmentResultReviewPolicy` per `AssessmentDelivery` + `AssessmentResultActiveReviewPolicyGuard` (tek aktif).
- Fail-closed: aktif policy yoksa `AssessmentResultReviewReader` → `review_policy_not_active`; skor özeti Stage 2.12 `AssessmentResultReader` ile çalışmaya devam eder.
- `availabilityMode`: `never` | `after_delivery_closed` | `scheduled_after_close`. Doğru cevap / açıklama asla `closesAt` öncesi; iptal delivery’de otomatik reveal yok.
- `StudentResultReviewView` yalnız attempt sahibi active student + eligible recipient; SUPER_ADMIN policy yönetebilir fakat öğrenci DTO okuyamaz. İzinsiz alan anahtarları `toArray()` çıktısında yer almaz.
- UI/controller/API yok. Migration: `Version20260911200000`.

### Assessment analytics / öğretmen insight (Aşama 2.14)

- Optimize Doctrine/DBAL okuma + DTO projection; **materialized analytics snapshot tablosu yok**.
- Kaynak: active `AssessmentResultRelease` (guard) → completed `AssessmentScoringRun` → `AssessmentItemScore` → alignments → learning outcomes.
- Cohort gizlilik eşiği **5**; ortalama/medyan/dağılım/LO yüzdeleri **ve** `participationRate` / `completionRate` eşiğin altında `suppressed` (yanıltıcı 0 yok — anahtarlar `toArray()`’dan çıkar). Summary’deki eligible/started/completed sayıları kalabilir; **soru düzeyinde** `outcomes` / `scoredResponseCount` / `correctRate` tamamen gizlenir (tek öğrenci çıkarımı engeli).
- Soru sırası: immutable blueprint `sectionPosition` + `itemPosition` (`assessment_attempt_items` snapshot); **shuffle `presentation_position` kullanılmaz**.
- Option distribution **yok** (selectedStableKey yalnız şifreli cevapta; decrypt etmeden aggregate güvenli değil).
- Yetki: Owner/Manager; Teacher sınıf coverage; Student yalnız kendi attempt DTO; SUPER_ADMIN aggregate OK / student DTO DENY; Staff/Admin/Moderator deny. Fresh auth + tenant isolation.
- UI/controller/API/PDF/Excel/bildirim/veli yok. Migration: **none** (gerekli indeksler zaten mevcut: `idx_ais_run_outcome`, `uniq_qra_revision_outcome`).

### Öğrenme içeriği / medya temeli (Aşama 2.15)

- Versioned `LearningContent` + sealable revision + append-only publication + outcome alignment + `StoredMediaAsset` metadata registry.
- Structured content: `src/LearningContent/` (Question content’ten ayrı); HTML/script/iframe/external URL yok; mediaId UUID only.
- Platform auth: Admin publish; Moderator return-draft only; Teacher no publish. Free access only via explicit `LearningContentAccessPolicy` (gate remains fail-closed by default).
- Catalog bridge: `CatalogTopicLesson` placements + nullable `CatalogSubject.canonical_subject_id` (UUID FK; no name/slug inference).
- AccessGate fail-closed: published içerik entitlement gate’e delege eder (free policy veya lisans). Review separation zorunlu.
- Gerçek upload/storage SDK/ödeme/AI yok. Öğrenci topic sayfası typed block renderer (Twig autoescape). Admin revision editor: typed block form (heading/paragraph/list/callout/quote/math). Migration: `Version20260912120000`. Detay: `docs/architecture-learning-content.md`.

### Access package / license / entitlement (Aşama 2.16)

- Domain-only packages, versioned grants, licenses, institution seats, resource access policies (`free`|`entitlement_required`).
- Assessment catalog grants: **grade_level only** (Assessment has no subject); LC catalog: subject+grade.
- Payment SDK/UI/API yok; Stage 2.17 yalnızca license komutlarını tetikler. Migration: `Version20260912160000`. Detay: `docs/architecture-access-entitlement.md`.

### Ticaret / ödeme / abonelik / fulfillment (Aşama 2.17)

- `CommercialOffer` (paket sürümünün fiyatlı sarmalayıcısı), `CommerceOrder` + `CommerceOrderItem` (donmuş snapshot ve totaller), `PaymentAttempt`, append-only `PaymentEvent` zinciri, `CommerceSubscription`, `CommerceFulfillment`, `PaymentRefund`.
- Para: yalnızca tamsayı minor unit (`App\Money\Money`, float yok), varsayılan `TRY`. Fiyatlar **vergi hariç** (net) + `taxRateBasisPoints` snapshot; `grandTotal = subtotal - discount + tax`, satır başına half-up yuvarlama.
- Capture → `AccessLicenseManager` ile **bir kez** `purchase` kaynaklı AccessLicense (idempotent). Tek seferlik: `validityDays` zorunlu; abonelik: dönem kapsamlı (period-scoped) lisans.
- Kısmi iade lisansı düşürmez; tam iade otomatik iptal etmez — geri alma yalnızca açık `CommerceFulfillmentManager::reverse()`.
- Yetki: katalog + settlement yalnızca aktif/doğrulanmış SUPER_ADMIN; bireysel satın alma yalnızca kişinin kendisi (proxy yok); kurumsal ödeme yalnızca Owner (Manager seat-only kalır).
- Gerçek ödeme SDK'sı, checkout UI, REST admin API ve kart verisi **yok** — Stage 2.18 webhook ingress + Stage 2.19 ops/reconciliation domain; Stage 2.20 authenticated `/yonetim` panel. Detay: `docs/architecture-commerce-payment.md`, `docs/architecture-payment-operations-reconciliation.md`, `docs/architecture-admin-operations-panel.md`.
- Ek ortam değişkeni: `COMMERCE_IDEMPOTENCY_HASH_KEY` (min 32 byte, APP_SECRET fallback yok).

### Yönetim operasyon paneli (Aşama 2.20)

- Authenticated panel: `/yonetim` (ADMIN shell; SUPER_ADMIN payment/webhook/recon/audit).
- Dead-letter yeniden deneme: `POST /yonetim/webhook/{eventId}/yeniden-dene` (CSRF + confirm + rate limit).
- UI önizleme (dev/test): `/onizleme/admin` — gerçek panel değildir.

### Tasarım sistemi ve UI önizlemeleri (Aşama 2.14.1)

- Merkezi CSS token’ları: `assets/styles/app.css` (`docs/ui-design-system.md`).
- Yenilenen kamu ana sayfa: `/`
- Rol paneli önizlemeleri (**yalnızca `dev` / `test`**): `/onizleme/ogrenci`, `/onizleme/ogretmen`, `/onizleme/veli`, `/onizleme/admin`
- Demo ViewModel’ler: `App\UiPreview\*` — veritabanı yok, mutasyon yok (`docs/ui-preview.md`).
- Production route tablosunda `/onizleme` yok.

Compose, container içinde `DATABASE_URL` / `REDIS_URL` değerlerini Docker DNS adlarıyla (`database`, `redis`) ayarlar. MariaDB host’a yayınlanmaz (XAMPP 3306 çakışmasını önlemek için). Host’taki `.env` içindeki `127.0.0.1` adresleri yalnızca Docker dışı çalıştırma içindir.

Container içinde PHPUnit çalıştırırken `APP_ENV` değerini test’e sabitleyin (Compose `APP_ENV=dev` geçirir):

```powershell
docker compose exec -e APP_ENV=test -e APP_DEBUG=1 app vendor/bin/phpunit
```

İlk volume oluşturmada `testlig_test` veritabanı `docker/mariadb/init` ile kurulur. Volume zaten varsa:

```powershell
docker compose exec database mariadb -uroot -p -e "CREATE DATABASE IF NOT EXISTS testlig_test; GRANT ALL ON testlig_test.* TO 'testlig'@'%';"
```

Durdurma:

```powershell
docker compose down
```

## Docker olmadan kurulum

1. PHP 8.3+, Composer, MariaDB 10.11 ve (isteğe bağlı) Redis kurun.
2. `.env.local` oluşturup `DATABASE_URL` / `REDIS_URL` değerlerini host’a göre ayarlayın.
3. Bağımlılıkları kurun:

```powershell
C:\xampp\php\php.exe composer.phar install
```

4. Veritabanını oluşturun ve migration çalıştırın (aşağıya bakın).
5. Yerel web sunucusu örneği:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public
```

## Veritabanı ve migration

```powershell
# Composer script (Windows uyumlu)
C:\xampp\php\php.exe composer.phar db:create
C:\xampp\php\php.exe composer.phar migrate
```

veya doğrudan:

```powershell
C:\xampp\php\php.exe bin\console doctrine:database:create --if-not-exists
C:\xampp\php\php.exe bin\console doctrine:migrations:migrate --no-interaction
```

## Testler

```powershell
C:\xampp\php\php.exe composer.phar test
```

## PHPStan ve kod biçimi

```powershell
C:\xampp\php\php.exe composer.phar stan
C:\xampp\php\php.exe composer.phar cs:check
C:\xampp\php\php.exe composer.phar cs:fix
```

## Diğer Composer scriptleri

| Script | Açıklama |
|--------|----------|
| `composer install` / `composer setup` | Bağımlılık kurulumu |
| `composer start` | `docker compose up -d` |
| `composer stop` | `docker compose down` |
| `composer test` | PHPUnit |
| `composer stan` | PHPStan |
| `composer cs:check` / `cs:fix` | PHP-CS-Fixer |
| `composer doctrine:validate` | Schema doğrulama |
| `composer migrate` | Migration çalıştırma |
| `composer cache:clear` | Cache temizleme |

## Sağlık kontrolü

`GET /health` — JSON durum bilgisi (uygulama, ortam, database/redis check).  
Parola, bağlantı dizesi veya sunucu yolu döndürmez.

## Bilinen sınırlamalar

- Web kayıt/giriş/e-posta doğrulama, şifre sıfırlama ve oturum içi parola değiştirme vardır; “beni hatırla”, OAuth/JWT, MFA ve sosyal giriş yok.
- Public kayıt yalnızca öğrenci içindir; öğretmen/veli/kurum/admin davet veya yönetici süreçleri sonraki aşamalarda.
- Soru bankası / sınav blueprint / delivery / attempt / scoring-result domain foundation var; scoring HTTP API / sonuç UI / PDF / analitik ve ödeme yok.
- Production dağıtım yapılandırması yok.
- Yerel Windows ortamında PHP 8.3 ve Docker bulunmayabilir; hedef runtime Docker’daki PHP 8.3’tür.
- `symfony/redis-messenger` paketinin Composer kurulumu için `ext-redis` gerekir (Docker imajında vardır). Yerelde `ext-redis` yoksa paket `--ignore-platform-req=ext-redis` ile kurulmuştur.

## Mimari notlar

Ayrıntılar: [docs/architecture.md](docs/architecture.md)
