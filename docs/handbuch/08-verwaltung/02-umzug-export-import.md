# Sicherung, Umzug, Export und Import

## Der Account-Export

**Account → Daten exportieren.** Du bekommst eine Datei mit deinem kompletten
Betrieb: Standorte, Räume, Tische, Öffnungszeiten, Regeln, Gäste, Buchungen,
Notizen, Zahlungen, Feedback, Vorlagen, Events.

Wofür das gut ist:

- **Sicherheitskopie** vor größeren Umstellungen
- **Datenportabilität** – die Daten gehören dir, du kannst sie mitnehmen
- **Umzug** auf eine andere Installation

**Nicht enthalten sind Zugangsdaten zu Fremddiensten** (Stripe-Schlüssel,
SMS-Schlüssel, Passwörter). Das ist Absicht: Eine Exportdatei, die
Zahlungsschlüssel enthält, wäre ein Sicherheitsproblem. Diese Verbindungen
richtest du nach einem Umzug neu ein.

Die Datei enthält personenbezogene Daten deiner Gäste. Behandle sie
entsprechend: verschlüsselt ablegen, nicht per Mail versenden, nach dem Umzug
löschen.

## Der Import

**Account → Daten importieren**, Datei auswählen.

Der Import arbeitet **additiv**: Er legt an, was fehlt, und lässt Vorhandenes in
Ruhe. Er löscht nichts.

Ein Hinweis aus der Praxis: Beim Import in einen Betrieb, in dem schon gearbeitet
wurde, kann es zu Kollisionen kommen – etwa wenn es einen Tag „VIP" oder eine
E-Mail-Vorlage schon gibt. Swayy fängt das ab und ordnet die vorhandenen
Einträge zu, statt Dubletten zu erzeugen. Trotzdem gilt: **Am saubersten ist ein
Import in einen leeren Betrieb.**

## Empfohlener Ablauf für einen Umzug

1. Export in der alten Installation ziehen.
2. In der neuen Installation Betrieb anlegen.
3. Import einspielen.
4. **Verbindungen neu einrichten:** Stripe/PayPal, SMS, Newsletter.
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

**Account → Gefahrenzone.** Nur der Inhaber kann das, und nur mit Eingabe des
Betriebsnamens zur Bestätigung.

Das löscht wirklich alles: Buchungen, Gäste, Einstellungen, Verläufe. **Es gibt
kein Zurück und keine Kopie.** Zieh vorher einen Export, auch wenn du sicher
bist.
