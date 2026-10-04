# MAT.1.3.3 taslak içerik paketi

Bu paket yerelde hazırlanmıştır. Production’a uygulanmamıştır. İçe aktarma, yayın ve placement bu dosyalarla yapılmaz.

## Kaynak

Erişim tarihi: 2026-10-04.

- Program: https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339
- Tema sayfası: https://tymm.meb.gov.tr/ilkokul-matematik-dersi/unite/113
- Depo: `data/curriculum/meb/tymm-2026/grade-1-matematik.yaml`
- PDF, TTKB PID 2339 ile aynı bayttır. SHA-256 `249D9FF5A9D7AAB3BE853F112C395A29A776DECE302D4A474EA23B2453A82FF0`

Doğrulanan çıktı: `MAT.1.3.3` / `mat_1_3_3` / Günlük yaşamdaki nesneleri biçimsel özelliklerine göre ayırt edebilme.

Program `mat_grade1_tymm`, sürüm `TYMM-2026`, ders kodu `matematik`, 1. sınıf. Tema `Nesnelerin Geometrisi (2)`. Müfredat konu kodu `nesnelerin_bicimsel_ozellikleri`. İçerik çerçevesi: Nesnelerin Biçimsel Özellikleri.

TYMM sayfasında çıktı adı `MAT. 1.3.3` (MAT’ten sonra boşluk) görünür. Depo ve PDF kuralı `MAT.1.3.3` kullanır. Boşluksuz resmî kod esas alındı.

Süreç bileşenleri yeni çıktı değildir. 1.3.3 için TYMM sayfasında a/b/c maddesi yoktur. Pedagojik kapsam: günlük nesneleri görünüşlerindeki biçimsel benzerlik ve farklılığa göre ayırt etme. Yuvarlak ve köşeli, gözlenebilir ipucudur; bütün biçimleri bu iki sınıfa indirgemez. Geometrik ad (üçgen, kare, dikdörtgen, çember) ve kenar/köşe sayısı bu paketin dışındadır.

## Özgünlük

Konu anlatımı ve beş soru özgün metindir. Resmî sayfadan soru veya uzun metin kopyalanmamıştır. Çıktı adı kaynak metindir. Sorular resmî soru olarak gösterilmez.

Nesneler biçimsel benzerlik ve farklılığa göre ayırt edilir. “Aynı biçim” ile “benzer biçim grubu” karıştırılmaz. Birinci soru kökü gruplama özelliğini “yuvarlak görünme” diye adlandırır; kutu ile cetvel bu özelliği paylaşmaz. Renk tek başına biçimi değiştirmez. Nesne adından biçim çıkarılmaz; kökte görünüş bilgisi verilir. Üçgen, kare, dikdörtgen, çember adlandırılmaz. Geometrik yapılardaki şekilleri çözümleme (MAT.1.3.4) ve şekilleri sınıflandırma (MAT.1.3.5) kapsama alınmaz.

## Katalog topic

Fixture adı `Nesnelerin Biçimsel Özellikleri`. `CatalogSlugger` çıktısı:

- Ünite slug: `nesnelerin-geometrisi-2`
- Konu slug: `nesnelerin-bicimsel-ozellikleri`

Öğrenci yolu, yayınlanmış placement varsa: `/ogrenci/dersler/matematik/nesnelerin-geometrisi-2/nesnelerin-bicimsel-ozellikleri`

Stable içerik kodu: `mat_1_3_3_nesnelerin_bicimsel_ozellikleri` (41 karakter, kolon sınırı 64).

Bu pakette placement kaydı yoktur. Importer allowlist paketi `tymm-2026/grade-1/matematik/mat-1-3-3` olarak tanır. Production içe aktarma bu dosyalarla yapılmaz.

## Soru CSV

`QuestionCsvParser` başlığı birebirdir. UTF-8 BOM, virgül, CRLF.

Kod, 32 küçük onaltılık ve geçerli UUID olmalıdır. Bu pakette kullanılan kodlar korundu:

| Sıra | Kod | Doğru |
| --- | --- | --- |
| 1 | `c42685528712410e898e1c5b1bfca821` | C |
| 2 | `b07a55ad45c9411ab8364d8e1b0dca54` | A |
| 3 | `21ea85aae2a94af99079a5fbe24ed443` | D |
| 4 | `67a48d575fe54d609fc6260e5de778c7` | B |
| 5 | `c648ed3074684de987576a75554bb1a6` | C |

Kökler yeniden yazıldı; UUID’ler aynı kaldı çünkü aynı taslak paketin aynı beş yuvasıdır.

İçe aktarma taslak `single_choice` soru yazar. Zorluk kolonu yoktur; importer `medium` yazar. Puan kolonu yoktur. Yayınlanmaz.

## Konu anlatımı

Özet alanı (`summary`): Günlük nesneleri görünüşlerindeki biçimsel benzerlik ve farklılıklara göre ayırt etmeyi öğren.

Doküman `LearningContentDocument` şemasıdır. `callout` varyantı yalnız `info`, `warning`, `tip`, `note` olabilir. Video, PDF ve görsel yoktur.

## İlerideki yayın sırası

Taslak içerik, inceleme, içerik yayını, placement taslağı, placement yayını. Bu adımlar ayrıdır ve bu turda çalıştırılmamıştır.
