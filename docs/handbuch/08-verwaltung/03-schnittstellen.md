# Schnittstellen für andere Programme

Dieses Kapitel brauchst du nur, wenn ein anderes Programm an Swayy angebunden
werden soll – Website, Kassensystem, Automatisierungsdienst. **Du musst hier
nichts einrichten, solange niemand ausdrücklich danach fragt.**

Gib deinem Dienstleister die Adresse dieses Kapitels; die Details holt er sich
aus der technischen Beschreibung.

## Zwei Wege

**API (Abholen und Anlegen)** – ein anderes Programm fragt Swayy: „Welche
Zeiten sind frei?", „Leg mir diese Reservierung an." Der klassische Fall: eine
individuell gebaute Website mit eigenem Buchungsformular.

**Webhooks (Bescheid geben)** – Swayy meldet von sich aus, wenn etwas passiert:
neue Reservierung, Stornierung, Zahlung eingegangen. Der klassische Fall: eine
Automatisierung, die bei jeder Buchung eine Nachricht in euren Teamchat
schreibt.

## API-Zugang anlegen

**API** in der Seitenleiste. Beim Anlegen eines Zugangs vergibst du einen Namen
und wählst **Berechtigungen** (Reservierungen lesen, Reservierungen schreiben,
Gäste lesen …).

Nur ankreuzen, was der andere Dienst wirklich braucht. „Lesen" allein ist
deutlich harmloser als „schreiben".

**Der Schlüssel wird genau einmal angezeigt.** Kopiere ihn sofort und gib ihn
sicher weiter – nicht per unverschlüsselter Mail, besser über einen
Passwortmanager oder telefonisch. Verloren? Alten Zugang widerrufen, neuen
anlegen.

Jeder Zugang gilt nur für **deinen** Betrieb. Ein Schlüssel kann keine fremden
Daten sehen.

Für Dienstleister liegt die vollständige technische Beschreibung unter
`/api/v1/openapi.yaml` – ein maschinenlesbares Dokument, das gängige Werkzeuge
direkt einlesen.

## Webhooks anlegen

**Webhooks** in der Seitenleiste. Du trägst die Adresse ein, die dein
Dienstleister dir nennt, und kreuzt an, worüber informiert werden soll –
einzelne Ereignisse oder „alle".

Danach:

- **Testereignis senden** schickt eine Probenachricht auf demselben Weg wie
  echte Ereignisse. Im **Zustellprotokoll** siehst du sofort, ob die Gegenstelle
  sie angenommen hat.
- **Secret neu erzeugen**, falls der Schlüssel irgendwo aufgetaucht ist. Die
  Gegenstelle muss dann umgestellt werden.
- **Pausieren**, wenn das Zielsystem gerade gewartet wird.

Nur öffentlich erreichbare, verschlüsselte Adressen (`https://…`) sind erlaubt.
Interne Adressen lehnt Swayy ab – das schützt davor, dass jemand über einen
Webhook an Systeme in eurem Netz kommt.

Schlägt eine Zustellung fehl, versucht Swayy es mehrfach mit wachsendem Abstand.
Nach 20 Fehlversuchen in Folge schaltet sich der Endpunkt selbst ab; du kannst
ihn hier wieder aktivieren, wobei der Fehlerzähler zurückgesetzt wird.

## Was ihr davon habt

Typische sinnvolle Anbindungen:

- **Kassensystem**: Reservierungen erscheinen an der Kasse
- **Teamchat**: neue Buchungen landen in einem Kanal
- **Website**: eigenes Buchungsformular im Design der Seite
- **Newsletter**: Einwilligungen wandern automatisch in eure Liste (dafür gibt
  es die fertige MailWizz-Anbindung, ganz ohne Programmierung)

Wenn nichts davon ansteht: Lass API und Webhooks einfach leer. Das ist kein
Nachteil.
