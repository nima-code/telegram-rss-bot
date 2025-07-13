FROM php:8.2-fpm-alpine

# نصب وابستگی‌های ضروری
RUN apk add --no-cache \
    nginx \
    libxml2-dev \
    oniguruma-dev \
    curl \
    && docker-php-ext-install mbstring xml simplexml dom \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# تنظیم دایرکتوری کاری
WORKDIR /app

# کپی فایل‌های پروژه
COPY . /app

# نصب وابستگی‌های Composer
RUN composer install --no-dev --optimize-autoloader || { echo "Composer install failed"; exit 1; }

# ساخت و تنظیم پرمیشن‌های پوشه‌ها
RUN mkdir -p /app/public /app/routes /etc/nginx/sites-enabled /var/log/nginx \
    && chown -R www-data:www-data /app/public /app/routes /etc/nginx/sites-enabled /var/log/nginx \
    && chmod -R 775 /app/public /app/routes /etc/nginx/sites-enabled /var/log/nginx \
    && ls -la /app/public /app/routes || { echo "Public or routes directory missing"; exit 1; } \
    && test -f /app/public/index.php || { echo "index.php not found"; exit 1; } \
    && test -f /app/vendor/autoload.php || { echo "vendor/autoload.php not found"; exit 1; } \
    && echo "Checking nginx config" && nginx -t || { echo "nginx config test failed"; exit 1; }

# کپی فایل تنظیمات nginx
COPY ./nginx.conf /etc/nginx/sites-enabled/default

# تنظیم لاگ php-fpm
RUN touch /var/log/php-fpm.log \
    && chown www-data:www-data /var/log/php-fpm.log \
    && chmod 664 /var/log/php-fpm.log

# باز کردن پورت 80 برای nginx
EXPOSE 80

# اجرای nginx و php-fpm در فرمت JSON
CMD ["/bin/sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]