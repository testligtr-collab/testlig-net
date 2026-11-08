# Testlig devam — 2 Kasım 2026 (X01 ödev karar belgesi)

Yarın kaldığı yerden devam için el sıkışma. Bu turda uygulama/ekran yok; yalnız mimari karar + PR #190.

Aynı içerik kişisel agent store’da da var: `testlig-devam-2026-11-02-x01-homework.md`

## Şu an durulan nokta

- **Sıradaki ürün adımı (henüz başlanmadı):** öğretmen ödev listesi + oluşturma ekranlarının **görsel hazırlığı** (sonra uygulama). Bu turda ekran üretilmedi.
- **Açık dokümantasyon PR:** [#190](https://github.com/testligtr-collab/testlig-net/pull/190) — incelemede; merge/deploy yok.
- **PR HEAD:** `223f7a365edfb743141ddd9ebe1497c6e998cb76`
- **Dal:** `docs/x01-homework-architecture`
- **Worktree:** `C:\xampp\htdocs\testlig-net-x01-homework-architecture`
- **Base main (doğrulanan):** `13ae4b670548386a54f775a131dee342c4c89c79` (PR #189 sonuç ekranları merge + Deploy VDS run `41483975063`)

### CI @ HEAD `223f7a3` (kayıt anı)

| Kontrol | Durum |
|---|---|
| Roadmap coverage | **pass** (run `41500304208`) |
| Browser acceptance | **pass** |
| Quality & tests | **pending / in_progress** — geçti sayılmadı; yarın yeniden bak |

## Yol haritası (değiştirilmeden bırakılanlar)

- `active_feature_ids`: yalnız **`X10-admin-navigation`** (partial)
- **`X01-homework`**: `planned` — çalışan ürün iddiası yok
- `depends_on`: **`M18-local-pdf`** korunur (ilk dilim metin; dosya sonraki)
- M12 present, M18-cloud-storage partial, PR #149/#157’ye dokunulmadı
- Roadmap-IDs PR gövdesinde: **X01-homework**

### X01 uygulamaya geçiş zamanlaması (belgede yazılı; #190 yapmaz)

1. #190 docs PR: X01 planned, aktif iş X10
2. İlk X01 **uygulama** PR’ı açılırken: aktif odak açıkça X01; X10 kaydı **partial** kalır (tamamlandı/retired değil)
3. X01 status ilerlemesi yalnız teslim edilen dilim kanıtıyla; tüm ödev ürünü bitmiş sayılmaz

## Ana belge

`docs/architecture-homework.md` — **önerilen mimari; uygulama yok.**

### Netleşmiş ilk dilim

- Kurum sınıfı; bağımsız öğretmen sınıfı yok
- Yalnız oluşturan/atayan öğretmen; co-teacher / sahiplik devri yok
- Atama/üyelik bitince erişim **geçmiş dahil** kapanır; kayıtlar silinmez; admin bypass yok
- Teslim gövdesi: yalnız sahibi öğrenci + güncel yetkili atayan öğretmen
- Admin/SA/owner/manager/moderatör otomatik gövde okumaz; rol hiyerarşisi aşmaz
- Recipient **snapshot** (geçmiş kayıt; tek başına yetki değil)
- Yayımlanan istem dondurulur
- Taslak var; gönderim sonrası düzenleme/yeniden/geç teslim yok
- `dueAt` inclusive, UTC, `ClockInterface`, TX içi
- Değerlendirme: **İncelendi** (geri bildirim isteğe bağlı) | **Yeniden çalışılmalı** (anlamlı geri bildirim zorunlu); sayısal puan yok; append-only
- `needs_rework` yeniden teslim/ek süre açmaz
- Dosya, bildirim, veli, test bağlama, rubrik, AI yok
- Assessment/Attempt/Scoring yeniden kullanılmaz; `HomeworkBlueprint` ürün değil
- Yer tutucu: `/yonetim/bolum/odevler` (ürün sayılmaz)

### Karakter sözleşmesi (§3.6)

| Alan | Limit | Boşluk-only |
|---|---|---|
| Başlık | ≤150 | Red |
| Açıklama | ≤10.000 | Red |
| Gönderilen teslim | ≤20.000 | Red |
| Taslak | ≤20.000 | Boş saklanabilir (trim→boş) |
| Geri bildirim | ≤5.000 | needs_rework zorunlu; reviewed isteğe bağlı |

Sayım: sunucu `mb_strlen($v, 'UTF-8')` (kod noktası, bayt değil); istemci aynı; sunucu esas; kaçışlı düz metin; HTML/zengin editör yok.

## Bu oturumda tamamlanan önceki dilimler (bağlam)

1. **PR #189** — admin sonuç listesi/detay open shell; main’e merge; deploy `13ae4b67…`
2. Canlı mobil kontrol 768×1024 / 390×844 — somut UI kusuru yok; formül/görsel/tamamlanmamış deneme CI’da kaldı; klavye odak halkası canlıda kesin doğrulanamadı
3. Menü sırası sonrası **Ödev ve projeler** salt okunur inceleme → yer tutucu → X01 karar belgesi kararı
4. PR #190 açıldı ve üç turda kararlar kilitlendi (gizlilik, etiket, sahiplik, metin sınırları)

## Yerel worktree’ler

| Konum | Dal / HEAD | Not |
|---|---|---|
| `...\testlig-net-x01-homework-architecture` | `docs/x01-homework-architecture` @ `223f7a3` | #190 worktree; temiz, origin ile senkron |
| `...\testlig-net-question-editor-layout` | detached `fceeefd` | untracked `docs/reports/` — **koru**, stash/reset/clean yok |
| `...\testlig-net-admin-test-results` | `feature/m12-admin-test-results` @ `b112a70` | #189 merge öncesi tip; eski |

## Yarın için önerilen sıra

1. PR #190 Quality & tests sonucunu kontrol et; yeşilse merge kararı kullanıcıda (otomatik merge etme)
2. Merge sonrası: öğretmen ödev **listesi + oluşturma** için görsel hazırlık promptu (uygulama değil)
3. Uygulama PR’ı açılacaksa: önce açık `active_feature_ids` geçişi (X01 aktif, X10 partial)
4. Dokunma: #149, #157, ops, Docker/XAMPP istemeden, SSH, production yazma

## Değişen dosyalar (#190)

- `docs/architecture-homework.md` (yeni + güncellemeler)
- `docs/architecture.md` (kısa X01 bağlantısı)
- `docs/product-roadmap.md` (satır + referans)
- `docs/roadmap/features.json` (X01 evidence/acceptance/pr_links)

## Repo

`testligtr-collab/testlig-net`

Kayıt tarihi: 2026-11-02 (Europe/Istanbul akşam).
