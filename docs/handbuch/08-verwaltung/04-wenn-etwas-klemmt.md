# Wenn etwas klemmt

Die häufigsten Fälle mit dem Weg zur Ursache. Fast alles davon ist eine
Einstellung.

## „Auf der Buchungsseite wird kein Termin angeboten"

Der Reihe nach prüfen:

1. **Öffnungszeiten** für diesen Wochentag hinterlegt (**Einstellungen › Platz &
   Zeiten › Öffnungszeiten**, Schalter „Geöffnet“)? Die Vorschau „So sehen Gäste
   es“ zeigt, was die Buchungsseite anbietet.
2. **Ausnahme** für das Datum (**Einstellungen › Platz & Zeiten › Ausnahmen**),
   die „Geschlossen“ sagt?
3. **Ausnahme** mit „Weniger Gäste“ oder gesperrten Räumen, die den Zeitraum
   abdeckt?
4. **Mindestvorlauf** – liegt der Termin zu nah an jetzt?
5. **Vorausbuchung** – liegt er weiter in der Zukunft als erlaubt?
6. **Gruppengröße** – außerhalb der Online-Grenzen?
7. **Tische** – gibt es überhaupt einen mit passender Kapazität, und ist er als
   „online buchbar" markiert?
8. Ist der Tisch womöglich **gesperrt**?

In der Praxis stecken neun von zehn Fällen in Punkt 1, 3 oder 7.

## „Der Gast hat keine Bestätigung bekommen"

1. Steht überhaupt eine **E-Mail-Adresse** an der Buchung?
2. Adresse richtig geschrieben? (Ein Blick ins Gastprofil.)
3. **Spam-Ordner** prüfen lassen.
4. Steht die Buchung auf **„Angefragt"**? Dann geht die Bestätigung erst raus,
   wenn ihr bestätigt.
5. Wartet die Buchung auf eine **Anzahlung**? Dann kommt die Bestätigung nach
   der Zahlung.

## „Die Erinnerung kam nicht"

- Ist die Erinnerung unter **Einstellungen › Gästekommunikation ›
  Benachrichtigungen** eingeschaltet?
- Liegt der eingestellte Zeitpunkt (Standard 24 Stunden vorher) schon in der
  Vergangenheit? Bei einer Buchung, die erst 3 Stunden vorher eingeht, wird
  nichts mehr verschickt.
- Bei SMS zusätzlich: Guthaben leer? Mobilnummer hinterlegt? SMS für **diesen
  Standort** aktiv?

## „Das Live-Board aktualisiert sich nicht"

Steht oben ein Hinweis auf fehlendes Signal, erscheint der Knopf **„⟳ Jetzt
aktualisieren"**. Einmal drücken.

Bleibt es dabei, hilft ein Neuladen der Seite. Hält das Problem an, liegt es an
der Verbindung oder einem Zwischensystem im Netz – dann die Person fragen, die
eure Installation betreut.

## „Ein Kollege sieht den Menüpunkt nicht"

Das ist die **Rolle**. Menüpunkte und Einstellungsseiten ohne Berechtigung
werden ausgeblendet. Unter **Einstellungen › Betrieb › Team & Rollen** die Rolle
prüfen und im Kapitel *Anmelden, Rollen und Rechte* nachsehen, was dazugehört.

## „Ein Gast steht doppelt in der Liste"

Klassisch, wenn einmal online und einmal telefonisch gebucht wurde. Auf dem
Gastprofil erscheint die Karte **„Mögliche Dublette"** – dort zusammenführen
(siehe *Gäste*).

Vorbeugen: bei telefonischen Buchungen immer den **Namensvorschlag** annehmen,
statt neu zu tippen.

## „Ein Tisch lässt sich nicht sperren"

Swayy lehnt die Sperre ab, wenn in dem Zeitraum noch eine aktive Buchung auf dem
Tisch liegt. Die Meldung nennt Datum, Uhrzeit und Tisch. Erst umbelegen oder
stornieren, dann sperren (im Tischplan über „Tisch sperren…“, sonst unter
**Einstellungen › Platz & Zeiten › Ausnahmen**).

## „Die Anzahlung wurde bezahlt, die Buchung steht aber noch auf offen"

Der Zahlungsanbieter meldet jede Zahlung an Swayy. Lade die Seite neu. Bleibt
es offen, prüfe unter **Einstellungen › Zahlungen › Zahlungsanbieter** (Status,
„Letzte Meldung von Stripe“) und im Konto des Zahlungsanbieters, ob die Zahlung
abgeschlossen ist.

## „Wir sehen die Zahlen aus einer Filiale nicht"

Du bist im falschen **Standort**. Oben links umschalten. Fehlt der Standort in
der Auswahl, ist dein Benutzer auf bestimmte Standorte beschränkt.

## „Statistiken sind leer, obwohl viel los war"

Fast immer fehlt das **Auschecken**. Erst damit gilt ein Besuch als
abgeschlossen – daran hängen Besuchszähler, Stammgast-Erkennung und
Feedback-Anfrage.

Nehmt euch das für zwei Wochen konsequent vor; danach stimmen die Zahlen.

## Fehlerseiten

Wenn Swayy etwas nicht ausführen kann, zeigt es eine Seite mit der Ursache und
dem nächsten Schritt. Unten steht ein **Fehlercode**. Nenne ihn dem Support, wenn du dich
meldest.

| Code | Titel | Was es bedeutet | Was du tust |
|---|---|---|---|
| 402 | Dieser Betrieb ist gesperrt | Der Zugang des Betriebs ist gesperrt. | Frag den Inhaber deines Betriebs. Plattform-Administratoren kehren von dort in die Plattform zurück. |
| 403 | Dafür fehlt dir das Recht | Deine Rolle hat für diese Seite keine Freigabe. | Frag den Inhaber oder die Administration. |
| 403 | Dieser Link funktioniert nicht (mehr) | Ein Link aus einer Mail ist abgelaufen, wurde verändert oder gehört zu einem anderen Vorgang. | Öffne den Link noch einmal aus deiner Mail oder wende dich an den Betrieb. |
| 404 | Diese Seite gibt es nicht | Die Adresse ist falsch oder der Eintrag wurde gelöscht. | Prüfe die Adresse oder starte über „Zur Übersicht“ neu. |
| 419 | Die Seite war zu lange offen | Ein Formular gilt aus Sicherheitsgründen nur eine Weile. | Lade die Seite neu („Seite neu laden“) und sende das Formular noch einmal. |
| 429 | Zu viele Anfragen | Von deinem Anschluss kamen in kurzer Zeit sehr viele Anfragen. | Warte die Zeit ab, die die Seite nennt, und versuch es dann erneut. |
| 500 | Da ist etwas schiefgegangen | Swayy konnte die Anfrage nicht ausführen. Der Fehler ist protokolliert. | Lade die Seite neu. Tritt der Fehler wieder auf, schreib dem Support und nenne den Fehlercode. |
| 503 | Swayy wird gerade aktualisiert | Swayy ist gerade nicht erreichbar, etwa bei einem Update. | Versuche es später noch einmal. |

Bei Formularen in der Verwaltung meldet Swayy Fehler direkt am Feld oder in einem
Hinweis: mit der Ursache (welche Eingabe, welche Regel) und dem nächsten Schritt.

## Alte Lesezeichen

Die Einstellungen liegen auf eigenen Seiten. Alte Lesezeichen und Links aus der
Zeit davor führen trotzdem ans Ziel:

- `/admin/settings` führt auf die erste Einstellungsseite, die deine Rolle öffnen
  darf. Alle Seiten samt Suche findest du über „Einstellungen“ in der
  Navigation, auf dem Smartphone über „Mehr“.
- Alte Sprungmarken wie `#zeiten`, `#zahlungen`, `#widget`, `#allgemein`,
  `#buchungsregeln`, `#raeume` oder `#integrationen` leiten auf die Seite weiter,
  die den Bereich übernommen hat, sofern deine Rolle sie öffnen darf.
- Hat deine Rolle keine Einstellungsseite, erscheint die Meldung „Für die
  Einstellungen fehlt die Berechtigung. Bitte einen Administrator des Betriebs
  fragen.“

Setz dir ein neues Lesezeichen auf die Seite, die du am häufigsten brauchst.

## Was du dem Support schicken solltest

Wenn du dich an den Anbieter wendest, spart das Zeit:

- **Was** hast du getan, **was** ist passiert, **was** hattest du erwartet?
- Der **Buchungscode** oder der Name des Gastes.
- **Datum und Uhrzeit** des Vorfalls.
- **Standort**, falls du mehrere hast.
- Der **Fehlercode** von der Fehlerseite, falls eine zu sehen war.
- Ein **Bildschirmfoto**, wenn eine Meldung zu sehen war.

Die Versionsnummer steht klein unten in der Verwaltung – bitte mitschicken.
