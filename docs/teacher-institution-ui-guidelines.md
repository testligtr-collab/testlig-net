# Öğretmen ve kurum arayüzü

Bu kabuk içerik çalışma alanı ile kurum çalışma alanı içindir. Yönetim stylesheet’i bu sayfalara yüklenmez. Öğrenci ve veli kabuğu aynı kalır.

## İki panel

Öğretmen yüzeyi üretim içindir: içerik, soru, test ve atandığı sınıflar. Kurum yüzeyi sınıf, öğretmen, öğrenci ve kurum testi içindir. İkisi `workspace.css` tokenlarını paylaşır. Menüleri ve yetki kaynakları ayrıdır.

## Rol etiketi

`WorkspaceRoleLabels` sabit Türkçe karşılık verir: Öğretmen, Uzman Öğretmen, Baş Öğretmen, Moderatör. Kurum rolü mevcut `InstitutionWorkspaceGate::roleLabel` metnidir. Metin kullanıcı girdisinden sınıf veya ikon üretmez.

## Bileşenler

`workspace-page`, `workspace-header`, `workspace-context`, `workspace-metric`, `workspace-card`, `workspace-footer`. İçerik çalışma alanı mevcut panel kabuğunu `is-workspace` ile kullanır. Menü yine `AdminNavBuilder` çıktısıdır. Şablon yeni `ROLE_*` kontrolü kurmaz.

## Eşikler

- 360 px: tek kolon, çekmece veya mobil menü
- 768 px: metrik ve filtrelerde iki kolon
- 1280 px: metriklerde üç kolon, okuma alanı en fazla 72rem
- Tablo kaydırması yalnız `.table-scroll` veya `.admin-table-wrap` içindedir

## Lifecycle ve SoD

Yazar kendi sürümünü yayımlamaz. Arayüz mevcut voter’ın vermediği yayım düğmesini göstermez. Mühürlü sürüm salt okunurdur. Domain enum değerleri değişmez.

## Kurum kapsamı

Üyelik olmadan `/kurum` açılmaz. Başka kurum kaydı opak 404’tür. Kurum seçimi POST ve CSRF ile kalır. Referans HTML’de UUID olarak basılmaz.

## Davet

E-posta ve not forma yazılır. Sonuç mesajı hesap varlığını ayırmaz. Token ve digest görünmez. Öğretmen daveti 72 saat ve tek kullanımlıktır.

## Yeni ekran

1. Öğretmen sınıf yüzeyi `teacher/layout`, kurum yüzeyi `institution/layout` uzatır. İçerik sayfası admin layout’ta kalır ve `workspace_shell` ile `workspace.css` alır.
2. `admin.css` ekleme.
3. Yazma POST, CSRF ve mevcut voter ile kalır.
4. E-posta, UUID, storage key, ciphertext ve davet tokenini listeye koyma.
5. `docs/browser-acceptance.md` listesine sentetik görüntüyü ekle.

## Çalışma alanı özeti

`/calisma-alani` (`app_workspace_dashboard`) öğretmen ve içerik ekibinin köküdür. `/yonetim` (`app_admin_dashboard`) yalnız Admin ve SuperAdmin yönetim özetidir. İkisi birbirinin aliasi veya yönlendirmesi değildir. Teacher `/yonetim` üzerinde 403 kalır. Admin ve SuperAdmin yönetim menüsündeki Özet `/yonetim` olarak kalır. Kurum, öğrenci ve veli menüleri değişmez.

Kök seçimi:

- Teacher, Uzman Öğretmen, Baş Öğretmen ve Moderatör: `/calisma-alani`
- Admin ve SuperAdmin: `/yonetim`
- Anonim: `/giris`
- Student, Parent ve yalnız kurum Owner/Manager: 403
- Aktif sınıf öğretmeni ataması olan kullanıcı, global içerik rolü olmasa da yalnız kendi sınıf sayısını görür

Sayaçlar:

- Taslak içerikler yalnız `createdBy` olan draft LearningContent kayıtlarıdır
- İnceleme kuyruğu yalnız review/return yetkisi olan kullanıcılara hesaplanır; Teacher için kart yoktur ve platform toplamı sorgulanmaz
- Sorular ve testler platform kapsamında ve sahibine göredir; kurum testi bu sayaca girmez
- Aktif sınıflar yalnız aktif üyelik, aktif atama ve aktif sınıftır; sonlanmış atama ve başka kurum yoktur

Kart sayısı artsa da sorgu sayısı artmaz. Sayılar tekil COUNT veya `GROUP BY status` ile, listeler en fazla 5 kayıt ve `updatedAt DESC, id DESC` ile gelir. `WorkspaceDashboardControllerTest` sorgu sayısının ek satırda sabit kaldığını doğrular.

Yeni kart eklemeden önce:

1. Yetki, mevcut `AdminAuthorization` metodundan gelsin
2. Sahip kapsamı ile yetki kapsamını ayır
3. Yetkisiz toplamı sorgulama
4. Twig entity ve `ROLE_*` kararı almasın
5. Bağlantı yalnız mevcut liste route’u olsun
6. Sahte artış, yüzde veya grafik ekleme
7. Sorgu sayısı kart sayısıyla artmasın
8. E-posta, UUID, storage key ve cevap gösterme
