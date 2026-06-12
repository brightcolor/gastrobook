#!/usr/bin/env bash
#
# GastroBook Quick-Install
#
#   curl -fsSL https://raw.githubusercontent.com/brightcolor/gastrobook/main/install.sh | bash
#
# Bei privatem Repo/Image vorher:
#   export GITHUB_TOKEN=<PAT mit repo + read:packages>
#   curl -fsSL -H "Authorization: token $GITHUB_TOKEN" https://raw.githubusercontent.com/brightcolor/gastrobook/main/install.sh | bash
#
# Lädt Compose-Dateien, generiert APP_KEY, findet automatisch einen freien
# Port und startet den Stack mit dem fertigen Image von ghcr.io.
set -euo pipefail

REPO="brightcolor/gastrobook"
BRANCH="main"
RAW="https://raw.githubusercontent.com/${REPO}/${BRANCH}"
DIR="${GASTROBOOK_DIR:-gastrobook}"

say()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFehler:\033[0m %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || fail "Docker ist nicht installiert (https://docs.docker.com/engine/install/)."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 wird benötigt ('docker compose')."

fetch() { # fetch <pfad> <ziel>
    local auth=()
    [ -n "${GITHUB_TOKEN:-}" ] && auth=(-H "Authorization: token ${GITHUB_TOKEN}")
    curl -fsSL "${auth[@]}" "${RAW}/$1" -o "$2" \
        || fail "Konnte $1 nicht laden. Privates Repo? GITHUB_TOKEN setzen (siehe Kopf dieses Skripts)."
}

port_free() { ! (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

find_port() { # find_port <startport>
    local p=$1
    while ! port_free "$p"; do p=$((p + 1)); done
    echo "$p"
}

say "Installiere nach ./${DIR}"
mkdir -p "${DIR}/docker"
cd "${DIR}"

fetch docker-compose.yml docker-compose.yml
fetch docker/nginx.conf docker/nginx.conf

# Bind mounts: Laravel-Storage-Struktur anlegen, Container (www-data) braucht Schreibrechte
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs docker/data/public
chmod -R 777 storage docker/data/public 2>/dev/null || true

if [ -f .env ]; then
    say ".env existiert bereits – bleibt unverändert."
else
    fetch .env.example .env

    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    APP_PORT="$(find_port 8080)"
    MAIL_PORT="$(find_port 8025)"

    sed -i.bak \
        -e "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" \
        -e "s|^APP_URL=.*|APP_URL=http://localhost:${APP_PORT}|" \
        .env && rm -f .env.bak
    {
        echo ""
        echo "# Von install.sh automatisch gewählte freie Host-Ports"
        echo "GASTROBOOK_PORT=${APP_PORT}"
        echo "MAILPIT_PORT=${MAIL_PORT}"
    } >> .env

    say "APP_KEY generiert, freie Ports gewählt: App ${APP_PORT}, Mailpit ${MAIL_PORT}"
fi

APP_PORT="$(grep -E '^GASTROBOOK_PORT=' .env | cut -d= -f2)"
APP_PORT="${APP_PORT:-8080}"

say "Ziehe Image von ghcr.io …"
if ! docker compose pull; then
    [ -n "${GITHUB_TOKEN:-}" ] && echo "${GITHUB_TOKEN}" | docker login ghcr.io -u token --password-stdin \
        || fail "Pull fehlgeschlagen. Bei privatem Image: 'docker login ghcr.io' (Token mit read:packages) und Skript erneut ausführen."
    docker compose pull
fi

say "Starte Stack (Migrationen + Tarife laufen automatisch) …"
docker compose up -d

say "Fertig! GastroBook läuft auf http://localhost:${APP_PORT}"
echo "    Registrierung:  http://localhost:${APP_PORT}/register"
echo "    Mailpit (Mails): http://localhost:$(grep -E '^MAILPIT_PORT=' .env | cut -d= -f2)"
echo "    Demodaten (optional): docker compose exec app php artisan db:seed"
