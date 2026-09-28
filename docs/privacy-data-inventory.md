# Gizlilik veri envanteri

Bu liste, 28 Eylül 2026 tarihinde repository’deki koddan okunan teknik kullanımdır. Saklama süresi kodda yoksa “işletme/hukuk doğrulaması gerekli” yazılır. Hukuki nitelendirme yapılmaz.

| Öğe | Amaç | Taraf | Zorunlu | Ne zaman oluşur | Kanıt | Saklama |
| --- | --- | --- | --- | --- | --- | --- |
| Oturum çerezi (`PHPSESSID`, ad `framework.yaml` içinde değiştirilmemiş) | Giriş yapmış oturumu taşımak | Birinci taraf | Oturum için zorunlu | Oturum başladığında. Yasal sayfalar bunu başlatmaz | `config/packages/framework.yaml` `cookie_secure: auto`, `cookie_samesite: lax`, `cookie_httponly: true`, `handler_id: null` | işletme/hukuk doğrulaması gerekli |
| Çift gönderimli CSRF çerezi | Form ve çıkış isteğinin site kaynağından geldiğini görmek | Birinci taraf | Yazma formu için zorunlu | Form gönderilirken tarayıcıda | `config/packages/csrf.yaml`, `assets/controllers/csrf_protection_controller.js` | Kalıcı `Max-Age` yok. Temizleme yalnız Turbo gönderim sonunda. işletme/hukuk doğrulaması gerekli |
| Beni hatırla | Yok | — | Hayır | Oluşmaz | `config/packages/security.yaml` içinde `remember_me` yok | Yok |
| Flash iletisi | Bir sonraki yanıttaki kısa durum metni | Birinci taraf, oturumun içinde | Hayır, ayrı çerez değil | Uygulama flash yazdığında | `templates/base.html.twig` `app.flashes`. Yasal sayfa bu bloğu boşaltır | Oturumla sınırlı. işletme/hukuk doğrulaması gerekli |
| Hız sınırı sayacı | Giriş, parola, e-posta, davet ve bazı yazmaları sınırlamak | Birinci taraf, sunucu önbelleği | Güvenlik için zorunlu | İlgili istek geldiğinde | `config/packages/rate_limiter.yaml` | Pencereler dosyada dakika cinsinden. Kişisel veri olarak ek saklama: işletme/hukuk doğrulaması gerekli |
| `localStorage` / `sessionStorage` | Yok | — | Hayır | Oluşmaz | `assets/` içinde kullanım yok | Yok |
| Tercih veya onay çerezi | Yok | — | Hayır | Oluşmaz | Kabul bandı ve consent çerezi kodu yok | Yok |
| Analitik veya reklam scripti | Yok | — | Hayır | Oluşmaz | Şablonlarda ve `assets/app.js` içinde üçüncü taraf analitik yok | Yok |
| Ödeme SDK’sı | Yok | — | Hayır | Ödeme yüzeyi kapalı | `src/Controller/PaymentWebhookController.php` hazır uç; checkout SDK’sı yok | Yok |
| Harici font veya CDN | Yok | — | Hayır | Sayfa kendi CSS’ini yükler | `config/packages/asset_mapper.yaml` `importmap_polyfill: false`; CSP `font-src 'self'` | Yok |
| YouTube gizlilik oynatıcısı | Ders videosunu göstermek | Üçüncü taraf | Hayır; yalnız o blok sayfada varsa | Kullanıcı video bloğunu açtığında | `src/LearningContent/Document/VideoEmbed.php` `https://www.youtube-nocookie.com/embed/` | Oynatıcının kendi çerezi bu depodan doğrulanamaz. işletme/hukuk doğrulaması gerekli |
| Vimeo oynatıcısı | Ders videosunu göstermek | Üçüncü taraf | Hayır; yalnız o blok sayfada varsa | Kullanıcı video bloğunu açtığında | Aynı dosya `https://player.vimeo.com/video/` | Oynatıcının kendi çerezi bu depodan doğrulanamaz. işletme/hukuk doğrulaması gerekli |
| E-posta iletimi | Doğrulama ve parola sıfırlama | Sağlayıcı kodda sabit değil | Hesap akışı için kullanılır | Kayıt, yeniden gönderim ve parola sıfırlama | `config/packages/mailer.yaml` `%env(MAILER_DSN)%` | Sağlayıcının rolü ve saklama süresi: işletme/hukuk doğrulaması gerekli |

Gelecekte zorunlu olmayan bir script veya harici origin, `src/Security/ContentSecurityPolicy.php` allowlist’i değiştirilmeden çalışamaz. Bu dosya `'unsafe-inline'`, `'unsafe-eval'`, `*` ve genel `https:` kaynağı içermez.
