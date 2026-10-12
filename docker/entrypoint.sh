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
# BEGIN create-admin
# Der Start bricht an diesem Schritt nie ab (set -e): Ausgabe und Ergebnis des Befehls werden ausdruecklich gelesen.
# Ist ein Oberadmin vorhanden, schweigt der Befehl und endet mit 0. Ein Fehler (zum Beispiel ein zu schwaches
# Passwort oder eine vergebene Adresse) steht mit seiner Ursache im Protokoll des Containers.
admin_status=0
admin_output=$(php artisan swayy:create-admin --if-missing 2>&1) || admin_status=$?
if [ "$admin_status" -eq 0 ]; then
    if [ -n "$admin_output" ]; then
        echo "$admin_output"
    fi
else
    echo "[swayy] Der Oberadmin konnte nicht angelegt werden (Ergebnis $admin_status). Der Befehl meldet:"
    echo "$admin_output" | tail -n 20
    if [ "$admin_status" -eq 1 ]; then
        # Ergebnis 1 meldet der Befehl bei einer abgelehnten Eingabe; Laravel meldet 1 auch bei einer abgefangenen Ausnahme.
        # Die Zeilen oben nennen die Ursache, der Hinweis gilt für den Fall der abgelehnten Eingabe.
        echo "[swayy] Der Start läuft weiter. Falls die Ausgabe oben eine abgelehnte Eingabe nennt, bitte SWAYY_ADMIN_EMAIL und SWAYY_ADMIN_PASSWORD in der .env prüfen"
        echo "[swayy] (Passwort mit Buchstaben und Ziffern in der Mindestlänge von SWAYY_PASSWORD_MIN_LENGTH, Adresse noch nicht vergeben)"
        echo "[swayy] und den Oberadmin danach mit \"php artisan swayy:create-admin\" anlegen."
    else
        echo "[swayy] Der Befehl ist unerwartet gescheitert; die Zeilen oben nennen die Ursache. Der Start läuft weiter."
        echo "[swayy] Bitte das Protokoll des Containers prüfen. Bleibt die Ursache offen, bitte den Support fragen."
    fi
fi
# END create-admin
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
