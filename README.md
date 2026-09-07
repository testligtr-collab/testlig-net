# Testlig
#
# Eğitim platformu — Symfony 7.4 LTS tabanlı modüler monolit altyapısı (Aşama 1).

## Amaç

Testlig; web ve ileride Flutter mobil istemcileri için ortak bir eğitim platformu backend’i olacaktır.
Bu depo şu an yalnızca temiz proje altyapısını içerir (iş modülleri sonraki aşamalarda eklenir).

## Gereksinimler

| Bileşen | Hedef |
|---------|--------|
| PHP | 8.3 (Docker imajı) |
| Composer | 2.x |
| Symfony | 7.4 LTS |
| MariaDB | 10.11 |
| Redis | 7.x (Compose) |
| Docker / Compose | Geliştirme için önerilir |

Yerel makinede PHP 8.2 ile sınırlı komutlar çalıştırılabilir; tam hedef çalışma zamanı Docker içindeki PHP 8.3’tür.

## Ortam değişkenleri

```powershell
copy .env.example .env.local
# .env.local içinde APP_SECRET, MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD ve DATABASE_URL değerlerini ayarlayın
```

- `.env` — güvenli geliştirme varsayılanları (commit edilir)
- `.env.example` — açıklamalı şablon
- `.env.local` — gerçek yerel değerler (commit edilmez)
- `.env.test` — PHPUnit için ayrı test yapılandırması

## Docker ile kurulum

```powershell
cd C:\xampp\htdocs\testlig-net
copy .env.example .env.local
# .env.local düzenleyin, ardından:
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php bin/console doctrine:database:create --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

Uygulama: http://localhost:8080  
Sağlık kontrolü: http://localhost:8080/health

Compose, container içinde `DATABASE_URL` / `REDIS_URL` değerlerini Docker DNS adlarıyla (`database`, `redis`) ayarlar. MariaDB host’a yayınlanmaz (XAMPP 3306 çakışmasını önlemek için). Host’taki `.env` içindeki `127.0.0.1` adresleri yalnızca Docker dışı çalıştırma içindir.

Container içinde PHPUnit çalıştırırken `APP_ENV` değerini test’e sabitleyin (Compose `APP_ENV=dev` geçirir):

```powershell
docker compose exec -e APP_ENV=test -e APP_DEBUG=1 app vendor/bin/phpunit
```

İlk volume oluşturmada `testlig_test` veritabanı `docker/mariadb/init` ile kurulur. Volume zaten varsa:

```powershell
docker compose exec database mariadb -uroot -p -e "CREATE DATABASE IF NOT EXISTS testlig_test; GRANT ALL ON testlig_test.* TO 'testlig'@'%';"
```

Durdurma:

```powershell
docker compose down
```

## Docker olmadan kurulum

1. PHP 8.3+, Composer, MariaDB 10.11 ve (isteğe bağlı) Redis kurun.
2. `.env.local` oluşturup `DATABASE_URL` / `REDIS_URL` değerlerini host’a göre ayarlayın.
3. Bağımlılıkları kurun:

```powershell
C:\xampp\php\php.exe composer.phar install
```

4. Veritabanını oluşturun ve migration çalıştırın (aşağıya bakın).
5. Yerel web sunucusu örneği:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t public
```

## Veritabanı ve migration

```powershell
# Composer script (Windows uyumlu)
C:\xampp\php\php.exe composer.phar db:create
C:\xampp\php\php.exe composer.phar migrate
```

veya doğrudan:

```powershell
C:\xampp\php\php.exe bin\console doctrine:database:create --if-not-exists
C:\xampp\php\php.exe bin\console doctrine:migrations:migrate --no-interaction
```

## Testler

```powershell
C:\xampp\php\php.exe composer.phar test
```

## PHPStan ve kod biçimi

```powershell
C:\xampp\php\php.exe composer.phar stan
C:\xampp\php\php.exe composer.phar cs:check
C:\xampp\php\php.exe composer.phar cs:fix
```

## Diğer Composer scriptleri

| Script | Açıklama |
|--------|----------|
| `composer install` / `composer setup` | Bağımlılık kurulumu |
| `composer start` | `docker compose up -d` |
| `composer stop` | `docker compose down` |
| `composer test` | PHPUnit |
| `composer stan` | PHPStan |
| `composer cs:check` / `cs:fix` | PHP-CS-Fixer |
| `composer doctrine:validate` | Schema doğrulama |
| `composer migrate` | Migration çalıştırma |
| `composer cache:clear` | Cache temizleme |

## Sağlık kontrolü

`GET /health` — JSON durum bilgisi (uygulama, ortam, database/redis check).  
Parola, bağlantı dizesi veya sunucu yolu döndürmez.

## Bilinen sınırlamalar (Aşama 1)

- Kullanıcı, rol, soru bankası, sınav, ödeme ve panel modülleri yok.
- JWT / dış servis entegrasyonu yok.
- Production dağıtım yapılandırması yok.
- Yerel Windows ortamında PHP 8.3 ve Docker bulunmayabilir; hedef runtime Docker’daki PHP 8.3’tür.
- `symfony/redis-messenger` paketinin Composer kurulumu için `ext-redis` gerekir (Docker imajında vardır). Yerelde `ext-redis` yoksa paket `--ignore-platform-req=ext-redis` ile kurulmuştur.

## Mimari notlar

Ayrıntılar: [docs/architecture.md](docs/architecture.md)
