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

Kayıt: http://localhost:8080/kayit · Giriş: http://localhost:8080/giris · Hesap: http://localhost:8080/hesabim
Şifremi unuttum: http://localhost:8080/sifremi-unuttum · Parola değiştir: http://localhost:8080/hesabim/sifre-degistir

Public kayıt yalnızca **öğrenci** (`ROLE_STUDENT`) oluşturur; e-posta doğrulanana kadar giriş yapılamaz.
Çıkış yalnızca `POST /cikis` (CSRF zorunlu). Giriş hataları generic mesaj kullanır (hesap durumu ifşa edilmez).
Parola sıfırlama yalnızca **active** hesaplara e-posta gönderir; public cevap her durumda aynıdır.
Süresi dolmuş reset kayıtları: `docker compose exec app php bin/console reset-password:remove-expired`
Mailpit UI yalnızca localhost’ta dinler (`127.0.0.1:8025`).

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
- Soru bankası / sınav blueprint domain foundation var; delivery/UI/HTTP API ve ödeme yok.
- Production dağıtım yapılandırması yok.
- Yerel Windows ortamında PHP 8.3 ve Docker bulunmayabilir; hedef runtime Docker’daki PHP 8.3’tür.
- `symfony/redis-messenger` paketinin Composer kurulumu için `ext-redis` gerekir (Docker imajında vardır). Yerelde `ext-redis` yoksa paket `--ignore-platform-req=ext-redis` ile kurulmuştur.

## Mimari notlar

Ayrıntılar: [docs/architecture.md](docs/architecture.md)
