# Yönetici özeti

Tarih: 2026-11-06 12:41 Europe/Istanbul. Bu belge salt okunur denetimdir. Kod, PR, CI, merge, deploy ve production verisi değiştirilmedi.

## Doğrulanan uçlar

- GitHub `main`: `b09f51bbf9e75ae67e65d9632d1ac1e914d573c5` (2026-11-05, PR #195 merge). Yerel `origin/main` aynı SHA. Bu eşitlik bu okuma anına aittir.
- Son başarılı Deploy VDS: run [42028237826](https://github.com/testligtr-collab/testlig-net/actions/runs/42028237826), head `b09f51bb…`, 2026-11-05T12:34:07Z. Önceki deploy `cc208c3c…` (PR #194) run [41888866082](https://github.com/testligtr-collab/testlig-net/actions/runs/41888866082) **failure**. Nedeni bu turda yeniden okunmadı.
- Aktif feature kaydı (main `features.json`): yalnız `X01-homework`. Durum **partial**. `X10-admin-navigation` partial ve active listesinde değil.
- Açık PR’lar (7): #101, #149, #151, #157, #168, #196, #197.

## Ne yapılmış sayılır

- Platform çekirdeği 2026-09-07 `73c9c86` ile başlamış. Kimlik, kurum üyeliği, sınıf/yıl, katalog, soru, test, sonuç ve admin kabuğunun büyük kısmı main’de.
- Son 7 günde main’e giren ürün dilimleri: admin test detay/önizleme/sonuç (#186–#189), UTC/DST düzeltmesi (#187), ödev mimarisi (#190), taslak (#191), atama (#192), öğrenci teslim (#193), değerlendirme kararı (#194), öğretmen değerlendirme (#195).
- #195 sonrası otomatik deploy başarılı. Bu, canlı öğretmen→öğrenci pilotu değildir.

## Ne yapılmamış

- `X01-homework` evidence metni main’de hâlâ “PR #195 incelemede; main merge/deploy yok” diyor. Merge olmuş. Bunu düzeltecek #196 açık ve merge edilmedi.
- Browser HTTP 500’ün PHP exception’ı yok. #197 tanı PR’ında Browser **yeşil** (run [42052914409](https://github.com/testligtr-collab/testlig-net/actions/runs/42052914409), 2026-11-05). Failure-only `browser-server-log` artifact **oluşmadı** çünkü job fail olmadı. Kök neden çözüldü denmez.
- Canlı ödev pilotu yok. Öğretmen oturumu ve alıcı sayısı doğrulanmadı.
- #149 ClamAV, #157 lsphp, #168 OLS, #151 haber açık.

## Tek ilk öneri

#196’yı (kanıt metni) ve #197’yi (log artifact) ayrı tut. İlki main kaydını merge gerçeğiyle hizalar. İkincisi ancak bir sonraki kırmızı Browser’da exception üretir. Bu raporda ikisi de merge edilmedi.

Ayrıntı: `01-tam-denetim.md`. Bulgular: `05-eksikler-celiskiler-ve-devam-plani.md`.
