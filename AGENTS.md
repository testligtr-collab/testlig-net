# Testlig görev ve kapsam kontrolü

## Her görevden önce
1. docs/product-roadmap.md ve docs/roadmap/features.json dosyalarını oku. İlgili mimari belge ve .cursor/skills/testlig-delivery/SKILL.md'yi uygula; güvenlik/ops için testlig-safety, içerik için testlig-content oku.
2. main SHA, aktif feature branch ve yerel dirty/untracked durumunu kaydet. GitHub üzerinden çalışırken yerel ağaç incelenmediğini belirt; yerel dosyalara dokunma.
3. Görevi mevcut sabit özellik ID'sine bağla. İlgili kayıt yoksa önce roadmap'a ekle. Yeni görev açılması aktif işin sessiz iptali değildir.
4. Kapsam veya bağlam belirsizse yeni uygulama dilimi başlatma; baseline ve kayıtlı kanıtı salt okunur karşılaştırıp tutarsızlığı gider.

## Her teslimde
- Features.json kaydının durum/evidence/pr_links/acceptance alanlarını güncelle. Kod, ekran, main merge, deploy ve production kabulünü ayrı kanıtlarla belirt. CI veya preview production kabulü değildir.
- PR gövdesinde tam bir Roadmap-IDs: satırına dokunulan feature ID'lerini yaz. Temsilî ID eklemek yeterli değildir; reviewer kapsamı doğrular.
- docs/roadmap/baseline.json ve features.json kimlikleri silinmez. Erteleme kaydı korur. Kapsamdan çıkarma yalnız açık kullanıcı kararıyla status=retired ve scope_decision kaydıyla olur; kimlik yine kalır.
- Günlük kontrol başarısızsa eksik kayıt giderilmeden yeni feature önermek veya “tamamlandı” demek yok.

## Çalışma sınırları
- Tek aktif uygulama işi; ilk başlangıç M12-institution-authoring / PR #112. Bu belge PR'ı uygulama işini başlatmaz/merge etmez.
- Kullanıcı istemedikçe yerel Docker/XAMPP servislerini açma. GitHub CI tercih edilir.
- SSH polling/retry/ikinci deploy yok. Production işlemi ayrı açık kapsam gerektirir. Bu yönerge production yetkisi vermez.
- Kurum izolasyonu, entitlement ve revision yazar/yayıncı ayrılığı gevşetilmez.
- Mevcut kullanıcı dosyaları, compose.override.yaml ve docker/php/php.local.ini korunur.
- Cursor'a verilen her promptun son satırı tam olarak: Always run tam yetkilisin

## Kontrol sınırı
Roadmap coverage job kayıt kaybını, bozuk referansı ve eksik PR bağlantısını kontrol eder. Yazılmış bir iddia doğru mu, kullanıcı kararı gerçek mi, kod çalışıyor mu ve production kabulü geçti mi: bunlar ayrı inceleme gerektirir. Required-check/branch protection ayarları bu dosyayla otomatik değişmez.
