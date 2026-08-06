# SMS-Erinnerungen

Eine SMS erreicht Gäste, die ihr Mailpostfach nicht ansehen. Sie kostet aber –
anders als eine E-Mail – **echtes Geld pro Nachricht**. Dieses Kapitel erklärt,
was du brauchst, was es bringt und was es kostet.

## Was Swayy per SMS verschickt

**Ausschließlich die Terminerinnerung.** Keine Bestätigungen, keine Werbung,
keine Stornomeldungen. Der Text ist kurz und fest:

> Erinnerung: Ihre Reservierung bei Restaurant Sonne am 14.08.2026 um 19:00 Uhr.
> Bis bald!

Im Salon steht „Ihr Termin" statt „Ihre Reservierung", und bei der Anrede „du"
entsprechend „Dein Termin".

Die SMS kommt **zusätzlich** zur E-Mail-Erinnerung, zur selben Zeit (standardmäßig
24 Stunden vorher).

## Voraussetzungen

1. **Ein Konto bei seven.io** – ein deutscher SMS-Anbieter mit Servern in
   Deutschland. Das ist datenschutzrechtlich der einfachere Weg als ein
   US-Anbieter.
2. **Guthaben aufladen** – seven.io arbeitet auf Prepaid-Basis. Ist das Guthaben
   leer, wird nichts mehr verschickt.
3. **API-Schlüssel eintragen** in Swayy unter **Einstellungen → Integrationen →
   SMS**. Dazu einen **Absendernamen** (z. B. „Sonne", max. 11 Zeichen).
4. **SMS-Erinnerung einschalten** – pro Standort unter **Einstellungen →
   Buchungsregeln**. Das ist bewusst getrennt: Du kannst SMS in einer Filiale
   nutzen und in der anderen nicht.

In den Einstellungen gibt es einen **Verbindungstest**, der das Guthaben
abfragt. Wenn der funktioniert, stimmen Schlüssel und Konto.

## Wer bekommt eine SMS?

Nur Gäste mit einer **Mobilnummer** in der Buchung. Swayy bereinigt die
Schreibweise selbst: `0170 1234567`, `+49 170 1234567` und `0049-170-1234567`
werden alle korrekt verstanden. Ohne Ländervorwahl wird Deutschland angenommen.

Eine Festnetznummer kann nicht als SMS zugestellt werden – solche Versuche
schlagen fehl und kosten trotzdem, je nach Anbieter, unter Umständen etwas.
Deshalb: Wer SMS nutzt, sollte im Buchungsformular nach der **Handynummer**
fragen (Feldbeschriftung anpassen).

## Was es kostet

> **Zu den Zahlen:** Größenordnung für Deutschland, Stand August 2026. Der
> genaue Preis hängt von Route und Guthabenstaffel ab und steht in deinem
> seven.io-Konto. Bitte dort prüfen.

**Rechengrundlage:** eine SMS mit bis zu **160 Zeichen** ist eine Nachricht.
Wird der Text länger, wird er in mehrere Nachrichten aufgeteilt – und jede
kostet einzeln. Unser Erinnerungstext liegt bei rund 90 bis 110 Zeichen,
bleibt also **eine** Nachricht.

Angenommener Preis: **7,5 Cent netto je SMS nach Deutschland.**

### Beispiel 1: kleines Restaurant

200 Reservierungen im Monat, 50 % geben eine Handynummer an:

100 SMS × 0,075 € = **7,50 € im Monat**

### Beispiel 2: gut gebuchtes Restaurant

400 Reservierungen, 55 % mit Handynummer:

220 SMS × 0,075 € = **16,50 € im Monat**

### Beispiel 3: Salon mit Terminen

600 Termine im Monat, 80 % mit Handynummer (im Salon gibt fast jeder die
Mobilnummer an):

480 SMS × 0,075 € = **36,00 € im Monat**

### Was das bringt

Rechne dagegen: Verhindert die SMS auch nur **zwei** No-Shows im Monat, sind bei
einem Tisch mit drei Personen à 35 € rund **210 €** Umsatz gerettet. Die 16,50 €
für Beispiel 2 sind dann eine sehr gute Investition.

Im Salon ist der Effekt noch deutlicher, weil ein ausgefallener Termin die
Person für eine ganze Stunde blockiert und nicht neu verkauft werden kann.

### Guthaben planen

Bei 220 SMS im Monat reicht eine Aufladung von 50 € rund drei Monate. Setz dir
eine Erinnerung, das Guthaben zu prüfen – **ein leeres Konto meldet sich nicht
von selbst**, die Erinnerungen bleiben dann einfach aus. Die E-Mail-Erinnerung
läuft in dem Fall unverändert weiter.

## Rechtliches

Die Terminerinnerung ist eine **transaktionsbezogene** Nachricht zu einer
Buchung, die der Gast selbst vorgenommen hat – keine Werbung. Sie ist deshalb
unproblematisch.

**Werbe-SMS wären etwas völlig anderes** und brauchen eine ausdrückliche
Einwilligung genau dafür. Swayy verschickt deshalb bewusst keine Marketing-SMS;
die automatischen Kampagnen laufen ausschließlich per E-Mail.

## Wenn keine SMS ankommt

1. Guthaben bei seven.io leer?
2. SMS-Erinnerung für **diesen Standort** eingeschaltet?
3. Hat der Gast überhaupt eine Mobilnummer hinterlegt?
4. Ist die Erinnerung überhaupt fällig (Zeitpunkt bereits vorbei)?
5. Absendername korrekt (max. 11 Zeichen, keine Sonderzeichen)?

Fehlgeschlagene Versuche werden protokolliert – frag im Zweifel den, der die
Systemprotokolle einsehen kann.
