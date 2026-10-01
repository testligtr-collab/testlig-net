# Herkese açık katalog önizlemesi

Ziyaretçi, giriş yapmadan yayımlanmış sınıf, ders, ünite ve konu adlarını görür. Bu yüzey `CatalogSubject` → `CatalogUnit` → `CatalogTopic` zincirinin published kayıtlarını okur. Yeni katalog tablosu yoktur. `LearningContent` gövdesi, PDF, video, soru, cevap ve test çözüm bu sayfalarda yoktur; onlar yalnız `/ogrenci/dersler` kapısından geçer.

## Adresler

| Adres | Route |
| --- | --- |
| `/dersler` | `app_public_catalog` |
| `/dersler/{1-12}` | `app_public_catalog_grade` |
| `/dersler/{sınıf}/{ders-slug}` | `app_public_catalog_subject` |
| `/dersler/{sınıf}/{ders-slug}/{ünite-slug}` | `app_public_catalog_unit` |
| `/sitemap.xml` | `app_public_sitemap` |
| `/robots.txt` | `app_robots` |

Sınıf parçasının kendi slug’ı yoktur; `GradeLevel` değeri `1`–`12` kullanılır. Ders ve ünite mevcut `[a-z0-9-]` slug’larıdır. Konu için ayrı public sayfa yoktur.

## Görünen ve gizlenen

Görünür: published ders, onun published ünitesi, onun published konusu, ad, slug, mevcut düz açıklama veya özet, alt kayıt sayısı, sıraya göre liste. Ünite `source_url` değeri yalnız mevcut ve `https` ise “Kaynak” olur (`rel="noopener noreferrer"`).

Gizli: draft ve archived, üst kaydı published olmayan alt kayıt, öğrenme içeriği gövdesi, revision JSON, PDF adresi, video kimliği, soru, cevap, test, UUID, storage key, audit, kullanıcı ve sahip. Twig entity almaz. Bulunamayan, taslak ve arşiv aynı opak 404’tür; redirect ile slug açıklanmaz.

Sayfa HTML’i herkes için aynıdır. Oturum okunmaz. İlk anonim GET çerez yazmaz. CTA kayda ve girişe gider. Öğrenci konu gövdesine geçiş, girişten sonraki mevcut resolver’dadır; öğretmen veya veli bu sayfadan öğrenci rotasına gönderilmez.

## Index ve sitemap

200 dönen dört katalog route’u index allowlist’indedir. 404, giriş, kayıt ve paneller noindex kalır. Canonical, sorgu dizgisini taşımaz. Görsel veya Course şeması eklenmez; yeterince gerçek ders sağlayıcı alanı yoktur.

Sitemap ana sayfayı, dört yasal sayfayı, `/dersler` ve published sınıf, ders, ünite adreslerini içerir. `lastmod` yalnız `published_at` doluysa vardır. Sınıf listesi bir sorgu, sınıf sayfası üç, ders sayfası üç, ünite sayfası iki, sitemap iki sorgudur. 1 ders / 7 ünite / 19 konu bu sayıları büyütmez.

Reklam, izleme veya yeni çerez bu işin parçası değildir. İleride reklam ayrı bir gizlilik ve hukuk işidir.

Deploy bu sayfaları salt okunur açar. Katalog verisi, soru veya öğrenme içeriği yazılmaz.
