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

# ایجاد دایرکتوری برای supervisord
RUN mkdir -p /etc/supervisor/conf.d

# کپی فایل‌های تنظیمات
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY entrypoint.sh /entrypoint.sh

# تنظیم پرمیشن برای entrypoint
RUN chmod +x /entrypoint.sh

# باز کردن پورت 80
EXPOSE 80

# اجرای entrypoint
CMD ["/entrypoint.sh"]