# X01 — Ödev ve proje (ürün / mimari karar)

**Durum:** Önerilen mimari; **öğretmen taslak**, **sınıfa atama + alıcı snapshot** ve **öğrenci metin teslimi** dilimleri koda alındı (0.1, 0.1b, 0.1c). Öğretmen değerlendirmesi henüz yok. Bu belge çalışan ürün veya tamamlanmış kabul değildir; canlı öğretmen kabulü açıktır.  
**Feature ID:** `X01-homework` (`partial` — taslak + atama dilimleri kodda; main merge, deploy ve production kabulü ayrı kanıtlardır)  
**Aktif uygulama işi:** `active_feature_ids` = `X01-homework`; `X10-admin-navigation` **partial** olarak korunur (tamamlandı veya kapsam dışı anlamına gelmez).  
**Bağımlılık kaydı:** `M18-local-pdf` roadmap’te `depends_on` olarak kalır. İlk uygulama dilimi metin tabanlıdır; dosya yükleme, proje dosyası ve medya sunumu bu dilime dahil değildir. Dosyalı teslim / rubrik sonraki dilimdir; bağımlılık sessizce silinmez.

Bölüm 0 uygulanan taslak dilimini anlatır. Bölüm 1–10’daki Assignment/Recipient/Submission/Evaluation adları **önerilen** gelecek kavramlardır; henüz Doctrine sınıfı değillerse öyle sunulmazlar.

İlgili mevcut omurga (yeniden kurulmaz): `Institution`, `InstitutionMembership`, `AcademicYear`, `Classroom`, `ClassroomTeacherAssignment`, `ClassroomStudentEnrollment` — bkz. `docs/architecture.md` (2.5–2.6) ve `docs/architecture-auth-membership-onboarding.md`. Sınıf testi ataması `AssessmentDelivery` ayrı kalır (2.10). Assessment / Delivery / Attempt / Scoring yeniden yazılmaz. `AssessmentType::HomeworkBlueprint` tek başına çalışan ödev ürünü değildir.

### Aktif iş geçiş zamanlaması

| An | Kayıt |
|---|---|
| Bu PR (#190, yalnız dokümantasyon) | `X01-homework` **planned** kalır; `active_feature_ids` yalnız `X10-admin-navigation` |
| İlk X01 **uygulama** PR’ı açılırken | Aktif uygulama odağı açıkça `X01-homework` yapılır; `X10-admin-navigation` kaydı **partial** olarak korunur |
| Bu geçişin anlamı | X10’un tamamlandığı veya kapsamdan çıkarıldığı anlamına **gelmez** |
| X01 status ilerlemesi | Yalnız gerçekten teslim edilen dilimin kanıtıyla kaydedilir; tüm ödev ürünü (dosya/rubrik/veli vb.) tamamlandı sayılmaz |

Bu dokümantasyon PR’ı `active_feature_ids` değiştirmez.

---

## 0. Uygulanan taslak dilimi (kodda) ve önerilen gelecek modeli

Bu bölüm koddaki gerçek durumu, aşağıdaki bölümler (1–10) ise **önerilen** nihai modeli anlatır. İkisi karıştırılmaz.

### 0.1 Uygulanan taslak dilimi

| Konu | Kodda olan |
|---|---|
| Tablo | Yalnız `homework_definitions` (`Version20261103120000`, geri alınabilir). Assignment / recipient / submission / evaluation tablosu **yok**. |
| Entity | `App\Entity\HomeworkDefinition`; durum enum’u `HomeworkDefinitionStatus` yalnız `Draft`. Bu dilimde yalnız `draft` yazılır. |
| Alanlar | `institution_id`, `classroom_id`, `academic_year_id`, `created_by_user_id`, `created_by_membership_id` (hepsi RESTRICT FK), `title` (≤150), `description` (MEDIUMTEXT, ≤10.000), `due_at` (UTC), `status`, `revision_number`, `created_at`, `updated_at`. |
| Sınıf anlamı | `classroom_id` yalnız **taslak hazırlığı** içindir. Öğrenci erişimi, alıcı veya atama anlamına gelmez; öğrenci taslağı göremez. |
| Rotalar | `GET /ogretmen/odevler` (`app_teacher_homeworks`), `GET/POST /ogretmen/odevler/yeni` (`app_teacher_homework_new`), `GET /ogretmen/odevler/{id}` (`app_teacher_homework_show`), `GET/POST /ogretmen/odevler/{id}/duzenle` (`app_teacher_homework_edit`). `{id}` UuidV7. GET hiçbir şey yazmaz. |
| Yazma | `HomeworkDefinitionManager::createDraft/updateDraft`: tek transaction, taze aktif+doğrulanmış kullanıcı, **güncel** aktif öğretmen üyeliği + **güncel** aktif `ClassroomTeacherAssignment`; kurum/sınıf/yıl sunucuda yeniden çözülür; `due_at` gelecekte olmalı (`ClockInterface`); form yerel tarih+saat `Europe/Istanbul` (`DateTimeZone`, sabit offset değil) → UTC saklanır; eşzamanlı düzenleme `revision_number` + beklenen sürüm ile reddedilir. Admin/SuperAdmin atlaması **yok**; sahiplik `created_by_user_id`. |
| Okuma | `HomeworkDefinitionQuery`: sahiplik + güncel aktif üyelik + güncel aktif sınıf ataması; biten atama geçmişi de gizler; co-teacher göremez. Başlık araması LIKE kaçışlı; sınıf filtresi; sayfalı; liste sorgu sayısı satır sayısıyla artmaz. |
| Yetki | `HomeworkDefinitionVoter` (`VIEW`, `EDIT`, `CREATE`) fail-closed; global `ROLE_ADMIN` / `ROLE_SUPER_ADMIN` tek başına deny. Yabancı/eksik kimlik için opak 404. |
| Denetim | `homework_definition_draft_created` / `homework_definition_draft_updated`; metadata yalnız kimlik/durum/sürüm (ödev metni **yok**). |
| Metin | `HomeworkText`: UTF-8 kod noktası sayımı (`mb_strlen`), trim, HTML ve kontrol karakteri reddi; tarayıcı sayacı da kod noktası sayar. Satır sonu LF olarak sayılır/saklanır. |
| UI | Öğretmen menüsünde **Ödevlerim**; liste, yeni/düzenle formu (Taslak özeti), detay. Yayımla, ata, teslim, dosya veya değerlendirme düğmesi **yoktur**. |

Bu dilim `X01-homework` ürününü tamamlamaz: yayımlama, atama, alıcı snapshot’ı, öğrenci teslimi, değerlendirme, dosya (`M18-local-pdf` bağımlılığı) ve production kabulü kapsam dışıdır. (Sınıfa atama + alıcı snapshot’ı sonraki alt bölümde ayrı bir dilim olarak anlatılır.)

### 0.1b Uygulanan sınıfa atama + alıcı snapshot dilimi (kodda; branch `feature/x01-homework-assign`)

Bu dilim 0.1’in üstüne biner; taslak dilimindeki kurallar (sahiplik, güncel aktif üyelik + sınıf ataması, Admin/SA atlaması yok) aynen geçerlidir. Main merge, deploy ve **canlı öğretmen kabulü açıktır**; CI veya yerel test başarısı production kabulü değildir.

| Konu | Kodda olan |
|---|---|
| Tablolar | `homework_assignments` (tanım başına tek satır: `UNIQUE(definition_id)`; atama anında dondurulmuş `title`, `description`, `due_at`, `status=assigned`, `recipient_count`, `assigned_at`, atayan kullanıcı + üyelik, kurum/sınıf/yıl) ve `homework_recipients` (`UNIQUE(assignment_id, student_user_id)`; öğrenci kullanıcı, öğrenci üyeliği, kayıt `enrollment_id`). Tüm FK’ler RESTRICT (`Version20261103180000`, geri alınabilir). Teslim / değerlendirme tablosu **yok**. |
| Durum anlamı | `HomeworkDefinition.status` `draft` kalır; liste ve detaydaki **“Atandı”** etiketi `HomeworkAssignment` satırının varlığından gelir. Atama tek başına öğrenci erişimi değildir (öğrenci ödev görme/teslim bu dilimde yok). |
| Servis | `HomeworkAssignmentManager::assignToClassroom(User, Uuid, int $expectedRevision, bool $confirmed)`: tek transaction; onay yoksa `confirmationRequired`; yabancı/eksik/bitmiş atama için opak `notFound`, pasif/doğrulanmamış kullanıcı için `forbidden`; ikinci atama `alreadyAssigned`; eski sürüm `staleRevision`; `due_at <= şimdi` `dueNotFuture`; uygun öğrenci yoksa `noRecipients` (hiçbir satır yazılmaz). Başarıda `homework_assignment_created` denetimi (yalnız kimlik/sayı/sürüm; ödev metni yok). |
| Kilit protokolü | Sıra: kurum WRITE → akademik yıl WRITE → sınıf WRITE → ödev tanımı WRITE → uygun `ClassroomStudentEnrollment` satırları WRITE (`id ASC`) → bu kayıtların öğrenci `InstitutionMembership` satırları WRITE (`id ASC`). `ClassroomStudentEnrollmentManager` enroll/transfer/end aynı kurum→yıl→sınıf hiyerarşisini önce alır; bu yüzden atama ile kayıt bitirme **sınıf satırında** serileşir. `InstitutionMembershipManager` end yalnız kurum→üyelik alır; atama kurum kilidini tuttuğu sürece üyelik sonu bekler, sonra üyelik kilidi altında durum yeniden okunur. Deadlock/lock-wait: `conflict`. |
| Snapshot geçerliliği | Kesin doğrulama noktası: atama transaction’ının **flush/commit** anı — o anda kilitler altında seçilen (aktif enrollment + aktif öğrenci üyeliği) küme `homework_recipients` olarak yazılır. Commit sonrası enrollment/üyelik değişimi bu satırları yeniden yazmaz. Canlı `countEligibleRecipients` yalnız onay ekranıdır. Test: deterministic end→assign / assign→end bariyerleri + eşzamanlı yarışta gerçekleşen sıraya göre kimlik kümesi (`HomeworkAssignmentConcurrencyTest`). |
| Yetki | `HomeworkDefinitionVoter` `ASSIGN` özniteliği; `EDIT`/`ASSIGN` atama satırı varsa reddedilir (`VIEW` açık kalır). `HomeworkDefinitionManager::updateDraft` atama varsa `notEditable`. |
| Rotalar | `GET/POST /ogretmen/odevler/{id}` (`app_teacher_homework_show`): `?ata=1` onay ekranı (GET yazmaz); POST CSRF niyeti `teacher_homework_assign`, alanlar `confirm_assign`, `revision`; başarıda PRG + flash. `GET /ogretmen/odevler?durum=taslak|atandi` durum süzgeci; sayaçlar (`hw-status-count`) süzgeçten bağımsız gerçek taslak/atandı sayısıdır. |
| UI | “Sınıfa ata” → onay ekranı (“Atamayı onayla”); atanmış ödevde düzenle/ata yok, “Atandı” etiketi, alıcı sayısı. Yayımla, teslim, değerlendir düğmesi **yoktur**. |

Test kanıtı (kod kanıtı; CI ve canlı kabul ayrıdır): `tests/Service/HomeworkAssignmentManagerTest.php`, `tests/Service/HomeworkAssignmentConcurrencyTest.php` (iki process worker + dosya bariyeri: end→assign dışlar / assign→end dondurur; sınıf `FOR UPDATE` ile gerçek yarışta kimlik kümesi gözlenen sıraya bağlanır; çift atamada tek assignment + tekil öğrenci kimlik seti + tek başarı audit; recipient kurum/sınıf/yıl/üyelik/enrollment bütünlüğü), `tests/Service/HomeworkDefinitionQueryTest.php`, `tests/Security/HomeworkDefinitionVoterTest.php`, `tests/Controller/TeacherHomeworkHttpTest.php`, `browser/tests/teacher-homework.spec.ts` (uzun başlık 768/390’da eleman `scrollWidth` + içerik bounding rect; + `tests/Browser/PrepareBrowserAcceptanceCommand.php` tohumu).

Kapsam dışı (bu dilimde de yok): öğrenci tarafı alıcı görünümü, teslim, değerlendirme, dosya/PDF (`M18-local-pdf` `depends_on` olarak kalır), atamayı geri alma/iptal.

### 0.1c Uygulanan öğrenci metin teslimi dilimi (kodda; branch 
eature/x01-homework-student-submit)

Bu dilim 0.1b üzerine biner. Öğretmen değerlendirmesi, dosya, bildirim, veli ve yeniden/geç teslim **yoktur**. Main merge, deploy ve **canlı öğrenci/öğretmen kabulü açıktır**; CI production kabulü değildir.

| Konu | Kodda olan |
|---|---|
| Tablo | homework_submissions (UNIQUE(recipient_id), UNIQUE(assignment_id, student_user_id); body MEDIUMTEXT; status draft\|submitted; submitted_at; revision_number; created/updated; tüm FK RESTRICT). Version20261104120000, geri alınabilir. Mevcut alıcılar için backfill **yok**. Evaluation tablosu **yok**. |
| Entity / servis | HomeworkSubmission; HomeworkSubmissionManager::saveDraft/submit; HomeworkStudentQuery (liste/detay DTO); HomeworkStudentScope (güncel enrollment+üyelik; snapshot enrollment_id yetki değildir). |
| Metin | HomeworkText::draftBody (boş OK) / submittedBody (trim sonrası boş red); ≤20.000 Unicode kod noktası; HTML/kontrol red; istemci homework_text.js aynı sayım. |
| Erişim | Açık ödev/taslak: aktif+doğrulanmış öğrenci + kendi recipient + assignment assigned + aktif öğrenci üyeliği + **o sınıfta güncel** aktif enrollment + yazmada aktif kurum/sınıf/yıl. Ayrılma/transfer yazmayı kapatır. Kendi **submitted** kaydı ayrıldıktan sonra salt okunur (yeni yazma yok). Admin/SA/veli/moderatör/öğretmen otomatik erişim yok; öğretmen taslağı görmez. |
| Son tarih | Inclusive: TX içinde ClockInterface; 
ow <= dueAt kabul; 
ow > dueAt ret. Süre dolması GET ile kalıcı status yapılmaz. |
| Kilit | kurum WRITE → yıl WRITE → sınıf WRITE → assignment WRITE → recipient WRITE → (varsa) submission WRITE → güncel enrollment WRITE → membership WRITE. İlk oluşturma recipient kilidi + UNIQUE ile güvenli. |
| Rotalar | GET /ogrenci/odevler, GET /ogrenci/odevler/{id}, POST .../taslak, POST .../gonder. GET yazmaz. CSRF student_homework_draft / student_homework_submit; gönderimde açık onay; 
evision_number. |
| UI | Öğrenci menüsünde **Ödevlerim**; liste (Teslim edilmedi/Taslak/Gönderildi); detayda dondurulmuş istem, taslak kaydet / onaylı gönder, gönderim sonrası salt okunur. |
| Denetim | homework_submission_draft_saved / homework_submission_submitted (kimlik/durum/sürüm; gövde yok). |

Kapsam dışı: değerlendirme, dosya (M18-local-pdf depends_on kalır), bildirim, veli, iptal, AssessmentAttempt.

### 0.2 Önerilen gelecek model (henüz kodda yok veya kısmi)

| Kavram | Durum |
|---|---|
| `HomeworkAssignment` | **Kısmen uygulandı** (0.1b): yalnız `assigned`; dondurulmuş metin/due; 1:1 tanım. `closed`/`cancelled` ve atama taslağı **yok**. |
| `HomeworkRecipient` | **Kısmen uygulandı** (0.1b): atama anı snapshot; öğrenci erişimi / liste **yok**. |
| `HomeworkSubmission` | **Kısmen uygulandı** (0.1c): draft/submitted metin; evaluation yok. |
| `HomeworkEvaluation` | **Önerilen.** Etiket + geri bildirim. |
| `archived` tanım durumu | **Önerilen.** Bu dilimde yazılmaz. |

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
| 5. Atayan öğretmen inceler | **İncelendi** (geri bildirim isteğe bağlı) veya **Yeniden çalışılmalı** (anlamlı geri bildirim zorunlu); §3.6 | Sayısal puan; rubrik; AI; yeniden teslim |
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
| `draft` | Öğrenci kaydetti; öğretmen değerlendirmez; öğretmen taslağı **görmez** (yalnız `submitted`) |
| `submitted` | Gönderildi; içerik kilitli; yeniden teslim yok |

Geç teslim durumu yok.

**Taslak gizliliği:** Öğretmen yalnız `submitted` gövdesini görür; `draft` yalnız öğrenci sahibinindir.

**Taslak boş metin:** Draft kaydı, gönderimden önce isteğe bağlı kayıt davranışıdır. Öğrenci taslağı **boş string** olarak saklanabilir (önceki taslağı temizleme / henüz yazmama). Yalnız boşluklardan oluşan draft metin trim sonrası boş sayılır ve boş draft olarak saklanır; bu “gönderilmiş teslim” değildir. **Submit** (§3.6) boş veya yalnız boşluk metni kabul etmez.

### 3.4 HomeworkEvaluation (öneri)

| Alan | Kural |
|---|---|
| Etiket | Yalnız **`reviewed` (İncelendi)** veya **`needs_rework` (Yeniden çalışılmalı)** |
| Geri bildirim — `needs_rework` | **Anlamlı metin zorunlu** (trim sonrası boş olamaz; §3.6 üst sınır) |
| Geri bildirim — `reviewed` | **İsteğe bağlı**; verilirse §3.6 sınırına uyar; yalnız boşluk → yok sayılır |
| Sayısal puan | **Yok** |
| Durum | `recorded` → düzeltmede önceki `superseded`; yeni satır `recorded` |
| Değerlendirilmemiş | Hiçbir etiket gösterilmez; “İncelendi” / “Yeniden çalışılmalı” gibi sunulmaz |

**`needs_rework` pedagojik geri bildirimdir.** Yeniden teslim izni, ek süre veya due uzatması **açmaz**. UI bunu vaat etmez.

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

### 3.6 Metin sınırları ve karakter sayım sözleşmesi (ürün sözleşmesi)

Bu sınırlar uygulanacak üründür; **bu PR’da validator/form kodu yoktur.**

| Alan | Üst sınır | Boş / yalnız boşluk |
|---|---|---|
| Ödev başlığı | ≤ **150** karakter | Red (trim sonrası boş olamaz) |
| Ödev açıklaması (istem) | ≤ **10.000** karakter | Red (trim sonrası boş olamaz) |
| Öğrenci **gönderilen** teslim metni | ≤ **20.000** karakter | Red (trim sonrası boş olamaz) |
| Öğrenci **taslak** metni | ≤ **20.000** karakter | Boş taslak saklanabilir (§3.3) |
| Öğretmen geri bildirimi | ≤ **5.000** karakter | `needs_rework`: anlamlı zorunlu; `reviewed`: isteğe bağlı |

**Karakter sayımı (sunucu esas):**

- Sınır **UTF-8 bayt sayısı değildir**.
- Sunucu, repodaki diğer metin alanlarıyla uyumlu olarak Unicode karakter sayısını `mb_strlen($value, 'UTF-8')` ile ölçer (UTF-8 kod noktası; Türkçe harfler ve BMP dışı kod noktaları tek karakter sayılır).
- İstemci sayacı **aynı Unicode kod noktası** sözleşmesini kullanır; JS `string.length` (UTF-16 birimi) astral karakterlerde sapma yaratır — sayaç kod noktası saymalı.
- **Sunucu doğrulaması esas güvenlik kontrolüdür**; istemci sınırı tek başına yetmez.
- Gösterim: Twig/autoescape ile kaçışlı düz metin. Bu sınırlar ham HTML veya zengin editör desteği **vermez**.

**Sonraki form kabul maddeleri:** karakter sayacı; erişilebilir Türkçe alan hatası; hatalı gönderimde güvenli girdinin korunması (PRG/re-render).

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
- Metin: Twig autoescape; HTML/zengin editör yok; uzunluk ve boşluk kuralları §3.6 (`mb_strlen` UTF-8); sunucu validator esas.
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
9. Evaluation: yalnız iki etiket; `needs_rework` anlamlı geri bildirim zorunlu; `reviewed` isteğe bağlı; append-only; `needs_rework` resubmit/ek süre açmaz.
10. §3.6: başlık/açıklama/submit boşluk-only red; taslak boş saklanabilir; karakter sayacı = sunucu `mb_strlen` UTF-8; istemci sapması sunucuda yakalanır; hatalı gönderimde girdi korunur; erişilebilir Türkçe hata.
11. Öğretmen erişimi kesilince değerlendirilemeyen submitted kalabilir; bypass yok.
12. Mobil/tablet + klavye odak kabulü ayrı işaretlenir.
13. Kod merge ≠ deploy ≠ production kullanıcı kabulü; karar belgesi kabul sayılmaz.

---

## 9. Sonraki görseller (sıra; bu turda UI yok)

1. Öğretmen — ödev listesi  
2. Öğretmen — oluşturma + sınıf/due + yayın  
3. Öğretmen — teslim listesi + etiket/geri bildirim  
4. Öğrenci — ödev listesi + teslim detayı  

`/yonetim/bolum/odevler` yer tutucusu ürün sayılmaz.

---

## 10. Yol haritası notları (bu PR değiştirmez)

| Kayıt | Bu PR (#190) |
|---|---|
| `X01-homework` status | `planned` kalır |
| `active_feature_ids` | Yalnız `X10-admin-navigation` |
| `depends_on` | `M18-local-pdf` korunur (metin dilimi dosya kullanmaz) |
| İlk X01 uygulama PR’ı | Aktif odak açıkça `X01-homework`; X10 kaydı **partial** kalır; X10 tamamlandı/retired değildir |
| X01 ilerleme kanıtı | Yalnız teslim edilen dilim; tüm ödev ürünü tamamlandı sayılmaz |
| Evidence | Karar belgesi; çalışan kod/kabul iddiası yok |

---

## G. Kalan gerçek belirsizlikler

Çözülen: teslim gizliliği; etiket + geri bildirim zorunluluğu (`needs_rework` zorunlu / `reviewed` isteğe bağlı); karakter üst sınırları ve `mb_strlen` UTF-8 sayımı; taslak boş saklama; aktif iş geçiş zamanlaması; sahiplik kesilmesi; snapshot; due inclusive; yeniden/geç teslim yok.

Ürün kapsamı üreten açık karar kalmadı. Uygulama diliminde yalnızca kod/CI/ekran kanıtı üretilir; yeni kapsam bu belgeden türetilmez.

---

## Referanslar

- `docs/architecture.md` — kurum, sınıf, assessment delivery, scoring sınırları
- `docs/architecture-auth-membership-onboarding.md` — üyelik
- `docs/product-roadmap.md` — ödev/proje hedef satırı
- `docs/roadmap/features.json` — `X01-homework`
- Canlı yer tutucu (ürün değil): `/yonetim/bolum/odevler`
