# Aşama 2.22 — Kimlik Doğrulama ve Üyelik Genişletme

Bu belge Aşama **2.22** için mimari karar kaydıdır (ADR). Kod, migration, entity veya UI
uygulaması içermez. Alt aşamalar (2.22.1 … 2.22.9) onay sonrası ayrı görevlerde uygulanır.

**Bağlam:** Aşama 2.21 (`docs/architecture-admin-identity-institution-management.md`) admin
kullanıcı/kurum yüzeyini kapattı ve davet / Stage 2.22’yi kapsam dışı bıraktı. Ana sayfa
yayını (PR #26) auth/üyelik modelini değiştirmedi. Bu ADR, 2.22 kapsamını resmileştirir ve
ürün güvenlik kararlarıyla revize edilmiştir.

**İstemci sözleşmesi:** Web, ilerideki tablet/mobil istemciler ve olası API aynı domain
kurallarını kullanır; yetki istemci seçimine değil sunucu tarafı güncel üyelik ve role
dayanır.

---

## 1. Amaç

Testlig’de öğrenci, veli, öğretmen ve kurum yetkilisi farklı kayıt bilgilerine ve
doğrulama süreçlerine ihtiyaç duyar. Tek bir gerçek kişi tek ana `User` kimliğiyle
oturum açabilir; aynı kişi birden fazla global rol ve birden fazla kurumsal/sınıfsal
bağlama sahip olabilir.

Aşama 2.22’nin amacı:

- Kayıt türlerini (öğrenci / veli / öğretmen başvurusu / kurum başvurusu) güvenli biçimde
  genişletmek; pending başvuruyu doğrudan yetkiden ayırmak
- Mevcut **e-posta zorunlu** kimliği koruyarak telefonu nullable, doğrulanmış bağlama
  olarak planlamak
- Kişisel davet ile sınıf/kurum katılım kodunu ayırmak (ikisi de rol değildir)
- Öğrenci–veli ilişkisini ayrı domain ilişkisi olarak tanımlamak
- SMS’i sağlayıcıdan bağımsız, feature-flag’li güvenlik sözleşmesiyle planlamak
- Mevcut e-posta girişini, kurum üyeliğini, fresh-actor ve audit desenlerini bozmamak

Bu aşama ürün panellerini tamamlamaz; kimlik ve üyelik omurgasını güvenli genişletir.

### Öğrenci ilk giriş kurulumu + sade panel (sonraki dilim)

Ayrı uygulama dilimi: doğrulanmış `ROLE_STUDENT` için `StudentProfile` (1:1 `User`),
`/ogrenci/kurulum` → `/ogrenci` / `/ogrenci/profil`. Doğum tarihi / telefon / açık adres toplanmaz.
Sahte ders/istatistik gösterilmez. Login `target_path` korunur; varsayılan öğrenci hedefi
kurulum veya paneldir.

---

## 2. Mevcut Durum

Aşağıdakiler **mevcut kod**tan kanıtlıdır; “hedef” değildir.

### 2.1 `User` kimlik alanları

`App\Entity\User` (`users` tablosu) şu alanlara sahiptir:

| Alan | Not |
|------|-----|
| `id` | UUID v7 |
| `email` / `normalizedEmail` | Unique: `uniq_users_normalized_email` |
| `password` | Hash; Serializer `#[Ignore]` |
| `firstName` / `lastName` | Zorunlu |
| `globalRoles` | JSON; `ROLE_USER` entity’de saklanmaz, `getRoles()` ekler |
| `status` | `UserStatus`: `pending_verification`, `active`, `suspended`, `archived` |
| `emailVerifiedAt`, `lastLoginAt`, `passwordChangedAt` | Zaman damgaları |
| `locale`, `timezone` | Varsayılan `tr_TR` / `Europe/Istanbul` |

**Yok:** telefon, `phoneVerifiedAt`, SMS secret, davet/katılım kodu, veli–öğrenci ilişkisi,
öğretmen/kurum başvuru kaydı.

`getUserIdentifier()` yalnızca `normalizedEmail` döner.

### 2.2 Login tanımlayıcısı

- Provider: `config/packages/security.yaml` → `property: normalizedEmail`
- Authenticator: `App\Security\LoginFormAuthenticator`
  - Form alanı `_username` e-posta olarak okunur
  - `App\Service\EmailNormalizer` ile normalize edilir
  - CSRF badge `authenticate`; başarıda yalnızca güvenli yerel path (`isSafeLocalPath`)
  - Varsayılan yönlendirme: `app_account` (`/hesabim`)
- `App\Security\UserChecker`: yalnız `UserStatus::Active` kimlik doğrulayabilir; diğer
  durumlar **tek generic** mesajla reddedilir
- Firewall `login_throttling`: 5 deneme / 15 dk (`rate_limiter` adı `login`)

Telefon veya SMS ile giriş **yoktur**.

### 2.3 Registration neden öğrenci odaklı

- `App\Service\RegistrationService` sınıf yorumu: *“Public student self-registration.
  Always assigns ROLE_STUDENT server-side.”*
- `UserFactory::create(..., initialRole: UserRole::Student)` sabitlenir
- `App\Dto\RegistrationRequest`: ad, soyad, e-posta, parola, `agreeTerms` — sınıf seviyesi,
  branş, kurum veya davet alanı yok
- Route: `/kayit` (`RegistrationController`); e-posta doğrulama zorunlu; otomatik login yok
- `README.md` / `docs/architecture.md`: public kayıt yalnızca bireysel öğrenci

Öğretmen / veli / kurum self-serve kaydı **uygulanmamıştır**.

### 2.4 Kurum üyeliği nasıl temsil ediliyor

- `App\Entity\Institution` + `App\Entity\InstitutionMembership`
- Üyelik rolleri (`InstitutionMembershipRole`): `owner`, `manager`, `teacher`, `staff`,
  `student` — **global `ROLE_*` ile senkronize edilmez**
- Unique `(institution_id, user_id)`; mutasyonlar `InstitutionMembershipManager` /
  `InstitutionCreator` / `InstitutionStatusManager`
- Yetki: `InstitutionVoter` + `RequestScopedInstitutionAuthLookup` (DBAL snapshot);
  stale Doctrine entity yetki kaynağı değildir
- Sınıf kayıtları: `ClassroomStudentEnrollment` → `student_membership_id`
  (`InstitutionMembership`); öğretmen atamaları ayrı assignment entity’leri
- Admin UI (2.21): `/yonetim/kurumlar*` — SuperAdmin odaklı; public kurum kaydı yok

### 2.5 Eksikler (telefon / SMS / davet / veli–öğrenci / başvuru)

| Konu | Durum |
|------|--------|
| Telefon alanı | `User` üzerinde yok |
| SMS OTP / adapter | Yok |
| Davet / katılım kodu | Yok; 2.21 out-of-scope “Invite flows” |
| Öğrenci–veli bağlantı entity | Yok; yalnız global `ROLE_PARENT` enum değeri |
| Öğretmen / kurum başvuru modeli | Yok |
| Çoklu hesap türü kayıt | Yok |
| Giriş sonrası bağlam seçimi | Yok; başarı → `/hesabim` |
| Kurum self-service başvuru | Yok; oluşturma SuperAdmin / `InstitutionCreator` |

`ROLE_PARENT` / `ROLE_TEACHER` / `ROLE_INSTITUTION_MANAGER` enum’da tanımlıdır
(`App\Enum\UserRole`) ancak public onboarding bunları üretmez; kurum erişimi global
rol ile gelmez (`docs/architecture.md` Aşama 2.5).

### 2.6 Korunacak güvenlik desenleri

Sonraki alt aşamalarda **bozulmadan** taşınacak mevcut desenler:

| Desen | Kaynak |
|-------|--------|
| Fresh actor yükleme | `FreshUserLoader`, `InstitutionalFreshEntityLoader` (`HINT_REFRESH` + kilit) |
| Active + verified politika | `ActiveVerifiedUserPolicy`, privileged/admin gate’ler |
| Generic login / status mesajı | `UserChecker` |
| Enumeration-safe parola sıfırlama | Reset password akışı + rate limit (`password_reset_*`) |
| Audit | `SecurityAuditRecorder` + metadata sanitizer; ham IP/UA yok (HMAC) |
| CSRF | Login, logout, admin mutation token’ları |
| Open redirect koruması | `LoginFormAuthenticator::isSafeLocalPath` |
| Domain üyelik ≠ global rol | `InstitutionMembership` + voter’lar |
| Rate limit altyapısı | `config/packages/rate_limiter.yaml` |
| Controller’ların entity mass-assign etmemesi | DTO + application services |

---

## 3. Temel Mimari Kararlar

Aşağıdakiler **kesin mimari kararlardır**. Onaylanan ürün kararları §14’te; açık hukuk/ürün
soruları ayrıca listelenir.

1. Tek kişi için tek ana `User` kimliği bulunur.
2. Aynı kişi birden fazla global role sahip olabilir (`User::$globalRoles`).
3. Kurum bağlantıları global rol yerine `InstitutionMembership` (ve ileride diğer bağlama
   ilişkileri) üzerinden belirlenir.
4. Giriş ekranında kullanıcıdan önceden rol seçmesi istenmez.
5. Yetkilendirme istemcinin rol/bağlam seçimine güvenmez.
6. Yetki ve üyelik sunucudaki güncel veriden yüklenir (fresh loader / DBAL snapshot /
   voter yeniden değerlendirme).
7. Kişisel davet ve sınıf/kurum katılım kodu kullanıcı rolü değildir; bağlama / katılım
   aracıdır.
8. Bağlama işlemi, hesabın gerekli kimlik durumuna geldikten sonra tamamlanır.
9. Kurum için ortak kullanılan kullanıcı adı/şifre hesabı oluşturulmaz.
10. Kurum başvurusu gerçek bir yetkili kişinin `User` hesabıyla yürütülür; onaysız
    `Institution` / `owner` / `manager` / `ROLE_INSTITUTION_MANAGER` üretilmez.
11. Aşama 2.22 boyunca bütün public self-service hesaplarda **e-posta zorunludur**; mevcut
    e-posta tabanlı kimlik korunur.
12. Telefon `User` üzerinde **nullable** hedef alandır; öğrenci için zorunlu değildir;
    veli telefonu öğrenci hesabına yazılmaz.
13. Telefon-only hesap ve telefon-only recovery bu aşamada **kapsam dışıdır**.
14. SMS tek zorunlu giriş yöntemi olmaz. Production’da gerçek sağlayıcı + feature flag
    olmadan aktif edilmez. Admin / SuperAdmin / kurum yöneticisi / yetkili öğretmen
    işlemlerinde SMS possession tek başına yeterli güvence sayılmaz.
15. Public kayıt doğrudan yüksek yetkili rol (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`,
    `ROLE_MODERATOR`) üretmez.
16. Public öğretmen kaydı doğrudan `ROLE_TEACHER` atamaz; ayrı pending başvuru gerekir.
17. Öğretmen ve kurum yetkileri doğrulanmadan aktif kurumsal yetkiye dönüşmez.

---

## 4. Kayıt Türleri

Dört **kayıt / başvuru başlangıcı** (UX girişi). Sunucu her türde ayrı DTO/policy kullanır;
form `User` entity’ye bağlanmaz (mevcut `RegistrationRequest` deseni genişler).
Tüm public self-service hesaplarda **e-posta zorunlu** kalır.

### 4.1 Öğrenci

- Minimum veri: ad, soyad, **zorunlu e-posta**, parola, onaylar
- Telefon isteğe bağlı (nullable); zorunlu değildir
- Sınıf seviyesi (ürün alanı; mevcut `RegistrationRequest`’te yok — hedef)
- Yaşa bağlı veli onayı: **hukuki/ürün kararı** (§14 açık); bu ADR yaş değeri uydurmaz
- Bu aşamada yeni doğum tarihi veya kimlik numarası toplama zorunluluğu **oluşturulmaz**
- İlk kayıtta okul, adres, TC/kimlik no, sağlık vb. hassas veri **toplanmaz**

Hedef: başlangıçta global `ROLE_STUDENT` atanabilir; kurum/sınıf erişimi ayrıca membership /
enrollment / kişisel davet veya katılım kodu ile gelir.

### 4.2 Veli

Güvenli varsayılan (Aşama 2.22):

- Veli hesabı **doğrulanmış e-posta** ile oluşturulur (e-posta zorunlu)
- Telefon isteğe bağlı; veli telefonu **öğrenci hesabının telefon alanına yazılmaz**
- `ROLE_PARENT` çocuk verisine erişim sağlamaz
- İsim/soyisim, okul veya sınıf aramasıyla çocuk bulma **yasaktır**
- Bağlantı isteği: öğrenci/veli tarafından güvenli kodla **veya** yetkili kurum aracılığıyla
- Bağlantı tamamlanmadan önce veli oturum açmış ve e-postası doğrulanmış olmalıdır
- Riskli senaryolarda karşılıklı onay veya kurum doğrulaması gerekir (§14 açık hangileri)
- Bağlantı iptal edilebilir ve audit üretir

### 4.3 Öğretmen

- Self-register **izin verilebilir**
- Public öğretmen kaydı doğrudan global `ROLE_TEACHER` **atamaz**
- Önce ayrı bir pending başvuru modeli gerekir (ör. `TeacherApplication` /
  `TeacherProfile` pending — kesin sınıf adı 2.22.3’te)
- Başvuru; global rol ve `InstitutionMembership`’ten **ayrıdır**
- Doğrulama/onay sonrasında kontrollü domain servisi gerekli rolü ve/veya üyeliği verir
- Kurum öğretmenliği yalnız aktif kurum üyeliği ve/veya classroom assignment ile kazanılır
- Reddedilen veya süresi dolan başvuru yetki üretmez
- Hangi belgelerin isteneceği açık ürün/KVKK kararıdır; bu ADR kimlik belgesi toplamayı
  zorunlu kılmaz

### 4.4 Kurum (yetkili kişi başvurusu)

- Ortak kurum kullanıcı hesabı **kesinlikle yoktur**
- Public kurum başvurusu doğrudan şunları **üretmez**:
  - `ROLE_INSTITUTION_MANAGER`
  - kurum `owner` / `manager` üyeliği
  - aktif `Institution`
- Ayrı **pending** kurum başvurusu gerekir
- Başvuran doğrulanmış gerçek `User` olmalıdır (e-posta doğrulanmış)
- SuperAdmin / onay servisi başvuruyu değerlendirir
- Onay transaction’ı mevcut `InstitutionCreator` ve kontrollü membership servislerini
  kullanır veya güvenli biçimde genişletir
- Aynı kurum / vergi / iletişim bilgileri için duplicate ve impersonation kontrolü gerekir
- Reddedilen başvuru yetki üretmez

---

## 5. Birleşik Giriş

### 5.1 Hedef kullanıcı deneyimi

- **Birincil:** e-posta + şifre (mevcut davranış korunur)
- Doğrulanmış telefon bağlandıktan sonra, ürün kararı ve feature flag ile:
  telefon + şifre ve/veya SMS OTP girişi **sonradan** açılabilir
- Şifremi unuttum: mevcut **e-posta** reset korunur; SMS ile parola sıfırlama bu aşamada
  kapsam dışıdır
- “Davet kodum var” / katılım kodu: rol seçimi değil, redeem akışına yönlendirme
- Giriş öncesi rol seçimi **yok**
- Tek geçerli bağlam varsa doğrudan yönlendirme
- Birden fazla bağlam varsa profil/bağlam seçim ekranı

### 5.2 Bağlam seçimi (güvenlik)

- Session’daki selected context yalnız **sunum / UX tercihidir**; yetki kaynağı değildir
- Context ID, kullanıcının erişebildiği güncel allowlist içinden seçilir
- Her hassas istekte üyelik ve durum yeniden doğrulanır (fresh / snapshot)
- Üyelik suspend/revoke olduğunda stale session yetki vermez
- Context değiştirme state-changing işlemdir: **POST + CSRF + safe redirect**
- Context değeri başka kullanıcı/kurum için IDOR üretmemelidir
- Mobil/API istemcilerinde context id imzalı tokenın içindeki kalıcı yetki gibi
  kullanılmaz; sunucu her istekte doğrular

### 5.3 Güvenlik vs UX

| UX hedefi | Güvenlik kuralı |
|-----------|-----------------|
| E-posta + şifre birincil | Mevcut provider / `UserChecker` / throttling |
| İleride telefon + şifre / SMS | Feature flag; OTP HMAC; yüksek yetkide SMS tek faktör değil |
| Davet / katılım CTA | Guessing rate limit; redeem policy sunucuda |
| Bağlam seçici | Presentation-only; her istekte taze üyelik |

---

## 6. Telefon Kimliği

Bu turda migration yazılmaz. Fiziksel tablo/entity kararı **2.22.2** uygulama tasarımında
kesinleşebilir; aşağıdaki **güvenlik sözleşmesi** ADR’de sabittir.

### 6.1 Aşama 2.22 kesin telefon politikası

- Mevcut e-posta tabanlı kimlik korunur
- Public self-service hesaplarda e-posta zorunlu kalır
- Telefon `User` için nullable hedef alandır
- Öğrenci için telefon zorunlu değildir
- Veli telefonu öğrenci hesabına yazılmaz
- Telefon-only hesap / telefon-only recovery kapsam dışıdır

### 6.2 Güvenli bağlama (kesin)

1. Doğrulanmamış telefon, kalıcı kullanıcı kimliği olarak **süresiz rezerve edilmez**.
2. OTP challenge / claim ayrı kısa ömürlü kayıt veya eşdeğer atomik mekanizmayla yönetilir.
3. Telefon yalnız başarılı OTP doğrulamasından sonra **transaction içinde** `User`
   kimliğine bağlanır.
4. `normalizedPhone`, kullanıcıya bağlandıktan sonra **global unique** olmalıdır.
5. Concurrent doğrulama yarışında DB unique constraint son güvenlik katmanıdır.
6. Çakışma **generic** hata üretir; diğer hesabın varlığını açıklamaz.
7. Canonical format: E.164 benzeri normalize; görüntüleme değeri ayrı tutulabilir.
8. Mevcut kullanıcılar migration sonrası telefonsuz (`null`) kalabilir.
9. İlk kolonlar nullable önerilir.

### 6.3 Telefon değişikliği (kesin)

Telefon değişimi şunları gerektirir:

- Yeniden kimlik doğrulama / step-up
- Yeni telefonun OTP ile doğrulanması
- Atomik değişim (eski bağ çözülür, yeni bağ kurulur)
- Mümkünse eski e-posta ve/veya eski telefon kanalına güvenlik bildirimi
- Audit (plain OTP / gereksiz PII yok)

**SIM-swap / geri dönüşüm riski:** Eski numara başka kişiye yeniden atanabilir. Bu nedenle
eski numarayla oturum/SMS-login güvenilmez kabul edilir; numara değişince SMS-login ve
ilgili güvenlik durumu yeniden doğrulanır; eski challenge’lar geçersizleştirilir.

### 6.4 Kod ve OTP saklama (kesin)

- 6 haneli OTP düşük entropilidir; düz SHA-256 gibi anahtarsız hızlı hash **tek başına
  yeterli değildir**
- OTP için sunucu secret/pepper kullanan **HMAC-SHA256** (veya eşdeğer anahtarlı digest)
- Karşılaştırma **constant-time**
- Secret repoya yazılmaz; rotation / version bilgisi tasarlanır
- Plain OTP hiçbir DB, log, exception, audit veya analytics kaydına yazılmaz
- Kişisel davet ve katılım kodlarında da güvenli digest; düşük entropili kodlarda HMAC
  zorunlu
- Rate limit yalnız IP’ye değil identifier / challenge / kod fingerprint’ine de uygulanır
- Yeniden kod istemek başarısız deneme sayacını **sıfırlamaz**

---

## 7. Davet ve Katılım Kodu

Tek “davet modeli” varsayımı yoktur. İki kavram **sözleşme olarak** ayrılır; aggregate /
entity isimleri 2.22.4’te kesinleşir.

### 7.A Kişisel davet

- Belirli amaç / alıcı / ilişki için
- Yüksek entropili
- **Tek kullanımlık**
- Süreli
- İptal edilebilir
- Redeem sonrası tüketilir
- Örnek amaçlar: veli–öğrenci bağlama, belirli öğretmen–kurum daveti, belirli üyelik

### 7.B Sınıf / kurum katılım kodu

- Sınırlı **çok kullanımlı** olabilir
- Kullanım kotası
- Son kullanma tarihi
- İptal / rotation
- Her kullanım için **ayrı redemption kaydı**
- Kodun kendisi rol veya yetki değildir
- Her redemption sunucu tarafı policy ile değerlendirilir
- Tek bir aggregate `redeemed` durumu çok kullanımlı kod için **yeterli değildir**

### 7.1 Ortak kurallar

- Ham kod saklanmaz (§6.4 digest politikası)
- Deneme / rate limit (IP + fingerprint + actor)
- Audit (kod plain metin yok)
- Kod hesap açmadan önce girilse bile bağlama, kimlik / e-posta doğrulaması sonrasında
  tamamlanır
- Ne kişisel davet ne katılım kodu `UserRole` / `InstitutionMembershipRole` değildir

---

## 8. Öğrenci–Veli Bağlantısı

İlkeler (hedef domain; bugün entity yok):

1. Ayrı domain ilişkisi gerekir (kesin sınıf adı uygulamada).
2. Global `ROLE_PARENT` tek başına çocuk verisine erişim sağlamaz.
3. Bağlantı doğrulanmış olmalıdır.
4. Durum, oluşturulma ve doğrulanma zamanları tutulur.
5. İptal / ayrılma süreci vardır (soft end; hard-delete yok).
6. Veli yalnız bağlı olduğu çocukların verisini görür.
7. Bir öğrenci birden fazla veliye; bir veli birden fazla öğrenciye bağlanabilir.
8. Hassas işlemler audit üretir.
9. İsim/okul/sınıf aramasıyla keşif yoktur (§4.2).
10. Bu aşamada doğum tarihi / TC zorunluluğu eklenmez; yaş/veli onayı hukuki kapı bekler.

Kurum üyeliği bu ilişkiyi ikame etmez; veli erişimi doğrulanmış parent-link (veya kurum
aracılı onaylı süreç) ile sınırlanır.

---

## 9. SMS Güvenlik Sözleşmesi

§14 ile uyumlu konum:

- SMS, ilk aşamada **isteğe bağlı**, düşük riskli kullanıcı girişi olarak hedeflenebilir
- Gerçek sağlayıcı ve production feature flag olmadan **aktif edilemez**
- `dev` / `test` yalnız fake adapter kullanır
- Admin, SuperAdmin, kurum yöneticisi ve yetkili öğretmen işlemlerinde SMS possession
  **tek başına yeterli güvence sayılmaz**
- MFA ayrı politika olarak daha sonra uygulanır
- SMS ile parola sıfırlama bu aşamada **kapsam dışıdır**
- Telefon numarası değişimi SMS-login oturumlarını ve güvenlik durumunu etkiler;
  yeniden doğrulama gerekir (§6.3)

Teknik kurallar: §6.4 + en az 6 hane, kısa ömür, tek kullanımlık, replay koruması,
generic hatalar, audit’te OTP yok.

Gerçek SMS sağlayıcı satın alma / production entegrasyonu **kapsam dışı** (§13).

---

## 10. Güvenlik Sınırları

| Tehdit | Beklenen kontrol |
|--------|------------------|
| Account enumeration | Generic auth/reset/register/telefon çakışma cevapları |
| Brute force | Login throttling + OTP/invite/join-code rate limit |
| Invite / join-code guessing | Entropy (kişisel) + kota/expiry + rate limit + audit |
| Session fixation | Framework session lifecycle; gözden geçirme |
| CSRF | State-changing POST + token (bağlam değişimi dahil) |
| Open redirect | `isSafeLocalPath` ve eşdeğer |
| Stale actor / stale context | Fresh load; üyelik revoke sonrası yetkisiz |
| IDOR | UUID + tenant; cross-tenant 404; context allowlist |
| Yetki yükseltme | Pending başvuru ≠ rol; onaysız kurum/üyelik yok |
| Hesap birleştirme kötüye kullanımı | Otomatik merge yok (§10.1) |
| SIM-swap | Telefon değişiminde step-up + invalidate (§6.3) |
| Kişisel veri / audit sızıntısı | OTP, kod, şifre, gereksiz PII yok |

### 10.1 Kimlik çakışması ve hesap birleştirme

- Aynı `normalizedEmail` ikinci `User` oluşturamaz (mevcut unique + race handling).
- Aynı doğrulanmış `normalizedPhone` ikinci `User`’a bağlanamaz.
- E-posta bir hesaba, telefon başka hesaba aitse **otomatik merge yapılmaz**.
- Kullanıcıya hangi hesabın bulunduğu açıklanmaz (enumeration yok).
- Hesap birleştirme bu aşamada otomatik bir auth davranışı **değildir**.
- Gelecekte merge gerekiyorsa ayrı, yüksek güvenlikli, audit edilen ve geri alınabilir
  süreç tasarlanır.
- Kurum/admin kullanıcısı yalnız telefon/e-posta eşleşmesine dayanarak hesap birleştiremez.
- Race condition’larda DB unique constraint + transaction kullanılır.

### 10.2 KVKK / çocuk verisi / onay kaydı (uyum kapısı)

Bu bölüm hukuki tavsiye vermez; hukuk / KVKK inceleme kapısıdır.

- Veri minimizasyonu ve amaçla sınırlılık
- Saklama ve silme politikası (ayrı prosedür)
- Kullanım şartları / gizlilik / onay metni **sürümü**
- Onay zamanı ve gerekli bağlamın kaydı
- Çocuk verisi için yaş / veli onayı kararı hukuk danışmanlığı gerektirir
- Hukuki eşik kesinleşmeden ADR yaş değeri uydurmaz
- Gereksiz doğum tarihi, TC kimlik numarası, açık adres veya belge toplanmaz
- Başvuru belgeleri gerekirse erişim, şifreleme, saklama ve silme için ayrı güvenlik tasarımı
- Audit kayıtları OTP, kod ve gereksiz PII içermez
- `User` `archived` olduğunda ilişkisel kayıtların saklama / anonymization politikası
  ayrıca tanımlanır

---

## 11. Alt Aşamalar

| Alt aşama | İçerik | Bağımlılık gerekçesi |
|----------|--------|----------------------|
| **2.22.1** | Bu ADR + kabul ölçütleri onayı | Koddan önce sözleşme |
| **2.22.2** | Telefon identity + verification claim foundation | SMS / telefon bağlama önkoşulu |
| **2.22.3** | Account-type onboarding + pending teacher/institution applications | Yetkisiz rol üretimini engeller |
| **2.22.4** | Personal invitation + limited-use join-code foundation | İki kod türü ayrı sözleşme |
| **2.22.5** | Parent–student verified link | Veli onboarding + kişisel davet üzerine |
| **2.22.6** | Institution/classroom redemption integration | Mevcut membership/enrollment servisleri |
| **2.22.7** | Unified identifier login + server-validated context selection | E-posta birincil; telefon opsiyonel giriş sonra |
| **2.22.8** | Provider-neutral SMS challenge + fake adapter + feature flag | Telefon claim sonrası; prod kapalı |
| **2.22.9** | Full security matrix / Release Gate | Tüm dilimler birleşince |

**2.22.1 teknik onayı olmadan** kod dilimi başlamaz.

---

## 12. Kabul Ölçütleri

Aşama 2.22 (tüm alt aşamalar) tamamlanmış sayılmadan önce en az:

- [ ] Mevcut e-posta + şifre girişinin geriye uyumluluğu
- [ ] Public self-service hesaplarda e-posta zorunluluğu
- [ ] Aynı e-posta / doğrulanmış telefon ile duplicate kimlik oluşmaması
- [ ] Doğrulanmamış telefonun süresiz rezervasyon yapmaması
- [ ] Rol/bağlam seçiminin yetki üretmemesi
- [ ] Kişisel davet ve katılım kodunun rol olmaması
- [ ] Public öğretmen kaydının doğrudan `ROLE_TEACHER` vermemesi
- [ ] Public kurum başvurusunun onaysız Institution/üyelik/rol üretmemesi
- [ ] Veli erişiminin doğrulanmış çocuk ilişkisiyle sınırlı olması
- [ ] Generic auth / çakışma hataları
- [ ] Rate limit (login, OTP, invite, join-code, register)
- [ ] OTP/davet digest’inde HMAC (veya eşdeğer) + constant-time
- [ ] Audit (hassas alanlar sanitize)
- [ ] CSRF (bağlam değişimi dahil)
- [ ] Fresh actor / stale session / stale context koruması
- [ ] Dev/test fake SMS; production feature flag kapalı varsayılan
- [ ] Otomatik hesap birleştirmenin olmaması
- [ ] Production secret/config doğrulaması
- [ ] Unit / integration / security / browser testleri
- [ ] Migration ileri stratejisi; geri alınabilirlik veya bilinçli irreversible notu
- [ ] Mobil/tablet/API aynı domain sözleşmesi

Tek bir alt aşama “2.22 bitti” sayılmaz; Release Gate 2.22.9’da toplanır.

---

## 13. Kapsam Dışı

Bu aşamada **yok**:

- Telefon-only hesap ve telefon-only recovery
- SMS ile parola sıfırlama
- Gerçek SMS sağlayıcısı satın alma / production entegrasyonu (flag’siz açılış)
- Otomatik hesap birleştirme
- Ödeme / abonelik checkout UI
- Flutter mobil uygulamanın yazılması
- Gerçek öğrenci / öğretmen / veli panellerinin tamamlanması
- Yapay zekâ
- Haber, oyun, simülasyon, video ürün modülleri
- Sahte demo verilerle “panel tamam” izlenimi
- Owner transfer, hard-delete, e-posta/parola admin değiştirme (ayrı görev)
- Bu ADR’nin kimlik belgesi / TC / doğum tarihi toplamayı zorunlu kılması

---

## 14. Ürün kararları

### 14.1 Aşama 2.22 için onaylanan kararlar

| # | Karar |
|---|--------|
| A1 | E-posta public self-service hesaplarda zorunlu kalır |
| A2 | Öğrenci telefonu zorunlu değildir (nullable) |
| A3 | Veli telefonu öğrenci hesabına yazılmaz |
| A4 | Doğrulanmış telefon (`normalizedPhone`) global unique olur |
| A5 | Öğretmen self-register başvurusu pending olur; doğrudan `ROLE_TEACHER` verilmez |
| A6 | Kurum self-service başvuru yapabilir; onaysız rol / kurum / üyelik oluşmaz |
| A7 | Kişisel davet tek kullanımlıdır |
| A8 | Sınıf katılım kodu sınırlı çok kullanımlı olabilir (ayrı redemption kayıtları) |
| A9 | SMS production sağlayıcısı kurulana kadar feature flag ile kapalıdır |
| A10 | Kurum / profil context seçimi yetki kaynağı değildir |

### 14.2 Açık kalan ürün / hukuk kararları

| # | Soru | Not |
|---|------|-----|
| O1 | Öğrenci self-registration için yaş / veli onayı eşiği | Hukuk danışmanlığı; ADR yaş uydurmaz |
| O2 | Veli–çocuk bağlantısında hangi senaryoda çift taraflı / kurum onayı gerektiği | Risk bazlı politika |
| O3 | Öğretmen doğrulamasında hangi kanıtların isteneceği | KVKK / ürün; belge zorunlu değil |
| O4 | Kurum başvurusunda hangi resmi bilgilerin zorunlu olacağı | Duplicate/impersonation ile birlikte |
| O5 | SMS’in genel kullanıcılar için ne zaman aktive edileceği | Sağlayıcı + flag sonrası |
| O6 | Yüksek yetkilerde MFA yöntemi | SMS tek faktör değildir; ayrı politika |

---

## 15. Referanslar (mevcut kod / doküman)

- `src/Entity/User.php`
- `src/Enum/UserRole.php`, `src/Enum/UserStatus.php`
- `src/Enum/InstitutionMembershipRole.php`
- `src/Service/RegistrationService.php`, `src/Dto/RegistrationRequest.php`
- `src/Security/LoginFormAuthenticator.php`, `src/Security/UserChecker.php`
- `config/packages/security.yaml`, `config/packages/rate_limiter.yaml`
- `src/Entity/InstitutionMembership.php`, `src/Service/InstitutionMembershipManager.php`
- `src/Service/InstitutionCreator.php`
- `src/Entity/ClassroomStudentEnrollment.php`
- `src/Service/FreshUserLoader.php`, `src/Service/InstitutionalFreshEntityLoader.php`
- `src/Service/SecurityAuditRecorder.php`
- `src/Security/InstitutionVoter.php`, `src/Security/ClassroomVoter.php`, `src/Security/AdminVoter.php`
- `docs/architecture.md`
- `docs/architecture-admin-identity-institution-management.md`

---

## 16. Belge durumu

| Madde | Değer |
|-------|--------|
| Aşama | 2.22 — Kimlik doğrulama ve üyelik genişletme |
| Tür | **Revize edilmiş ADR taslağı** (2.22.1) |
| Ürün | Kullanıcı ürün kararları işlendi (§14.1) |
| Onay | **Teknik onay bekliyor** |
| Kodlama | **Başlamadı** |
| Uygulama | Bu belgede kod / migration yok |
