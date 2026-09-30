# Soru bankası CSV içe aktarma

İçerik ekibi, mevcut soru oluşturma yetkisiyle UTF-8 CSV yükleyip taslak soru oluşturabilir. Bu yol yeni bir soru tablosu açmaz. `QuestionManager` ve ayrı `QuestionAnswerKey` modeli kullanılır. Import yayımlamaz, incelemeye göndermez ve arşivlemez.

Deploy bu importu çalıştırmaz. Production verisine örnek soru yazılmaz.

## Kolonlar

İlk satır birebir şu başlık olmalıdır. Fazla, eksik veya tekrar eden kolon dosyayı reddeder.

| Kolon | Anlam |
| --- | --- |
| `code` | `questions.code`. 32 küçük onaltılık karakter; depolanan değer UUID tireleri olmadan. Değiştirilemez. |
| `grade_level` | `1`–`12` tam sayı |
| `subject_code` | Aktif `Subject.code`. Nokta ve tire alt çizgiye çevrilir, küçük harfe iner (`MAT` → `mat`). İsim veya slug ile aranmaz. |
| `learning_outcome_code` | Aktif kazanım kodu. `MAT.1.3.1` → `mat_1_3_1`. Kazanım, ders ve sınıfla aynı yayındaki programda olmalıdır. |
| `stem` | Düz soru kökü |
| `option_a` | Zorunlu |
| `option_b` | Zorunlu |
| `option_c` | Boş olabilir |
| `option_d` | Boş olabilir; `option_c` boşken dolu olamaz |
| `correct_option` | Yalnız `A`, `B`, `C` veya `D`. Metinden tahmin edilmez. |
| `explanation` | İsteğe bağlı düz metin |

Domain editörü 2–6 seçenek kabul eder. Bu ilk dilim 2–4 seçenek taşır. Zorluk kolonu yoktur; taslak, soru formundaki varsayılan olan `medium` ile yazılır ve yayımlanmadan önce düzenlenebilir.

Örnek şablon: `/yonetim/sorular/ice-aktar/sablon`. UTF-8 BOM içerir. Örnek satırın kodu `11111111111141118111111111111111` içe aktarılmaz.

## Akış

1. POST + CSRF dosyayı okur. Domain satırı yazılmaz.
2. Önizleme satır durumunu gösterir: Oluşturulacak, Atlanacak, Hatalı, Çakışma.
3. Hatalı veya çakışan satır varken onay formu görünmez.
4. Onay kutusunun metni: “Önizlemeyi kontrol ettim; sorular taslak olarak oluşturulsun.”
5. Onaylı apply aynı sunucu planını tek transaction içinde yazar. Önizlemeden sonra aynı kod oluşmuşsa apply, onaylanan oluşturma listesini transaction içinde yeniden kontrol eder ve bütün importu geri alır. Kısmi satır kalmaz.

Plan `var/question-imports` altındadır, public web kökünde değildir. Tahmin edilemez kimlik, kullanıcıya bağlı sahiplik, içerik özeti, 15 dakika süre, tek kullanımlık apply ve süre sonunda silme vardır. Ham CSV session veya veritabanına yazılmaz. Dosya adı audit’e girmez.

## Idempotency

Doğal anahtar mevcut `questions.code` değeridir. Varsa satır atlanır ve “Mevcut kayıt, güncellenmedi.” denir. İçerik farklı olsa bile güncellenmez. `--update-existing` yoktur.

## Yetki ve SoD

Bağlantı ve endpoint yalnız `AdminAuthorization::canAuthorQuestions` için açıktır: SuperAdmin, Admin, HeadTeacher, ExpertTeacher, Teacher. Moderator, Student, Parent ve kurum yöneticisi 403 alır. Anonim kullanıcı `/giris` sayfasına gider.

Kayıt, oturumdaki aktörün platform taslağıdır. Öğretmen kendi taslağını yayımlayamaz; mevcut ayrım durur.

## Sınırlar

- Yalnız `.csv`. MIME tek başına güven kaynağı değildir.
- En fazla 1 MiB ve 200 veri satırı.
- UTF-8 veya UTF-8 BOM. NUL bayt, geçersiz UTF-8, ZIP, XLSX ve HTML reddedilir.
- Virgül veya noktalı virgül; ikisi birden başlığa uyuyorsa dosya reddedilir.
- CRLF veya LF. Boş satırlar atlanır.
- Alan başına en fazla 4000 karakter. HTML, `<`, `>` ve `javascript:` reddedilir.
- `=` `+` `-` `@` ile başlayan soru kodu canonical koda uymaz. Metin hücreleri değiştirilmez; hata tablosu HTML’dir.

## Hata metinleri

Dosya: boş dosya, geçersiz UTF-8, boş bayt, ikili dosya, 1 MiB, başlık, ayırıcı, 200 satır, veri satırı yok.

Satır: kod zorunlu / geçersiz / örnek satır / dosyada yineleniyor; sınıf; ders kodu zorunlu, geçersiz, bulunamadı, aktif değil; kazanım zorunlu, geçersiz, bulunamadı, aktif değil, ders ve sınıfla eşleşmiyor, belirsiz; soru kökü; alan uzunluğu; HTML; seçenek sayısı, boşluk, tekrar; doğru seçenek.

Apply mesajları soru kökü veya seçenek içermez.

## Audit

Başarılı apply tek `questions_bulk_imported` olayı yazar: `import_id`, `created_count`, `skipped_count`, `row_count`, `reason_code`, `source`. Soru kökü, seçenek, doğru cevap, açıklama, dosya adı ve dosya içeriği yazılmaz.

`QuestionManager` her yeni soru için mevcut `question_created` olayını da yazar. Bu, canonical yazma yolunun zorunlu auditidir.

## Excel

1. Dosya → Farklı Kaydet → CSV UTF-8 (virgülle ayrılmış).
2. Türkçe karakterler için şablonu indirip onun üzerine yazın.
3. Örnek satırı silin.
4. Önizlemeyi kontrol etmeden onaylamayın.

## Performans

Önizleme, dosyadaki ders, kazanım ve soru kodlarını üç ayrı toplu sorguda okur. Satır başına sorgu yoktur. Apply, doğrulanan her yeni soruyu mevcut `QuestionManager` üzerinden yazar; bu yazılar satır başınadır ve tek transaction içindedir.
