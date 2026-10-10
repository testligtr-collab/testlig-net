# Kanonik Testlig ürün yol haritası

Makine tarafından kontrol edilen özellik kayıtları: [features.json](roadmap/features.json). Kapsam tabanı: [baseline.json](roadmap/baseline.json). Süreç: [AGENTS.md](../AGENTS.md).

Aşağıdaki 10 Ekim karşılaştırması başlangıç kaydıdır. Sonraki görevler features.json'daki sabit kimlikleri günceller; tarihsel kod/teslim/kabul kanıtı silinmez. Baseline değişikliği önceki kimlikleri kaldırmaz. Kapsamdan çıkarılan kayıt silinmez, explicit scope_decision ile retired durumuna alınır. PR #101'in eski envanteri bu baseline'a göre ileride uzlaştırılacak; bu PR onu değiştirmez.

# Testlig V1 → V2: özellik karşılaştırması ve ana yol haritası

Tarih: 10 Ekim 2026, Europe/Istanbul.
Amaç: V1 envanterindeki ürün yeteneklerini V2 ile eşleştirmek, yapılmış işleri yeniden planlamamak, eksikleri görünür bir sıraya almak.

## 1. Kaynak ve kanıt sınırı

- V1 kaynağı: Kullanıcının yüklediği “Yapıştırılan metin.txt”, Testlig — İşlevsel Mimari Haritası. Kaynak dosya değiştirilmedi.
- V2 kaynağı: testligtr-collab/testlig-net main, SHA 2a615f1113126ad5b023b4a2893f35e021c5ec7d. GitHub recursive tree truncated=false; controller/entity/service/test yolları ve aşağıda belirtilen uygulama dosyaları salt okunur incelendi.
- Açık çalışma: PR #112, feature/institution-test-authoring kapsamı; HEAD edaef8090506da51a384d5af62f71d052547647a, 10 Ekim kontrolünde OPEN ve merge edilmemiş. CI başarısı önceki teslim kaydında run 37980804189 olarak kayıtlı; bu karşılaştırmada CI yeniden çalıştırılmadı.
- 5 Ekim devir belgesi ilk vizyon için ek kaynaktır; V1'de varlığı kanıtlamayan gelecek hedefleri ayrı tutuldu.
- Production, eski localhost ve V1 repository kodu bu görevde açılmadı. V1'in “aktif” durumu yüklenen raporun beyanıdır; her modülün canlı kabulünün tekrar yapıldığı anlamına gelmez.
- V1 raporunda DB şema uyumsuzluğu, PHPUnit'te 1 failure + 2 error ve PHPStan yapılandırma engeli de var. V1 kapsam referansıdır; hataları ve teknik borcu V2'ye taşımak gerekmiyor.
- V2'nin tarihsel mimari/ADR bölümlerinde “UI yok”, “öğrenci-only kayıt” gibi eski durumlar bulunuyor. Güncel controller kodu ile çelişen tarihsel cümleler bugünkü eksik sayılmadı.
- “Eksik” = incelenen V2 main'de ürün karşılığı bulunamadı. “Kısmi” = bir temel/ekran var, V1 kapsamı tamamlanmamış. “Mevcut” = adı belirtilen dar akış kodda mevcut; bütün modülün production kabulü değildir.
- Yeni kod, GitHub PR/commit, merge, deploy, SSH, Docker, veri/import/yayın veya provider hesabı işlemi yapılmadı. Bu rapordaki sıra geliştirme önerisidir; her iş ayrı küçük teslim olarak uygulanacak.

## 2. Yapılmış işler: yeniden yazılmayacak temel

| Mevcut V2 yeteneği | Kanıt / sınır |
|---|---|
| E-posta ile giriş/çıkış, doğrulama, parola reset/değişim | SecurityController, RegistrationController, EmailVerificationController, ResetPasswordController, ChangePasswordController |
| Öğrenci ve veli kaydı; öğretmen ve kurum başvuru girişleri | /kayit/{ogrenci,veli,ogretmen,kurum}; OnboardingApplicationController. Başvuru göndermek yetki kazanmak değildir |
| Merkezi kullanıcı, global rol/durum, kurum üyeliği | AdminUserController, AdminInstitutionController; global rol ≠ kurum erişimi |
| Kurum çalışma alanı ve kurum seçimi | InstitutionWorkspaceController; yalnız aktif ve uygun üyelik |
| Akademik yıl oluşturma/aktifleştirme | #111 main'de; yeni aktif yıl önceki aktif yılı kapatabilir |
| Kurum sınıfı, öğretmen daveti/ataması, öğrenci daveti/kaydı ve transfer | InstitutionWorkspaceController + invite controller'ları ve domain manager'lar |
| Platform soru editörü, inceleme/yayın, CSV ve paket import | AdminQuestionController, AdminQuestionImportController, QuestionManager; institution soru yazma ekranı bundan farklı |
| Platform test editörü ve mühürlü revision/yayın | AdminTestController, AssessmentManager |
| Öğrenci platform testi ve kurum ataması: çözme/bitirme/sonuç/geçmiş | StudentAssessmentController, InstitutionTestAssignmentController; mevcut single-choice practice kuralları |
| Öğrenci panelinde gerçek sınava “Devam et” | StudentAssessmentPractice::continueCard; ders ilerlemesi değildir |
| Assessment erişim politikası UI + öğrenci entitlement | AdminTestController, StudentAssessmentPractice::evaluateAssessment kullanımı; A keşif/yeni start, B mevcut attempt, C geçmiş/sonuç ayrımı |
| Katalog konusu → içerik ve platform test yerleşimi | CatalogTopicLesson / CatalogTopicAssessment; öğrenci “Kendini dene” kartları |
| Öğrenme içeriği editörü + öğrenci gövdesi | AdminLearningContentController; paragraph/heading/list/quote/math/callout/video/document |
| YouTube/Vimeo video blokları ve özel PDF yükleme/sunma | LearningDocumentStorage, AdminLearningDocumentController, StudentLearningDocumentController; bulut provider entegrasyonu değildir |
| Veli bağlantısı, çoklu çocuk listesi ve sınav özeti | ParentDashboardController; güvenli read-model, başka öğrencinin cevap incelemesi açılmaz |
| Öğretmen/kurum sınıf testi sonuçları | TeacherClassroomController / InstitutionTestAssignmentController; doğru, yanlış, boş, puan ve yüzde mevcut |
| Ödeme webhook, DLQ/mutabakat ve audit operasyonu | AdminPayment/Webhook/Reconciliation/Audit controller'ları; checkout değildir |
| Ana sayfa, yasal sayfa yüzeyi, public ders katalog adları, sitemap/robots | HomeController, LegalPageController, PublicCatalogController; haber/CMS ürünlerinin varlığını kanıtlamaz |
| CI/deploy ve erişim/DB bütünlüğü | Mevcut delivery omurgası; production mobil/tablet kabulünden ayrıdır |

PR #112 kurum test ekranını main’e aldı. PR #114 merge SHA ff4cd745b4e479edbcf0bcf284000846ee12182b kurum soru ekranını main’e aldı. Main CI 38051969843, Roadmap coverage 38051969849 ve deploy 38053237728 success. Production kurum sorusu ve testi oluşturulmadı. Pilot uygulanmadı.

## 3. V1 modüllerinin tamamı için karşılaştırma

| ID | V1 modülü / rapordaki kanıt | V2 durumu | Yol haritasına kalan iş |
|---|---|---|---|
| M01 | Üyelik: Registration, InstitutionRegistration, Activation, MemberCode | Kısmi; dört kayıt giriş yolu var | Başvuru inceleme/onay/reddinin ürün zinciri, üye kodu ihtiyacı ve deneme erişimi |
| M02 | Giriş: e-posta/üye kodu, SMS, OAuth, JWT | E-posta akışı mevcut; diğerleri eksik/kısmi | SMS sağlayıcısı+HTTP, OAuth, mobil token; üye kodu ve misafir giriş ayrı güvenlik tasarımı |
| M03 | Kullanıcı yönetimi: toplu importer, grup/geçiş, moderator permissions | Rol/durum/üyelik yönetimi mevcut | Toplu kullanıcı aktarımı, grup geçiş yönetimi, ayrıntılı moderatör modül izinleri |
| M04 | Kurum: başvuru, onay, kurum yönetimi ve panel | Kurum/üyelik/panel mevcut; başvuru kısmi | Başvuru karar ekranı ve onay sonrası provisioning; kurum alanlarını ihtiyaç bazlı tamamla |
| M05 | Şube: InstitutionBranch, banka hesabı, hierarchy | Şube/kampüs ürün modeli bulunamadı | Kurum → şube/kampüs ilişkisi, izolasyon ve operasyon ekranları; banka verisi ayrı mahremiyet kapsamı |
| M06 | Sınıf: TeacherClass + Classroom; öğretmene ait bağımsız sınıf | Kurum sınıfı mevcut | Bireysel öğretmenin kurumsuz sınıfı ve katılım kodu V2'de karşılanmıyor; çift eski modeli kopyalamadan karar ver |
| M07 | Öğretmen: profil, panel, not, mesaj, rapor, canlı ders talebi | İçerik çalışma alanı ve kurum sınıf raporu mevcut | Mesleki profil/onay zinciri, öğrenciye özel öğretmen notu, takvim, mesaj, canlı ders talepleri |
| M08 | Öğrenci: öğrenme, sınav, oyun, simülasyon, Q&A, ödeme, canlı ders | Katalog/öğrenme/test/sonuç/continue mevcut | İçerik etkinlik/ilerleme takibi ve diğer ürün modülleri |
| M09 | Veli: çocuk link, rapor, aktivite, mesaj, bildirim | Bağlantı/çocuk/test özeti mevcut | Çalışma etkinliği raporu, mesaj ve bildirim; ödeme/paket görünümü ayrıca |
| M10 | Müfredat: taxonomy, grade, subject, topics, importer | Katalog, canonical eşleme ve pedagojik domain/import mevcut | Genel program/kazanım yönetim UI'si; tüm hedef seviyelerde içerik envanteri |
| M11 | Soru havuzu: öğretmen/kurum soruları, import, AI PDF/Word | Platform editörü + CSV/paket mevcut; kurum soru ekranı main’de | AI destekli PDF/Word çıkarımı; production soru yazımı ve pilot ayrı |
| M12 | Sınav: platform, öğretmen, kurum, öğrenci ve API | Platform ve kurum teslimat/öğrenci akışı mevcut; kurum test yazımı main’de | Gerçek pilot; bağımsız öğretmen sınıfı; manuel değerlendirme ürün yüzeyi ve API |
| M13 | Raporlama: öğrenci/öğretmen/kurum/admin, achievement | Sonuç/geçmiş ve sınıf raporu mevcut; analytics domain hazır | Kazanım/madde/öğrenci gelişimi ekranları, güvenli filtreler ve dışa aktarma |
| M14 | Canlı ders: Request/Package/Review, Zoom/BBB/Jitsi webhook | Ürün karşılığı bulunamadı | Talep, yetkili onay, kredi, takvim/oda, katılım, yoklama/kayıt/tekrar; provider seçimi |
| M15 | Ödeme: Plan, Coupon, Order, iyzico/PayTR/havale | Ticaret/lisans/domain+ops mevcut; sandbox seam | Paket/teklif UI, checkout, gerçek provider adapter, havale operasyonu, kupon/kampanya |
| M16 | SMS: Netgsm/İleti Merkezi/Vatan, konfigürasyon ve giriş | Telefon claim/normalizasyon kısmi | OTP üretim/gönderim/provider+rate limit+form; telefon doğrulaması ve SMS girişi ayrı |
| M17 | AI: provider settings, simulation generator, question import | Ürün karşılığı bulunamadı | Provider adapter/ayar, kota/maliyet, güvenli import/taslak; uzman onayı |
| M18 | Depolama: StorageAdmin, StoredFile, StorageConnectionLog, CloudStorageConfig; Local/S3/R2/Bunny | Özel yerel PDF saklama + metadata provider enum mevcut | Bulut storage adapter'ları, bağlantı ayarı/testi, dosya envanteri, kota ve yaşam döngüsü |
| M19 | Doküman: bağımsız katalog, öğretmen üretimi/onay, Ghostscript preview | Ders içindeki özel PDF blokları mevcut | Bağımsız doküman kütüphanesi, taxonomy filtreleri, öğretmen yayın kuyruğu, önizleme/dönüştürme |
| M20 | Video: bağımsız katalog, YouTube/Vimeo/dosya | YouTube/Vimeo ders blokları mevcut | Video kütüphanesi, dosya video yükleme/streaming, gerekiyorsa izleme ilerlemesi |
| M21 | Simülasyon: Simulation + QuizQuestion/Option, AI generate | Ürün karşılığı bulunamadı | Güvenli etkileşimli içerik motoru, katalog, yayın, öğrenci etkinliği; AI sonra |
| M22 | Oyun: GameCatalog, GameScore | Ürün karşılığı bulunamadı | Eğitsel oyun kataloğu, çalıştırma güvenliği, skor/ilerleme; oyunlaştırmadan ayrı |
| M23 | Q&A: öğrenci sorusu, uzman cevabı, public hub, API | Ürün karşılığı bulunamadı | Soru sorma/cevap, moderasyon, görünürlük, abuse koruması, public arama |
| M24 | Mesajlaşma: öğretmen/veli/admin, SupportMessage paylaşımlı | Ürün karşılığı bulunamadı | İzinli konuşma modeli ve rol/sınıf/kurum sınırları; destek kaydıyla aynı entity'ye sıkıştırma |
| M25 | Bildirim: StudentNotification ve olay servisleri | Ürün bildirim modeli/merkezi bulunamadı | Olay → kuyruk → kanal; kullanıcı tercihleri, okundu durumu, e-posta/web/mobile push |
| M26 | Destek: Contact/Feedback/SupportAdmin, spam kayıtları | Ürün karşılığı bulunamadı | İletişim formu, talep yönetimi, cevap/durum ve spam koruması |
| M27 | Haber: Content/NewsCategory, hub, editor access | Ana sayfa bölümü var; çalışan haber ürünü yok | Haber/blog içerik modeli, editör/yayın, kategori ve public sayfalar |
| M28 | SEO: metadata, SchemaOrg, sitemap, robots | sitemap/robots+yanıt güvenliği mevcut | İçerik başına SEO/structured data yönetimi; public route allowlist'ini bilinçli koru |
| M29 | Sayfa yönetimi: Page/Nav/Footer/Home/Showcase/Slider/SiteModuleSetting | Ana sayfa/yasal yüzey mevcut; DB-backed CMS yok | Sayfa/menü/footer/slider/bölüm/SSS ve site ayarlarının yönetimi |
| M30 | Mobil API: /api/v1, JWT ve ApiRefreshToken | API/Flutter ürünü yok | Ortak DTO sözleşmesi, auth/refresh/device ve endpoint'ler; sonra Flutter/offline/push |
| M31 | Analitik/Ads: SiteVisitLog, AdSlot, AdImpression, AdsConfig | Eğitim analytics temeli var; site ziyaret/reklam ürünü yok | Site trafik analitiği ve reklam/slot yönetimi ayrı; çocuk mahremiyeti ve consent kararı gerekir |

M18'deki V1 kanıtı ADMIN'in platform medya depolama yönetimidir. “Her kullanıcı için Drive gibi Dosyalarım, klasör paylaşımı ve çöp kutusu vardı” şeklinde genişletilmez. Kişisel/kurumsal bulut dosya alanı isteniyorsa ayrı ürün kapsamıdır; bu raporda ek tasarım kalemi olarak korunur.

## 4. Ana modül satırları içinde kaybolmaması gereken alt işler

| İş | V1 kanıtı / ek hedef | V2 karşılığı ve kalan |
|---|---|---|
| Üye koduyla giriş | LoginFormAuthenticator / MemberCodeService raporda | V2 normalizedEmail provider; üye kodu akışı yok |
| Misafir/kodla sınav ve sonradan hesaba sahiplenme | AccessCodeService, GuestExamLoginService, GuestAttemptClaimSubscriber | V2 davet/katılım entity'si misafir login/attempt claim demek değil; uçtan uca akış yok |
| Kurum/sınıf katılım kodu | ClassStudentAuth / TeacherClassJoin | Güvenli e-posta davetleri mevcut; kodla girişten farklı |
| Başvuru onayı | V1 TeacherApproval / InstitutionRegistration approval | V2 başvuru göndermesi ve decision service var; TeacherApplicationManager::markApproved rol vermez; tam yönetim/onay zinciri eksik |
| Baş/uzman öğretmen özel paneli | /panel/head ve /panel/expert | V2 rol yetkileri ve ortak içerik çalışma alanı mevcut; özel ekip/kuyruk/talep dashboard'ları ayrıca |
| Moderatöre modül bazlı izin verme | AdminModulePermission | V2 voter/rol matrisi mevcut; admin tarafından düzenlenen aynı modül izin ürünü yok |
| Kurum toplu öğrenci/üye aktarımı | BulkUserImporter / branch import | Soru CSV importundan farklı; kurum kullanıcı aktarımı eksik |
| Öğretmen notları ve geri bildirim | StudentTeacherNote | Sınıf sonuç tablosu not/geri bildirim sistemi değildir |
| Deneme üyeliği | StudentRegistrationTrialApplicator / TrialAccess | V2 free policy lisanssız kaynak erişimidir; süreli trial ürünü değildir |
| Öğretmen/banka/şube ödeme bilgileri | TeacherBankAccount / BankAccount | V2 payment ops aynı kişisel banka yönetimi değildir; gerçek ihtiyaç+veri minimizasyonu ile tasarlanmalı |
| Video dosyası/medya bağlantısı | VideoEmbed + storage; YT/Vimeo/file | V2 URL blokları var; upload/encoding/streaming bulunamadı |
| AI PDF/Word soru aktarımı | QuestionImportAi | V2 CSV ve onaylı paket importu var; AI/OCR akışı yok |
| Oyun skoru | GameScore | AssessmentScoringRun ile birleştirilmez |
| Canlı ders değerlendirmesi/kredi | LiveLessonReview / Package / Request | V2 ürün zinciri yok |
| Uygulama içi mesaj+bildirim | TeacherMessageNotification / StudentNotification | Mail doğrulama göndermek bu ürünü karşılamaz |
| Menü/site modülü/slider yönetimi | NavMenuItem / SiteModuleSetting / HomeSection / Slider | V2 AdminNavBuilder ve HomepageView statik presentation; CMS yönetimi değil |
| Mobil auth ve veli API'si | ApiRefreshToken / Api/V1/Auth / Api Parent | V2 web session; mobil JWT/refresh endpoint'i yok |
| Ziyaret ve reklam ölçümü | SiteVisitLog / AdSlot / AdImpression | Eğitim sonucu analitiğinden ayrı boşluk |
| PII şifreleme politikası | V1 raporu şifreli PII/banka/destek alanları anlatır | V2 cevap şifrelemesi/audit sanitizasyonu var; bütün PII alanları için eşdeğer encryption iddiası yapılmadı; ayrı güvenlik envanteri |
| Bağımsız öğretmen sınıfı | TeacherClass, institution olmayan teacher owner | V2 kurum sınıfı ile aynı ürün değil; scope kararı öncesi üçüncü legacy sınıf modeli ekleme |

## 5. V1 raporunda kesin kanıtlanmayan ama ilk vizyonda korunacak işler

Bu satırlar “V1'de çalışıyordu” diye raporlanmaz. 5 Ekim devir özetinde hedef olarak bulunur veya kullanıcı son konuşmada kapsamı belirtmiştir.

| Hedef | V2 durumu | Yol haritasındaki karşılığı |
|---|---|---|
| Yanlış defteri, favori/not, kazanım haritası, adaptif tekrar | Ayrı ürün karşılığı bulunamadı; scoring/outcome temeli var | Öğrenme ilerlemesi ve pedagojik geri bildirim paketi |
| Günlük çalışma planı ve son ders | Ders ziyaret/ilerleme modeli yok | Test “Devam et”i yeniden yazmadan ders ilerlemesi ekle |
| Ödev/proje, dosya teslimi, geç teslim, rubrik | Domain enum veya preview tam ödev ürünü değildir | Atama/teslim/öğretmen değerlendirme döngüsü |
| Oyunlaştırma: seri, görev, rozet/seviye/ödül | Ürün karşılığı bulunamadı | Eğitsel oyun motorundan ayrı motivasyon modülü |
| Rehberlik | Ayrıntılı akış kararı eksik | Yaş/rol/mahremiyet kapsamı netleştirilip planlanacak |
| Manuel değerlendirme, itiraz/regrade/release politika UI | Domain temeli mevcut; genel HTTP ürün akışı tamamlanmamış | Yeni puanlama motoru değil mevcut manager'ların güvenli yüzeyi |
| PDF/Excel rapor dışa aktarma | Ürün yüzeyi bulunamadı | Yetki/mahremiyet koruyan export |
| Kişisel/kurumsal Dosyalarım | V1 admin storage'dan ayrı ürün kararı | Klasör/kota/paylaşım/sürüm/çöp kutusu kapsamı netleştir |
| Mobil/tablet Flutter, offline, push, deep link, mağaza | Ürün yok | API ve cihaz yönetimi sonrasında |
| Yedek/geri dönüş, gözlemleme ve kapasite | Deploy/rollback temeli var | Backup politikası+restore tatbikatı, metric/alert/yük testi |
| Erişilebilirlik WCAG hedefi | Tam kabul kanıtı yok | Klavye/ekran okuyucu ve responsive kabul |
| Hukuk/retention/izinler | Belgeler+yasal sayfa yüzeyi mevcut | İçerik/saklama/çocuk izinlerinin doğrulanması; teknik envanter hukuk onayı değildir |

## 6. Bağımlılık sırasıyla güncellenmiş yol haritası

Aynı anda tek aktif uygulama işi tutulacak. Aşağıdaki paketler birer PR değildir; her biri küçük görev ve ayrı kabul noktalarına bölünecek. PR numaraları proje bitiş sayacı değildir.

| Sıra | Paket / yapılacaklar | Ön koşul | Bitiş kanıtı |
|---|---|---|---|
| 0 | Bu karşılaştırmayı kanonik envantere işle; V1 alt özelliklerinde belirsiz olanları Cursor yerel kodundan salt okunur teyit et | V1 kaynak klasörü + bu rapor; V2 main ve #112 ayrımı | Her satırda kaynak, durum, bağımlılık ve kabul bulunur; hiçbir konu sessiz kaybolmaz |
| 1 | #112 kurum test ekranı | Teslim edildi | Production kurum testi ve pilot ayrı |
| 2 | #114 kurum soru ekranı | Merge ff4cd745b4e479edbcf0bcf284000846ee12182b; CI 38051969843; coverage 38051969849; deploy 38053237728 | Production soru yazımı ve pilot ayrı |
| 3 | Tek kurum pilotu | Production kayıtları ve oturum doğrulanmadı | Bölüm 9 sırası uygulanmadı; 390/768 ayrı ölçülür |
| 4 | Başvuru onayı ve rol/üyelik provisioning; öğretmen profil akışı | Mevcut application/identity manager'lar | Onay yetkisi, tekrar güvenliği, reddin erişim vermemesi, başka kullanıcı/kurum izolasyonu |
| 5 | Depolama ve medya altyapısı: provider abstraction, bağlantı yönetimi, tarama/lifecycle, mevcut PDF uyumu | Tenant/entitlement ve dosya politikası | Local+seçilen bulut provider, özel erişim, storageKey sızmaması, integrity; ücretli provider seçimi ayrı |
| 6 | Doküman/video kütüphaneleri ve öğretmen materyal kuyruğu | Medya altyapısı + mevcut LC editör/publish | Bağımsız katalog/filtre ve ders bağlama; öğretmen kendi yüklemesini kendisi onaylamaz |
| 7 | Bildirim merkezi, destek ve izinli mesajlaşma | Rol/kurum/veli bağları; queue/mail | Sadece izinli alıcı, idempotent gönderim, spam/rate limit, tercih/okundu durumu |
| 8 | Öğrenme ilerlemesi, ödev, geri bildirim ve analitik ekranları | Mevcut içerik/sınav/outcome; dosya teslimi için storage | Son ders/plan/ödev ve gerçek scoring read-model; küçük cohort gizliliği; GET veri yazmaz |
| 9 | Paket/teklif, checkout, gerçek ödeme sağlayıcısı ve trial/kupon | Mevcut commerce/license/entitlement; provider kararı | Sandbox ve gerçek provider kabulü ayrı; webhook/fulfillment idempotency ve öğrenci gate korunur |
| 10 | Haber/CMS/SEO, Q&A; moderatör görev/izinleri | Güvenli içerik publish ve abuse politikasına dayanır | Gerçek public sayfalar, moderasyon, draft sızmaması; ana sayfa placeholder'ları ürünle eşleşir |
| 11 | Şube/kampüs, toplu aktarım ve bağımsız öğretmen sınıfı | Ayrı scope/model kararları | Kurum/şube izolasyonu, geçiş geçmişi; role hierarchy eski projeden kopyalanmaz |
| 12 | Canlı ders | Provider, kredi/paket, takvim, bildirim, izin/rıza | Talep→onay→oda→katılım; yoklama/kayıt ve token sınırları |
| 13 | Simülasyon, eğitsel oyun, oyunlaştırma; AI üretim yardımı ve rehberlik | İçerik motoru/güvenlik/öğrenme verisi; AI provider kararı | Onaylı içerik, gerçek skor/ilerleme; yaş güvenliği+kota+maliyet |
| 14 | API ve Flutter mobil/tablet | Ortak DTO/gate sözleşmesi ve cihaz auth | Web yetkilerini koruyan API, sonra Flutter; offline/push/deep link ve mağaza kabulü |

SMS/OAuth/misafir giriş/katılım kodu ayrı kimlik dilimleridir: mevcut e-posta girişini değiştirmeden, paket 4 çevresinde ihtiyaç ve güvenlik kararlarıyla sıraya alınır. Site ziyaret/Ads, reklam ve çocuk mahremiyeti kararı gerektirdiğinden zorunlu pilot ön koşulu değildir; paket 10 sonrası ayrı iş olarak izlenir.

Kalite/backup/gizlilik/performans işleri sona bırakılmaz: her paketin teslim kapısıdır. Büyük restore/yük/erişilebilirlik tatbikatları ayrıca planlanır.

## 7. Mevcut açık kabul ve korunacak kayıtlar

- MAT.1.3.3 çözme/sonuç/geçmiş ve dört yayın audit olayı önceki production gözlemlerinde kaydedildi; burada yeniden çalıştırılmadı. Import/apply/yayın/attempt tekrar edilmez.
- Production 390 ve 768 px kabulü hâlâ açık; sentetik CI tarayıcı sonucu bunu kapatmaz.
- Öğretmen sonuç tablosunun gerçek pilot kabulü açık. Engel ekran eksikliği değil; production kayıtları ve uygun oturum doğrulanmadı. Bölüm 9 planı uygulanmadı. Platform MAT testi kurum testinin yerine kullanılmaz.
- Başvuru onayı kullanıcıya kendi kendine rol verme değildir. Yetki ve SoD gevşetilmez.
- PR #101 ürün envanteri main'de değil; eski doküman ayrı açık PR olarak duruyor. Bu görevde ona veya #112'ye yazılmadı.
- Local/S3/R2/Bunny enum üyelerinin varlığı SDK veya çalışan bağlantı kanıtı değildir.
- Flutter API, gerçek checkout, SMS/OAuth, bağımsız oyun/simülasyon/Q&A/haber/mesaj/destek/storage admin ürünleri tamamlandı sayılmadı.
- Eğitim analytics DTO'ları, site trafik/reklam analitiği yerine sayılmadı.

## 8. İncelenen V2 kaynakları ve izlenebilir bağlantılar

Sabit kaynak kökü:
https://github.com/testligtr-collab/testlig-net/tree/2a615f1113126ad5b023b4a2893f35e021c5ec7d

Özellikle içerikleri okunan dosyalar:

- config/packages/security.yaml
- src/Controller/RegistrationController.php
- src/Controller/OnboardingApplicationController.php
- src/Controller/SecurityController.php
- src/Controller/HomeController.php
- src/Controller/PublicCatalogController.php
- src/Controller/InstitutionWorkspaceController.php
- src/Controller/InstitutionTestAssignmentController.php
- src/Controller/ParentDashboardController.php
- src/Controller/TeacherClassroomController.php
- src/Controller/StudentAssessmentController.php
- src/Controller/StudentLearningDocumentController.php
- src/Controller/Admin/AdminUserController.php
- src/Controller/Admin/AdminInstitutionController.php
- src/Controller/Admin/AdminSystemController.php
- src/Controller/Admin/AdminQuestionController.php
- src/Controller/Admin/AdminLearningContentController.php
- src/Controller/Admin/AdminLearningDocumentController.php
- src/Controller/Admin/AdminAssessmentResultController.php
- src/Service/RegistrationService.php
- src/Service/StudentAssessmentPractice.php
- src/Service/PhoneVerificationClaimManager.php
- src/Service/TeacherApplicationManager.php
- src/Service/InstitutionApplicationManager.php
- src/Service/Admin/AdminNavBuilder.php
- src/LearningContent/Document/LearningDocumentStorage.php
- src/Enum/StoredMediaStorageProvider.php
- src/Homepage/HomepageView.php
- docs/architecture.md
- docs/architecture-auth-membership-onboarding.md (tarihsel ADR; güncel kodun yerine geçmez)
- docs/architecture-learning-content.md
- docs/architecture-commerce-payment.md
- docs/architecture-admin-operations-panel.md (ilk aşama sınırları güncel feature yokluğu sanılmadı)

PR #112:
https://github.com/testligtr-collab/testlig-net/pull/112

## 9. Tek kurum pilotu

Bu plan uygulanmadı. Production soru, test, sınıf veya attempt yazılmadı. 390/768 kabulü açık. Öncelik mevcut kayıtları kullanmaktır. Eksik kayıt bu belgeyle oluşturulmaz. Platform MAT testi kurum testinin yerine kullanılmaz.

1. Mevcut aktif kurumu kullan. Aktif kurum yoksa pilot başlamaz.
2. Aynı kurumda iki ayrı aktif owner veya manager kullan. Biri soru ve test revision’ını yazar. Diğeri yayımlar. Yazar kendi revision’ını yayımlamaz.
3. Mevcut aktif akademik yılı kullan.
4. Bu yıldaki mevcut 1. sınıfı kullan.
5. Bu sınıfa atanmış mevcut öğretmeni kullan.
6. Bu sınıfa kayıtlı mevcut öğrenciyi kullan.
7. 1. sınıf ve seçilen ders için mevcut yayımlı programın aktif kazanımını kullan. Kazanım yoksa soru yazılmaz.
8. Yazar en az üç tek seçenekli kurum sorusunu yazıp incelemeye gönderir. Yayıncı bu soruları yayımlar.
9. Yazar yalnız bu yayımlı sorulardan 1. sınıf kurum testini yazıp incelemeye gönderir. Yayıncı testi yayımlar.
10. Yayımlı testi bu 1. sınıfa atar ve etkinleştirir.
11. Öğrenci bir soruyu doğru, birini yanlış cevaplar, birini boş bırakıp testi bitirir.
12. Atanmış öğretmen sonuçta Doğru, Yanlış, Boş, puan ve yüzdeyi okur.
13. Yalnız başka sınıfa atanmış öğretmen aynı teslimat sonucunda reddedilir.

10 Ekim salt okunur kontrolünde production oturumu yoktu. Kurum, üyelik, sınıf ve kazanım kayıtları doğrulanamadı.
