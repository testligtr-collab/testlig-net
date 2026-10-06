# Testlig ürün envanteri

Tarih: 2026-10-06. Kaynak: `origin/main` SHA `b059f28cc11f5dd4561e2d2d14830eed6dc8ccae` (release `20261005T152913Z-b059f28cc11f`) + ChatGPT SuperAdmin/öğrenci oturumu (Cursor ortamı production’a bağlanmadı).

Bu belge mimariyi yeniden tasarlamaz. Domain entity bulunması çalışan UI demek değildir. Preview (`/onizleme/*`, yalnız `dev`/`test`) gerçek ürün değildir. Test dosyası adı, bu oturumda test çalıştırıldığı anlamına gelmez. Canlı sütunu yalnız tarihli dış gözlem veya “canlı doğrulanmadı”dır.

Durumlar: **Çalışıyor** somut akış+kanıt; **Kısmi** altyapı veya akışın bir parçası; **Planlandı** mimaride var, uygulama kanıtı yok; **Engelli** somut bağımlılık.

## MAT.1.3.3 yayın kontrol noktası (dış, 2026-10-06)

Kaynak: ChatGPT tarayıcısında SuperAdmin + öğrenci oturumu. Yerel kodla yeniden doğrulanmış sayılmaz. Import/apply tekrarlanmaz. Beş sorunun bankada yayımlanması öğrenci testi oluşturmaz.

| Nesne | Kimlik |
| --- | --- |
| Content | `01a10bdc-1b0b-7ddb-872f-d9ae7cf56917` |
| Revision | `01a10bdc-1b0f-737e-afcb-15de2cbadd44` |
| Publication | `01a110a4-7375-7c58-8352-a042e2585fb4` |
| Placement | `01a110a5-d675-71cb-96f4-f821dbe531fd` |
| Catalog topic | `01a0cebb-8c06-7d2a-b9ab-bb2373242175` |
| Sorular | `c4268552-8712-410e-898e-1c5b1bfca821`, `b07a55ad-45c9-411a-b836-4d8e1b0dca54`, `21ea85aa-e2a9-4af9-9079-a5fbe24ed443`, `67a48d57-5fe5-4d60-9fc6-260e5de778c7`, `c648ed30-7468-4de9-8757-6a75554bb1a6` |

Gözlem: içerik ücretsiz yayımlandı; beş soru yayımlandı; yerleşim oluşturulup yayımlandı; ilgili audit olayları görüldü; öğrenci hesabında doğru konu altında gövde açıldı; masaüstünde yatay taşma yok; temel HTML’de cevap anahtarı / `storageKey` / revision kimliği göstergesi yok. Mobil/tablet doğrulanmadı.

Paket: `tymm-2026/grade-1/matematik/mat-1-3-3`. Öğrenci yolu (kod): `/ogrenci/dersler/matematik/nesnelerin-geometrisi-2/nesnelerin-bicimsel-ozellikleri`.

## Öğrenci akışı (sınıf → ders → konu → içerik → test → sonuç → devam)

| Bağlantı | Route / kod | Durum | Eksik |
| --- | --- | --- | --- |
| Sınıf | `StudentOnboardingController` `/ogrenci/kurulum` → `StudentProfile.gradeLevel`. Katalog sınıf seçici yok; öğrenci başka grade slug’ı opaque 404 | Kısmi | Public `/dersler/{grade}` ayrı ziyaretçi yüzeyi |
| Ders | `StudentCourseCatalogController::index/subject` `/ogrenci/dersler`, `/{subjectSlug}`; `StudentCatalogQuery` | Çalışıyor (kod+test); MAT canlı: konu gövdesi açıldı | — |
| Ünite | `::unit` `/{subjectSlug}/{unitSlug}` | Çalışıyor (kod+test) | — |
| Konu | `::topic` `/{subjectSlug}/{unitSlug}/{topicSlug}`; `StudentTopicContentQuery` | Çalışıyor (kod+test); MAT canlı masaüstü | Mobil/tablet yok |
| İçerik | Aynı konu sayfası; `StudentContentBlockNormalizer` + `student/courses/topic.html.twig` | Çalışıyor (MAT gövde) | Dashboard “devam et” bu yerleşime bağlı değil |
| Konu → test | Konu şablonunda `app_student_tests` yok | **Kopuk** | Soru bankası yayını assessment oluşturmaz |
| Test listesi | `StudentAssessmentController` `/ogrenci/testler`; `StudentAssignedTestCatalog` | Çalışıyor (kod+`StudentAssessmentPracticeTest`) | Canlı MAT testi yok |
| Çöz / devam (attempt) | `POST .../baslat`, `GET .../coz`, `POST .../cevap`, `POST .../bitir`; state `resume` | Çalışıyor (kod+test) | Dashboard “Öğrenmeye devam et” her zaman boş empty-state |
| Sonuç | `GET .../sonuc`; geçmiş `/ogrenci/testler/gecmisim` | Çalışıyor (kod+test) | Canlı doğrulanmadı |
| Öğrenmeye devam et | `templates/student/dashboard.html.twig` sabit empty-state | **Kopuk** | In-progress attempt veya son ders sorgusu yok |

## Modül envanteri

| Modül/özellik | Domain durumu | Gerçek UI | API | Test kanıtı | Canlı kanıt | Eksik | Bağımlılık |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Kayıt (öğrenci self-serve) | `RegistrationService` | `/kayit` | Yok | `RegistrationControllerTest` (dosya var; bu oturumda çalıştırılmadı) | Canlı doğrulanmadı | Öğretmen/kurum self-serve kayıt yok (başvuru ayrı) | SMTP |
| E-posta doğrulama | VerifyEmailBundle + `UserAccountLifecycle` | `/dogrula/eposta`, yeniden gönder | Yok | Mevcut kayıt/doğrulama testleri | Canlı doğrulanmadı | — | SMTP |
| Giriş / çıkış | `LoginFormAuthenticator`, `POST /cikis` CSRF | `/giris` | Yok | AccessControl / login testleri | ChatGPT SA+öğrenci 2026-10-06 (Cursor’da yok) | Beni hatırla, MFA, OAuth yok | — |
| Parola sıfırla/değiştir | ResetPasswordBundle, `PasswordManager` | `/sifremi-unuttum`, `/sifre-yenile`, hesap | Yok | Mevcut reset testleri | Canlı doğrulanmadı | — | SMTP |
| Telefon / OTP | `PhoneVerificationClaimManager`, User phone bind | Controller yok | Yok | `PhoneVerificationClaimManagerTest` vb. | Canlı doğrulanmadı | SMS sağlayıcı, form, login-by-phone | 2.22 SMS sözleşmesi |
| Bağlam seçimi | Kurum session UUID | `/kurum/baglam` POST + `/kurum` seçim | Yok | `InstitutionWorkspaceControllerTest` | Canlı doğrulanmadı | Öğrenci/öğretmen çok-bağlam UI sınırlı | Aktif üyelik |
| Global roller | `UserGlobalRoleManager` | Admin kullanıcı detay (2.21) | Yok | Privileged user / identity matrix testleri | Canlı doğrulanmadı (MAT’ta SA kullanıldı) | Rol UI tam ürün paneli değil | SA/Admin |
| Kurum üyelik | `Institution` + `InstitutionMembership` | `/kurum`, davet kabul | Yok | Institution voter/workspace testleri | Canlı doğrulanmadı | Global rol üyelik vermez | SA kurum oluşturma |
| Yetkilendirme (voter) | Snapshot + fresh actor | Panellerde | Yok | Voter kernel testleri | SoD MAT yayında kullanıldı (dış) | — | Active+verified |
| Öğrenci paneli | `StudentProfile` | `/ogrenci`, `/profil`, `/kurulum` | Yok | `StudentOnboardingFlowTest` | 2026-10-06 konu gövdesi | “Devam et” sahte empty; öğrenme araçları Yakında | Profil sınıfı |
| Veli paneli | `ParentStudentLink` | `/veli`, `/veli/baglan` | Yok | `ParentPanelControllerTest` | Canlı doğrulanmadı | Cevap/çözüm yok (bilinçli) | Öğrenci kodu |
| Öğretmen sınıf | Assignment + delivery | `/ogretmen/siniflarim`, atama/sonuç | Yok | Institution/teacher assignment testleri | Canlı doğrulanmadı | Platform deneme raporu öğretmen 403 | Kurum üyeliği + atama |
| Uzman / baş öğretmen | LC/soru/test SoD (HEAD/EXPERT) | `/calisma-alani`, `/yonetim/icerikler|sorular|testler` (yetkiye göre) | Yok | Manager SoD testleri | Canlı doğrulanmadı | Ayrı “uzman portal” yok | Active+verified |
| Moderasyon | Return-to-draft, publish yok | İçerik/soru detay | Yok | Voter | Canlı doğrulanmadı | — | — |
| Admin kabuğu | `AdminAuthorization` | `/yonetim` | Yok | `AdminSecurityMatrixTest` | 2026-10-06 MAT yayın UI | — | Active+verified Admin/SA |
| SuperAdmin ops | Payment/audit/webhook | `/yonetim/odemeler`, `/webhook`, `/uzlastirma`, `/denetim` | Yok | Admin payment/audit controller testleri | MAT audit 2026-10-06 | Checkout değil | SA |
| Kurum çalışma alanı | ClassroomManager + davet | `/kurum/*`, `/davet/ogretmen`, `/davet/ogrenci` | Yok | Invite/classroom write testleri | Canlı doğrulanmadı | Yoklama yok | Owner/Manager |
| Akademik yıl | Domain 2.6 | Kurum onboarding/sınıf yazımı dolaylı | Yok | Academic classroom domain testleri | Canlı doğrulanmadı | Yıl UI zayıf | Institution |
| Sınıf / kayıt / öğretmen atama | Classroom*Manager | `/kurum` sınıf form; öğretmen atama | Yok | `InstitutionClassroomWriteTest` | Canlı doğrulanmadı | — | Active year |
| Müfredat program | `CurriculumProgram` + reconcile CLI | Admin katalog eşleme; LC alignment | Yok | Curriculum domain + ops workflow testleri | `mat_grade1_tymm` production’da (önceki apply) | Genel müfredat UI yok | Checksum’lı fixture |
| Öğrenci kataloğu | CatalogSubject/Unit/Topic + placement | `/ogrenci/dersler/**` | Yok | `StudentCourseCatalogControllerTest`, `StudentTopicPageControllerTest` | MAT konu 2026-10-06 | — | Published tree + placement + LC + gate |
| Public katalog | Aynı catalog published | `/dersler`, `/dersler/{grade}/…` (ünite; konu public route yok) | Yok | Public catalog testleri | Önceki anonim unit 200 (2026-10-05 Cursor HTTP; bu görevde yok) | Gövde public değil (bilinçli) | Publish-tree |
| Öğrenme içeriği | LC + revision + publication | `/yonetim/icerikler*` | Yok | Lifecycle / student topic testleri | MAT free publish 2026-10-06 | — | SoD, access policy |
| Video/PDF | VideoUrlParser; `LearningDocumentAsset` | Admin blok form; öğrenci stream | Yok | Content/document testleri | MAT paketinde medya yok | Antivirus yok; Teacher self-ready yok | Admin ready |
| Soru bankası | Question + revision + answer key | `/yonetim/sorular*`, CSV import, ops package import | Yok | Question package + manager testleri | 5 soru yayın 2026-10-06 | Öğrenci listesi yok | SoD, alignment |
| Test editör | `Assessment` 2.9 | `/yonetim/testler*` | Yok | Assessment domain + `AdminTest` testleri | Canlı doğrulanmadı | MAT soruları bağlı değil | Yayımlı soru |
| Teslimat | `AssessmentDelivery` 2.10 | `/kurum` test atama; `/ogretmen` atama | Yok | Delivery testleri | Canlı doğrulanmadı | Platform pratik ayrı teknik kurum | Published assessment |
| Attempt / cevap | Encrypted answers 2.11 | `/ogrenci/testler/**` | Yok | `StudentAssessmentPracticeTest` | Canlı doğrulanmadı | Kurum teslimatı bu editörden çözülmez | Delivery+recipient |
| Scoring / sonuç | 2.12 calculator + release | Öğrenci sonuç; `/yonetim/testler/{id}/sonuclar` SA/Admin | Yok | Scoring + practice testleri | Canlı doğrulanmadı | Öğretmen platform raporu 403 | submitted/expired |
| Sonuç inceleme politikası | 2.13 domain | Öğrenci sonuçta policy’ye bağlı alanlar | Yok | Review policy testleri | Canlı doğrulanmadı | Ayrı policy UI yok | Delivery |
| Analytics 2.14 | DTO/query, tablo yok | HTTP yok | Yok | Analytics query testleri | Yok | Panel/PDF yok | Released results, n≥5 |
| Öğrenci ilerlemesi / çalışma planı | Yok | Dashboard empty-state kopyası | Yok | Kanıt bulunamadı | Yok | Gerçek progress entity yok | — |
| Ödev | `AssessmentType::HomeworkBlueprint` etiket | Preview kartları | Yok | Kanıt bulunamadı (ürün ödevi) | Yok | Ayrı ödev modülü yok | Assessment kararı |
| Bildirim | Yok | Preview “Demo bildirim”; topbar demo | Yok | `UiPreviewControllerTest` (preview) | Yok | Kuyruk/ürün bildirimi yok | — |
| Destek / iletişim | Yok | Public footer linkleri | Yok | Kanıt bulunamadı | Yok | Ticket yok | — |
| Canlı ders | Mimari: kapsam dışı | Yok | Yok | Kanıt bulunamadı | Yok | Planlandı | — |
| Oyunlaştırma | Yok | Homepagede pazarlama cümlesi | Yok | Kanıt bulunamadı | Yok | Planlandı | — |
| Rehberlik | Yok | Yok | Yok | Kanıt bulunamadı | Yok | Planlandı | — |
| AI | Yok | Yok | Yok | Kanıt bulunamadı | Yok | Planlandı | — |
| Access package / lisans | 2.16 domain | LC/assessment **policy formu** (free) | Yok | AccessLicense domain testleri | MAT free policy 2026-10-06 | Paket kataloğu UI yok | SA activate packages |
| Entitlement gate | `EntitlementAccessGate` | Öğrenci LC gövdesi | Yok | Student topic + entitlement testleri | MAT free gövde | Assessment’ta free policy yok | Login+verified |
| Checkout / kart | `PaymentCheckoutOrchestrator` + adapter interface | Kart UI yok | Webhook POST | Commerce hasher / webhook testleri | Canlı doğrulanmadı | Provider SDK yok | COMMERCE keys |
| Abonelik / iade | Domain 2.17 | Admin ödeme listesi (ops) | Webhook | Commerce/refund testleri | Canlı doğrulanmadı | Dunning/proration/fatura yok | SA |
| Ödeme ops 2.19 | Inbox, DLQ, reconcile | `/yonetim/webhook`, `/uzlastirma` | Webhook | Dead-letter / reconcile testleri | Canlı doğrulanmadı | — | SA |
| Public web / CMS | Homepage + yasal | `/`, `/gizlilik` vb. | Yok | Browser acceptance / legal tests | Apex smoke geçmiş deploy | Tam hukuki metin P1 ertelendi | — |
| REST / mobil API | Mimari: yok | Yok | Yok | Kanıt bulunamadı | Yok | Planlandı | Auth sözleşmesi |
| Flutter / offline / push | Mimari gelecek | Yok | Yok | Kanıt bulunamadı | Yok | Planlandı | API |
| Audit | `security_audit_events` | `/yonetim/denetim` SA | Yok | Audit sanitizer testleri | MAT yayın audit 2026-10-06 | Retention prosedürü plan | SA |
| Backup/restore ürünü | Deploy tarball/rollback script | Ürün UI yok | Yok | Kanıt bulunamadı (ürün) | Canlı doğrulanmadı | Ayrı backup modülü yok | VDS ops |
| Teslim / CI / deploy | GHA CI + Deploy VDS + ops SSH | Yok (GitHub) | Yok | Workflow guard testleri | Deploy 37333899773; question apply 37335464523 | Ban-risk tek SSH | `VDS_SSH_PAUSED` |
| UI preview | Fake views | `/onizleme/*` | Yok | `UiPreviewControllerTest` | Production router’da yok | Gerçek modül sayılmaz | `dev`/`test` |

## Bilinçli sınırlar

- Öğrenci gövdesi giriş ister; `free` yalnız lisans kapısını açar.
- Soru bankası ≠ assessment ≠ öğrenci test listesi.
- `/onizleme` ve anasayfa cihaz metni mobil uygulama kanıtı değildir.
- `docs/release-readiness.md` 2026-09-28 RC1 denetimidir; bugünün envanteri bu dosyadır.
