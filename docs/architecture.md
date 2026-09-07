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
- **Durumlar (`UserStatus`):** `pending_verification` (varsayılan), `active`, `suspended`, `archived`. Yalnızca `active` kimlik doğrulamaya uygun kabul edilir; `archived` fiziksel silme değildir.
- **E-posta:** Giriş kimliği `normalizedEmail` (trim + lowercase). Görünen `email` trim edilmiş biçimi saklar; unique constraint normalized alan üzerindedir.
- **Parola:** Yalnızca hash saklanır (`password_hashers: auto`); plain-text alan yoktur. Symfony Serializer çıktısında parola `#[Ignore]` ile gizlenir. PHP native session serialization framework varsayılanına bırakılır (özel `__serialize` yok); Security oturum yenileme/parola değişimi ile uyumludur.
- **Kontrollü yazma:** Hesap oluşturma `UserFactory`; global rol değişiklikleri `UserGlobalRoleManager` üzerinden. Controller’lar `User::create()`, `setGlobalRoles()`, `addGlobalRole()`, `setPassword()`, `transitionTo()` gibi mutasyon metotlarını doğrudan çağırmamalıdır. Rol, durum ve parola değişiklikleri ileride uygulama servisleri + audit log üzerinden yapılacaktır.
- **`ROLE_SUPER_ADMIN`:** Factory ve `UserGlobalRoleManager` üzerinden atanamaz. İlk super-admin hesabı ileride açıkça onaylanan, audit log üreten, tek kullanımlık bir CLI bootstrap komutuyla oluşturulacaktır (bu aşamada komut yok).
- **Zaman alanları:** Oluşturma anında `createdAt`, `updatedAt` ve `passwordChangedAt` aynı `$now` ile set edilir. Zaman bağımlı iş kuralları gerektiğinde Symfony Clock kullanılabilir.

## Sonraki aşamalar

Kayıt/giriş UI, e-posta doğrulama, paneller ve domain üyelikleri ayrı görevlerle eklenecektir.
