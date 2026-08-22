---
name: gastrobook-backend
description: Fallstricke im GastroBook-Backend - Mandantentrennung, "genau-einmal"-Abläufe, idempotente Migrationen, Du/Sie-Anrede, Datenschutzerklärung, seiteneigenes CSS. Nutzen bei jeder Änderung unter app/, database/ oder resources/.
---

# Backend-Fallstricke in GastroBook

Laravel 13, PostgreSQL, Redis für Cache/Session/Queue. Jeder Punkt hier steht
für einen Fehler, der schon einmal passiert ist.

## "Genau einmal" braucht eine Sperre

Zwischen Prüfen und Schreiben liegt ein Zeitfenster; zwei gleichzeitige Anfragen
laufen beide durch. Diese Bugklasse ist im Projekt **dreimal** aufgetreten
(Event, Erstattung, Warteliste).

Immer in einer Transaktion mit `lockForUpdate()` oder als bedingtes Update
("setze auf erledigt, WO noch nicht erledigt") und danach die betroffene
Zeilenzahl auswerten. Betrifft alles, was genau einmal passieren darf:
Reservierungsplätze, Stornierungen, Erstattungen, Wartelistenaufrücken.

## Mandantentrennung

Jede Abfrage auf mandantengebundene Daten braucht die Bedingung auf den
Standort/Mandanten. Fehlt sie, sieht ein Betrieb die Reservierungen eines
anderen.

## Migrationen müssen idempotent sein

`docker/entrypoint.sh` läuft mit `set -e` und ruft `migrate` auf. Eine Migration,
die beim zweiten Lauf scheitert (Spalte existiert schon, Index existiert schon),
bringt den **Container in eine Neustartschleife** — die Anwendung ist dann
komplett weg, nicht nur die Änderung. Also vorher auf Existenz prüfen.

## Anrede wirkt app-weit

`LocationSettings::du()` steuert Du/Sie in **Web, Mail und SMS** gleichzeitig.
Wer nur eine Stelle anpasst, erzeugt einen Mischmasch. In `booking.blade` braucht
die Verzweigung einen Block-`@php`, kein Inline-Konstrukt.

## Datenschutzerklärung ist Teil der Änderung

`resources/legal/datenschutz.md` bei **jeder** Änderung mitpflegen, die
Datenverarbeitung berührt — neue Felder, neue Empfänger, neue Speicherdauer,
neue Dienste. Nicht später, sondern im selben Schritt.

## Seiteneigenes CSS immer scopen

Ein `!important` auf einer Tailwind-Utility (`.hidden`) hat die Admin-Seitenleiste
lahmgelegt; der schnelle Fix erzeugte den nächsten Fehler. Seiten-CSS immer auf
einen eigenen Container beschränken, nie global auf Utility-Klassen.

## Keine Remote-Fonts im Vite-Build

Schriften sind selbst gehostet (`@fontsource-variable/inter`,
`@fontsource-variable/fraunces`). Eine Remote-Font im Build lässt das
laravel-vite-plugin mit "fetch failed" scheitern — die CI baut dann **kein
Release-Image**.

## Keine echten Daten

Nirgends — nicht in Tests, nicht in Kommentaren, nicht im Changelog, nicht in
der Historie.

## Prüfen

```bash
php artisan test
vendor/bin/phpstan analyse
```

Nur die betroffenen Tests laufen lassen, solange die Änderung klein ist
(`php artisan test --filter=…`).
