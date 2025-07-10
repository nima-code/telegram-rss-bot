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
RUN composer install --no-dev --optimize-autoloader

# ساخت و تنظیم پرمیشن‌های پوشه‌ها
RUN mkdir -p /app/storage/app /app/bootstrap/cache /etc/nginx/sites-enabled \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache \
    && chown -R www-data:www-data /etc/nginx/sites-enabled \
    && chmod -R 775 /etc/nginx/sites-enabled

# کپی فایل تنظیمات nginx
COPY ./nginx.conf /etc/nginx/sites-enabled/default

# باز کردن پورت 80 برای nginx
EXPOSE 80

# اجرای nginx و php-fpm
CMD php-fpm -D && nginx -g "daemon off;"