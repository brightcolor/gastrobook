# Schnittstellen für andere Programme

Dieses Kapitel brauchst du, wenn ein anderes Programm an Swayy angebunden werden
soll – Website, Kassensystem, Automatisierungsdienst. **Richte hier etwas ein,
sobald jemand ausdrücklich danach fragt.** Alle Seiten liegen unter
**Einstellungen › Verbindungen**.

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

## API-Schlüssel anlegen

**Einstellungen › Verbindungen › API-Schlüssel.** Mit **API-Schlüssel anlegen**
öffnet sich die Seitenleiste. Du vergibst einen **Namen** (das Programm, das den
Schlüssel nutzt, etwa „Kassensystem“) und hakst die **Rechte** an, die es
braucht. Die Rechte tragen deutsche Namen, zum Beispiel „Reservierungen lesen“,
„Reservierungen anlegen und ändern“, „Gäste lesen“, „Freie Zeiten abfragen“,
„Warteliste befüllen“, „Events lesen“, „Webhooks verwalten“ und „Berichte
lesen“. Im Salon steht „Termine“ statt „Reservierungen“ und „Kunden“ statt
„Gäste“.

Hake nur an, was der andere Dienst wirklich braucht. „Lesen“ allein ist deutlich
harmloser als „anlegen und ändern“. Für die meisten Programme reicht
„Reservierungen lesen“.

**Der Schlüssel wird genau einmal angezeigt**, direkt nach dem Anlegen in einem
grünen Kasten mit dem Knopf **Kopieren**. Kopiere ihn sofort und gib ihn sicher
weiter – am besten über einen Passwortmanager, per Mail nur verschlüsselt. Hast
du ihn verloren, **widerrufe** den Schlüssel (Knopf „Widerrufen“ in der Zeile,
mit Rückfrage) und lege einen neuen an. Ein widerrufener Schlüssel verliert sofort
den Zugang.

Die Liste zeigt je Schlüssel die Rechte und wann er zuletzt genutzt wurde. Jeder
Schlüssel gilt nur für **deinen** Betrieb. Er kann keine fremden Daten sehen.
Ist die Schnittstelle in deinem Tarif nicht enthalten, steht oben ein Hinweis
mit dem Weg zum Tarifwechsel (**Einstellungen › Betrieb › Abo & Rechnungen**).

Für Dienstleister liegt die vollständige technische Beschreibung unter
`/api/v1/openapi.yaml` – ein maschinenlesbares Dokument, das gängige Werkzeuge
direkt einlesen. Die Seite zeigt außerdem ein Beispiel für eine Abfrage.

## Webhooks anlegen

**Einstellungen › Verbindungen › Webhooks › Webhook anlegen.** Du trägst die
Adresse ein, die dein Dienstleister dir nennt, und kreuzt an, worüber informiert
werden soll – einzelne Ereignisse oder „Alle Ereignisse“.

Jeder Endpunkt steht mit seinem Status in der Liste. Daneben stehen diese Knöpfe:

- **Testereignis senden** schickt eine Probenachricht auf demselben Weg wie
  echte Ereignisse. Im **Zustellprotokoll** weiter unten siehst du sofort, ob die
  Gegenstelle sie angenommen hat.
- **Geheimnis neu erzeugen**, falls das Geheimnis irgendwo aufgetaucht ist. Das
  neue Geheimnis erscheint einmal in einem grünen Kasten zum Kopieren. Die
  Gegenstelle muss danach umgestellt werden; bis dahin schlagen Zustellungen
  fehl. Mit dem Geheimnis prüft die Gegenstelle die Signatur jeder Nachricht.
- **Pausieren** (und danach **Aktivieren**), wenn das Zielsystem gerade gewartet
  wird.
- **Löschen**, mit Rückfrage.

Erlaubt sind öffentlich erreichbare, verschlüsselte Adressen (`https://…`).
Vor jeder Zustellung prüft Swayy die Adresse erneut. Führt sie in ein internes
Netz, schaltet sich der Endpunkt sofort ab; der Grund steht im Zustellprotokoll.
Das schützt davor, dass jemand über einen Webhook an Systeme in eurem Netz kommt.

Schlägt eine Zustellung fehl, versucht Swayy es mehrfach mit wachsendem Abstand.
Wie viele Versuche und welche Abstände gelten, steht im Abschnitt
„Wiederholungen und Abschaltung“ der Seite. Nach 20 gescheiterten Ereignissen in
Folge (Vorgabe) schaltet sich der Endpunkt selbst ab; du kannst ihn hier wieder
aktivieren, wobei der Fehlerzähler zurückgesetzt wird.

## Newsletter

**Einstellungen › Verbindungen › Newsletter.** Hier verbindest du deine
MailWizz-Installation: Stimmt ein Gast beim Buchen dem Newsletter zu, trägt
Swayy ihn in deine Liste ein. Du trägst die **API-URL**, den **API-Key** und die
**Listen-UID** ein (die Seite sagt, wo du sie in MailWizz findest) und klickst
**Speichern und Verbindung testen**. Der Status zeigt, ob die Verbindung
funktioniert; schlägt der Test fehl, nennt die Seite die Ursache. Der Schalter
„Gäste mit Einwilligung übertragen“ schaltet die Übertragung ein und aus, die
Zugangsdaten bleiben gespeichert. Der API-Key wird verschlüsselt gespeichert und
nie wieder angezeigt.

## Was ihr davon habt

Typische sinnvolle Anbindungen:

- **Kassensystem**: Reservierungen erscheinen an der Kasse
- **Teamchat**: neue Buchungen landen in einem Kanal
- **Website**: eigenes Buchungsformular im Design der Seite
- **Newsletter**: Einwilligungen wandern automatisch in eure Liste (dafür gibt
  es die fertige MailWizz-Anbindung, ganz ohne Programmierung)

Wenn nichts davon ansteht, lass API-Schlüssel und Webhooks leer.
