# E-Mails an Gäste

Swayy verschickt automatisch. Du musst nichts anstoßen – aber du solltest
wissen, was wann rausgeht, und die Texte einmal auf deine Stimme bringen.

## Welche Mails es gibt

| Mail | Wann |
|---|---|
| **Reservierung bestätigt** | sofort nach der Buchung (wenn automatisch bestätigt wird) |
| **Anfrage eingegangen** | sofort, wenn ihr erst bestätigen müsst |
| **Reservierung storniert** | bei Stornierung – egal von welcher Seite |
| **Anfrage abgelehnt** | wenn ihr eine Anfrage ablehnt |
| **Erinnerung** | eine einstellbare Zeit vor dem Termin, standardmäßig 24 Stunden |
| **Feedback-Anfrage** | nach dem Besuch, standardmäßig 18 Stunden später |
| **Wartelisten-Angebot** | wenn ihr einem Wartenden einen Tisch anbietet |

Dazu kommen anlassbezogene Nachrichten: Zahlungslinks bei Anzahlungen,
Bestätigungslink bei der E-Mail-Verifizierung, Anmeldelink fürs Kundenkonto und
– falls eingerichtet – die Marketing-Nachrichten (eigenes Kapitel).

**Die Erinnerung ist die wirksamste Mail überhaupt.** Sie kostet nichts und
senkt die No-Show-Quote spürbar. Lass sie an. Du schaltest sie unter
**Einstellungen › Gästekommunikation › Benachrichtigungen** im Abschnitt
„Erinnerung“ ein und stellst dort die Stunden vorher ein.

Die **Anrede** („du“ oder „Sie“) für alle Texte wählst du auf derselben Seite im
Abschnitt „Anrede“.

## Texte anpassen

**Einstellungen › Gästekommunikation › Vorlagen.** Für jede Vorlage der Liste
kannst du **Betreff** und **Text** überschreiben. Die Vorlage öffnet sich an der
Seite. Ohne eigene Fassung verschickt Swayy eine sinnvolle Standardfassung. Die
Liste zeigt je Vorlage „Standard“ oder „Angepasst“, und „Auf Standard
zurücksetzen“ nimmt deine Fassung zurück.

Der Standardtext in der Seitenleiste folgt der Anrede des Standorts, den du oben
gewählt hast. Die Seitenleiste nennt, für welche Anrede der Text gilt.

### Platzhalter

In geschweiften Klammern stehen Platzhalter, die beim Versand durch die echten
Werte ersetzt werden:

`{guest_name}`, `{reservation_date}`, `{reservation_time}`, `{party_size}`,
`{location_name}`, `{tenant_name}`, `{table_name}`, `{reservation_code}`,
`{cancel_link}`, `{modify_link}`

Die Seite zeigt alle Platzhalter; ein Klick kopiert einen. Am besten stehen
lassen und nur den Text drumherum ändern. **Lösch niemals
`{cancel_link}`** aus der Bestätigung – dann kann der Gast nicht mehr selbst
absagen und ihr habt den Anruf.

### So wird aus deinem Text eine gestaltete Mail

Du schreibst einfachen Text. Swayy gestaltet ihn beim Versand:

- Mehrere Zeilen in der Form „Bezeichnung: Wert“ direkt untereinander, etwa
  „Datum: {reservation_date}“ und „Uhrzeit: {reservation_time} Uhr“, werden zu
  einer Tabelle.
- Eine Zeile „Beschriftung: {cancel_link}“ wird zu einem Knopf mit dieser
  Beschriftung. Der erste Knopf ist hervorgehoben.
- Eine Zeile aus drei Bindestrichen (`---`) setzt eine Trennlinie; Text darunter
  erscheint klein, gut für Hinweise.
- Bleibt ein Platzhalter leer, etwa der Zahlungslink ohne offene Zahlung,
  entfällt seine Zeile ganz.

Oben steht eine Überschrift, die Swayy je Mail setzt, unten der Fußbereich mit
Adresse, Telefon, E-Mail, Impressum und Datenschutz deines Standorts. Pflege
diese Angaben unter **Einstellungen › Betrieb › Standorte** (Adresse, Telefon,
E-Mail, Logo) und **Einstellungen › Betrieb › Allgemein** (Impressum,
Datenschutz, Markenfarbe), dann erscheinen sie in jeder Mail.

### Gültigkeit

Eine eigene Fassung gilt für den ganzen Betrieb, also für alle Standorte. Swayy
nimmt die eigene Fassung, und sonst den Standardtext.

## Absender und Antworten

**Einstellungen › Gästekommunikation › Absender & Antworten.** Die Mails kommen
von der Adresse, die für dein System hinterlegt ist, mit einem Anzeigenamen. Den
**Absendernamen** trägst du hier ein; ist das Feld leer, steht dort der Name des
Standorts oder Betriebs.

Die **Antwortadresse** bestimmt, wohin die Antwort eines Gastes geht: Antwortet er
auf die Bestätigung, landet die Antwort in deinem Postfach. Gäste antworten
häufiger, als man denkt. Steht weder hier noch am Standort eine Adresse, weist
die Seite darauf hin: Dann erreichen Antworten niemanden.

Mit **Antworten über Swayy sammeln** bekommt jede Mail eine eigene Adresse.
Antworten erscheinen im Buchungsverlauf und gehen zusätzlich an deine Adresse.
Diese Funktion richtet der Betreiber von Swayy ein; die Seite sagt, wenn sie
noch fehlt.

## Zustellung

Jede versendete Mail wird protokolliert (Empfänger, Betreff, Zeitpunkt).

Kommt eine Mail nicht an, liegen die häufigsten Gründe außerhalb von Swayy:
Tippfehler in der Adresse, Spam-Ordner beim Empfänger, volles Postfach. Prüfe
zuerst die Adresse im Gastprofil und lass den Gast im Spam-Ordner nachsehen.

## Wer bekommt was nicht?

- **Ohne E-Mail-Adresse** gibt es keine Mail. Wenn Telefon Pflicht ist und
  E-Mail freiwillig, verzichtest du für Gäste ohne Adresse auf Bestätigung,
  Erinnerung und Feedback. Das entscheidest du unter **Einstellungen ›
  Buchungen › Buchungsformular**.
- **Anonymisierte Gäste** bekommen nichts mehr.
- **Marketing-Nachrichten** gehen nur an Gäste mit Einwilligung. Bestätigungen
  und Erinnerungen gehören zur Buchung und laufen unabhängig davon weiter – auch
  wenn jemand die Werbung abbestellt hat.

## Optional: Benachrichtigung für dich

Unter **Einstellungen › Gästekommunikation › Benachrichtigungen** kannst du im
Abschnitt „Info an den Betrieb“ einschalten, dass **du** bei jeder neuen
Reservierung eine Mail bekommst, wahlweise an eine eigene Adresse. Ohne eigene
Adresse geht die Mail an die Kontaktadresse des Standorts.
Praktisch am Anfang, wenn man dem Automatismus noch nicht traut. Nach ein paar
Wochen schalten die meisten es wieder aus.
