# M10 müfredat yönetimi kararı

Bu kayıt 2026-10-26 tarihli kullanıcı kararıdır. Yönetim, mevcut `Subject`, `CurriculumProgram`, `CurriculumUnit`, `CurriculumTopic` ve `CurriculumLearningOutcome` modeli üzerinde `/yonetim/programlar` adresinde uygulanır. Öğrenci kataloğu ayrı durur. M10-curriculum `partial` kalır. Resmî metin doğrulaması ve production kabulü açık kalır.

## Kaynak ağaç

Teslim isteği, “MEB 1–12 Müfredat Şema Ağacı — İngilizce entegre” metninin değiştirilmeden `docs/curriculum/sources/user-provided-grade-1-12-tree.md` dosyasına yazılmasını ve 11. sınıf İngilizce ile Tarih dallarını 11. sınıf altında, 12. sınıfı Lise kademesi altında yerleştiren özetin `docs/curriculum/grade-1-12-tree-outline.md` dosyasında tutulmasını istiyordu.

2026-10-26 müfredat yönetimi turunda da bu metin yoktu. İki dosya bu yüzden oluşturulmadı. Ağaç uydurulmadı. Resmî kazanım kodu üretilmedi. Yayımlı katalog değiştirilmedi. Program sürümü, dersler, öğrenme çıktıları ve resmî kodlar doğrulanmadan metin production’a aktarılmaz, import edilmez ve yayımlanmaz. Kaynak eksikliği katalog ve program yönetimini durdurmadı.

2026-10-27 devam turunda mesajın sonundaki ağaç yeri yalnızca “[1–12 MÜFREDAT AĞACI METNİNİ BURAYA YAPIŞTIR]” yer tutucusuydu. Bu yer tutucu müfredat metni değildir. İki kaynak dosyası yine yazılmadı. Resmî kazanım kodu üretilmedi. Veri tabanına import edilmedi.

Aynı günün sonraki mesajı da ağacın eklendiğini söyledi, fakat metin mesajda yoktu. Kaynak dosyaları bu yüzden yine yazılmadı. Eksik aktarım, ders, program ve sabit kademe/sınıf erişimi işini durdurmadı.

Metin aynen gelirse kaynak dosyası açıklama paragrafından sonra eksiksiz saklanır. Özet yalnız hiyerarşiyi düzenler; içerik eklemez ve resmî doğruluk iddiası taşımaz.

## Mevcut model

Operasyonel sınıf `Classroom` kaydıdır. Kurum ve akademik yıl foreign key'dir. Sınıf adı, örneğin "5-A", müfredat kaydı değildir.

Sınıf düzeyi mevcut `GradeLevel` enum'udur ve `Classroom` ile `CurriculumProgram` üzerinde durur. Katalog dersi `CatalogSubject` aynı enum'u taşır. Bu tur yeni bir katalog foreign key'i veya serbest metin müfredat listesi eklemez. Sınıf oluşturma ekranı yalnız 1–12 düzeyini seçer. Taslak, pasif veya yanlış kademedeki bir katalog satırı seçilemez çünkü satır seçilmez.

Müfredat hiyerarşisi bugün `Subject`, `CurriculumProgram` (ders + düzey + kod + sürüm), `CurriculumUnit`, `CurriculumTopic` ve `CurriculumLearningOutcome` üzerindedir. Öğrenci kataloğu ayrıca `CatalogSubject`, `CatalogUnit` ve `CatalogTopic` yayın durumunu taşır. Bu iki yapı bu turda birleştirilmedi.

## Uygulanan eşleme

`GradeLevel` 1–12 enum olarak kalır. 1–4, 5–8 ve 9–12 sabit erişim kapısıdır; yeni kademe kaydı açılmaz. Operasyonel `Classroom` adı, örneğin 5-A, katalog düzeyi değildir.

Ders `Subject` kaydıdır. Program ve sürüm `CurriculumProgram` kaydıdır. Ünite `CurriculumUnit`, konu `CurriculumTopic`, kazanım `CurriculumLearningOutcome` kaydıdır. Öğrenci keşfi `CatalogSubject`, `CatalogUnit` ve `CatalogTopic` yayın zincirini kullanır. Bu iki ağaç birleştirilmedi.

`/yonetim/programlar` liste, arama, filtre, detay, ders ve taslak program oluşturma, taslak ad düzenleme, yayın, emekliye ayırma ve bağımlılığı olmayan taslak silme sunar. Alt kayıt oluşturma, ad düzenleme, arşiv ve kullanılmamış taslak silme aynı programın içinde kalır. Başka programın UUID’si üst kayıt olamaz. Yayımlı programın adı ve yapısı yerinde değişmez.

Aktif doğrulanmış süper yönetici ve yönetici bu işlemleri kullanır. Moderatör yalnız `curriculum.view`, `curriculum.manage`, `curriculum.publish`, `curriculum.availability` ve `curriculum.archive` izinlerinden sahibine açık olanı kullanır. İçerik, soru ve test izinleri müfredat izni vermez. Yayın, pasiflik ve arşiv izinleri birbirinden çıkmaz.

Ünite, konu ve kazanım `active`, `inactive` ve `archived` durumlarını taşır. Pasif kayıt yeniden aktif edilir. Arşiv son durumdur ve pasif gibi gösterilmez. Pasif yapmak alt kaydın kendi durumunu yazmaz. Üst kayıt pasif, arşiv veya program yayında değilse alt kayıt öğrenciye kapalı kalır. Üst kayıt yeniden açılınca alt kaydın kendi durumu değişmez.

Ders `SubjectStatus` içinde `inactive` değerini de taşır. Aktif ve pasif birbirine döner. İkisi de arşive gider. Arşiv son durumdur. Kullanılmamış aktif ders, bağlı program, içerik, soru, test, sınıf dersi veya katalog eşlemesi yoksa silinir. Pasif veya arşiv ders silinmez. Bağlı ders silinmez.

Programın yayın durumu `draft`, `published` ve `retired` olarak kalır. Öğrenci erişimi ayrı `student_access` alanıdır. Pasif yapmak yayını, kodu, dersi, sınıf düzeyini, sürümü ve alt kayıtların kendi durumunu değiştirmez. Emekli programın öğrenci erişimi değişmez. Emekliye ayırma geri açılmaz.

Yönetim menüsünde tek giriş Müfredat Yönetimi'dir. `/yonetim/programlar` ve `/yonetim/mufredat` adresleri durur. Aynı ekranda görünmeleri iki modeli veya yayın zincirini birleştirmez.

Kademe ve sınıf düzeyi yeni kayıt değildir. `curriculum_access_gates` tablosunda sabit üç kademe ve 1–12 sınıf düzeyi vardır. Yeni kademe veya sınıf eklenmez, silinmez ve arşivlenmez. Pasif kapı öğrenciyi kapatır. Kapı açılınca alt kayıtların kendi durumu yazılmaz. Operasyonel 5-A bu kapı değildir. `GradeLevel` enum olarak kalır.

Üst düğüm yayında veya aktif değilse alt düğüm öğrenciye kendi durumundan bağımsız kapalıdır. Üst kayıt açılınca alt kaydın kendi durumu yazılmaz. Bağımlı kayıt kalıcı silinmez. Pasif veya arşiv kayıt da silinmez. Silinen taslağın kodu yeniden kullanılınca yeni kimlik alır. Yayımlı programın kodu, dersi, sınıf düzeyi ve sürümü yerinde değişmez.

## Açık kalan karar

- Kullanıcının 1–12 İngilizce entegre ağaç metni `docs/curriculum/sources/user-provided-grade-1-12-tree.md` dosyasında aynen durur. Okunur özet `docs/curriculum/grade-1-12-tree-outline.md` yalnız 11 ve 12. sınıf girintisini düzeltir. Bu metin kullanıcı taslağıdır. Resmî MEB müfredatı olarak doğrulanmamıştır.
- Resmî program yılı, sürüm ve kazanım kodu doğrulanmadı. Import ve yayın yok. Resmî kazanım kodu üretilmedi.
- Production görsel kabulü yok. CI görüntüsü canlı kabul değildir.
- Kademe ve sınıf düzeyi oluşturulmaz veya silinmez. Sabit kapılar yalnız pasif, yeniden aktif ve ad düzenleme taşır. Sınıf düzeyi `GradeLevel` enum olarak kalır.
- Toplu işlem yok.
