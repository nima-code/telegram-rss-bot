#!/bin/sh
set -e

# تنظیم پرمیشن‌ها برای دایرکتوری‌های مورد نیاز
mkdir -p /app/storage/app /app/storage/logs /app/bootstrap/cache /tmp
chown -R www-data:www-data /app /app/storage /app/bootstrap/cache /tmp
chmod -R 775 /app/storage /app/bootstrap/cache /tmp
chmod -R 777 /app/storage/logs

# بررسی وجود فایل supervisord.conf
if [ ! -f /etc/supervisor/conf.d/supervisord.conf ]; then
    echo "Error: supervisord.conf not found in /etc/supervisor/conf.d/"
    exit 1
fi

# اجرای supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf