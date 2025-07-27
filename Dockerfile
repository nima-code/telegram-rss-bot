FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    nginx \
    libxml2-dev \
    oniguruma-dev \
    curl \
    supervisor \
    && docker-php-ext-install mbstring xml simplexml dom \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && curl -o /etc/ssl/certs/cacert.pem https://curl.se/ca/cacert.pem

WORKDIR /app
COPY . /app
RUN composer require guzzlehttp/guzzle && composer install --no-dev --optimize-autoloader || { echo "Composer install failed"; exit 1; }

RUN mkdir -p /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage /app/storage/logs /app/storage/feeds \
    && chown -R www-data:www-data /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage /app/storage/logs /app/storage/feeds \
    && chmod -R 775 /app/public /app/routes /etc/nginx /var/log/nginx /var/log/supervisor /var/run /app/storage /app/storage/logs /app/storage/feeds \
    && touch /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && chown www-data:www-data /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && chmod 664 /app/storage/logs/lumen-$(date +%Y-%m-%d).log \
    && echo "Creating feeds directory and setting permissions" \
    && mkdir -p /app/storage/feeds \
    && chown -R www-data:www-data /app/storage/feeds \
    && chmod -R 775 /app/storage/feeds \
    && ls -la /app/storage /app/storage/feeds || { echo "Directory creation failed"; exit 1; } \
    && echo "Checking storage permissions" \
    && touch /app/storage/feeds/test.json && rm /app/storage/feeds/test.json || { echo "Storage write test failed"; exit 1; } \
    && (test -f /app/.env || { echo "Creating default .env"; echo -e "APP_NAME=LumenRSSBot\nAPP_ENV=local\nAPP_KEY=$(php -r 'echo base64_encode(random_bytes(32));')\nAPP_DEBUG=true\nTELEGRAM_BOT_TOKEN=7648771268:AAE4Hxioz8tTod0vmm8ajN4Pdz4ikQ0ktbg\nTELEGRAM_MODE=webhook" > /app/.env; }) \
    && test -f /app/public/index.php || { echo "index.php not found"; exit 1; } \
    && test -f /app/vendor/autoload.php || { echo "vendor/autoload.php not found"; exit 1; } \
    && echo "Checking nginx config" && nginx -t || { echo "nginx config test failed"; exit 1; }

COPY ./nginx.conf /etc/nginx/nginx.conf
COPY ./supervisord.conf /etc/supervisord.conf

EXPOSE 80
CMD ["/bin/sh", "-c", "touch /app/storage/logs/lumen-$(date +%Y-%m-%d).log && chown www-data:www-data /app/storage/logs/lumen-$(date +%Y-%m-%d).log && chmod 664 /app/storage/logs/lumen-$(date +%Y-%m-%d).log && touch /app/storage/feeds/sent_${TELEGRAM_CHAT_ID:-1428476584}.json && chown www-data:www-data /app/storage/feeds/sent_${TELEGRAM_CHAT_ID:-1428476584}.json && chmod 664 /app/storage/feeds/sent_${TELEGRAM_CHAT_ID:-1428476584}.json && /usr/bin/supervisord -c /etc/supervisord.conf"]