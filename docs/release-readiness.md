# RC1 yayın hazırlığı

Bu belge 2026-09-28 denetiminin kanıtıdır. Yeni ürün kararı içermez. Şema değişikliği yoktur.

Durum: `geçti` kanıtlanan davranış, `düzeltildi` bu PR’daki dar düzeltme, `ertelendi` ayrı iş.

## Envanter

Route’lar `src` içindeki `#[Route]` ve `name:` değerlerinden, erişim `config/packages/security.yaml` `access_control` ve rol hiyerarşisinden, cache ise `src/EventSubscriber/*NoStoreResponseSubscriber.php` ile `SensitiveResponseHeaderSubscriber` üzerinden okundu. Twig `path('...')` adları controller adlarıyla karşılaştırıldı. `app_login` sabiti `LoginFormAuthenticator` içindedir; kırık route çıkmadı.

| Önek | Kim | Anonim | CSRF | Cache |
| --- | --- | --- | --- | --- |
| `/`, genel sayfalar | herkese açık | 200 | yok | nosniff; `X-Robots-Tag` yok |
| `^/kayit` | herkese açık | 200 | formda | no-store ve `noindex, nofollow` |
| `^/dogrula/eposta`, `^/sifremi-unuttum`, `^/sifre-yenile`, `^/giris`, `^/cikis` | herkese açık | 200 veya yönlendirme | login/logout ve formlarda | token ve unutulan parola sayfaları no-store, noindex, no-referrer; `/giris` HTML yanıtı `noindex, nofollow` |
| `^/davet/ogretmen`, `^/davet/ogrenci` | herkese açık | 200 | POST | no-store, noindex, no-referrer |
| `^/basvuru`, `^/hesabim` | `ROLE_USER` | `/giris` | formda | no-store, noindex, no-referrer |
| `^/kurum`, `^/ogretmen` | `ROLE_USER`; asıl sınır üyelik ve atama | `/giris` | POST | no-store, noindex, no-referrer |
| `^/ogrenci` | `ROLE_STUDENT` | `/giris` | POST | no-store, noindex, no-referrer |
| `^/veli` | `ROLE_PARENT` | `/giris` | POST | no-store, noindex, no-referrer |
| `^/yonetim` | `ROLE_USER`; panel kapısı controller/voter | `/giris` | POST | no-store, noindex, no-referrer |
| `^/calisma-alani` | `ROLE_USER`; içerik yetkisi veya aktif sınıf ataması | `/giris` | çıkış POST; sayfa salt GET | no-store, noindex, no-referrer |
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
| Header ve cache | düzeltildi | `SensitiveResponseHeaderSubscriber` no-store ve no-referrer’ı hassas öneklerde tutar. `ResponseSecurityPolicySubscriber` HTML indeks, CSP ve çerçeve kararını yazar. `/` `X-Robots-Tag` taşımaz ve no-store değildir. `/kayit` ve `/giris` `noindex, nofollow` taşır. `X-Content-Type-Options: nosniff` ana yanıtlarda durur | Aşağıdaki üretim baseline ve test istemcisi farkı |
| Kırık link | düzeltildi | Twig `path()` taramasında eksik route yok. `href="#"` yasal maddeleri bağlantı olmaktan çıktı | Gizlilik ve koşullar metni yazılmadı |
| Hata sayfaları | düzeltildi | `templates/bundles/TwigBundle/Exception/error.html.twig`, `error403.html.twig`, `error404.html.twig`. İstisna metni yok. Ana sayfa ve giriş var. CSRF Symfony’de 403 | `APP_DEBUG=0` olmadan iz sürme sayfası görünür. Ayrı 419 kodu yok |
| Skip link ve odak | geçti | `templates/base.html.twig` `skip-link` ve `#main-content`. `assets/styles/app.css` görünür odak | Piksel düzeni değişmedi |
| N+1 | düzeltildi | Öğrenci platform listesi artık yayın listesi + bir madde sayısı + bir pratik + bir deneme sorgusu. Kurum atama kartları bir alıcı sorgusu + bir deneme + bir madde sayısı. Sayı kart sayısıyla artmaz | Süre testi yazılmadı. Üretim yük testi yok. Diğer panellerdeki sayfalı raporlar önceki testlerde duruyor |
| Operasyon | geçti | Mevcut deploy workflow `concurrency`, tek oturum ve `ConnectionAttempts=1`. CI test transport | SSH workflow değiştirilmedi. SSH/SCP bu PR’da çalıştırılmadı |
| Migration | geçti | Bu PR’da migration dosyası yok | Şema gerektiren iş eklenmedi |

## Yanıt güvenliği

### Test istemcisi ve üretim farkı

PR #76 CI istemcisinin `/` ve `/kayit` üzerinde gördüğü tek başına `X-Robots-Tag: noindex` uygulama kodundan gelmiyordu. Symfony `DisallowRobotsIndexingListener` (`kernel.response`, öncelik -255) header yoksa `noindex` yazar. `framework.disallow_search_engine_index` varsayılanı `$debug` olduğu için WebTestCase’te açıktı, `APP_DEBUG=0` üretimde kapalıydı. 2026-09-28 tarihinde, SSH kullanmadan, tek `curl -sI` turunda `https://testlig.net/`, `/giris`, `/kayit` ve `https://www.testlig.net/` bu header’ı taşımıyordu. Aynı yanıtlarda `Content-Security-Policy`, `Content-Security-Policy-Report-Only`, `X-Frame-Options`, `Referrer-Policy` ve HSTS de yoktu. Dördü de `X-Content-Type-Options: nosniff` taşıyordu. `/` ve `/giris` ile `www` önbelleği `max-age=0, must-revalidate, private` idi. `/kayit` `no-store, private` idi. Durum dördünde 200’dü. Cookie, token veya secret kaydedilmedi. Repository içindeki `public/.htaccess` ve `docker/nginx/default.conf` güvenlik header’ı yazmaz. Üretim OpenLiteSpeed vhost’u repository dışındadır.

`disallow_search_engine_index: false` bu listener’ı her ortamda kapatır. İndeks kararı artık route allowlist’idir.

### İndeks

Indexlenebilir route’lar `app_home` ve dört yasal sayfadır. Başarılı HTML yanıtta `X-Robots-Tag` silinir; `index, follow` yazılmaz. Giriş, kayıt, davet, paneller, diğer HTML yanıtlar ve yönlendirmeler `noindex, nofollow` alır. Hata sayfaları buna dahildir. Header route adı veya token taşımaz. JSON sağlık, webhook ve PDF baytları bu karara girmez. Özel önek subscriber’ları PDF URL’lerinde mevcut noindex’i bırakır.

### CSP ve clickjacking

HTML için tek enforcing header:

`default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'nonce-<32 hex>'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; media-src 'self'`

`upgrade-insecure-requests` yalnız `prod` ve HTTPS istekte eklenir. `worker-src`, `manifest-src` ve `block-all-mixed-content` kullanılmaz. HSTS uygulama katmanında üretilmez. `X-Frame-Options: DENY` HTML ve yönlendirmededir; PDF, JSON ve webhook’ta yoktur. `Permissions-Policy` HTML’de kamerayı, mikrofonu, konumu ve ödemeyi kapatır; tam ekranı kapatmaz. Hassas yüzeylerdeki `Referrer-Policy: no-referrer` korunur. Yoksa public HTML `strict-origin-when-cross-origin` alır.

Nonce yalnız AssetMapper import map ve modül girişindedir. Her ana istekte `random_bytes(16)` ile üretilir, loglanmaz. CSS `importmap` üzerinden değil `<link rel="stylesheet">` ile yüklenir. `importmap_polyfill` kapalıdır; `ga.jspm.io` allowlist’e alınmaz. Native import map olmayan tarayıcılar Stimulus’u yüklemez. `'unsafe-inline'`, `'unsafe-eval'`, `*` ve `https:` yoktur. YouTube sıradan `youtube.com` iframe hostu değildir.

### Bilinen sınır

Gerçek Chromium kabulü `docs/browser-acceptance.md` içindedir. PHPUnit hâlâ başlıksız bir tarayıcı çalıştırmaz. Playwright işi CSP’yi kapatmaz, production adresine gitmez ve video host isteklerini keser. Pixel karşılaştırma yoktur.

## Ertelenen

### P1 — yasal kimlik ve nihai metin

`/gizlilik`, `/kullanim-kosullari`, `/cerez-politikasi` ve `/cocuk-ve-veli-bilgilendirmesi` herkese açıktır ve yalnız kodda doğrulanan davranışı anlatır. Veri sorumlusu kimliği, saklama, aktarım, çocuk modeli ve sözleşme kabul kanıtı `docs/legal-review-checklist.md` içindedir. Envanter `docs/privacy-data-inventory.md` içindedir. Bu kararlar alınmadan sayfalar tamamlanmış aydınlatma veya sözleşme değildir.

### P1 — öğretmen ve kurum panelleri

Öğrenci ve veli kabuğu `docs/student-parent-ui-guidelines.md` altındadır. Öğretmen çalışma alanı ve kurum kabuğu `docs/teacher-institution-ui-guidelines.md` altındadır. Yönetim stylesheet’i bu iki kabuğa yüklenmez. Pixel snapshot yoktur.

## Bu PR’da yapılmayanlar

Yeni ürün alanı, yeni soru tipi, ödev, ödeme, reklam, yeni rol, production seed, hukuki metin ve migration yok. Hız sınırı aynı kaldı.
