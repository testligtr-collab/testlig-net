<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Planned admin section entries. These are not completed product flows.
 *
 * @phpstan-type Section array{
 *     key: string,
 *     id: string,
 *     label: string,
 *     group: string,
 *     purpose: string,
 *     current: list<string>,
 *     missing: list<string>,
 *     next: string,
 *     related_route: string|null,
 *     related_label: string|null
 * }
 */
final class AdminSectionCatalog
{
    /**
     * @return list<Section>
     */
    public static function all(): array
    {
        return [
            self::entry('rol-izinleri', 'role_permissions', 'Rol ve modül izinleri', 'people', 'Hangi rolün hangi yönetim bölümünü kullanacağını görmek.', ['İzinler kodda tanımlıdır ve her yönetim adresinde yeniden kontrol edilir.'], ['Rol ve modül izinlerini yöneten bir ekran yoktur.'], 'İzin matrisi, mevcut kapıları gevşetmeden okunur hale getirilecek.'),
            self::entry('subeler', 'branches', 'Şube ve kampüsler', 'campus', 'Bir kurumun şube ve kampüslerini yönetmek.', ['Kurum kaydı ve kurum üyeliği mevcuttur.'], ['Şube oluşturma, bağlama ve şube yetkisi yoktur.'], 'Şube ve kampüs işlemleri kurum kaydına bağlanacak.'),
            self::entry('akademik-yillar', 'academic_years', 'Akademik yıllar', 'campus', 'Akademik yılı kurumdan bağımsız bir listede yönetmek.', ['Akademik yıl, kurum ekranının içindeki mevcut akışta açılır.'], ['Platform geneli yıl listesi ve yıl kapatma bu adreste yoktur.'], 'Yıl işlemleri kurum ekranındaki mevcut akış korunarak genişletilecek.', 'app_admin_institutions', 'Kurumlar'),
            self::entry('siniflar', 'classes', 'Sınıflar', 'campus', 'Platformdaki sınıfları yönetmek.', ['Ataması olan kullanıcı Sınıflarım bağlantısından kendi sınıflarını görür.'], ['Platform geneli sınıf listesi, sınıf oluşturma ve öğrenci atama bu adreste yoktur.'], 'Sınıf yönetimi kurum ve öğretmen atamasındaki mevcut kurallarla eklenecek.'),
            self::entry('odevler', 'assignments', 'Ödev ve projeler', 'learning', 'Ödev ve proje vermek, teslim almak ve değerlendirmek.', ['Test ve öğrenme içeriği ayrı adreslerde yönetilir.'], ['Ödev, proje, teslim ve değerlendirme yoktur.'], 'Ödev akışı test ve içerik yayınından ayrı kurulacak.'),
            self::entry('raporlar', 'reports', 'Raporlar', 'learning', 'Yönetim için sonuç ve kullanım raporlarını görmek.', ['Bir testin sonuçları, o testin kendi ekranında açılır.'], ['Yönetim rapor kataloğu ve dışa aktarma bu adreste yoktur.'], 'Raporlar mevcut test sonucu akışına bağlanacak.', 'app_admin_tests', 'Test ve sınavlar'),
            self::entry('medya', 'storage', 'Medya ve depolama', 'media', 'Dosya envanterini ve depolama durumunu görmek.', ['Ders içeriğindeki PDF saklama mevcuttur.'], ['Medya envanteri, kota ve dosya yaşam döngüsü yoktur.', 'Haber görseli bu depoya bağlı değildir.'], 'Depolama yönetimi, mevcut PDF saklama bozulmadan eklenecek.'),
            self::entry('dokumanlar', 'documents', 'Dokümanlar', 'media', 'Bağımsız doküman kütüphanesini yönetmek.', ['Doküman, ders içeriğinin içindeki PDF blok olarak durur.'], ['Ayrı doküman kataloğu, inceleme kuyruğu ve yayın yoktur.'], 'Doküman kütüphanesi ders içeriğindeki PDF akışından ayrılacak.'),
            self::entry('videolar', 'videos', 'Videolar', 'media', 'Video kütüphanesini yönetmek.', ['YouTube ve Vimeo bağlantısı ders içeriğinin içinde kullanılabilir.'], ['Video kataloğu, dosya videosu ve yayın kuyruğu yoktur.'], 'Video kütüphanesi mevcut ders videosu akışını bozmadan eklenecek.'),
            self::entry('simulasyonlar', 'simulations', 'Simülasyonlar', 'interactive', 'Etkileşimli simülasyonları yayınlamak.', [], ['Simülasyon kataloğu, yayın ve öğrenci etkinliği yoktur.'], 'Simülasyonlar güvenli bir içerik modeli kurulunca eklenecek.'),
            self::entry('oyunlar', 'games', 'Eğitsel oyunlar', 'interactive', 'Eğitsel oyunları kataloglamak ve skoru tutmak.', [], ['Oyun kataloğu, çalıştırma ve skor yoktur.'], 'Oyunlar oyunlaştırmadan ayrı bir katalog olarak eklenecek.'),
            self::entry('canli-dersler', 'live_lessons', 'Canlı dersler', 'interactive', 'Canlı ders planlamak ve katılımı görmek.', [], ['Canlı ders, katılım ve kayıt yoktur.'], 'Canlı ders takvimi ve katılım kuralları sonra eklenecek.'),
            self::entry('soru-cevap', 'qna', 'Soru-cevap', 'interactive', 'Öğrenci sorusunu ve uzman cevabını yönetmek.', [], ['Soru sorma, cevap, moderasyon ve gizleme yoktur.'], 'Soru-cevap, kötüye kullanım kurallarıyla eklenecek.'),
            self::entry('rehberlik', 'guidance', 'Rehberlik', 'interactive', 'Rehberlik görüşmelerini ve notlarını yönetmek.', [], ['Rehberlik kaydı, görüşme ve gizlilik akışı yoktur.'], 'Rehberlik, öğrenci verisi kuralları netleşince eklenecek.'),
            self::entry('oyunlastirma', 'gamification', 'Oyunlaştırma', 'interactive', 'Puan, rozet ve ilerleme kurallarını yönetmek.', [], ['Puan, rozet ve liderlik yoktur.'], 'Oyunlaştırma, eğitsel oyun kataloğundan ayrı ele alınacak.'),
            self::entry('mesajlar', 'messages', 'Mesajlar', 'communication', 'İzinli mesajlaşmayı yönetmek.', [], ['Konuşma, kurum sınırı ve okundu durumu yoktur.'], 'Mesajlaşma destek talebinden ayrı kurulacak.'),
            self::entry('bildirimler', 'notifications', 'Bildirimler', 'communication', 'Bildirimleri ve tercihleri yönetmek.', [], ['Bildirim listesi, okundu durumu ve kanal tercihi yoktur.'], 'Bildirimler olay kaydından üretilecek.'),
            self::entry('destek', 'support', 'Destek talepleri', 'communication', 'Destek talebini cevaplamak ve spam’i ayırmak.', [], ['Talep, cevap, durum ve spam yönetimi yoktur.'], 'Destek formu ve talep kuyruğu eklenecek.'),
            self::entry('sayfalar', 'pages', 'Sayfalar', 'site', 'Site sayfalarını oluşturmak ve düzenlemek.', ['Yasal sayfalar kodda sabittir.'], ['Yönetilen sayfa oluşturma ve yayın yoktur.'], 'Sayfa yönetimi yasal metinlerin yerine geçmeden eklenecek.'),
            self::entry('ana-sayfa', 'home_sections', 'Ana sayfa bölümleri', 'site', 'Ana sayfadaki bölümleri yönetmek.', ['Haber bandı yayındaki haberleri gösterir. Başka bölümler sabittir.'], ['Bölüm sıralama, gizleme ve içerik bağlama yoktur.'], 'Ana sayfa bölümleri haber bandındaki mevcut davranışı koruyarak yönetilecek.'),
            self::entry('menu-footer', 'menu_footer', 'Menü ve footer', 'site', 'Üst menüyü ve alt bilgiyi yönetmek.', ['Menü ve alt bilgi şablonda sabittir.'], ['Bağlantı ekleme, sıralama ve gizleme yoktur.'], 'Menü ve footer yönetimi sabit yasal bağlantıları düşürmeden eklenecek.'),
            self::entry('seo', 'seo', 'SEO', 'site', 'İçerik başına arama bilgilerini yönetmek.', ['Site haritası ve robots dosyası mevcuttur.'], ['İçerik başına başlık, açıklama ve yapılandırılmış veri ekranı yoktur.'], 'SEO yönetimi mevcut site haritası kurallarını genişleterek eklenecek.'),
            self::entry('trafik', 'traffic_ads', 'Trafik ve reklam', 'site', 'Site trafiğini ve reklam alanlarını yönetmek.', [], ['Ziyaret ölçümü ve reklam alanı yoktur. Çocuk mahremiyeti kararı açıktır.'], 'Trafik ve reklam, mahremiyet kararı verilmeden açılmayacak.'),
            self::entry('paketler', 'packages', 'Paketler ve teklifler', 'commerce', 'Satış paketlerini ve teklifleri yönetmek.', ['Ödeme denemeleri ayrı adreste izlenir.'], ['Paket, teklif ve fiyat ekranı yoktur.'], 'Paketler mevcut ödeme kaydının yerine geçmeden eklenecek.', 'app_admin_payments', 'Ödemeler'),
            self::entry('siparisler', 'orders', 'Siparişler', 'commerce', 'Siparişleri görmek ve durumunu izlemek.', ['Ödeme denemesi ayrı adreste durur.'], ['Sipariş listesi ve sipariş durumu yoktur.'], 'Siparişler ödeme denemesiyle karıştırılmadan eklenecek.', 'app_admin_payments', 'Ödemeler'),
            self::entry('kuponlar', 'coupons', 'Kupon ve kampanyalar', 'commerce', 'Kupon ve kampanya tanımlamak.', [], ['Kupon, kampanya ve indirim uygulaması yoktur.'], 'Kuponlar ödeme tutarını değiştiren kurallarla eklenecek.'),
            self::entry('sms', 'sms', 'SMS', 'integrations', 'SMS gönderimini ve sağlayıcı durumunu görmek.', [], ['SMS sağlayıcısı, şablon ve gönderim kaydı yoktur.'], 'SMS, mevcut e-posta doğrulamasının yerine geçmeden değerlendirilecek.'),
            self::entry('yapay-zeka', 'ai', 'AI', 'integrations', 'Yapay zeka sağlayıcısını ve kullanım sınırını yönetmek.', [], ['Sağlayıcı, kota ve üretim akışı yoktur.'], 'AI, uzman onayı olmadan içerik yayınlamayacak.'),
            self::entry('kimlik', 'identity_providers', 'Kimlik sağlayıcıları', 'integrations', 'Harici kimlik sağlayıcılarını yönetmek.', ['E-posta ve parola ile giriş mevcuttur.'], ['Harici sağlayıcı, misafir giriş ve katılım kodu yoktur.'], 'Kimlik sağlayıcıları mevcut e-posta girişini değiştirmeden eklenecek.'),
            self::entry('mobil-api', 'mobile_api', 'Mobil API durumu', 'integrations', 'Mobil API ve cihaz oturumunun durumunu görmek.', [], ['Mobil API, yenileme jetonu ve cihaz kaydı yoktur.'], 'Mobil API ortak sözleşme netleşince eklenecek.'),
            self::entry('operasyon', 'operations', 'Yedekleme ve operasyon durumu', 'system', 'Yedekleme ve operasyon durumunu görmek.', ['Sistem durumu ekranı servis özetini gösterir.', 'Denetim kaydı ayrı adrestedir.'], ['Yedekleme sonucu, geri yükleme ve operasyon görevi yoktur.'], 'Yedekleme durumu salt okunur bir özet olarak eklenecek.', 'app_admin_system', 'Sistem durumu'),
        ];
    }

    /**
     * @return Section|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @param list<string> $current
     * @param list<string> $missing
     *
     * @return Section
     */
    private static function entry(
        string $key,
        string $id,
        string $label,
        string $group,
        string $purpose,
        array $current,
        array $missing,
        string $next,
        ?string $relatedRoute = null,
        ?string $relatedLabel = null,
    ): array {
        return [
            'key' => $key,
            'id' => $id,
            'label' => $label,
            'group' => $group,
            'purpose' => $purpose,
            'current' => $current,
            'missing' => $missing,
            'next' => $next,
            'related_route' => $relatedRoute,
            'related_label' => $relatedLabel,
        ];
    }
}
