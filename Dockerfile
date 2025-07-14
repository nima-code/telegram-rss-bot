FROM php:8.2-fpm-alpine

# نصب وابستگی‌های ضروری
RUN apk add --no-cache \
    nginx \
    libxml2-dev \
    oniguruma-dev \
    curl \
    supervisor \
    && docker-php-ext-install mbstring xml simplexml dom \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# تنظیم دایرکتوری کاری
WORKDIR /app

# کپی فایل‌های پروژه
COPY . /app

# نصب وابستگی‌های Composer
RUN composer install --no-dev --optimize-autoloader || { echo "Composer install failed"; exit 1; }

# ساخت و تنظیم پرمیشن‌های پوشه‌ها
RUN mkdir -p /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage /app/storage/logs \
    && chown -R www-data:www-data /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage \
    && chmod -R 775 /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage \
    && touch /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && chown www-data:www-data /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && chmod 664 /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && ls -la /app/public /app/routes /app/storage || { echo "Directory missing"; exit 1; } \
    && (test -f /app/.env || { echo "Creating default .env"; echo -e "APP_NAME=LumenRSSBot\nAPP_ENV=local\nAPP_KEY=$(php -r 'echo base64_encode(random_bytes(32));')\nAPP_DEBUG=true\nTELEGRAM_BOT_TOKEN=7648771268:AAE4Hxioz8tTod0vmm8ajN4Pdz4ikQ0ktbg\nTELEGRAM_MODE=webhook" > /app/.env; }) \
    && test -f /app/public/index.php || { echo "index.php not found"; exit 1; } \
    && test -f /app/vendor/autoload.php || { echo "vendor/autoload.php not found"; exit 1; } \
    && echo "Checking nginx config" && nginx -t || { echo "nginx config test failed"; exit 1; }

# کپی فایل‌های تنظیمات
COPY ./nginx.conf /etc/nginx/nginx.conf
COPY ./supervisord.conf /etc/supervisord.conf

# باز کردن پورت 80 برای nginx
EXPOSE 80

# اجرای supervisor
CMD ["/bin/sh", "-c", "cat /app/storage/logs/lumen-*.log; /usr/bin/supervisord -c /etc/supervisord.conf"]