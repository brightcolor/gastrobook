# Sicherung, Umzug, Export und Import

## Wo du Export, Import und Löschen findest

Alles steht auf einer Seite: **Einstellungen › Betrieb › Daten**. Die Seite ist
den Inhabern des Betriebs vorbehalten. Sie hat drei Abschnitte: „Alle Daten
herunterladen“, „Daten einspielen“ und „Betrieb löschen“. Unter **Mein Konto**
steht nur ein Verweis darauf.

## Der Export

**Einstellungen › Betrieb › Daten › Alle Daten herunterladen.** Du bekommst
eine Datei mit deinem kompletten Betrieb: Standorte, Räume, Tische,
Öffnungszeiten, Regeln, Gäste, Buchungen, Notizen, Zahlungen, Feedback,
Vorlagen, Events, Mitarbeiter und Leistungen.

Wofür das gut ist:

- **Sicherheitskopie** vor größeren Umstellungen
- **Datenportabilität** – die Daten gehören dir, du kannst sie mitnehmen
- **Umzug** auf eine andere Installation

Zugangsdaten zu Fremddiensten (Stripe-Schlüssel, SMS-Schlüssel, Passwörter)
bleiben aus Sicherheitsgründen draußen, damit die Datei keine Schlüssel
verrät. Diese Verbindungen richtest du nach einem Umzug neu ein.

Die Datei enthält personenbezogene Daten deiner Gäste. Behandle sie
entsprechend: verschlüsselt ablegen, nicht per Mail versenden, nach dem Umzug
löschen.

## Der Import

**Einstellungen › Betrieb › Daten › Daten einspielen.** Du wählst die
Export-Datei, bestätigst mit dem Kästchen („Ja, die Daten aus der Datei zu …
hinzufügen“) und klickst „Datei einspielen“.

Der Import arbeitet **additiv**: Er legt an, was fehlt, und lässt Vorhandenes
unverändert. Gelöscht wird dabei nichts.

Nach dem Import gelten einige Dinge neu: Bestätigungslinks entstehen neu, und
bereits versendete Links zum Ändern oder Stornieren funktionieren danach nicht
mehr. Offene Erstattungen gelten als erledigt; sie gehören ins alte System.

Ein Hinweis aus der Praxis: Beim Import in einen Betrieb, in dem schon gearbeitet
wurde, kann es zu Kollisionen kommen – etwa wenn es einen Tag „VIP" oder eine
E-Mail-Vorlage schon gibt. Swayy fängt das ab und ordnet die vorhandenen
Einträge zu, damit keine Dubletten entstehen. Trotzdem gilt: **Am saubersten ist ein
Import in einen leeren Betrieb.**

## Empfohlener Ablauf für einen Umzug

1. Export in der alten Installation ziehen.
2. In der neuen Installation Betrieb anlegen.
3. Import einspielen.
4. **Verbindungen neu einrichten:** Stripe/PayPal (**Zahlungen › Zahlungsanbieter**),
   SMS (**Gästekommunikation › SMS-Versand**), Newsletter (**Verbindungen ›
   Newsletter**).
5. **Rechtstexte prüfen** – sie liegen als Dateien am Server und wandern nicht
   automatisch mit.
6. Zwei, drei Buchungen stichprobenartig vergleichen.
7. Eine Testbuchung durchführen (siehe *Einrichtung*).
8. Erst dann die alte Buchungsseite abschalten und den Link umbiegen.

## Regelmäßige Sicherung

Der Export ersetzt keine Serversicherung, sondern ergänzt sie. Wer Swayy selbst
betreibt, braucht zusätzlich ein Backup der Datenbank und des
Dateiverzeichnisses – das macht die Person, die deine Installation betreut.

Als Betriebsinhaber ohne Serverzugang ist ein monatlicher Account-Export, sicher
abgelegt, ein vernünftiges Sicherheitsnetz.

## Einen Betrieb löschen

**Einstellungen › Betrieb › Daten › Betrieb löschen.** Das kann nur der letzte
verbliebene Inhaber. Gibt es weitere Inhaber, ändere zuerst ihre Rolle unter
**Einstellungen › Betrieb › Team & Rollen**. Zur Bestätigung tippst du den Namen
des Betriebs ein.

Das löscht wirklich alles: Buchungen, Gäste, Einstellungen, Verläufe. **Der
Betrieb ist danach endgültig weg, auch als Kopie.** Zieh vorher einen Export,
auch wenn du sicher bist.
