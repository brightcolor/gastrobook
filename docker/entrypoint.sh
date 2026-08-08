#!/bin/sh
set -e

# APP_URL ist keine Kosmetik: Daraus entstehen alle Links in Mails, der
# Zahlungsruecksprung und die erlaubten Host-Header. Steht dort im Echtbetrieb
# localhost, zeigen alle Links ins Leere - und bis 1.109.1 endete jeder Aufruf
# unter der echten Domain in 400. Deshalb hier laut und frueh.
case "${APP_ENV}:${APP_URL}" in
    production:*localhost*|production:*127.0.0.1*|production:)
        echo "[swayy] ============================================================"
        echo "[swayy] WARNUNG: APP_ENV=production, aber APP_URL='${APP_URL}'."
        echo "[swayy] Links in Mails, Magic-Links und der Zahlungsruecksprung"
        echo "[swayy] zeigen damit auf localhost und funktionieren fuer Gaeste"
        echo "[swayy] nicht. Bitte APP_URL auf die oeffentliche Adresse setzen,"
        echo "[swayy] z. B. APP_URL=https://buchung.example.com"
        echo "[swayy] ============================================================"
        ;;
esac

echo "[swayy] Copying assets to nginx volume..."
cp -a /var/www/html/public/. /public-export/

echo "[swayy] Running database migrations..."
php artisan migrate --force

# Non-critical setup: allowed to fail after the first successful run
echo "[swayy] Running seeders (non-fatal)..."
php artisan db:seed --class=PlanSeeder --force  || echo "[swayy] PlanSeeder already up-to-date"
php artisan swayy:create-admin --if-missing      || echo "[swayy] Admin already exists"
php artisan swayy:install-legal                  || echo "[swayy] Legal pages already installed"

echo "[swayy] Clearing stale caches (storage/ is a persistent volume and can still hold compiled views/config/routes from a previous image)..."
php artisan view:clear
php artisan config:clear
php artisan route:clear

echo "[swayy] Warming caches..."
php artisan config:cache
php artisan route:cache

echo "[swayy] Handing off to PHP-FPM..."
exec php-fpm
