# Öğrenci ve veli arayüzü

Bu kabuk `/ogrenci` ve `/veli` içindir. Yönetim stylesheet’i bu sayfalara yüklenmez. Öğretmen ve kurum panelleri bu turda aynı kalır.

## Sade yüzey

Açık mavi-gri zemin, beyaz kart, mavi bağlantı, turuncu yalnız ana eylem. Sahte ilerleme, puan, rozet, seri veya tavsiye üretilmez. İlerleme yalnız sunucunun verdiği soru sırası ve sonuç sayılarıyla yazılır.

## Menü

Öğrenci: Panel, Dersler, Testler, Geçmişim, Profil, Hesabım ve CSRF’li çıkış. Veli: Panel, Çocuklarım, Test sonuçları, Hesabım ve çıkış. Veli menüsünde test başlatma yoktur. Menü kararı şablonda yeni bir rol dizesiyle kurulmaz; route’lar mevcut güvenlik katmanındadır.

## Bileşenler

`portal-hello`, `portal-role`, `student-quick-card`, `student-catalog-card`, `student-content`, `student-test-option`, `student-metrics`, `empty-state`. İkon adı `portal_icon` allowlist’indedir. Bilinmeyen ad `default` olur.

## Eşikler

- 360 px: tek kolon
- 768 px: ders ve test kartlarında iki kolon
- 1280 px: hızlı erişim üç kolon, okuma gövdesi yaklaşık 42rem
- Belge yatay taşmaz
- Okuma, video ve seçenek alanları sabit bir alt çubuğun altında kalmaz

## İçerik ve test

Öğrenci gövdesi yalnız yayımlanmış ve görünür zincirden gelir. PDF adresi yetkili rotadır; storage key basılmaz. Video yalnız gizlilik hostundadır. Cevap anahtarı çözüm ekranından önce HTML’de yoktur. Başka öğrencinin sonucu opak 404’tür.

## Veli

Özet ad, sınıf, yayımlanan ders adları ve sınırlı test özetidir. E-posta, okul, şehir, öğrenme hedefi, cevap, çözüm ve teknik anahtar yoktur. Bağlantı kodu forma yazılır, URL’ye yazılmaz. Kod yoksa bağlama ekranı açılır. Başka çocuk opak 404’tür.

## Yeni ekran

1. Öğrenci veya veli layout’unu uzat; `admin.css` ekleme.
2. Yeni menü linkini mevcut route güvenliğine bağla.
3. Veriyi DTO ile ver; entity, UUID, storage key ve cevap anahtarı koyma.
4. İkonu allowlist’e ekle.
5. Yazmayı POST, CSRF ve PRG ile bırak.
6. `docs/browser-acceptance.md` listesine sentetik görüntüyü ekle.
