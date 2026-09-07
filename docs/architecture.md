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
- **E-posta doğrulama:** SymfonyCasts VerifyEmailBundle imzalı URL (DB’de plain token yok). Süre `VERIFY_EMAIL_LIFETIME`. Aktivasyon `UserAccountLifecycle` üzerinden `pending_verification` → `active`.
- **Giriş/çıkış:** `/giris`, `/cikis`; normalized email; login throttling; logout CSRF; güvenli yerel redirect. `lastLoginAt` `LoginSuccessListener` ile güncellenir (yazma hatası girişi bozmaz).
- **E-posta:** Giriş kimliği `normalizedEmail` (trim + lowercase). Görünen `email` trim edilmiş biçimi saklar; unique constraint normalized alan üzerindedir.
- **Parola:** `password_hashers: auto`; PasswordStrength (+ prod/dev’de NotCompromisedPassword); Serializer `#[Ignore]`.
- **Kontrollü yazma:** Hesap oluşturma `UserFactory` / `RegistrationService`; roller `UserGlobalRoleManager`. Controller’lar entity mutasyonlarını doğrudan çağırmamalıdır.
- **`ROLE_SUPER_ADMIN`:** Factory ve role manager üzerinden atanamaz; ileride audit’li CLI bootstrap.
- **Yerel posta:** Mailpit (`http://localhost:8025`); container SMTP `mailpit:1025`.
- **Bu aşamada yok:** şifre sıfırlama, beni hatırla, OAuth/JWT, admin/öğretmen panelleri, sosyal giriş.

## Sonraki aşamalar

Şifre sıfırlama, davet/kurum üyelikleri, paneller ve domain modelleri ayrı görevlerle eklenecektir.
