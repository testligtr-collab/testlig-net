# Yönetim arayüzü

Bu kabuk yalnız `/yonetim` ve onun altında açılan sayfalar içindir. Öğrenci, veli ve kurum panelleri aynı stylesheet’i yüklemez.

## Renk

Mevcut Testlig tokenları kullanılır: mavi ana renk, turuncu yalnız ana eylem, açık mavi-gri zemin, beyaz kart. Yeni renk `assets/styles/admin.css` içinde bu tokenlara bağlanır. Sayfa içine `style=""` yazılmaz. Renk, metinsiz durum anlatmaz.

## Menü

`AdminNavBuilder` tek yetki kaynağıdır. Şablon `is_granted` veya `ROLE_*` ile yeni menü kararı vermez. Operasyon grubu yalnız builder öğeyi ürettiyse görünür. İkon adı istekten okunmaz; `AdminIconCatalog` bilinmeyen adı `default` yapar.

Admin ödeme, webhook, uzlaştırma ve denetim bağlantısını görmez. SuperAdmin bu bağlantıları görür. İki rol de üyelik olmadan `/kurum` açamaz.

## Bileşenler

- `admin-sidebar` / `panel-sidebar`: marka, ad, rol, gruplar, ana sayfa, hesabım, CSRF’li çıkış
- `admin-topbar`: 1024 px altında menü, “Yönetim” ve baş harf
- `admin-metric`: gerçek sayım; bağlantısı olmayan kart tıklanabilir görünmez
- `admin-filters`: etiketli GET araç çubuğu ve aktif filtrede “Temizle”
- `admin-table` / `admin-table-wrap`: yatay kaydırma yalnız bu kapsayıcıda
- `admin-status`: Türkçe etiket ve varyant noktası
- `empty-state` ve `admin-flash`

## Eşikler

- 360 px: metrikler tek kolon, filtreler tek kolon
- 768 px: metrikler iki kolon
- 1024 px: sabit 17.5rem sidebar, çekmece kapalı
- 1280 px: metrikler dört kolon
- 1024 px altında çekmece Esc, “Menüyü kapat” ve bağlantı tıklamasıyla kapanır

## Durum etiketi

Domain değeri değişmez. `AdminStatusLabels` onu Türkçe metne ve `neutral`, `success`, `warning`, `danger` veya `limited` varyantına çevirir. Bilinmeyen değer “Bilinmiyor” olur ve sınıf olarak basılmaz.

## Yeni sayfa

1. `admin/layout.html.twig` uzat.
2. Menü gerekiyorsa izni `AdminAuthorization` ve kaydı `AdminNavBuilder` içine ekle.
3. İkonu allowlist’e ekle; şablona serbest ikon adı yazma.
4. Filtre GET kalsın, yeni parametre allowlist’e girsin.
5. Yazma formu POST, CSRF ve mevcut voter ile kalsın.
6. E-posta, UUID, storage key, ciphertext ve parola özetini listeye koyma.
7. `docs/browser-acceptance.md` içindeki ekran listesine sentetik görüntüyü ekle.
