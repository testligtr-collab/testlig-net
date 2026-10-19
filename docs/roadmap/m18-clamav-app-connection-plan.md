# M18 uygulama ClamAV bağlantısı — açma ve geri alma planı

Bu belgenin ilk hali salt okunur plandı. PR #149 aynı kapsamı koda çevirir: soru ayrıntısında kalıcı görsel durumu, varsayılan kapalı bağlantı kontrolü ve henüz çalıştırılmayan açma/geri alma aracı. Bu kayıt production bağlantısı veya uçtan uca kabul değildir.

19 Ekim 2026 CLI soket kabulü durur: https://github.com/testligtr-collab/testlig-net/actions/runs/39234379972 SHA `7d941ed696b076ab48246eb24e7eeab9ae4d0e98`. O çalıştırmada ölçülen `virusgroup` GID `985` yalnız o ölçümdür; sonraki işlem yeniden ölçer. Üyelik eklendi, `nPING`/`nVERSION` geçti, kurtarma kaydı tutuldu. Bu sonuç güncel web işçisi veya SELinux kabulü değildir. 17 Ekim 2026 tek web isteği `5002 / 5002 / [5002]` tarihî kanıt olarak kalır. Yüklenmiş main/bytecode kanıtı açık kalır.

## Mevcut

`config/services.yaml` `UnixClamdTransport` soketini `%env(QUESTION_IMAGE_CLAMAV_SOCKET)%` ve `CvdDatabaseDirectoryReader` dizinini `%env(QUESTION_IMAGE_CLAMAV_DATABASE)%` ile bağlar. `QUESTION_IMAGE_CLAMAV_DATABASE` için servis varsayılanı boştur. Soket anahtarının servis varsayılanı yoktur. Kök `.env` her ikisini boş bırakır. Boş soket, kullanım anında `UnixClamdSocketPath` tarafından reddedilir.

Uygulama `public/index.php` üzerinden `symfony/runtime` kullanır. Çözüm sırası şudur. Süreçte zaten tanımlı bir ortam değişkeni dotenv tarafından ezilmez. Geçerli `.env.local.php` varsa runtime onu yükler ve dotenv dosyalarını atlar. O dosya yoksa sıra `.env`, `.env.local`, `.env.$APP_ENV`, `.env.$APP_ENV.local` şeklindedir; sonraki dosya aynı anahtarı ezer. Prod için `.env.prod` ve `.env.prod.local`, `.env.local` değerinden daha yüksek önceliklidir.

`deploy/vds/release-deploy.sh` arşivdeki `.env.local` ve `.env.*.local` dosyalarını siler ve `shared/.env.local` kopyasını release `.env.local` yapar. Shared ve çalışan release kopyası deploy anında aynı kaynaktan gelir; sonra ayrı dosyalardır. Deploy `composer dump-env` çalıştırmaz. `cache:warmup` `%env()%` değerini konteynıra gömmez; işçi değeri açılışında okur. Warmup, anahtarı açmanın yolu değildir.

`POST /yonetim/calisma-kimligi` varsayılan kapalıdır. `WEB_RUNTIME_IDENTITY_ENABLED` ve en fazla 15 dakika ileri `WEB_RUNTIME_IDENTITY_EXPIRES_AT` birlikte gerekir. Aktif doğrulanmış SuperAdmin, CSRF ve nonce ister. Yanıt `Cache-Control: no-store, private` taşır ve yalnız nonce ile euid, egid, groups döner. ClamAV komutu göndermez.

`ClamdLoadedDailyVersion` yalnız `nVERSION` gönderir ve yüklenmiş daily alanını okur. Main veya bytecode alanı yoktur. Bu sınıf tarama tazelik gözleminin içindedir. `QuestionImageScanner` gerçek pending görseli okur, `INSTREAM` ile tarar ve uygun sonuçta clean/ready yazabilir. Bu yol bağlantı kabulü değildir.

Grup kurtarma kaydı `shared/clamav-socket-group.record` olarak durur. Ortam geri alımı bu kaydı okumaz, değiştirmez veya silmez. Grup üyeliğini kaldırmaz. Grup geri alımı ayrı workflow’dur.

Deploy’daki OLS adımı `lswsctrl stop`, bir saniye bekleyiş ve `start` kullanır. Yorumu, yumuşak restart’ın eski PHP opcode bırakabileceğini ve stop/start’ın lsphp işçilerini yenilediğini söyler. Bu kesinti deploy’a aittir. Kimlik penceresi OLS yenilemez.

## Eksik

Bağlantı kontrolü PR #149 ile `/yonetim/sorular/{id}` ekranına eklendi. Varsayılan kapalı, kısa süreli, SuperAdmin, ayrı CSRF ve `no-store, private` kullanır. Çıktı yalnız kimlik eşleşmesi ve ping/sürüm etiketleridir. `StoredMediaAsset`, gerçek dosya, `INSTREAM` ve clean/ready yoktur. Canlı açma bu kayıtta çalıştırılmadı. CLI kabulü web kabulünün yerine geçmez.

## Canlıda bilinmeyen

Shared ve release `.env.local` içindeki iki anahtarın bugünkü yokluğu veya değeri bilinmez. Eski absent ölçümü güncel değildir. `.env.local.php`, `.env.prod` veya `.env.prod.local` içinde bu anahtarların olup olmadığı bilinmez. Web işçisinin süreç ortamında bu adların tanımlı olup olmadığı bilinmez. Mevcut işçilerin ek grupları bilinmez. Soket yolu, sahibi, modu ve CVD dizini bu plan yazılırken yeniden ölçülmemiştir. Paket varsayılanı veya 19 Ekim yolu canlı ölçüm değildir. SELinux ve yüklenmiş main/bytecode bilinmez.

## Sonraki operasyon sırası

Bu sıra bu PR’da çalıştırılmaz. OLS yenilemesi ve web kabul isteği ayrıca yetkilendirilir.

1. Çalışan release SHA’sını doğrula. Uyuşmazsa yazma.
2. Soket ve CVD dizinini yeniden ölç. Sahip, grup ve mod beklenen sözleşmeden farklıysa yazma. İmza dosyalarına izin veya ACL verme. Önceki CLI okuma sonucunu güncel web erişimi sayma. `virusgroup` GID’sini yeniden ölç; 985’i varsayma.
3. Allowlist’teki ClamAV ve bağlantı kapısı anahtarlarını shared `.env.local` ve release `.env.local` içinde ayrı oku. Başka anahtarları okuma veya loglama. `.env.local.php` varsa, `.env.prod` veya `.env.prod.local` anahtarı içeriyorsa, satır tekrarlı veya belirsizse yazma. Süreç ortamındaki değerler dotenv’ten önce gelir ve bu dosyalardan görünmez. Worker ortamı bu araçla okunmaz; bu açık blocker olarak kalır.
4. Her dosya için her anahtarın önceki yokluğunu veya önceki satırını ayrı kurtarma kaydına yaz. Kayıt grup kaydından ayrı, root sahipliğinde ve `0600` olsun. Grup kaydına dokunma.
5. Yalnız bu iki anahtarı iki dosyaya yaz ve doğrula. Bir dosyanın yazma hatasında değişen dosyayı kayıttaki önceki duruma geri al. İkinci dosyayı denemeyi kesme. Doğrulanmayan kayıt silinmez. Kısmi yazma başarı değildir. Grup üyeliği bu hatada değişmez.
6. Onaylı canlı apply, dosyalar doğrulandıktan sonra mevcut `lswsctrl stop` / `start` yenilemesini çalıştırır. Bu kesintilidir ve grup üyeliğini değiştirmez. Bu PR’da çalıştırılmaz. Yenileme web kabulü değildir.
7. Yenilemeden sonra, eksik kod dilimi varsa, aynı yeni web sürecinde POSIX kimliği ve yalnız `nPING`/`nVERSION` doğrulanır. 17 Ekim kaydı yeni grup listesiyle yeniden yazılmaz. Yeni grup listesi bütün işçilerin veya SELinux’un kanıtı değildir.
8. Geri alma yalnız kayıttaki iki anahtarın önceki yokluk veya değerini iki dosyaya döndürür ve doğrular. Doğrulanmadan env kaydı silinmez. Grup üyeliğini kaldırmaz. Grup geri alımı ayrı kalır.

Başarılı env açılması production bağlantısı veya uçtan uca kabul değildir. M18 partial kalır.
