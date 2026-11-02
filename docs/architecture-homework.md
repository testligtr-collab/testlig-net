# X01 — Ödev ve proje (ürün / mimari karar)

**Durum:** Önerilen mimari; uygulama henüz yok.  
**Feature ID:** `X01-homework` (`planned`)  
**Bağımlılık kaydı:** `M18-local-pdf` roadmap’te `depends_on` olarak kalır. İlk uygulama dilimi metin tabanlıdır; dosya yükleme, proje dosyası ve medya sunumu bu dilime dahil değildir. Dosyalı teslim / rubrik sonraki dilimdir; bağımlılık sessizce silinmez.

Bu belge kod, entity, migration, route, voter veya servis oluşturmaz. Aşağıdaki adlar **önerilen kavram adlarıdır**; mevcut Doctrine sınıfları gibi sunulmaz.

İlgili mevcut omurga (yeniden kurulmaz): `Institution`, `InstitutionMembership`, `AcademicYear`, `Classroom`, `ClassroomTeacherAssignment`, `ClassroomStudentEnrollment` — bkz. `docs/architecture.md` (2.5–2.6) ve `docs/architecture-auth-membership-onboarding.md`. Sınıf testi ataması `AssessmentDelivery` ayrı kalır (2.10).

---

## 1. Kavramlar ve ilişkiler

### 1.1 Ayrı kavramlar

| Kavram (öneri adı) | Anlamı | İlk dilimde |
|---|---|---|
| **HomeworkDefinition** | Öğretmenin kurum bağlamında oluşturduğu ödev tanımı (başlık, metin istemi, taslak/yayın öncesi düzenleme). | Evet |
| **HomeworkAssignment** | Tanımın belirli bir **Classroom** + **AcademicYear** için yayımlanmış/atanmış kaydı; son tarih; atama anında dondurulmuş istem metni. | Evet |
| **HomeworkRecipient** | Atama anında sabitlenen öğrenci alıcı satırı (üyelik + kullanıcı kimliği snapshot). | Evet |
| **HomeworkSubmission** | Alıcının metin teslimi (taslak → gönderilmiş). | Evet |
| **HomeworkEvaluation** | Öğretmenin teslime verdiği değerlendirme + geri bildirim; düzeltmeler append-only. | Evet |

Tanım ≠ atama ≠ teslim ≠ değerlendirme. Tek satırda birleştirilmez.

### 1.2 Kurum, sınıf, akademik yıl

- Homework yalnız **kurum sınıfları** içindir. Bağımsız öğretmen sınıfı ilk dilimde yok (ayrı ürün kararı / sonraki scope).
- Assignment, aktif kurumun aktif akademik yılındaki aktif `Classroom` kimliğine bağlanır.
- Tanım ve atama `institution_id` taşır; sunucu her yazmada kurum/sınıf/yıl ilişkisini yeniden çözümler.
- Global `ROLE_TEACHER` tek başına sınıf veya ödev erişimi vermez. Yazma için: aktif+doğrulanmış kullanıcı + aktif kurum üyeliği (teacher/owner/manager kurallarına göre) + **aktif geçerli `ClassroomTeacherAssignment`** (öğretmen yazması) veya owner/manager sınıf yönetimi politikası (aşağıdaki yetki matrisi).
- Ayrı şube yetkisi veya paralel üyelik modeli kurulmaz. Şube/kampüs X01’e eklenmez.

### 1.3 Atanan öğrenci kapsamı

- İlk dilim audience: **tek sınıf** (classroom). Kurum geneli veya serbest öğrenci listesi yok.
- Alıcılar atama/yayın anında `HomeworkRecipient` olarak **sabitlenir** (§4).
- Öğrenci görünürlüğü: kendi recipient satırı + uygun aktif öğrenci üyeliği; başka öğrencinin teslimi opaque deny.

### 1.4 Mevcut test sistemiyle bağ

- `AssessmentType::HomeworkBlueprint` **ödev ürünü değildir**; assessment enum etiketidir.
- Metin ödevi için `AssessmentAttempt` / `AssessmentScoringRun` yeniden kullanılmaz; ikinci attempt/scoring sistemi kurulmaz.
- Mevcut sınıf testi `AssessmentDelivery` akışı aynen sürer.
- **İlk uygulama dilimine test bağlantısı önerilmez** (çakışmayı önlemek için). İsteğe bağlı “ilgili test” referansı sonraki dilimde, yalnız salt okunur bağ + ayrı adlandırma ile düşünülebilir.
- Kullanıcıya karışmayan adlandırma önerisi: yönetim/öğrenci menüsünde **Ödev**; test ekranlarında **Test / Sınav / Deneme**. “Ödev planı” assessment tipi ürün ödevi sanılmamalı.

### 1.5 Depolama

- İlk dilim: düz metin alanları (uzunluk üst sınırı sunucuda). `StoredMediaAsset` / PDF / ClamAV yolu yok.
- `M18-local-pdf` dosyalı teslim diliminde kullanılmak üzere kayıtlı kalır.

---

## 2. En küçük uygulama dilimi

Tek küçük teslim sırası (hepsi metin; dosya yok):

| Adım | Dahil | Hariç |
|---|---|---|
| 1. Yetkili öğretmen taslak tanım oluşturur | Başlık + istem metni; kurum/sınıf bağlamı seçimine hazır taslak | Dosya, rubrik, AI, çoklu sınıf şablonu |
| 2. Sınıf + son tarih seçer | Aktif atandığı sınıf; `dueAt` UTC | Geçmişe dönük toplu tarih, saat dilimi UI karmaşası |
| 3. Açık işlemle yayımlar/atar | POST + CSRF; recipient materialize; durum `assigned` | Sessiz otomatik yayın; kurum geneli audience |
| 4. Uygun öğrenci görür ve metin teslim eder | Liste + detay; taslak kaydet; gönder | Dosya; veli; bildirim; geç teslim |
| 5. Öğretmen inceler, değerlendirir, geri bildirim yazar | Not/skor (basit metin veya sayısal etiket — ürün kararı §G) + feedback metni | Rubrik motoru; otomatik puan; AI |
| 6. Öğrenci kendi değerlendirmesini görür | Yalnız kendi graded sonucu | Sınıf sıralaması; anonim akran |

**Sonraki iş (tamamlandı sayılmaz):** dosya/proje teslimi, geç teslim, toplu aktarım, bildirim, veli görünümü, rubrik, otomatik puanlama, AI, çoklu kurum paylaşımı, gelişmiş proje yönetimi, bağımsız öğretmen sınıfı, test–ödev bağlama, Admin/SA öğrenci teslim okuma ürünü.

---

## 3. Yaşam döngüsü

### 3.1 HomeworkDefinition

| Durum | Anlam |
|---|---|
| `draft` | Düzenlenebilir; öğrenci görmez |
| `archived` | Yeni atama yok; geçmiş assignment’lar kalır |

Geçiş: `draft` → (atama yayınından sonra tanım “kaynak” olarak kalabilir veya `archived`). İlk dilimde tanımın birden fazla sınıfa yeniden atanması **hariç** (tek assignment = tek sınıf); yeniden kullanım sonraki dilim.

### 3.2 HomeworkAssignment

| Durum | Anlam |
|---|---|
| `draft` | Sınıf/due seçilmiş olabilir; recipient yok; öğrenci görmez |
| `assigned` | Yayımlanmış; recipient sabit; öğrenci görür |
| `closed` | Yeni teslim yok (due geçti veya öğretmen kapattı) |
| `cancelled` | İptal; yeni teslim/değerlendirme yazılmaz; geçmiş satırlar silinmez |

Geçiş (öneri): `draft` → `assigned` → `closed` \| `cancelled`; `assigned` → `cancelled`. `cancelled` / `closed` geri açma ilk dilimde yok.

### 3.3 HomeworkSubmission

| Durum | Anlam |
|---|---|
| `draft` | Öğrenci kaydetti; öğretmen değerlendirmez |
| `submitted` | Gönderildi; içerik kilitli (ilk dilim) |
| *(yok: late)* | Geç teslim ilk dilimde kabul edilmez |

### 3.4 HomeworkEvaluation

| Durum | Anlam |
|---|---|
| `recorded` | İlk değerlendirme kaydı |
| `superseded` | Yeni kayıt gelince önceki `superseded` (satır silinmez) |

Aktif görünüm: en son `recorded` evaluation; geçmiş satırlar audit/okuma için kalır.

### 3.5 Karar noktaları (öneri + gerekçe)

| Soru | Öneri | Gerekçe |
|---|---|---|
| Yayımlanmış ödev metni değiştirilebilir mi? | **Assignment üzerinde istem metni yayın anında dondurulur.** Tanım taslağı sonra değişse bile mevcut assignment metni değişmez. Yeni metin = yeni assignment (sonraki dilim veya iptal+yeni). | Teslim/değerlendirme tutarlılığı; genel revision motoru gerekmez |
| Öğrenci teslim öncesi taslak? | **Evet** (`draft` submission) | Küçük; GET yazmaz; ayrı POST kaydet |
| Teslim sonrası düzenleme / yeniden teslim? | **İlk dilimde hayır** | Mükerrer ve yarışmayı sade tutar; resubmit sonraki dilim |
| Son tarih dahil/hariç? | **`dueAt` inclusive üst sınır:** `submittedAt <= dueAt` (sunucu `ClockInterface`, saklama **UTC**). UI kurum/yerel gösterim ayrı; karşılaştırma UTC. | Repo UTC/clock kalıbı |
| Geç teslim? | **İlk dilimde reddedilir** (`due_passed`) | Kapsam dar |
| İptal/arşiv? | Assignment `cancelled` / tanım `archived`; submission ve evaluation **fiziksel silinmez** | Geçmiş ve mahremiyet denetimi |
| Değerlendirme düzeltmesi? | **Append-only yeni evaluation**; eskisi `superseded` | Assessment manual grade kalıbına benzer; sessiz overwrite yok |

Gereksiz genel content-revision motoru tasarlanmaz. Dondurulmuş assignment metni + append-only evaluation yeterlidir.

---

## 4. Atama ve üyelik

### 4.1 Sabit alıcı vs dinamik üyelik

| Seçenek | Artı | Eksi |
|---|---|---|
| A — Yayın anında recipient snapshot | Sahiplik net; transfer/IDOR daha az sürpriz; `AssessmentDeliveryRecipient` ile uyumlu | Sonradan katılan otomatik görmez |
| B — Her istekte canlı enrollment | Yeni öğrenci hemen görür | Transfer/ayrılma belirsiz; geçmiş teslim kime ait karmaşası |

**Seçim: A — atama anında sabitleme.**

- Sonradan sınıfa katılan: bu assignment’ı **otomatik almaz** (yeni assignment veya sonraki “alıcı güncelle” dilimi gerekir).
- Ayrılan / enrollment `ended`: recipient satırı kalır; **yeni teslim yazma** için aktif enrollment + aktif student membership yeniden doğrulanır → fail-closed deny. Geçmiş `submitted` / evaluation okuma: kendi user_id sahipliği ile (enrollment bitmiş olsa bile kendi geçmişi — ürün kararı: **evet, kendi submitted/graded geçmişini görür**; yeni draft/submit yok).
- Öğretmen assignment’ı biterse: **yazma** (yeni ödev, yayın, değerlendirme) deny. **Geçmiş görüntüleme:** kendi oluşturduğu assignment’ların salt okunur listesi ilk dilimde **önerilir** (sınıf coverage kaybı yazmayı keser, geçmişi silmez). Başka öğretmenin ödevini yönetme: **hayır** (owner/manager ayrı §5).
- Kurum `archived` / sınıf arşiv / akademik yıl `closed`: yeni publish/submit/evaluate deny; mevcut satırlar silinmez.
- Öğrenci transferi: eski sınıftaki recipient/teslim **otomatik taşınmaz**; yeni sınıf eski ödeve erişim açmaz.
- Oluşturan öğretmen = assignment `createdBy` (membership/user). Başka öğretmen aynı sınıfta olsa bile ilk dilimde **yönetmez / değerlendirmez** (co-teacher paylaşımı sonraki dilim). Owner/Manager: kurum politikası §5.

---

## 5. Yetki matrisi (öneri)

Mevcut altyapı esas: active+verified actor, `InstitutionMembership`, `ClassroomTeacherAssignment` / enrollment, fail-closed voter + DBAL snapshot kalıbı. Global rol ≠ tenant erişimi.

| Aktör | Liste/görüntüle | Oluştur/düzenle (taslak) | Ata/yayın | Teslim | Değerlendir | İptal/arşiv |
|---|---|---|---|---|---|---|
| SuperAdmin | *Ürün kararı* — otomatik tüm öğrenci teslimi **önerilmez** | Hayır (bypass yazarlık yok) | Hayır | Hayır | Hayır | *Ayrı ops kararı* |
| Admin (global) | Otomatik kurum/öğrenci teslimi **yok** | Hayır | Hayır | Hayır | Hayır | Hayır |
| Kurum owner/manager | Kurum içi ödev özeti (metin minimizasyonu); teslim gövdesi varsayılan **kapalı** | Evet (politika: evet, sınıf seçerek) | Evet | Hayır | *Ayrı karar* — ilk dilimde **hayır** (yalnız atayan öğretmen) | Evet |
| Sınıfa atanmış öğretmen (oluşturan) | Kendi sınıf ödevleri + teslimler | Evet | Evet (atanmış sınıf) | Hayır | Evet | Evet (kendi) |
| Aynı sınıf başka öğretmen | Hayır | Hayır | Hayır | Hayır | Hayır | Hayır |
| Başka kurum/sınıf öğretmeni | Hayır | Hayır | Hayır | Hayır | Hayır | Hayır |
| Öğrenci | Yalnız kendi recipient ödevleri | Hayır | Hayır | Evet (kendi) | Hayır | Hayır |
| Veli | İlk dilimde **yok** | — | — | — | — | — |
| Moderatör | Hayır — içerik/test izninden **türetme** | Hayır | Hayır | Hayır | Hayır | Hayır |

**Yeni izin önerisi (uygulama bu PR’da yok):** örn. `HOMEWORK_TEACHER_MANAGE`, `HOMEWORK_STUDENT_SUBMIT`, `HOMEWORK_OWNER_VIEW_SUMMARY`. Mevcut `ADMIN_SECTION_ENTRY` yer tutucu sayfası ürün yetkisi değildir.

**Ayrı ürün kararı (kullanıcı onayı gerekir):** SuperAdmin/Admin veya owner’ın öğrenci teslim gövdesini okuması. Mahremiyet ve “öğretmen değerlendirmesi” modeli bozulmasın diye ilk dilimde **kapalı** önerilir; açılırsa ayrı audit + gerekçe kodu.

---

## 6. Güvenlik ve veri bütünlüğü

- Her yazmada institution, classroom, academic year, teacher assignment / student enrollment, recipient ve submission ilişkileri **sunucuda yeniden çözülür**. İstemci UUID’si yetki kanıtı değildir.
- Actor: active + e-posta doğrulanmış; stale identity map’e güvenilmez (fresh load / snapshot).
- **GET kayıt veya durum yazmaz** (liste, detay, değerlendirme görünümü).
- Yazmalar: POST + CSRF + PRG; domain TX; gerektiğinde pessimistic lock (assignment → recipient/submission sırası); concurrent submit → unique + conflict.
- Mükerrer teslim: `(assignment_id, student_user_id)` için en fazla bir `submitted` (ve en fazla bir aktif draft) — DB unique / guard.
- Metin: Twig autoescape; max uzunluk (öneri: başlık ≤200, istem ≤10k, teslim ≤20k, feedback ≤5k karakter — uygulama diliminde netleştirilir); HTML yok.
- Audit: `homework_*` action allowlist; metadata’da id’ler / status / reason_code; **teslim gövdesi ve geri bildirim metni audit’e kopyalanmaz**.
- Liste okumalarında minimizasyon: öğretmen sınıf listesinde gövde yok; öğrenci başkasını görmez.
- Hard delete yok; `cancelled` / `archived` / `superseded`.
- Bildirim gönderimi ilk dilimde yok.

---

## 7. Mevcut test sistemiyle sınır

- `HomeworkBlueprint` enum ≠ X01 ürünü.
- Attempt/scoring/result release **metin ödevi için kullanılmaz**; yeni DecimalScoreCalculator veya paralel puan motoru **yok**.
- İlk dilim değerlendirmesi: öğretmenin kaydettiği basit sonuç (metin notu ve/veya sınırlı sayısal puan alanı — §G) + feedback; assessment `percentage` formülü bağlanmaz.
- Sınıf testi delivery/attempt yolları değişmez.
- UI copy: Ödev vs Test ayrık tutulsun.

---

## 8. Test ve kabul planı (uygulama öncesi)

Uygulama başlayınca ayrı kanıtlanacak; bu belge kabul tamamlamaz.

1. Kurum A öğretmeni kurum B sınıfına atayamaz / göremez.
2. Aktif `ClassroomTeacherAssignment` olmayan öğretmen publish/evaluate alamaz.
3. Öğrenci başka öğrencinin submission’ına 403/404 (oracle yok tercihi: opaque 404).
4. `dueAt` sonrası submit reddi; saklanan anlar UTC; clock test edilebilir.
5. Çift submit / eşzamanlı draft→submit conflict; GET side-effect yok.
6. Transfer sonrası eski teslim sahipliği korunur; yeni sınıf eski assignment’ı açmaz.
7. Pasif kurum/sınıf/yıl ve bitmiş öğretmen atamasında yazma deny; geçmiş satır silinmez.
8. Assignment metni publish sonrası tanım düzenlemesinden etkilenmez; eski teslim aynı metne bağlı kalır.
9. Evaluation sonrası öğrenci yalnız kendi aktif evaluation’ını görür; superseded gizlenir veya “geçmiş” ayrı (ilk dilimde yalnız aktif).
10. Mobil/tablet yerleşim + klavye odak halkası kabul listesinde ayrı işaretlenir.
11. Kod merge ≠ deploy ≠ production kullanıcı kabulü; CI yeşili production kabulü değildir.

---

## 9. Sonraki görseller (yalnız sıra; bu turda UI yok)

Rolüne göre ilk dilimde gereken gerçek ekranlar (HTML/Twig/mock üretilmez; `/yonetim/bolum/odevler` yer tutucusu ürün sayılmaz):

1. **Öğretmen** — ödev listesi (kendi sınıf atamaları).
2. **Öğretmen** — oluşturma + sınıf/due + yayın.
3. **Öğretmen** — teslim listesi + değerlendirme/geri bildirim.
4. **Öğrenci** — ödev listesi + teslim detayı (taslak/gönder + kendi notu).

Admin shell kartı X10 yer tutucusu olarak kalır; X01 uygulama dilimi öğretmen/öğrenci yüzeyi veya açık kararlı kurum workspace ile gelir.

---

## 10. Önerilen bağımlılıklar (grafik değiştirilmez)

Roadmap `depends_on` / `active_feature_ids` bu belgeyle değiştirilmez. Uygulama öncesi **öneri**:

| Öneri | Not |
|---|---|
| Mevcut: `M18-local-pdf` | Dosyalı dilimde; metin diliminde kullanılmaz, kayıt silinmez |
| Örtük omurga | M06 sınıf/yıl, üyelik 2.5–2.6 — zaten present; yeni ID icat edilmez |
| Bildirim | Ayrı feature; X01 ilk dilim bağımlılığı yapılmaz |
| X10-admin-navigation | Aktif kabuk işi; X01’e taşınmaz; ödev yer tutucusu X10 kapsamında kalabilir |

---

## G. Kullanıcı kararı gerektiren kalan noktalar

1. Owner/Manager’ın teslim gövdesi okuma ve değerlendirme yetkisi (öneri: ilk dilimde kapalı).
2. SuperAdmin/Admin operasyon erişimi (öneri: yok; ayrı mahremiyet kararı).
3. Değerlendirme skoru: yalnız metin mi, yoksa sınırlı sayısal puan mı (otomatik calculator değil).
4. Öğretmen ataması bittikten sonra salt okunur geçmiş kapsamının genişliği.
5. Co-teacher’ın aynı ödevi değerlendirmesi (öneri: sonraki dilim).
6. Test–ödev salt okunur bağın sonraki dilime alınması (öneri: ilk dilim dışı — onaylandı sayılır bu belgede).
7. X01 uygulama dilimine geçilmeden `active_feature_ids` değişikliği (ayrı açık kullanıcı kararı; X10 sessiz iptal edilmez).

---

## Referanslar

- `docs/architecture.md` — kurum, sınıf, assessment delivery, scoring sınırları
- `docs/architecture-auth-membership-onboarding.md` — üyelik
- `docs/product-roadmap.md` — ödev/proje hedef satırı
- `docs/roadmap/features.json` — `X01-homework`
- Canlı yer tutucu (ürün değil): `/yonetim/bolum/odevler`
