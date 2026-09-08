# Testlig — Mimari kararlar (Aşama 1)

## Yaklaşım

- **Modüler monolit:** Tek Symfony uygulaması; iş yetenekleri sonraki aşamalarda sınırlı bağlamlar / modüller olarak eklenecek.
- **Mikroservis yok:** Bu aşamada ve yakın vadede servis ayrıştırması yapılmayacak.

## İstemciler

- Symfony hem **web** hem **mobil API** için ortak backend olacaktır.
- Mobil uygulama ileride **Flutter** ile geliştirilecektir.
- Bu aşamada API kaynakları veya mobil istemci yoktur.

## Veri ve altyapı

- **MariaDB 10.11** birincil ilişkisel veritabanıdır.
- **Doctrine ORM** ve **Doctrine Migrations** şema yönetimini sağlar.
- **Redis** önbellek ve Messenger transport’u için hazırlanmıştır.
- **Symfony Messenger** asenkron işler için kullanılır (şimdilik sync/in-memory varsayılanları).

## Identity and access

- **Tek merkezi `User` hesabı:** Oturum ve kimlik doğrulama bu entity üzerinden yürür; UUID **v7** uygulama tarafında üretilir (`BINARY(16)`). Doctrine proxy uyumluluğu için entity `final` değildir.
- **Global roller:** `UserRole` backed enum (`ROLE_USER` … `ROLE_SUPER_ADMIN`). `getRoles()` her zaman `ROLE_USER` içerir; hiyerarşi genişlemesi **Symfony `role_hierarchy`** ile yapılır (entity içinde elle expand edilmez).
- **Domain üyelikleri ayrı:** Kurum, sınıf, öğretmen/öğrenci bağlantıları global rol dizisine konmaz; sonraki aşamalarda ayrı membership modelleriyle tutulur.
- **Durumlar (`UserStatus`):** `pending_verification` (varsayılan), `active`, `suspended`, `archived`. Yalnızca `active` kimlik doğrulamaya uygun kabul edilir (`UserChecker`); `archived` fiziksel silme değildir.
- **Public kayıt (Aşama 2.2):** Yalnızca bireysel öğrenci self-serve kaydı. Sunucu tarafında sabit `ROLE_STUDENT`. Form `RegistrationRequest` DTO’suna bağlanır; User entity’ye mass-assign yok. Başarılı kayıtta otomatik login yok; e-posta doğrulaması gerekir.
- **E-posta doğrulama:** SymfonyCasts VerifyEmailBundle imzalı URL (DB’de plain token yok). Süre `VERIFY_EMAIL_LIFETIME`. İmza her zaman (active replay dahil) doğrulanır; suspended/archived aktive edilmez. Aktivasyon `UserAccountLifecycle` üzerinden `pending_verification` → `active`.
- **Giriş/çıkış:** `/giris`; çıkış yalnızca `POST /cikis` + CSRF. Normalized email; login throttling; tüm hesap durumu/kimlik hataları için generic mesaj (enumeration yok). Güvenli yerel redirect. `lastLoginAt` `LoginSuccessListener` ile güncellenir (yazma hatası girişi bozmaz). Kayıt sonrası mailer transport hatası 500 üretmez; hesap `pending_verification` kalır, resend kullanılabilir.
- **E-posta:** Giriş kimliği `normalizedEmail` (trim + lowercase). Görünen `email` trim edilmiş biçimi saklar; unique constraint normalized alan üzerindedir.
- **Parola:** `password_hashers: auto`; PasswordStrength (+ prod/dev’de NotCompromisedPassword); Serializer `#[Ignore]`.
- **Kontrollü yazma:** Hesap oluşturma `UserFactory` / `RegistrationService`; parola `PasswordManager`; roller `UserGlobalRoleManager` (actor+reason zorunlu); durum `UserStatusManager` (suspend/archive/controlled reactivate). E-posta aktivasyonu `UserAccountLifecycle` ile sınırlı kalır. Controller’lar entity mutasyonlarını doğrudan çağırmamalıdır.
- **Parola sıfırlama / değiştirme (Aşama 2.3):** SymfonyCasts ResetPasswordBundle. DB’de yalnızca hashed selector/token (`reset_password_requests`); `user_id` unique (tek aktif istek). Plain token yok. Ömür `RESET_PASSWORD_LIFETIME` (varsayılan 3600 sn). Yalnızca `active` hesaplara e-posta. Public cevaplar enumeration-safe. Rate limit: IP 5/15dk + normalize e-posta HMAC anahtarı 3/15dk. Token URL’den session’a alınır (`/sifre-yenile/{token}` → `/sifre-yenile`); giriş yapmış kullanıcı tokenı session’a yazmaz, temizler. Reset/change transaction içinde `PESSIMISTIC_WRITE` kullanıcı kilidi + token yeniden doğrulama. `passwordChangedAt` MariaDB saniye çözünürlüğünde monoton artar. Hatalar typed reason enum ile ayrılır; kullanıcı mesajı presentation’da. Oturum: parola değişiminde mevcut oturum sonlandırılır; `User::isEqualTo()` password + normalizedEmail + status + sıralı roller ile diğer oturumları düşürür. Cleanup: `php bin/console reset-password:remove-expired`.
- **Security audit (Aşama 2.4):** Append-only `security_audit_events` (UUID v7, `ClockInterface` `occurredAt`). Action’lar: `user_registered`, `email_verified`, `login_succeeded`, `password_reset_completed`, `password_changed`, `role_changed`, `status_changed`, `super_admin_bootstrapped`. Actor type: `user` / `system` / `cli`. Ham e-posta, IP, User-Agent, parola, token, cookie, Authorization, body **yazılmaz**; IP/UA yalnızca `AUDIT_HASH_KEY` ile HMAC. Metadata strict allowlist. ORM `preUpdate`/`preRemove` ile immutable. Kritik işlemler (parola, rol, durum, SUPER_ADMIN bootstrap) audit ile **aynı transaction**; audit yazılamazsa rollback. Login audit **best-effort** (DB hatası girişi düşürmez). Kullanıcı silinince actor/subject `ON DELETE SET NULL`; event kalır. Okuma paneli/API bu aşamada yok. İleride yasal saklama süresi için ayrı kontrollü retention prosedürü planlanır (hash-chain yok).
- **`ROLE_SUPER_ADMIN` bootstrap:** `php bin/console app:user:bootstrap-super-admin --email=... --confirm`. Varsayılan kapalı (`ALLOW_SUPER_ADMIN_BOOTSTRAP=0`). Parola CLI argümanı değil (hidden input). Mevcut SUPER_ADMIN veya aynı e-posta reddedilir. `GET_LOCK` + `security_bootstrap_guards` unique satır + `JSON_CONTAINS` kontrolü. Factory/role manager SUPER_ADMIN vermez. Başarıdan sonra env’i tekrar kapatın.
- **Kurum ve üyelik (Aşama 2.5):** `Institution` + `InstitutionMembership` tenant omurgası. Global `ROLE_*` (öğretmen, institution manager, admin, moderator) **tek başına hiçbir kuruma erişim vermez**. Erişim = **active institution** + **active membership** (veya açık `ROLE_SUPER_ADMIN` override). `ROLE_ADMIN` / `ROLE_MODERATOR` otomatik kurum erişimi almaz. Kurum oluşturma bu aşamada yalnızca internal `InstitutionCreator` ile ve **SUPER_ADMIN** tarafından; UI/form yok. İlk owner membership aynı transaction’da oluşur. Üyelik rolleri: owner / manager / teacher / staff (global rollerle senkronize edilmez). Owner: tam yönetim; Manager: teacher/staff; Teacher: görüntüleme (+ üyeleri görme); Staff: minimum görüntüleme. Manager owner ekleyemez/yönetemez; son active owner suspend/end edilemez; owner rolü `changeRole` ile verilmez; owner devri yok. Kurumlar arası izolasyon zorunlu (`institution_id` scope). Unique `(institution_id, user_id)` + transaction içinde `PESSIMISTIC_WRITE` (önce institution, sonra membership). Fiziksel silme yok: institution `archived`, membership `ended`. Audit action’ları (`institution_*`) domain işlemiyle aynı transaction’da; metadata’da `reason_code` (serbest PII reason yok).
- **Kurum actor sertleştirmesi:** Kurum domainindeki bütün yetkili işlemlerde actor **active + e-posta doğrulanmış** olmalıdır. `ROLE_SUPER_ADMIN` override, suspended / archived / pending_verification hesabı **bypass etmez**. Kritik yazma servisleri (`InstitutionCreator`, `InstitutionStatusManager`, `InstitutionMembershipManager`) actor/subject/institution/membership satırlarını Doctrine identity map’e güvenmeden yükler: DQL + `Query::HINT_REFRESH` + uygun `PESSIMISTIC_READ/WRITE` (`InstitutionalFreshEntityLoader`). Yalnızca `EntityManager::find(..., lockMode)` fresh hydration **garanti etmez**; managed entity eski role/status taşıyabilir. Kilit sırası: **1) Institution (WRITE)** → **2) User’lar UUID artan sırada (READ)** → **3) Membership (WRITE)**. Kurum oluşturmada henüz institution satırı yoksa yalnızca User kilitleri. Actor membership yetkisi de HINT_REFRESH ile okunur. Deadlock / lock timeout → `conflict`. `InstitutionVoter` Doctrine entity kullanmaz; DBAL üzerinden typed authorization snapshot okur (`RequestScopedInstitutionAuthLookup`: user status/roles/verified, institution status, membership role/status). Snapshot’lar request-scope cache’lenir ve `ResetInterface` ile istekler arasında temizlenir; mid-request mutation sonrası otomatik invalidate edilmez (HTTP akışında vote→mutate sırası esas alınır). Stale managed entity yetki kaynağı kabul edilmez. Membership role ile global role ayrımı korunur.
- **Yerel posta:** Mailpit (`http://localhost:8025`); container SMTP `mailpit:1025`.
- **Bu aşamada yok:** beni hatırla, OAuth/JWT, MFA, admin/öğretmen/öğrenci panelleri, public kurum kaydı, davet, sınıf/öğrenci atama, abonelik, audit UI.

## Sonraki aşamalar

Davet akışları, paneller ve sınıf/öğrenci domain modelleri ayrı görevlerle eklenecektir.
