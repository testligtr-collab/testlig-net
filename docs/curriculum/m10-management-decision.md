# M10 müfredat yönetimi kararı

Bu kayıt 2026-10-26 tarihli kullanıcı kararıdır. Yönetim, mevcut `Subject`, `CurriculumProgram`, `CurriculumUnit`, `CurriculumTopic` ve `CurriculumLearningOutcome` modeli üzerinde `/yonetim/programlar` adresinde uygulanır. Öğrenci kataloğu ayrı durur. M10-curriculum `partial` kalır. Resmî metin doğrulaması ve production kabulü açık kalır.

## Kaynak ağaç

Teslim isteği, “MEB 1–12 Müfredat Şema Ağacı — İngilizce entegre” metninin değiştirilmeden `docs/curriculum/sources/user-provided-grade-1-12-tree.md` dosyasına yazılmasını ve 11. sınıf İngilizce ile Tarih dallarını 11. sınıf altında, 12. sınıfı Lise kademesi altında yerleştiren özetin `docs/curriculum/grade-1-12-tree-outline.md` dosyasında tutulmasını istiyordu.

2026-10-26 müfredat yönetimi turunda da bu metin yoktu. İki dosya bu yüzden oluşturulmadı. Ağaç uydurulmadı. Resmî kazanım kodu üretilmedi. Yayımlı katalog değiştirilmedi. Program sürümü, dersler, öğrenme çıktıları ve resmî kodlar doğrulanmadan metin production’a aktarılmaz, import edilmez ve yayımlanmaz. Kaynak eksikliği katalog ve program yönetimini durdurmadı.

Metin aynen gelirse kaynak dosyası açıklama paragrafından sonra eksiksiz saklanır. Özet yalnız hiyerarşiyi düzenler; içerik eklemez ve resmî doğruluk iddiası taşımaz.

## Mevcut model

Operasyonel sınıf `Classroom` kaydıdır. Kurum ve akademik yıl foreign key'dir. Sınıf adı, örneğin "5-A", müfredat kaydı değildir.

Sınıf düzeyi mevcut `GradeLevel` enum'udur ve `Classroom` ile `CurriculumProgram` üzerinde durur. Katalog dersi `CatalogSubject` aynı enum'u taşır. Bu tur yeni bir katalog foreign key'i veya serbest metin müfredat listesi eklemez. Sınıf oluşturma ekranı yalnız 1–12 düzeyini seçer. Taslak, pasif veya yanlış kademedeki bir katalog satırı seçilemez çünkü satır seçilmez.

Müfredat hiyerarşisi bugün `Subject`, `CurriculumProgram` (ders + düzey + kod + sürüm), `CurriculumUnit`, `CurriculumTopic` ve `CurriculumLearningOutcome` üzerindedir. Öğrenci kataloğu ayrıca `CatalogSubject`, `CatalogUnit` ve `CatalogTopic` yayın durumunu taşır. Bu iki yapı bu turda birleştirilmedi.

## Uygulanan eşleme

Kademe için ayrı tablo yoktur. `GradeLevel` 1–12 enum olarak kalır. 1–4, 5–8 ve 9–12 yalnız etiket olarak okunur. Operasyonel `Classroom` adı, örneğin 5-A, katalog düzeyi değildir.

Ders `Subject` kaydıdır. Program ve sürüm `CurriculumProgram` kaydıdır. Ünite `CurriculumUnit`, konu `CurriculumTopic`, kazanım `CurriculumLearningOutcome` kaydıdır. Öğrenci keşfi `CatalogSubject`, `CatalogUnit` ve `CatalogTopic` yayın zincirini kullanır. Bu iki ağaç birleştirilmedi.

`/yonetim/programlar` liste, arama, filtre, detay, ders ve taslak program oluşturma, taslak ad düzenleme, yayın, emekliye ayırma ve bağımlılığı olmayan taslak silme sunar. Alt kayıt oluşturma, ad düzenleme, arşiv ve kullanılmamış taslak silme aynı programın içinde kalır. Başka programın UUID’si üst kayıt olamaz. Yayımlı programın adı ve yapısı yerinde değişmez.

Aktif doğrulanmış süper yönetici ve yönetici bu işlemleri kullanır. Moderatör yalnız `curriculum.view`, `curriculum.manage`, `curriculum.publish` ve `curriculum.archive` izinlerinden sahibine açık olanı kullanır. İçerik, soru ve test izinleri müfredat izni vermez. Yayın izni oluşturma izninden çıkmaz.

Üst düğüm yayında veya aktif değilse alt düğüm öğrenciye kendi durumundan bağımsız kapalıdır. Üst kayıt açılınca alt kaydın kendi durumu yazılmaz. Bağımlı kayıt kalıcı silinmez. Silinen taslağın kodu yeniden kullanılınca yeni kimlik alır.

## Açık kalan karar

- Kullanıcının 1–12 İngilizce entegre ağaç metni hâlâ gelmedi. Kaynak dosyaları yazılmadı.
- Resmî program yılı, sürüm ve kazanım kodu doğrulanmadı. Import ve yayın yok.
- Production görsel kabulü yok.
- İçerik durumu aktiften arşive gider. Ayrı bir geri alınabilir pasif durum eklenmedi.
- Toplu işlem yok.
