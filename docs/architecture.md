# Testlig — Mimari kararlar (Aşama 1)

## Yaklaşım

- **Modüler monolit:** Tek Symfony uygulaması; iş yetenekleri sonraki aşamalarda sınırlı bağlamlar / modüller olarak eklenecek.
- **Mikroservis yok:** Bu aşamada ve yakın vadede servis ayrıştırması yapılmayacak.

## İstemciler

- Symfony hem **web** hem **mobil API** için ortak backend olacaktır.
- Mobil uygulama ileride **Flutter** ile geliştirilecektir.
- Bu aşamada API kaynakları veya mobil istemci yoktur.

## Veri ve altyapı

- **MariaDB 10.11** birincil ilişkisel veritabanıdır.
- **Doctrine ORM** ve **Doctrine Migrations** şema yönetimini sağlar.
- **Redis** önbellek ve Messenger transport’u için hazırlanmıştır.
- **Symfony Messenger** asenkron işler için kullanılır (şimdilik sync/in-memory varsayılanları).

## Sonraki aşamalar

İş modülleri (kullanıcı / kimlik, müfredat, soru bankası, sınav, ödeme, paneller vb.) Aşama 1 tamamlandıktan sonra ayrı görevlerle eklenecektir.
