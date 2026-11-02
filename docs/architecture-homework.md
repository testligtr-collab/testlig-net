# X01 — Ödev ve proje (ürün / mimari karar)

**Durum:** Önerilen mimari; uygulama henüz yok. Bu belge çalışan ürün veya tamamlanmış kabul değildir.  
**Feature ID:** `X01-homework` (`planned`)  
**Aktif uygulama işi (bu PR):** değişmez — `active_feature_ids` yalnız `X10-admin-navigation`.  
**Bağımlılık kaydı:** `M18-local-pdf` roadmap’te `depends_on` olarak kalır. İlk uygulama dilimi metin tabanlıdır; dosya yükleme, proje dosyası ve medya sunumu bu dilime dahil değildir. Dosyalı teslim / rubrik sonraki dilimdir; bağımlılık sessizce silinmez.

Bu belge kod, entity, migration, route, voter veya servis oluşturmaz. Aşağıdaki adlar **önerilen kavram adlarıdır**; mevcut Doctrine sınıfları gibi sunulmaz.

İlgili mevcut omurga (yeniden kurulmaz): `Institution`, `InstitutionMembership`, `AcademicYear`, `Classroom`, `ClassroomTeacherAssignment`, `ClassroomStudentEnrollment` — bkz. `docs/architecture.md` (2.5–2.6) ve `docs/architecture-auth-membership-onboarding.md`. Sınıf testi ataması `AssessmentDelivery` ayrı kalır (2.10). Assessment / Delivery / Attempt / Scoring yeniden yazılmaz. `AssessmentType::HomeworkBlueprint` tek başına çalışan ödev ürünü değildir.

### X01 uygulamaya geçerken (ayrı açık karar; bu PR yapmaz)

X01 kod dilimi başlatılacağı zaman: `X10-admin-navigation` **partial** olarak korunur; `active_feature_ids` odağı **ayrı, açık kullanıcı kararıyla** `X01-homework` yapılır. Bu dokümantasyon PR’ı aktif işi değiştirmez.

---

## 1. Kavramlar ve ilişkiler

### 1.1 Ayrı kavramlar

| Kavram (öneri adı) | Anlamı | İlk dilimde |
|---|---|---|
| **HomeworkDefinition** | Öğretmenin kurum bağlamında oluşturduğu ödev tanımı (başlık, metin istemi, taslak/yayın öncesi düzenleme). | Evet |
| **HomeworkAssignment** | Tanımın belirli bir **Classroom** + **AcademicYear** için yayımlanmış/atanmış kaydı; son tarih; atama anında dondurulmuş istem metni. | Evet |
| **HomeworkRecipient** | Atama anında sabitlenen öğrenci alıcı satırı (üyelik + kullanıcı kimliği snapshot). Geçmiş kayıttır; tek başına güncel erişim yetkisi değildir. | Evet |
| **HomeworkSubmission** | Alıcının metin teslimi (taslak → gönderilmiş). | Evet |
| **HomeworkEvaluation** | Öğretmenin teslime verdiği etiket + metin geri bildirim; düzeltmeler append-only. Sayısal puan yoktur. | Evet |

Tanım ≠ atama ≠ teslim ≠ değerlendirme. Tek satırda birleştirilmez.

### 1.2 Kurum, sınıf, akademik yıl

- Homework yalnız **kurum sınıfları** içindir. Bağımsız öğretmen sınıfı ilk dilimde yok.
- Assignment, aktif kurumun aktif akademik yılındaki aktif `Classroom` kimliğine bağlanır.
- Tanım ve atama `institution_id` taşır; sunucu her yazmada kurum/sınıf/yıl ilişkisini yeniden çözümler.
- Global `ROLE_TEACHER` (veya başka global rol) tek başına sınıf veya ödev erişimi vermez. Öğretmen yazması/okuması için birlikte gerekir: aktif+doğrulanmış hesap + uygun aktif kurum üyeliği + **geçerli aktif `ClassroomTeacherAssignment`** + ödevi **oluşturup atayan** öğretmen olmak (§3, §5).
- Genel admin rol hiyerarşisi (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`) bu nesne kuralını **aşmaz**; otomatik teslim gövdesi veya değerlendirme yetkisi vermez (§5).
- Ayrı şube yetkisi veya paralel üyelik modeli kurulmaz.

### 1.3 Atanan öğrenci kapsamı

- İlk dilim audience: **tek sınıf**. Kurum geneli veya serbest öğrenci listesi yok.
- Alıcılar atama/yayın anında `HomeworkRecipient` olarak **sabitlenir** (§4).
- Teslim gövdesi görünürlüğü §5: yalnız sahibi öğrenci ve atayan, güncel yetkisi süren öğretmen.

### 1.4 Mevcut test sistemiyle bağ

- `AssessmentType::HomeworkBlueprint` **ödev ürünü değildir**.
- Metin ödevi için `AssessmentAttempt` / `AssessmentScoringRun` kullanılmaz; ikinci scoring sistemi kurulmaz; sayısal puan yok.
- Mevcut sınıf testi `AssessmentDelivery` akışı aynen sürer.
- **İlk dilimde test bağlama yok.**
- Adlandırma: menüde **Ödev**; test ekranlarında **Test / Sınav / Deneme**.

### 1.5 Depolama

- İlk dilim: düz metin. `StoredMediaAsset` / PDF / görsel / ClamAV yolu yok.
- `M18-local-pdf` dosyalı sonraki dilim için kayıtlı kalır.

---

## 2. En küçük uygulama dilimi

Tek küçük teslim sırası (hepsi metin; dosya yok):

| Adım | Dahil | Hariç |
|---|---|---|
| 1. Atayan öğretmen taslak tanım oluşturur | Başlık + istem metni | Dosya, rubrik, AI, çoklu sınıf, owner/manager oluşturma |
| 2. Kendi sınıfı + son tarih seçer | Aktif atandığı sınıf; `dueAt` UTC | Geç teslim, ek süre |
| 3. Açık işlemle yayımlar/atar | POST + CSRF; recipient materialize; `assigned` | Sessiz yayın; kurum geneli; co-teacher |
| 4. Uygun öğrenci görür ve metin teslim eder | Liste + detay; taslak; tek gönderim | Dosya; veli; bildirim; yeniden teslim; geç teslim |
| 5. Atayan öğretmen inceler | Etiket: **İncelendi** veya **Yeniden çalışılmalı** + metin geri bildirim | Sayısal puan; rubrik; AI; otomatik puan |
| 6. Öğrenci güncel değerlendirmeyi görür | Aktif etiket + geri bildirim | Eski superseded UI’si zorunlu değil; sınıf sıralaması yok |

**Kapsam dışı (ilk dilim; tamamlandı sayılmaz):** dosya/görsel yükleme, veli, bildirim, sayısal puan, rubrik, AI, test bağlama, co-teacher, sahiplik devri, yeniden teslim, geç teslim, yönetim/acil destek bypass, owner/manager/Admin/SA teslim gövdesi.

---

## 3. Yaşam döngüsü

### 3.1 HomeworkDefinition (öneri)

| Durum | Anlam |
|---|---|
| `draft` | Düzenlenebilir; öğrenci görmez |
| `archived` | Yeni atama yok; geçmiş assignment’lar kalır |

İlk dilimde tek sınıf ataması; tanımın çoklu yeniden kullanımı sonraki dilim.

### 3.2 HomeworkAssignment (öneri)

| Durum | Anlam |
|---|---|
| `draft` | Recipient yok; öğrenci görmez |
| `assigned` | Yayımlanmış; recipient sabit; uygun öğrenci görür |
| `closed` | Yeni teslim yok (`dueAt` geçti veya öğretmen kapattı) |
| `cancelled` | İptal; yeni teslim/değerlendirme yazılmaz; satırlar silinmez |

Geçiş: `draft` → `assigned` → `closed` \| `cancelled`; `assigned` → `cancelled`. Geri açma yok.

**İptal / arşiv — yazma vs okuma:**

| Durum | Yeni yazma | Geçmiş okuma |
|---|---|---|
| Assignment `cancelled` / `closed` | Submit ve evaluate yok | Kayıtlar silinmez. Öğrenci: kendi submitted + güncel evaluation (§4). Öğretmen: yalnız §5 güncel yetki sürüyorsa |
| Definition `archived` | Yeni assignment yok | Eski assignment’lar yukarıdaki kuralla yaşar |
| Kurum/sınıf/yıl pasif | Publish/submit/evaluate deny | Satırlar silinmez |

### 3.3 HomeworkSubmission (öneri)

| Durum | Anlam |
|---|---|
| `draft` | Öğrenci kaydetti; öğretmen değerlendirmez; gövde yalnız sahibi (+ yetkili atayan öğretmen, taslak politikası: öğretmen taslağı **görmez** — yalnız `submitted`) |
| `submitted` | Gönderildi; içerik kilitli; yeniden teslim yok |

Geç teslim durumu yok.

**Taslak gizliliği (net):** Öğretmen yalnız `submitted` gövdesini görür; öğrenci `draft` yalnız kendisine aittir.

### 3.4 HomeworkEvaluation (öneri)

| Alan | Kural |
|---|---|
| Etiket | Yalnız **`reviewed` (İncelendi)** veya **`needs_rework` (Yeniden çalışılmalı)** |
| Geri bildirim | Metin zorunlu veya isteğe bağlı — uygulama diliminde alan zorunluluğu netleştirilir; içerik metindir |
| Sayısal puan | **Yok** |
| Durum | `recorded` → düzeltmede önceki `superseded`; yeni satır `recorded` |
| Değerlendirilmemiş | Hiçbir etiket gösterilmez; “İncelendi” / “Yeniden çalışılmalı” gibi sunulmaz |

**`needs_rework` pedagojik geri bildirimdir.** İlk dilimde yeniden teslim izni, ek süre veya due uzatması oluşturmaz. UI bunu vaat etmez.

Öğrenci yalnız güncel (`recorded`) etiket + geri bildirimi görür. Eski satırlar silinmez; ilk dilim UI’sinde superseded listesi zorunlu değildir.

### 3.5 Dondurulmuş kararlar

| Konu | Karar |
|---|---|
| Yayımlanmış istem metni | Assignment üzerinde yayın anında **dondurulur**; tanım sonra değişse bile mevcut assignment metni değişmez |
| Teslim öncesi taslak | Evet (`draft` submission); GET yazmaz |
| Gönderim sonrası düzenleme / yeniden teslim | **Yok** |
| Son tarih | Inclusive: sunucunun kabul anı `now <= dueAt` ise kabul; `now > dueAt` ise red. Saklama UTC; `ClockInterface`; kontrol **transaction içinde**, submit kilidi altında |
| Geç teslim / ek süre | **Yok** |
| Değerlendirme düzeltmesi | Append-only; sessiz overwrite yok |

---

## 4. Atama ve üyelik

**Seçim (kesin): yayın/atama anında recipient snapshot.**

- `HomeworkRecipient` geçmiş kayıttır; **tek başına** güncel erişim yetkisi değildir.
- Sonradan sınıfa katılan otomatik alıcı olmaz.
- Transfer / ayrılma: eski teslim sahibi ve içeriği değişmez; yeni sınıfa taşınmaz.

### 4.1 Öğrenci — güncel koşullar

| İşlem | Gerekli koşullar (birlikte) |
|---|---|
| Açık ödevi listede görmek / istemi okumak (henüz submit yok veya draft) | Aktif+doğrulanmış kullanıcı; ilgili `HomeworkRecipient` satırı; assignment `assigned`; aktif student `InstitutionMembership`; o sınıfta aktif `ClassroomStudentEnrollment`; kurum/sınıf/yıl yazmaya uygun |
| Draft kaydet / submit | Yukarıdakiler + assignment henüz `closed`/`cancelled` değil + `now <= dueAt` (TX içinde) + mükerrer `submitted` yok |
| Kendi `submitted` gövdesini ve güncel evaluation’ı okumak | Aktif+doğrulanmış kullanıcı + submission `student_user_id` sahipliği. Enrollment bitmiş olsa bile **kendi geçmiş submitted/evaluation** okunabilir; yeni draft/submit yok. Recipient snapshot tek başına yetmez; sahiplik + actor kontrolü gerekir |
| Başkasının teslimi | Opaque deny (404 tercih) |

### 4.2 Öğretmen sahipliği (kesin)

- İlk dilimde **yalnız ödevi oluşturan ve atayan öğretmen** düzenler, atar ve değerlendirir.
- Aynı sınıf başka öğretmen / co-teacher otomatik erişim kazanmaz.
- Sahiplik devri ve ortak değerlendirme **yok**.
- Gerekli sınıf öğretmen ataması veya kurum üyeliği biterse: **geçmiş dahil** tüm ödev/teslim/evaluation erişimi kapanır.
- Kayıtlar DB’de korunur; otomatik başka öğretmene devredilmez.
- **Operasyonel sınır:** Bu durumda değerlendirilemeyen `submitted` teslimler kalabilir. Gizli admin/owner/SA bypass **eklenmez**. İleride yönetim erişimi ayrı açık karar + izin + denetim ister.

### 4.3 Kurum / yıl / sınıf pasifliği

Yeni publish/submit/evaluate deny; satırlar silinmez. Erişim §4.1–4.2 güncel yetkiye bağlıdır.

---

## 5. Yetki matrisi (ilk dilim — kesin)

Fail-closed. Global rol hiyerarşisi nesne kuralını aşmaz. Teslim gövdesi: yalnız sahibi öğrenci + atayan ve **güncel yetkisi devam eden** öğretmen.

| Aktör | Ödev listesi / meta | Teslim gövdesi | Oluştur / düzenle / ata | Submit | Değerlendir | İptal/arşiv |
|---|---|---|---|---|---|---|
| SuperAdmin | Otomatik yok | **Hayır** | Hayır | Hayır | Hayır | Hayır |
| Admin (global) | Otomatik yok | **Hayır** | Hayır | Hayır | Hayır | Hayır |
| Kurum owner/manager | İlk dilimde ödev ürün yönetimi **yok** | **Hayır** | Hayır | Hayır | Hayır | Hayır |
| Atayan öğretmen (güncel atama+üyelik) | Kendi ödevleri | Evet (`submitted`) | Evet | Hayır | Evet | Evet (kendi) |
| Atayan öğretmen (atama/üyelik bitmiş) | **Hayır** (geçmiş dahil kapalı) | **Hayır** | Hayır | Hayır | Hayır | Hayır |
| Aynı sınıf başka öğretmen | Hayır | Hayır | Hayır | Hayır | Hayır | Hayır |
| Öğrenci | Kendi recipient ödevleri (§4.1) | Yalnız kendi | Hayır | Evet (kendi) | Hayır | Hayır |
| Veli | Yok | Yok | — | — | — | — |
| Moderatör | Hayır — içerik/test izninden **türetme** | Hayır | Hayır | Hayır | Hayır | Hayır |

**Yönetim / acil destek erişimi:** ilk dilimde yoktur. İleride ayrı açık karar, yeni izin ve audit tasarımı gerekir; bu PR izin uygulamaz.

**Yeni izin adları (öneri; uygulanmamış):** örn. `HOMEWORK_ASSIGNING_TEACHER_MANAGE`, `HOMEWORK_STUDENT_SUBMIT`. `ADMIN_SECTION_ENTRY` / `/yonetim/bolum/odevler` ürün yetkisi değildir.

---

## 6. Güvenlik ve veri bütünlüğü

- Her yazmada institution, classroom, academic year, teacher assignment / student enrollment, recipient ve submission ilişkileri sunucuda yeniden çözülür. İstemci UUID yetki değildir.
- Actor: active + verified; stale identity map yok.
- **GET yazmaz.**
- Yazmalar: POST + CSRF + PRG; domain TX; assignment → recipient/submission kilit sırası; due kontrolü aynı TX içinde `ClockInterface` UTC anı ile.
- Mükerrer: `(assignment_id, student_user_id)` en fazla bir `submitted` (+ en fazla bir aktif draft).
- Metin: Twig autoescape; HTML yok; uzunluk üst sınırları uygulama diliminde sabitlenir (belge önerisi: başlık ≤200, istem ≤10k, teslim ≤20k, feedback ≤5k).
- Audit: `homework_*`; metadata id/status/reason_code; **teslim gövdesi ve geri bildirim audit’e kopyalanmaz**.
- Liste minimizasyonu; hard delete yok.
- Bildirim yok (ilk dilim).

---

## 7. Mevcut test sistemiyle sınır

- `HomeworkBlueprint` ≠ X01 ürünü.
- Attempt / ScoringRun / DecimalScoreCalculator / result release ödeve bağlanmaz.
- Değerlendirme yalnız iki etiket + metin geri bildirim.
- Sınıf testi delivery yolları değişmez.
- UI: Ödev vs Test ayrık.

---

## 8. Test ve kabul planı (uygulama öncesi)

Bu belge kabulü tamamlamaz. Uygulama diliminde ayrı kanıtlanır.

1. Kurum izolasyonu: A öğretmeni B kurumuna yazamaz / göremez.
2. Ataması olmayan veya ataması bitmiş öğretmen: publish/evaluate/gövde okuma deny (geçmiş dahil).
3. Owner/manager/Admin/SA teslim gövdesi okuyamaz; moderatör türetmez; rol hiyerarşisi aşamaz.
4. Başka öğrencinin teslimi opaque deny.
5. `dueAt` inclusive; `now > dueAt` red; UTC + TX içi clock.
6. Çift submit / concurrent conflict; GET side-effect yok.
7. Transfer: eski teslim sahibi/içerik aynı; yeni sınıf eski ödevi açmaz; sonradan katılan alıcı olmaz.
8. Publish sonrası istem metni değişmez.
9. Evaluation: yalnız iki etiket; değerlendirilmemiş boş; append-only supersede; `needs_rework` resubmit açmaz.
10. Öğretmen erişimi kesilince değerlendirilemeyen submitted kalabilir; bypass yok.
11. Mobil/tablet + klavye odak kabulü ayrı işaretlenir.
12. Kod merge ≠ deploy ≠ production kullanıcı kabulü.

---

## 9. Sonraki görseller (sıra; bu turda UI yok)

1. Öğretmen — ödev listesi  
2. Öğretmen — oluşturma + sınıf/due + yayın  
3. Öğretmen — teslim listesi + etiket/geri bildirim  
4. Öğrenci — ödev listesi + teslim detayı  

`/yonetim/bolum/odevler` yer tutucusu ürün sayılmaz.

---

## 10. Yol haritası notları (bu PR değiştirmez)

| Kayıt | Bu PR |
|---|---|
| `X01-homework` status | `planned` kalır |
| `active_feature_ids` | Yalnız `X10-admin-navigation` |
| `depends_on` | `M18-local-pdf` korunur (metin dilimi dosya kullanmaz) |
| X01 uygulama başlangıcı | Ayrı karar: X10 partial kalır; aktif odak X01’e alınır |
| Evidence | Karar belgesi hazırlığı; çalışan kod/kabul iddiası yok |

---

## G. Kalan gerçek belirsizlikler

Çözülen konular (teslim gizliliği, etiketli değerlendirme, öğretmen sahipliği kesilmesi, snapshot, due inclusive, yeniden teslim yok) yukarıda kesinleşti; “karar bekliyor” olarak tekrar edilmez.

Hâlâ uygulama diliminde netleştirilecek / ayrı karar:

1. Geri bildirim metninin zorunlu olup olmadığı (etiket her zaman zorunlu).
2. Metin alanları için kesin karakter üst sınırları.
3. X01 koduna geçişte `active_feature_ids` değişiminin zamanlaması (süreç kararı; bu PR’da yapılmaz).

Yeni ürün kapsamı bu listeden üretilmez.

---

## Referanslar

- `docs/architecture.md` — kurum, sınıf, assessment delivery, scoring sınırları
- `docs/architecture-auth-membership-onboarding.md` — üyelik
- `docs/product-roadmap.md` — ödev/proje hedef satırı
- `docs/roadmap/features.json` — `X01-homework`
- Canlı yer tutucu (ürün değil): `/yonetim/bolum/odevler`
