# MAT.1.3.2 inceleme listesi

Onaylanmadan içe aktarma, yayın, commit veya production işlemi yapılmaz.

## Bloklar

1. Başlık: Eş Nesneleri Tanıyalım
2. Paragraf: Eş nesnelerin renk, biçim ve büyüklük gibi görsel özellikleri aynıdır. Nesneleri karşılaştırırken bu özelliklere birlikte bakarız.
3. Bilgi kutusu: Yalnız rengin aynı olması yeterli değildir. Renkle birlikte biçim ve büyüklüğü de karşılaştırırız.
4. Başlık: Nasıl Karşılaştırırız?
5. Liste: Renklerine bak. Biçimlerine bak. Büyüklüklerine bak. Yönü değişmiş olsa da bu özellikler aynıysa nesneleri eş olarak değerlendir.
6. Başlık: Birlikte Düşünelim
7. Paragraf: Bir nesne yana çevrilse de rengi, biçimi ve büyüklüğü aynı kaldıysa eşi olabilir. Büyük bir top ile küçük bir topun rengi ve biçimi aynı olsa bile büyüklükleri farklıysa eş değildir. Benzer görünmek, eş olmak demek değildir.
8. Başlık: Hatırlayalım
9. İpucu kutusu: Eş nesnelerin renk, biçim ve büyüklük gibi görsel özellikleri aynıdır. Nesnenin yalnızca yönü değişirse eşlik bozulmaz.

## Soru kontrolü

| Kod | Alt beceri | Doğru | Yanlış seçenekler | Okuma | Belirsizlik | Çıktı |
| --- | --- | --- | --- | --- | --- | --- |
| `3a492d0c79f5484e8f8ad6ac9bf0f990` | Günlük nesnede eş çifti seçme | B | A büyüklük farkı. C ve D ayrı biçim. | Kısa kök. B üç ölçütü birlikte söyler. | Yalnız B renk, biçim ve büyüklüğü aynı der. | Eş çift kararı |
| `dad9fe2ff11e40e4905a924bcaf6e254` | Aynı renk ve biçim, farklı büyüklük | D | A renk yetmez. B yön yetmez. C biçim tek başına yetmez. | Kök renk, biçim ve büyüklüğü ayırır. | Büyüklük farkı söylendiği için yalnız D doğrudur. | Üç ölçüt birlikte |
| `d71b3d2377fd4aa190c24d009fb60453` | Yön değişince eşlik | A | B yön farkını bozulma sanır. C büyüklük farkı varmış gibi söyler. D yönün rengi değiştirdiğini söyler. | Kök üç özelliğin aynı, yönün farklı olduğunu söyler. | B, C ve D kökteki bilgiye veya ölçüte aykırıdır. Yalnız A doğrudur. | Yön tek başına bozmaz |
| `94ea025e9ddb4eb3be5778a3646686e0` | Aynı renk, farklı biçim | C | A ve D rengi yeter sayar. B farklı biçimleri eş sayar. | Renk ve iki nesne adı. | Biçimler cümlede ayrılmış. Yalnız C doğru. | Renk önemli ama yetmez |
| `7c1a193662264bb594de96c67e179679` | Ölçütten çıkarım | B | A yalnız renk. C yalnız malzeme. D yalnız yer. | Dört kısa özellik. | Yeterli bilgi yalnız B: renk, biçim ve büyüklük birlikte. | Üç ölçüt yargısı |

Doğru sıra: B, D, A, C, B. Ardışık aynı harf yok.

- Eş nesnelerde renk, biçim ve büyüklük birlikte kontrol edilir.
- Yalnız aynı renk yeterli değildir. Renk de yok sayılmaz.
- Yön değişince, bu üç özellik aynı kaldıysa eşlik bozulmaz.
- Büyüklük farklıysa eş değillerdir.
- Sorular “yukarıdaki resim” demez.
- Her soruda tek doğru bırakıldı.

Resmî tema renk, boy ve şekli birlikte ölçü olarak anar. Bu paket de aynı üç özelliği birlikte ister. Renk tek başına eşlik sayılmaz.

## Yerel doğrulama

- CSV: 5 satır, 11 kolon, BOM, virgül, CRLF, `QuestionCsvParser` kabul etti.
- Kodlar benzersiz ve geçerli UUID.
- Ders `matematik`, çıktı `mat_1_3_2`, sınıf `1`.
- Dört seçenek dolu, tekrar yok, açıklama dolu.
- Formül karakteriyle başlayan hücre yok.
- Konu anlatımı şema 1, dokuz blok, `LearningContentDocumentValidator` kabul etti.
- Veritabanı, production ve Docker kullanılmadı. Kazanımın canlı kayıtta bulunduğu bu turda yeniden sorgulanmadı.
