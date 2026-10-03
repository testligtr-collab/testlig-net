# MAT.1.3.2 taslak içerik paketi

Bu paket yerelde hazırlanmıştır. Production’a uygulanmamıştır. İçe aktarma, yayın ve placement bu dosyalarla yapılmaz.

## Kaynak

Erişim tarihi: 2026-10-02.

- Program: https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339
- Tema sayfası: https://tymm.meb.gov.tr/ilkokul-matematik-dersi/unite/109
- PDF, TTKB PID 2339 ile aynı bayttır. SHA-256 `249D9FF5A9D7AAB3BE853F112C395A29A776DECE302D4A474EA23B2453A82FF0`

Doğrulanan çıktı: `MAT.1.3.2` / `mat_1_3_2` / Nesnelerin eşliğini değerlendirebilme.

Program `mat_grade1_tymm`, sürüm `TYMM-2026`, ders kodu `matematik`, 1. sınıf. Tema `Nesnelerin Geometrisi (1)`. Müfredat konu kodu `es_nesneler`.

Süreç bileşenleri yeni çıktı değildir. Pedagojik kapsam için okundu: ölçüt belirleme, ölçme, ölçütle karşılaştırma ve yargıda bulunma.

## Özgünlük

Konu anlatımı ve beş soru özgün metindir. Resmî sayfadan soru veya uzun metin kopyalanmamıştır. Çıktı adı kaynak metindir. Sorular resmî soru olarak gösterilmez.

Eş nesneler renk, biçim ve büyüklük birlikte karşılaştırılarak değerlendirilir. Yalnız aynı renk yeterli değildir. Renk de göz ardı edilmez. Yalnız yön değişince eşlik bozulmaz.

## Katalog topic

Fixture adı `Eş Nesneler`. `CatalogSlugger` çıktısı:

- Ünite slug: `nesnelerin-geometrisi-1`
- Konu slug: `es-nesneler`

Öğrenci yolu, yayınlanmış placement varsa: `/ogrenci/dersler/matematik/nesnelerin-geometrisi-1/es-nesneler`

Bu pakette placement kaydı yoktur.

## Soru CSV

`QuestionCsvParser` başlığı birebirdir. UTF-8 BOM, virgül, CRLF.

Kod, 32 küçük onaltılık ve geçerli UUID olmalıdır. `mat-1-3-2-es-nesneler-01` biçimi importer tarafından reddedilir. Bu pakette kullanılan kodlar:

| Sıra | Kod | Doğru |
| --- | --- | --- |
| 1 | `3a492d0c79f5484e8f8ad6ac9bf0f990` | B |
| 2 | `dad9fe2ff11e40e4905a924bcaf6e254` | D |
| 3 | `d71b3d2377fd4aa190c24d009fb60453` | A |
| 4 | `94ea025e9ddb4eb3be5778a3646686e0` | C |
| 5 | `7c1a193662264bb594de96c67e179679` | B |

İçe aktarma taslak `single_choice` soru yazar. Zorluk kolonu yoktur; importer `medium` yazar. Puan kolonu yoktur. Yayınlanmaz.

## Konu anlatımı

Özet alanı (`summary`): Eş nesneleri renk, biçim ve büyüklüklerine göre karşılaştırmayı öğren.

Doküman `LearningContentDocument` şemasıdır. `callout` varyantı yalnız `info`, `warning`, `tip`, `note` olabilir. İstenen `success` bu modelde yoktur; özet kutu `tip` olarak yazıldı. Video, PDF ve görsel yoktur.

## İlerideki yayın sırası

Taslak içerik, inceleme, içerik yayını, placement taslağı, placement yayını. Bu adımlar ayrıdır ve bu turda çalıştırılmamıştır.
