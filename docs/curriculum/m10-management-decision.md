# M10 müfredat yönetimi kararı

Bu kayıt 2026-10-26 tarihli kullanıcı kararıdır. Müfredat CRUD'u, moderatör delegasyonu, import ve yayın bu turda uygulanmadı. M10-curriculum `partial` kalır.

## Kaynak ağaç

Teslim isteği, “MEB 1–12 Müfredat Şema Ağacı — İngilizce entegre” metninin değiştirilmeden `docs/curriculum/sources/user-provided-grade-1-12-tree.md` dosyasına yazılmasını ve 11. sınıf İngilizce ile Tarih dallarını 11. sınıf altında, 12. sınıfı Lise kademesi altında yerleştiren özetin `docs/curriculum/grade-1-12-tree-outline.md` dosyasında tutulmasını istiyordu.

2026-10-26 takip mesajında da bu metin yoktu. İki dosya bu yüzden oluşturulmadı. Ağaç uydurulmadı. Resmî kazanım kodu üretilmedi. Yayımlı katalog değiştirilmedi. Program sürümü, dersler, öğrenme çıktıları ve resmî kodlar doğrulanmadan metin production’a aktarılmaz, import edilmez ve yayımlanmaz.

Metin aynen gelirse kaynak dosyası açıklama paragrafından sonra eksiksiz saklanır. Özet yalnız hiyerarşiyi düzenler; içerik eklemez ve resmî doğruluk iddiası taşımaz.

## Mevcut model

Operasyonel sınıf `Classroom` kaydıdır. Kurum ve akademik yıl foreign key'dir. Sınıf adı, örneğin "5-A", müfredat kaydı değildir.

Sınıf düzeyi mevcut `GradeLevel` enum'udur ve `Classroom` ile `CurriculumProgram` üzerinde durur. Katalog dersi `CatalogSubject` aynı enum'u taşır. Bu tur yeni bir katalog foreign key'i veya serbest metin müfredat listesi eklemez. Sınıf oluşturma ekranı yalnız 1–12 düzeyini seçer. Taslak, pasif veya yanlış kademedeki bir katalog satırı seçilemez çünkü satır seçilmez.

Müfredat hiyerarşisi bugün `Subject`, `CurriculumProgram` (ders + düzey + kod + sürüm), `CurriculumUnit`, `CurriculumTopic` ve `CurriculumLearningOutcome` üzerindedir. Öğrenci kataloğu ayrıca `CatalogSubject`, `CatalogUnit` ve `CatalogTopic` yayın durumunu taşır. Bu iki yapı bu turda birleştirilmedi.

## Açık yönetim kararı

- Kategori/kademeden sınıf düzeyi, ders, ünite/tema, konu ve kazanıma kadar oluşturma, düzenleme, aktif/pasif ve silme/arşiv yönetimi isteniyor.
- Admin işlemleri mevcut yetki kapsamında kalır. Moderatör yalnız açıkça verilmiş ayrı müfredat izinleriyle işlem yapar.
- Hiç kullanılmamış ve bağımlılığı olmayan taslak kalıcı silinebilir.
- İçerik, soru, sınav veya öğrenci geçmişine bağlı kayıt arşivlenir. Geçmiş referanslar korunur.
- Üst kayıt pasifse alt kayıt kendi başına yayımlanmış veya erişilebilir sayılmaz.
- Üst kayıt yeniden açılınca alt kayıtların kendi durumları sessizce değiştirilmez.
- Yeniden oluşturulan kayıt yeni kimlik alır. Geçmiş ilişkiler otomatik taşınmaz.
- Toplu alt kayıt silme sessiz gerçekleşmez. Bağımlılık özeti, açık onay ve audit gerekir.
