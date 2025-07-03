#!/bin/sh
set -e

# تنظیم پرمیشن‌ها در زمان اجرا
mkdir -p /app/storage/app /app/storage/logs /app/bootstrap/cache
chown -R www-data:www-data /app /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache
chmod -R 777 /app/storage/logs

# اجرای supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf