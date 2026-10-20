# Admin bölüm uygulama sırası

Bu sıra eksik kullanıcı işlemlerini kaydeder. Menüdeki bölüm girişleri bu işlemleri tamamlamaz. Haber yönetimi metin akışı başladı ve admin kapsamını kapatmaz. Çalışan ekranlar yeniden yazılmaz.

1. M27-news — Haber, kategori ve yayın. Bu dilim: menü, liste, kategori, taslak, inceleme, başka yöneticinin yayını, arşiv, yalnız yayındaki public liste/detay, ana sayfa bandı. Açık: haber görseli. Görsel, M18-cloud-storage ve bekleyen PR #149 ile canlı tarama bağlantısına bağlıdır; metin akışı onu beklemez.
2. M29-cms — Site sayfası oluşturma ve düzenleme, ana sayfa bölümlerini yönetme, menü ve footer düzenleme. Haber bandının yayındaki kayıtlardan beslenmesi bu CMS değildir.
3. M28-seo — İçerik başına başlık, açıklama ve yapılandırılmış veri. Haber sayfalarının site haritası ve dizin izni bu kayıttadır.
4. M19-documents — Doküman kütüphanesinde liste, yükleme, inceleme ve yayın.
5. M20-video-library — Video kütüphanesinde katalog, bağlantı veya dosya ve yayın.
6. M18-cloud-storage — Medya envanteri ve dosya yaşam döngüsü. Kayıt partial kalır. Haber görseli bu bağımlılığın parçasıdır.
7. M21-simulations — Simülasyon kataloğu, yayın ve öğrenci etkinliği.
8. M22-games — Oyun kataloğu, çalıştırma ve skor.
9. M14-live-lessons — Canlı ders planı, katılım ve kayıt.
10. M23-qna — Soru sorma, cevap, moderasyon ve gizleme.
11. M24-messaging — İzinli mesajlaşma ve kurum sınırı.
12. M25-notifications — Bildirim listesi, okundu durumu ve tercih.
13. M26-support — Destek talebi, cevap, durum ve spam.
14. M13-analytics ve M13-export — Yönetim raporları ve dışa aktarma. Mevcut sonuç ekranları durur.
15. M15-coupons ve M15-commerce-core — Paket, kupon ve mevcut ödeme ekranlarında olmayan ticaret işlemleri. Süper yönetici ödeme ekranları durur.
16. M05-branches — Şube ve kampüs oluşturma, bağlama ve yetki.

M05-bank-data ve M31-traffic-ads karar kayıtlarıdır; bu uygulama sırasına alınmaz. M07 okul verisi bağımlılığı, X07 ve X08 ayrı kayıtlardır.
