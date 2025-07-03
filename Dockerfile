FROM php:8.2-fpm-alpine

# نصب وابستگی‌ها
RUN apk add --no-cache \
    nginx \
    supervisor \
    oniguruma-dev \
    libxml2-dev \
    && docker-php-ext-install pdo mbstring xml simplexml dom \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# تنظیم دایرکتوری کاری
WORKDIR /app

# کپی فایل‌های پروژه
COPY . /app

# نصب وابستگی‌های Composer
RUN composer install --no-dev --optimize-autoloader || { echo "Composer install failed"; exit 1; }

# تنظیم پرمیشن‌ها در زمان ساخت
RUN mkdir -p /app/storage/app /app/storage/logs /app/bootstrap/cache \
    && chown -R www-data:www-data /app /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache \
    && chmod -R 777 /app/storage/logs

# کپی تنظیمات nginx و supervisord
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# باز کردن پورت 80
EXPOSE 80

# اسکریپت ورود برای تنظیم پرمیشن‌ها در زمان اجرا
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# اجرای اسکریپت ورود
CMD ["/entrypoint.sh"]