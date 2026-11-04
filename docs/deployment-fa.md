# استقرار مرکز کنترل روی سرور

این راهنما می‌گوید مرکز کنترل (`nextbook-control`) را چطور روی یک سرور واقعی بالا بیاوریم، به نکست‌بوک وصلش کنیم، و چه چیزهایی را باید حتماً امن کنیم. پس از هر مرحلهٔ مهم یک دستور هست که درستی کار را می‌سنجد:

```bash
php artisan control:doctor
```

این دستور هر چیزی را که اشتباه تنظیم شده با `FAIL` یا `WARN` می‌نویسد، و اگر `FAIL` باشد با کد خروج ۱ تمام می‌شود (برای اسکریپت استقرار). **هیچ‌وقت استقرار را تمام‌شده ندانید تا این دستور بدون `FAIL` تمام نشود.**

## ۱. چه چیزی لازم است

- سروری که فقط برای همین کار است (یا دست‌کم کنار چیزی که مشتری‌ها به آن دسترسی دارند نباشد): این سیستم کلید امضای مجوزها و فهرست همهٔ مشتری‌ها را دارد.
- Ubuntu 22.04 یا بالاتر، PHP ‏۸.۲ یا بالاتر با افزونه‌های `pgsql`، `sodium`، `bcmath`، `mbstring`، `curl`، `intl`، `xml`، Composer، Node.js ‏۲۰ یا بالاتر (فقط برای ساخت فایل‌های سمت مرورگر)، PostgreSQL ‏۱۴ یا بالاتر، nginx، Supervisor، و یک دامنه با گواهی HTTPS.
- یک سرور ایمیل (SMTP). واتساپ اختیاری است و بعداً هم اضافه می‌شود.

## ۲. دیتابیس

دیتابیس و کاربرِ جدا برای همین برنامه، نه کاربر `postgres`:

```bash
sudo -u postgres psql -c "CREATE USER nextbook_control WITH PASSWORD '...یک رمز قوی...';"
sudo -u postgres psql -c "CREATE DATABASE nextbook_control OWNER nextbook_control;"
```

دیتابیس را فقط به همین سرور باز بگذارید (`listen_addresses = 'localhost'`).

## ۳. کد

```bash
cd /var/www
git clone <آدرس ریپو> nextbook-control
cd nextbook-control
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.production.example .env
```

بعد `.env` را پر کنید: `APP_URL` (با `https://`)، `DB_PASSWORD`، `MAIL_*`. پس از آن:

```bash
php artisan key:generate
php artisan control:keys            # کلید امضای مجوزها را می‌سازد و در .env می‌نویسد
php artisan migrate --force
php artisan control:staff you@example.com owner --name="نام شما"
```

- `control:keys` **کلید عمومی** را چاپ می‌کند؛ آن را بنویسید، به هر نکست‌بوک داده می‌شود.
- `control:staff` یک رمز قوی می‌سازد و **فقط یک‌بار** نشان می‌دهد. در اولین ورود از شما می‌خواهد تأیید دو مرحله‌ای را راه بیندازید و **کدهای بازیابی را نگه دارید**.

مجوزها و کش را برای سرعت ثابت کنید:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> بعد از `config:cache`، تغییر `.env` تا دوباره `php artisan config:cache` را نزده‌اید اثر نمی‌کند.

## ۳.۱. nginx

(نمونه؛ مسیرها و دامنه را عوض کنید. گواهی را با Let's Encrypt بگیرید.)

```nginx
server {
    listen 443 ssl http2;
    server_name control.example.com;
    root /var/www/nextbook-control/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/control.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/control.example.com/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "same-origin" always;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param HTTPS on;
    }
    # فایل‌های پنهان (.env و ...) هیچ‌وقت از بیرون خوانده نشود
    location ~ /\.(?!well-known) { deny all; }
}
server { listen 80; server_name control.example.com; return 301 https://$host$request_uri; }
```

چون برنامه پشت nginx است، در `.env` بنویسید `TRUSTED_PROXIES=127.0.0.1`. بدون آن، همهٔ بازدیدکننده‌ها در گزارش ممیزی و محدودکنندهٔ ورود «یک نفر» (خود nginx) دیده می‌شوند و لینک‌ها `http://` ساخته می‌شود.

## ۴. صف و زمان‌بند (بدون این دو هیچ پیامی نمی‌رود)

**صف** با Supervisor (`/etc/supervisor/conf.d/nextbook-control.conf`):

```ini
[program:nextbook-control-queue]
command=php /var/www/nextbook-control/artisan queue:work --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/log/nextbook-control-queue.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start nextbook-control-queue
```

**زمان‌بند** با cron (`sudo crontab -u www-data -e`):

```
* * * * * cd /var/www/nextbook-control && php artisan schedule:run >> /dev/null 2>&1
```

یادآوری‌ها هر صبح ساعت ۸ به وقت کابل اجرا می‌شوند. اگر cron کار نکند، `control:doctor` پس از چند دقیقه می‌گوید «زمان‌بند هرگز اجرا نشده».

## ۵. وصل‌کردن یک نکست‌بوک

**در مرکز کنترل** (در مرورگر): مشتری را بسازید، بسته بدهید، در بخش «اتصال به نکست‌بوک» شناسهٔ کمپنی را بنویسید و «ساخت توکن» را بزنید. **توکن فقط یک‌بار نشان داده می‌شود؛ همان لحظه کپی کنید.**

**در نکست‌بوک** (روی سرورش)، در `.env`:

```
CONTROL_URL=https://control.example.com
CONTROL_PUBLIC_KEY=<کلید عمومی که control:keys چاپ کرد>
```

و بعد:

```bash
php artisan config:cache
php artisan license:connect <شناسهٔ کمپنی> <توکن>
php artisan license:pull --company=<شناسهٔ کمپنی>
```

اگر `ok ... Licence version 1 received` نوشت، وصل است. نکست‌بوک خودش هر ۴ ساعت مجوز را تازه می‌کند و هر شب مصرف را می‌فرستد؛ برای این‌ها زمان‌بند (`schedule:run`) در نکست‌بوک هم باید در cron باشد.

## ۶. به‌روزرسانی

```bash
cd /var/www/nextbook-control
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan control:doctor
```

## ۷. پشتیبان‌گیری

دو چیز باید پشتیبان داشته باشد، **جدا از هم و جدا از این سرور**:

1. **دیتابیس**، هر شب: `pg_dump -Fc nextbook_control > backup.dump`. هر چند وقت یک‌بار بازیابی‌اش را آزمایش کنید؛ پشتیبانی که هرگز آزمایش نشده، پشتیبان نیست.
2. **فایل `.env`** (به‌ویژه `LICENSE_PRIVATE_KEY`) در جایی امن و رمزگذاری‌شده.

اگر کلید خصوصی گم شود یا عوض شود (`control:keys --force`)، باید `LICENSE_PUBLIC_KEY` تازه را به **همهٔ** نکست‌بوک‌ها بدهید. تا هر نکست‌بوک کلید تازه و مجوز تازه نگیرد، مجوز قبلی‌اش را «نامعتبر» می‌داند و **نادیده می‌گیرد؛ در آن فاصله محدودیت اعمال نمی‌شود** (مشتری قفل نمی‌شود، ولی محدودیت‌ها هم کار نمی‌کنند). برای همین کلید را عوض نکنید مگر ناچار.

## ساخت کمپنی مشتری از همین سیستم (اختیاری، فقط مالک)

در صفحهٔ هر مشتری، مالک می‌تواند «ساخت کمپنی» را بزند: کمپنی، شعبهٔ اصلی و مدیر اول در نکست‌بوک ساخته و وصل می‌شود. برای روشن‌کردنش:

1. در `.env` این سیستم: `ERP_URL=https://آدرس-نکست‌بوک` (باید https باشد).
2. در `.env` نکست‌بوک: `CONTROL_PROVISIONING=true` (پیش‌فرض خاموش است) و `CONTROL_PUBLIC_KEY` همین سیستم.
3. نکست‌بوک باید ایمیل تنظیم‌شده داشته باشد، چون مدیر اول با «فراموشی رمز» برای خودش رمز می‌گذارد. رمزی از این سیستم رد نمی‌شود.

آدرسی که کمپنی در آن ساخته می‌شود پیش از زدن دکمه روی صفحه نشان داده می‌شود. اگر مشتری آدرس نکست‌بوک اختصاصی دارد همان استفاده می‌شود؛ این آدرس را کسی که «مدیریت مشتری» دارد (فروش هم) می‌تواند عوض کند، پس مالک پیش از تأیید باید آدرس نمایش‌داده‌شده را بخواند.

## ۸. چک‌لیست امنیتی

پیش از اینکه مشتری واقعی وصل شود، همه را تیک بزنید.

**برنامه**
- [ ] `APP_ENV=production` و `APP_DEBUG=false` (صفحهٔ خطا در حالت debug رمزها و کلیدها را نشان می‌دهد).
- [ ] `APP_URL` با `https://` و `SESSION_SECURE_COOKIE=true`.
- [ ] `TRUSTED_PROXIES` درست (فقط آدرس همان proxy، نه `*` مگر سرور فقط از راه proxy در دسترس باشد).
- [ ] `php artisan control:doctor` بدون `FAIL` (و `WARN`ها را فهمیده‌اید).
- [ ] فایل `.env` فقط برای کاربر برنامه خواندنی است (`chmod 600 .env`) و در git نیست.

**کارکنان**
- [ ] هیچ حساب پیش‌فرض یا آزمایشی نیست؛ فقط حساب‌هایی که خودتان با `control:staff` ساختید.
- [ ] برای همه تأیید دو مرحله‌ای روشن است (برنامه آن را اجباری می‌کند) و کدهای بازیابی جای امن نگه داشته شده.
- [ ] نقش‌ها حداقل لازم‌اند: فقط یک یا دو نفر «مالک»؛ فروش، مالی و پشتیبانی فقط نقش خودشان.
- [ ] وقتی کسی از تیم می‌رود، همان روز در «کارکنان» غیرفعال می‌شود (نشست‌هایش فوراً بسته می‌شود).

**سرور**
- [ ] فایروال: فقط ۸۰ و ۴۴۳ و SSH باز (SSH فقط با کلید، بدون ورود با رمز). پورت PostgreSQL از بیرون بسته.
- [ ] PHP-FPM و nginx با کاربر غیر root؛ `storage` و `bootstrap/cache` قابل‌نوشتن، بقیه نه.
- [ ] به‌روزرسانی امنیتی سیستم‌عامل خودکار یا هفتگی.
- [ ] فایل‌های لاگ بزرگ نمی‌شوند (`LOG_STACK=daily`، چرخش لاگ).

**داده**
- [ ] پشتیبان شبانهٔ دیتابیس خارج از سرور، و بازیابی‌اش یک‌بار آزمایش شده.
- [ ] `LICENSE_PRIVATE_KEY` فقط روی همین سرور و در پشتیبان رمزگذاری‌شده است؛ در ایمیل، چت یا git نیست.
- [ ] توکن نمونه‌ها فقط یک‌بار نشان داده می‌شود؛ اگر جایی لو رفت، «ساخت توکن تازه» توکن قبلی را فوراً از کار می‌اندازد.

**ارتباط با مشتری**
- [ ] ایمیل با SMTP واقعی و آدرس فرستندهٔ خودتان؛ `control:doctor` دیگر دربارهٔ `MAIL_MAILER=log` هشدار نمی‌دهد.
- [ ] واتساپ فقط وقتی `WHATSAPP_DRIVER=cloud` شود که ۵ قالب در Meta تأیید شده باشد (`docs/messaging-fa.md`).
- [ ] گیرنده‌ها فقط کسانی هستند که کانال را پذیرفته‌اند (همین‌طور برنامه کار می‌کند؛ چیزی برای تنظیم نیست).

## ۹. اگر مشکلی پیش آمد

| نشانه | دستور / علت |
|---|---|
| پیام‌ها «در انتظار» می‌مانند | `queue:work` اجرا نمی‌شود: `sudo supervisorctl status` |
| یادآوری صبح نرفت | cron نیست یا خراب است: `control:doctor` («زمان‌بند…») |
| نکست‌بوک مجوز نمی‌گیرد | `license:pull --company=...` خطایش را می‌نویسد (توکن، آدرس، کلید عمومی) |
| نکست‌بوک «امضا معتبر نیست» می‌گوید | `CONTROL_PUBLIC_KEY` آن با کلید این سرور یکی نیست: `control:doctor` کلید درست را می‌نویسد |
| همهٔ کاربران در گزارش ممیزی یک IP دارند | `TRUSTED_PROXIES` تنظیم نیست |
| بعد از تغییر `.env` اثری نیست | `php artisan config:cache` را دوباره بزنید |
