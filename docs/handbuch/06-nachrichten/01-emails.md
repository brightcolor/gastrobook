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
senkt die No-Show-Quote spürbar. Lass sie an.

## Texte anpassen

**E-Mail-Vorlagen** in der Seitenleiste. Für jede der sieben Mails kannst du
**Betreff** und **Text** überschreiben. Wer nichts ändert, bekommt eine
sinnvolle Standardfassung.

### Platzhalter

In geschweiften Klammern stehen Platzhalter, die beim Versand durch die echten
Werte ersetzt werden:

`{guest_name}`, `{reservation_date}`, `{reservation_time}`, `{party_size}`,
`{location_name}`, `{tenant_name}`, `{table_name}`, `{reservation_code}`,
`{cancel_link}`, `{modify_link}`

Am besten stehen lassen und nur den Text drumherum ändern. **Lösch niemals
`{cancel_link}`** aus der Bestätigung – dann kann der Gast nicht mehr selbst
absagen und ihr habt den Anruf.

### Zwei Ebenen

Vorlagen gelten für den ganzen Betrieb. Bei mehreren Standorten kann ein
einzelner Standort abweichen. Swayy nimmt immer die spezifischste Fassung:
Standort vor Betrieb vor Standardtext.

## Absender

Die Mails kommen von der Adresse, die für dein System hinterlegt ist – aber mit
**deinem Betriebsnamen** als Anzeigename. Optional trägst du eine
**Antwortadresse** ein: Antwortet ein Gast auf die Bestätigung, landet die
Antwort dann in deinem Postfach. Ohne diese Einstellung gehen solche Antworten
ins Leere, und Gäste antworten häufiger, als man denkt.

## Zustellung

Jede versendete Mail wird protokolliert (Empfänger, Betreff, Zeitpunkt).

Kommt eine Mail nicht an, liegen die häufigsten Gründe außerhalb von Swayy:
Tippfehler in der Adresse, Spam-Ordner beim Empfänger, volles Postfach. Prüfe
zuerst die Adresse im Gastprofil und lass den Gast im Spam-Ordner nachsehen.

## Wer bekommt was nicht?

- **Ohne E-Mail-Adresse** gibt es keine Mail. Wenn Telefon Pflicht ist, aber
  E-Mail nicht, verzichtest du auf Bestätigung, Erinnerung und Feedback. Das ist
  eine bewusste Entscheidung, keine Panne.
- **Anonymisierte Gäste** bekommen nichts mehr.
- **Marketing-Nachrichten** gehen nur an Gäste mit Einwilligung. Bestätigungen
  und Erinnerungen gehören zur Buchung und laufen unabhängig davon weiter – auch
  wenn jemand die Werbung abbestellt hat.

## Optional: Benachrichtigung für dich

Unter **Einstellungen → Buchungsregeln** kannst du einschalten, dass **du** bei
jeder neuen Reservierung eine Mail bekommst, wahlweise an eine eigene Adresse.
Praktisch am Anfang, wenn man dem Automatismus noch nicht traut. Nach ein paar
Wochen schalten die meisten es wieder aus.
