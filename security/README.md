# Lokaler Sicherheitstest

Ein reproduzierbares Kit, um gastrobook **lokal** gegen eine Wegwerf-Instanz zu
prüfen: externe Scanner (nuclei, ZAP) plus app-genaue Prüf-Skripte für
Autorisierung, Mandantentrennung und Geschäftslogik.

Alles läuft nur gegen die eigene lokale Instanz. Keine Scans gegen die
Produktion mit echten Betrieben.

## Warum ein produktionsgleicher Stack

Ein `php artisan serve` (einfädig) bricht unter der Last paralleler Scanner ein
und verfälscht die Ergebnisse. Darum wird hier der echte Stack aus der
`docker-compose.yml` des Projekts gefahren (nginx + php-fpm + postgres + redis).

## 1. Stack starten

```bash
cd security/docker
cp stack.env.example stack.env
# gültigen Schlüssel setzen:
sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" stack.env
# GASTROBOOK_IMAGE in stack.env auf die zu testende Fassung zeigen lassen
# (veröffentlichtes Image oder lokal gebautes).

docker compose -f ../../docker-compose.yml -f docker-compose.override.yml --env-file stack.env up -d
```

Benannte Volumes gehören anfangs root; die App läuft als `uid 82`. Einmalig den
Eigentümer setzen und die Laravel-Verzeichnisse anlegen (Projektname = Ordner
`docker`, Volumes also `docker_*`):

```bash
docker compose -f ../../docker-compose.yml -f docker-compose.override.yml --env-file stack.env up -d db redis
docker run --rm -v docker_public:/p -v docker_storage:/s alpine:3 sh -c \
  "mkdir -p /s/framework/views /s/framework/cache/data /s/framework/sessions /s/logs /s/app/public /s/app/private && chown -R 82:82 /p /s"
docker compose -f ../../docker-compose.yml -f docker-compose.override.yml --env-file stack.env up -d
```

Prüfen: `curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/up` → `200`.

## 2. Seeden

Aus `security/docker` heraus, wo `stack.env` liegt. Der `tinker`-Aufruf wird
gepipet; `tail -n +2` entfernt das `<?php` der Datei, und `grep` greift nur die
Ergebniszeile `SEEDJSON:{…}` (psysh echot auch den Quelltext).

```bash
CC="docker compose -f ../../docker-compose.yml -f docker-compose.override.yml --env-file stack.env"
$CC exec -T app php artisan migrate:fresh --seed
mkdir -p ../out
tail -n +2 ../seed/seed-pentest.php | $CC exec -T app php artisan tinker \
  | tr -d '\r' | grep -oE 'SEEDJSON:\{.*\}' | sed 's/SEEDJSON://' > ../out/ids.json
$CC exec -T app php artisan route:list --json > ../out/routes.json
```

`out/ids.json` enthält Passwort, API-Token und die IDs beider Testbetriebe
(A = eigen, B = fremd). Das Testpasswort kommt aus `PENTEST_PASSWORD`
(Vorgabe `pentest-local-9z`) und gilt nur lokal. `out/routes.json` je
Routenstand neu ziehen.

## 3. App-genaue Prüfungen (White-Box)

```bash
cd ../harness
pip install -r requirements.txt
GB_BASE=http://127.0.0.1:8088 GB_IDS=../out/ids.json GB_ROUTES=../out/routes.json python sicherheitstest.py
GB_BASE=http://127.0.0.1:8088 GB_IDS=../out/ids.json bash geschaeftslogik.sh
```

`sicherheitstest.py` prüft Anmeldezwang, Mandantentrennung (Web + API, lesend
und schreibend), Rollen, Gast-Token, CSRF, Kopfzeilen, gespeichertes XSS und
die Login-Drossel. `geschaeftslogik.sh` prüft Honeypot, Eingabegrenzen,
Tischwahl über die Mandantsgrenze und die Stornofrist.

**Drosselung beachten:** Die App begrenzt Login- und Buchungsversuche. Mehrere
Läufe direkt hintereinander laufen daher in 429-Antworten und melden
Scheinbefunde. Entweder die Läufe entzerren (eine Minute warten) oder die
Rate-Limits vorher zurücksetzen:

```bash
docker compose -f ../../docker-compose.yml -f ../docker/docker-compose.override.yml \
  --env-file ../docker/stack.env exec -T redis redis-cli FLUSHALL
```

## 4. Externe Scanner (Black-Box)

```bash
cd ../scanners
./run-nuclei.sh                      # Exposures, Misconfig, CVEs, Tech-Erkennung
cp ../zap/pw.env.example ../zap/pw.env   # GB_PW = Testpasswort eintragen
./run-zap.sh                         # angemeldet, tief (Beta/Alpha, high/low)
```

Ergebnisse landen in `security/out/` (`nuclei.jsonl`, `zap-deep.xml/html`).

### Grenze von ZAP „insane"

ZAPs eingebettete Session-DB (HSQLDB) hat ein internes Dateigrößen-Limit. Ein
`insane`-Lauf über viele tausend URLs (der Buchungskalender erzeugt beliebig
viele Datum/Zeit/Personen-Kombinationen) sprengt es, unabhängig vom freien
Plattenplatz, und der Report lässt sich nicht mehr exportieren. Darum fährt
`plan-deep.yaml` mit Stärke `high` (die Beta/Alpha-**Regeln** bringen die
Breite), schließt die Slot-/Kalender-Fallen aus und deckelt den Crawl. So bleibt
die Session exportierbar.

## 5. Befunde einordnen

- **Mandantentrennung** beweisen die White-Box-Skripte direkt mit bekannten
  fremden IDs (lesend und schreibend). Black-Box „0 Funde" heißt nur „die
  Heuristik hat nichts getroffen".
- ZAPs **boolean-SQLi**-Heuristik meldet an gebundenen Parametern und
  reflektierten Eingaben gern Fehlalarme. Vor jeder Meldung: am Quelltext
  prüfen (geht der Wert gebunden in die Query oder wird er gecastet?) und eine
  echte Nutzlast gegenfeuern (`' OR '1'='1' --` → liefert sie Daten oder nur
  einen Fehler?).

## 6. Abbauen

```bash
cd security/docker
docker compose -f ../../docker-compose.yml -f docker-compose.override.yml --env-file stack.env down -v
docker image rm gastrobook-zap:deep   # optional, ~4 GB
```
