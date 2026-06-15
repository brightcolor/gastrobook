#!/bin/sh
set -e

echo "[swayy] Copying assets to nginx volume..."
cp -a /var/www/html/public/. /public-export/

echo "[swayy] Running database migrations..."
php artisan migrate --force

# Non-critical setup: allowed to fail after the first successful run
echo "[swayy] Running seeders (non-fatal)..."
php artisan db:seed --class=PlanSeeder --force  || echo "[swayy] PlanSeeder already up-to-date"
php artisan swayy:create-admin --if-missing      || echo "[swayy] Admin already exists"
php artisan swayy:install-legal                  || echo "[swayy] Legal pages already installed"

echo "[swayy] Warming caches..."
php artisan config:cache
php artisan route:cache

echo "[swayy] Handing off to PHP-FPM..."
exec php-fpm
