# Gerçek tarayıcı kabulü

Bu paket production sayfasını veya production hesabını açmaz. Docker bu turda çalıştırılmaz. Veri, CI’daki MariaDB ve Redis üzerinde, `APP_ENV=test` iken `app:browser-acceptance:prepare` ile kurulur. Komut `when@test` dışında kayıtlı değildir ve HTTP route’u yoktur. Manifest `var/browser-acceptance.json` içindedir; git’e girmez ve artifact olarak yüklenmez.

## Araç

Playwright `1.55.1`, yalnız Chromium. Paket `browser/package.json` ve lockfile içindedir. AssetMapper `importmap.php` paketlerine eklenmez. `retries` 0, `workers` 1, `fullyParallel` kapalı, video kapalı. Hata izi yalnız başarısız testte kalır. `bypassCSP` kullanılmaz. CSP header’ı test yardımcısıyla değiştirilmez.

Sunucu `php -S 127.0.0.1:8080 -t public browser/router.php` ile localhost’a bağlanır. `browser/router.php` `public/` dışındadır.

## Sentetik roller

Bir SuperAdmin, bir platform Admin, bir Teacher, bir Student, bir ikinci Student (opak sonuç), bir Parent, bir Institution Owner. Parola mevcut PHP test parolasıyla aynıdır ve komut çıktısına yazılmaz. Aktif okul, dönem, sınıf, öğretmen ataması ve öğrenci kaydı vardır. Yayımlanmış Matematik katalog zinciri sentetik bir kazanıma bağlıdır; resmi kazanım kodu icat edilmez. Yayımlanmış içerikte başlık, paragraf, liste, callout, alıntı, matematik, YouTube gizlilik videosu ve onaylı PDF vardır. Bir yayımlanmış platform testi ve bir taslak soru vardır.

Veli bağlantısı komut içinde kurulur. Düz bağlantı kodu manifestte tutulmaz.

## Taranan akışlar

Herkese açık `/`, dört yasal sayfa, `/giris`, `/kayit` ve `/kayit/ogrenci`. Teacher, Student ve SuperAdmin girişleri ayrı Chromium context’lerindedir. Placeholder CSRF submit sırasında uzun değere döner. Cookie’siz eski token girişi hesap açmaz. Çıkış POST formudur. `GET /cikis` çıkış yapmaz.

Öğrenci ve veli kabuğu `docs/student-parent-ui-guidelines.md` içindedir. Öğretmen ve kurum kabuğu `docs/teacher-institution-ui-guidelines.md` içindedir. Öğrenci panel, ders listesi, ders, ünite, konu, test listesi, çözme, sonuç, geçmiş ve profil sentetik görüntülerdedir. Veli özeti, kod formu ve bağlantısız veli ekranı da öyledir. Kod görüntüye yazılmaz. Öğretmen çalışma alanı, içerik, sürüm, soru, test ve sınıflar; kurum özeti, sınıf, davet ve test listesi aynı turdadır.

Öğrenci panel, ders zinciri, tip bloklar, PDF kartı ve video iframe’i. Video isteği `youtube-nocookie` ve `player.vimeo.com` için kesilir. Öğrenci bir testi başlatır, seçenek işaretler, kaydeder, onay kutusunu işaretler, bitirir, sonuç ve geçmişi görür. Çözmeden önce cevap anahtarı ve teknik anahtar yoktur. Kayıtlı `Manual` yayın politikası skorlu sonuç okuyucusunu henüz kapatmaz; biten denemenin sonuç sayfası ürünün bugünkü skorlu görünümüdür. Başka öğrencinin sonuç adresi 404’tür.

Öğretmen `/yonetim` kabuğunu açamaz. İçerik, revision, soru ve test listelerini açar. Yayın düğmesini görmez. Admin aynı inceleme kaydında yayın düğmesini görür. Blok ekleme ve kaldırma, kirli form uyarısı, PDF dosya metni ve seçenek ekleme Stimulus ile çalışır.

SuperAdmin gruplu menüyü ve ödeme/denetim bağlantılarını görür. Admin bu bağlantıları görmez. Mobil çekmece Esc ile ve çekmecenin yanındaki açık şerit üzerinden kapanır. Kapatma denetimi çekmeceyi örten düğmedir; tıklama üst çubuğun altındaki açık alana yapılır. Global Admin ve SuperAdmin üyeliksiz `/kurum` alamaz. Owner kurum listelerini görür. Atanan öğretmen yalnız kendi sınıfını görür.

Veli yalnız bağlı öğrencinin sınırlı özetini görür. Başka çocuk adresi 404’tür. Veli test başlatamaz.

## Viewport ve erişilebilirlik

360×800, 768×1024 ve 1280×900. Ekran görüntüleri ana sayfa, giriş, öğrenci paneli, konu, test listesi, öğretmen çalışma alanı, revision, admin özeti, SuperAdmin özeti, kurum, veli ve gizlilik sayfasındadır. Yönetim kabuğu ayrıca kullanıcı, kurum, müfredat, içerik, soru, test ve sistem listelerini ve SuperAdmin operasyon menüsünü 1280 genişliğinde alır. Dosya adı rol, route ve genişlik taşır; e-posta veya UUID taşımaz. Görseller sentetiktir.

Pixel snapshot yoktur. Sabitler: belge yatay taşması, görünür ana eylemlerin viewport içinde kalması, açık mobil menünün `aria-expanded` değeri, kritik menü hedefinin en az 44 px olması, tek `h1` ve `main`. Yatay kaydırma `.bottom-nav`, `.table-scroll` ve `.admin-table-wrap` içindedir. Ücretli erişilebilirlik servisi ve Axe yoktur. Yönetim yüzeyinin rengi `docs/admin-ui-guidelines.md` içindedir.

## Allowlist

Aynı origin. Bilinçli kesilen hostlar: `https://www.youtube-nocookie.com` ve `https://player.vimeo.com`. Başka origin isteği hata sayılır.

## Sınırlar

Test sırası tek worker ile korunur. Bir platform testi yalnız bir spec içinde çözülür. Rate limit doldurulmaz. Hata izi sentetik test kimliğini içerebilir; production secret içermez ve başarı artifact’ına girmez. Production deploy bu komutu veya Playwright paketini çalıştırmaz.
