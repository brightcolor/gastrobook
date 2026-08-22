---
name: gastrobook-betrieb
description: Betrieb von GastroBook auf swayy.de - Speicher- und Plattengrenzen des Hosts, Redis-Lockup, Watchtower-Grenzen bei compose.yml, Pflichtprüfung nach dem Deploy. Nutzen bei Deployment, Container-Fragen oder wenn die Anwendung live nicht erreichbar ist.
disable-model-invocation: true
---

# Betrieb auf swayy.de

Image `ghcr.io/brightcolor/gastrobook:latest`, dahinter nginx, PostgreSQL 17 und
Redis 7. Watchtower zieht neue Images.

## Nach dem Deploy live prüfen — Pflicht

Eine grüne CI und ein "healthy" gemeldeter Container haben schon einen
**elfstündigen Totalausfall (HTTP 400) verdeckt**. Nach jedem Deploy von außen
prüfen:

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://<domain>/
```

Bei einer SPA zusätzlich die Ansicht im Browser öffnen — ein 200 auf die
Startseite beweist nicht, dass die Anwendung lädt.

Häufige Ursache für 400: falsche `APP_URL`. `docker/entrypoint.sh` warnt früh und
laut, wenn `APP_ENV=production` mit localhost-`APP_URL` zusammentrifft.

## Der Host ist am Speicherlimit

3,8 GB ohne Swap bei rund 30 Containern. Speicherhungrige Container (der
Chrome-Container `fgc-remaster` war der Auslöser) lösen **globale OOM-Stürme**
aus: SSH und alle TCP-Dienste sind dann weg, nicht nur GastroBook. Vor dem Start
zusätzlicher Container den freien Speicher prüfen.

Port 8090 ist absichtlich nur auf loopback gebunden.

## Die Platte läuft voll

38 GB, stand schon auf 100 %. Alte Images nach jedem Deploy entfernen:

```bash
docker image prune -f
```

## Redis legt die Anwendung ganz lahm

Cache, Session **und** Queue laufen über Redis. Ein Read-Only-Lockup von Redis
sieht deshalb nicht nach einem Cache-Problem aus, sondern nach einem
Totalausfall. Bei unerklärlichen Fehlern zuerst Redis prüfen.

Die Datenbank ist **PostgreSQL**, nicht MySQL und nicht SQLite.

## Watchtower aktualisiert keine compose.yml

Änderungen an `docker-compose.yml` (neue Umgebungsvariablen, neue Dienste, neue
Ports) werden **nicht** automatisch ausgerollt. Die Datei muss auf dem Host
angefasst und `docker compose up -d` ausgeführt werden. Ein neues Image allein
reicht nicht.

## Vor dem Ausrollen

1. `git status` prüfen
2. Änderungen und Migrationen ansehen — sind die Migrationen idempotent?
3. Tests prüfen
4. Zielumgebung feststellen
5. Risiken benennen
6. erst danach ausrollen
