# RC1 yayın hazırlığı

Bu belge 2026-09-28 denetiminin kanıtıdır. Yeni ürün kararı içermez. Şema değişikliği yoktur.

Durum: `geçti` kanıtlanan davranış, `düzeltildi` bu PR’daki dar düzeltme, `ertelendi` ayrı iş.

## Envanter

Route’lar `src` içindeki `#[Route]` ve `name:` değerlerinden, erişim `config/packages/security.yaml` `access_control` ve rol hiyerarşisinden, cache ise `src/EventSubscriber/*NoStoreResponseSubscriber.php` ile `SensitiveResponseHeaderSubscriber` üzerinden okundu. Twig `path('...')` adları controller adlarıyla karşılaştırıldı. `app_login` sabiti `LoginFormAuthenticator` içindedir; kırık route çıkmadı.

| Önek | Kim | Anonim | CSRF | Cache |
| --- | --- | --- | --- | --- |
| `/`, genel sayfalar | herkese açık | 200 | yok | nosniff; noindex yok |
| `^/kayit` | herkese açık | 200 | formda | no-store; kayıt formu noindex değil |
| `^/dogrula/eposta`, `^/sifremi-unuttum`, `^/sifre-yenile`, `^/giris`, `^/cikis` | herkese açık | 200 veya yönlendirme | login/logout ve formlarda | token ve unutulan parola sayfaları no-store, noindex, no-referrer |
| `^/davet/ogretmen`, `^/davet/ogrenci` | herkese açık | 200 | POST | no-store, noindex, no-referrer |
| `^/basvuru`, `^/hesabim` | `ROLE_USER` | `/giris` | formda | no-store, noindex, no-referrer |
| `^/kurum`, `^/ogretmen` | `ROLE_USER`; asıl sınır üyelik ve atama | `/giris` | POST | no-store, noindex, no-referrer |
| `^/ogrenci` | `ROLE_STUDENT` | `/giris` | POST | no-store, noindex, no-referrer |
| `^/veli` | `ROLE_PARENT` | `/giris` | POST | no-store, noindex, no-referrer |
| `^/yonetim` | `ROLE_USER`; panel kapısı controller/voter | `/giris` | POST | no-store, noindex, no-referrer |
| `^/health` | herkese açık | 200 yerel | yok | nosniff |
| `^/webhook/odeme/...` | herkese açık, yalnız POST | imza | ödeme imzası | nosniff |

`User::getRoles()` her kullanıcıya `ROLE_USER` ekler. `/yonetim` ve `/kurum` firewall kuralı bu yüzden tek başına yetki sınırı değildir. Öğrenci ve veli yönetim paneline controller kapısından giremez. Süper yönetici kurum üyeliği olmadan `/kurum` açamaz.

## Denetim

| Alan | Durum | Kanıt | Sınır |
| --- | --- | --- | --- |
| Anonim ve kayıt | geçti | `AccessControlOrderTest`, `RegistrationControllerTest` | Yerel çekirdek testi bu oturumda yok; CI çalıştırır |
| E-posta doğrulama, giriş, çıkış, parola | geçti | Mevcut kayıt, doğrulama ve sıfırlama testleri. Token URL bu PR’da no-store ve no-referrer | Hız sınırı gevşetilmedi. Test ortamında gerçek SMTP yok |
| Rol matrisi | geçti | `InstitutionWorkspaceControllerTest::testGlobalRolesWithoutMembershipAreDenied` Student, Parent, Teacher, Moderator, Admin ve SuperAdmin için `/kurum` 403. `AdminSecurityMatrixTest` öğrenci ve veliyi yönetimden çıkarır | Yeni rol eklenmedi |
| Öğrenci ders ve test | geçti | Mevcut öğrenci içerik ve `StudentAssessmentPracticeTest`. Kurum ataması `InstitutionClassroomAssignmentTest` | YouTube/Vimeo için ağ isteği yapılmadı. Pilot içerik değişmedi |
| Veli | geçti | `ParentPanelControllerTest` | Kod özeti ve opaque 404 mevcut testlerde |
| İçerik, soru, test SoD | geçti | `LearningContentManager`, `QuestionManager`, `AssessmentManager` `reviewSeparation()` | Bu PR içerik yazmadı |
| Kurum sınıf testi | geçti | PR #75 testleri main üzerinde duruyor | Ödev, yeni soru tipi ve CSV yok |
| Header ve cache | düzeltildi | `SensitiveResponseHeaderSubscriber`. `/hesabim` ve `/basvuru` no-store, noindex, no-referrer oldu. Parola sıfırlama ve doğrulama URL’leri aynı. `/kayit` no-store ama noindex değil. `/` no-store almıyor. `X-Content-Type-Options: nosniff` ana yanıtlara eklendi. Veli ve yönetim `Referrer-Policy: no-referrer`. `ReleaseCandidateReadinessTest` | CI istemcisi `/` ve `/kayit` yanıtında `X-Robots-Tag: noindex` gördü. Uygulama kodunda bu tek değerin kaynağı yok. Yeni subscriber bu sayfalara `noindex, nofollow` yazmıyor. Kaynağı ayrı izlenecek. CSP aşağıda P1 |
| Kırık link | düzeltildi | Twig `path()` taramasında eksik route yok. `href="#"` yasal maddeleri bağlantı olmaktan çıktı | Gizlilik ve koşullar metni yazılmadı |
| Hata sayfaları | düzeltildi | `templates/bundles/TwigBundle/Exception/error.html.twig`, `error403.html.twig`, `error404.html.twig`. İstisna metni yok. Ana sayfa ve giriş var. CSRF Symfony’de 403 | `APP_DEBUG=0` olmadan iz sürme sayfası görünür. Ayrı 419 kodu yok |
| Skip link ve odak | geçti | `templates/base.html.twig` `skip-link` ve `#main-content`. `assets/styles/app.css` görünür odak | Piksel düzeni değişmedi |
| N+1 | düzeltildi | Öğrenci platform listesi artık yayın listesi + bir madde sayısı + bir pratik + bir deneme sorgusu. Kurum atama kartları bir alıcı sorgusu + bir deneme + bir madde sayısı. Sayı kart sayısıyla artmaz | Süre testi yazılmadı. Üretim yük testi yok. Diğer panellerdeki sayfalı raporlar önceki testlerde duruyor |
| Operasyon | geçti | Mevcut deploy workflow `concurrency`, tek oturum ve `ConnectionAttempts=1`. CI test transport | SSH workflow değiştirilmedi. SSH/SCP bu PR’da çalıştırılmadı |
| Migration | geçti | Bu PR’da migration dosyası yok | Şema gerektiren iş eklenmedi |

## Ertelenen

### P1 — CSP ve çerçeve politikası

Kodda `Content-Security-Policy` veya `X-Frame-Options` yok. Video blokları YouTube ve Vimeo allowlist’ine bağlı. Bu PR gevşek veya tahminî bir CSP eklemez. Ayrı iş: `frame-src` allowlist’i mevcut `VideoUrlParser` ile aynı tutan sıkı bir politika.

### P1 — yasal sayfalar

Gizlilik ve kullanım koşulları metni yok. Eski `href="#"` bağlantıları düz yazıya alındı. Metin uydurulmadı.

### P1 — tarayıcı piksel turu

Yerel Docker kapalı. 360, 768 ve 1280 görünüm turu bu oturumda yapılmadı.

## Bu PR’da yapılmayanlar

Yeni ürün alanı, yeni soru tipi, ödev, ödeme, reklam, yeni rol, production seed, hukuki metin ve migration yok. Hız sınırı aynı kaldı.
