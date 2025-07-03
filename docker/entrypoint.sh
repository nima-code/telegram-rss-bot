#!/bin/sh
set -e

# تنظیم پرمیشن‌ها در زمان اجرا
mkdir -p /app/storage/app /app/storage/logs /app/bootstrap/cache
chown -R www-data:www-data /app /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache
chmod -R 777 /app/storage/logs

# بررسی دسترسی‌های نوشتن
if [ ! -w /app/storage ]; then
    echo "ERROR: /app/storage is not writable"
    exit 1
fi
if [ ! -w /app/storage/logs ]; then
    echo "ERROR: /app/storage/logs is not writable"
    exit 1
fi

# اجرای supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf