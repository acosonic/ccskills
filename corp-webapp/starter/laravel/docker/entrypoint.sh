#!/bin/bash
# First run: key, vendor, migrations, storage link, seed (seeding never overwrites existing accounts).
cd /var/www

[ -f .env ] || cp .env.example .env
grep -qE '^APP_KEY=.+' .env || php artisan key:generate --force

mkdir -p storage/app/public storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist --optimize-autoloader

echo "[entrypoint] Waiting for MySQL and running migrations..."
for i in $(seq 1 30); do
    php artisan migrate --force && break
    echo "[entrypoint] DB not ready, retry $i/30"; sleep 3
done

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan db:seed --force || true

# Caches built as root must stay writable by php-fpm (www-data).
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
