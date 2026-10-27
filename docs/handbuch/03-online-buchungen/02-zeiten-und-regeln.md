# Öffnungszeiten, Ausnahmen und Buchungsregeln

Öffnungszeiten, Ausnahmen und Regeln bestimmen zusammen, wann überhaupt gebucht
werden kann. Sie greifen in dieser Reihenfolge ineinander. Alle Seiten gelten
für den Standort, der oben in der Auswahl steht.

## 1. Öffnungszeiten (der Normalfall)

**Einstellungen › Platz & Zeiten › Öffnungszeiten.** Der Abschnitt „Reguläre
Woche“ zeigt jeden Wochentag mit einem Schalter **Geöffnet**:

- Ein ausgeschalteter Tag ist geschlossen. Gäste finden dort keine Uhrzeit.
- Ein eingeschalteter Tag hat ein oder mehrere **Zeitfenster** mit „Öffnet“ und
  „Schließt“. Mit **Zeitfenster hinzufügen** kommt ein weiteres dazu. Zwei
  Fenster am Tag sind üblich (Mittag/Abend); die Lücke dazwischen ist dann nicht
  buchbar.
- Optional gibst du einem Fenster eine **Bezeichnung** („Mittagstisch“), zur
  Übersicht für euch.
- Über Mitternacht funktioniert: 18:00–01:00 ist ein Fenster. Ein Hinweis unter
  dem Fenster bestätigt das.
- Gleiche Uhrzeit bei „Öffnet“ und „Schließt“ ergibt ein Fenster rund um die Uhr
  (24 Stunden). Es reicht bis zur selben Uhrzeit am Folgetag; Fenster des
  Folgetags dürfen dort erst danach beginnen. Ein Hinweis unter dem Fenster sagt
  es.
- Die Uhrzeitfelder springen in Schritten von fünf Minuten (einstellbar mit
  `SWAYY_OPENING_HOURS_TIME_STEP_MINUTES`). Eine gespeicherte Zeit zwischen den
  Schritten, etwa 11:32, bleibt erhalten und speicherbar.

Mit **Auf andere Tage übertragen…** kopierst du die Fenster eines Tages auf
beliebige andere Tage. Zwei Schnellwahlen helfen dabei: „Mo–Fr“ und „Alle Tage“.
Die kopierten Fenster ersetzen die Fenster der gewählten Tage. Gespeichert wird
erst über die Speicherleiste mit **Speichern**; **Verwerfen** nimmt alles zurück.

### Die Vorschau „So sehen Gäste es“

Neben der Woche zeigt die Vorschau, was die Buchungsseite anbietet. Sie rechnet
mit den Zeiten im Formular, auch bevor du speicherst. Du siehst die angebotenen
Uhrzeiten eines Tages und die **letzte Uhrzeit** je Fenster. Die letzte Uhrzeit
liegt so weit vor dem Schließen, wie ein Besuch dauert. Ein Fenster, das für
einen Besuch zu kurz ist, bekommt einen Hinweis; dann bietet die Seite dort keine
Uhrzeit an.

Sind alle Tage geschlossen, weist die Seite darauf hin: Gäste finden online keine
Uhrzeit.

### Buchbare Saison

Der Abschnitt „Buchbare Saison“ gilt jedes Jahr, zum Beispiel für eine
Terrasse, die von April bis Oktober öffnet. Mit **Saison hinzufügen** trägst du
Beginn und Ende (Tag und Monat) ein, optional mit Bezeichnung. Ohne Eintrag ist
das ganze Jahr buchbar. Außerhalb der Saison nimmt die Buchungsseite keine
Buchungen an und zeigt, ab wann es wieder losgeht.

## 2. Ausnahmen (ein Tag oder Zeitraum ist anders)

**Einstellungen › Platz & Zeiten › Ausnahmen.** Hier steht alles, was an
bestimmten Tagen anders ist: Feiertage, Betriebsferien, besondere
Öffnungszeiten, weniger Plätze, gesperrte Räume oder Tische. Die Liste zeigt
„Kommend“ nach Monaten und darunter „Vergangen“.

Mit **Ausnahme anlegen** öffnet sich die Seitenleiste:

- **Von** und **Bis**: der Zeitraum. Ohne „Bis“ gilt die Ausnahme für einen
  einzelnen Tag. Der letzte Tag liegt heute oder später, und ein Zeitraum hat
  eine Höchstlänge (Vorgabe 93 Tage).
- **Bezeichnung**: ein Name wie „Betriebsferien“.
- **Was ist anders?** – du wählst eine oder mehrere Wirkungen:
  - **Geschlossen**: Niemand kann online buchen. „Geschlossen“ steht für sich
    allein und lässt sich nicht mit einer anderen Wirkung verbinden.
  - **Andere Öffnungszeiten**: An diesen Tagen gelten nur die Zeitfenster, die
    du einträgst, etwa kürzer an Heiligabend. Mehrere Fenster ergeben eine Pause
    dazwischen.
  - **Weniger Gäste** (Restaurant): Höchstens so viele Gäste gleichzeitig, für
    den ganzen Betrieb oder nur für einen Raum, optional in einem Zeitfenster
    des Tages. Gut, wenn eine Gesellschaft die halbe Küche bindet.
  - **Räume oder Tische sperren** (Restaurant): Du hakst ganze Räume oder
    einzelne Tische an, optional mit Uhrzeiten. Sperren wirken in den Zählweisen
    „Nach Tischen“ und „Beides“, denn dort vergibt Swayy Tische. In „Nach
    Personen“ fehlen diese Karte und die Wahl eines Raums bei „Weniger Gäste“;
    die Grenze gilt dann für den ganzen Betrieb (Zählweise unter *Kapazität &
    Dauer*).
- **Uhrzeiten** bei „Weniger Gäste“ und beim Sperren: Beginn und Ende, beide
  leer für den ganzen Tag.

Im Fuß der Seitenleiste steht **So wirkt es**: ein Satz, der beschreibt, was
Gäste erleben, zum Beispiel wann sie noch buchen können. Er ändert sich mit
deinen Eingaben und zeigt Fehler, bevor du speicherst.

**Gruppen:** Eine Ausnahme mit mehreren Wirkungen, etwa Betriebsferien mit
„Geschlossen“ und gesperrter Terrasse, bleibt ein Eintrag. Du bearbeitest und
löschst sie gemeinsam. Ältere Einträge aus der Zeit vor dieser Seite (frühere
Sondertage und Sperren) stehen einzeln in der Liste; die Zeilen eines alten
Sondertags am selben Datum, etwa Mittag und Abend, bilden einen Eintrag. Sie
lassen sich löschen, und wo das Formular sie abbilden kann, auch bearbeiten.
Trägt ein älterer Eintrag Räume oder Tische, während die Zählweise „Nach
Personen“ gilt, steht in der Liste ein Hinweis dazu.

**Bestehende Reservierungen bleiben.** Eine Ausnahme storniert nichts. Liegen im
Zeitraum schon Reservierungen (im Salon Termine), nennt die Meldung nach dem
Speichern ihre Zahl. Informiere die Gäste bei Bedarf selbst. Eine Tischsperre
lehnt Swayy ab, solange auf dem Tisch eine Reservierung liegt (siehe
*Tischplan*).

Trag Feiertage und Betriebsferien **im Voraus** ein. Der häufigste peinliche
Fehler ist eine Onlinebuchung für einen Tag, an dem gar niemand da ist.

An jedem Datum gilt höchstens eine Ausnahme mit „Geschlossen“ oder „Andere
Öffnungszeiten“. Liegt auf dem Tag schon eine, nennt die Meldung sie und bittet
dich, sie zu bearbeiten oder zuerst zu löschen. „Weniger Gäste“ und Sperren
dürfen sich überlagern.

## 3. Einzelne Tische sperren

Für „dieser eine Tisch, an diesem Abend“ wählst du in einer Ausnahme „Räume oder
Tische sperren“. Im Tischplan geht es direkt über **„Tisch sperren…“** im Fenster
des Tisches. Die Reihenfolge ist: Standort → Raum → einzelner Tisch.

## 4. Kapazität & Dauer

**Einstellungen › Platz & Zeiten › Kapazität & Dauer.**

**Takt der Uhrzeiten** – in welchem Abstand Uhrzeiten angeboten werden (15, 30
oder 60 Minuten). Kleinerer Takt = mehr Auswahl für den Gast, aber zerstückelte
Tische.

**Standarddauer** (Restaurant) – wie lange ein Tisch als belegt gilt. Zu kurz und
ihr steht unter Druck; zu lang und der Abend verkauft sich nur einmal.

**Tischzeiten je Gruppengröße** (Restaurant) – dasselbe, aber gestaffelt: 1–2
Personen 75 Minuten, 3–5 Personen 105, 6–20 Personen 150. Realistischer als ein
Einheitswert, weil große Runden länger sitzen. Mit „Tischzeit hinzufügen“ legst
du eine Regel an. Jede Gruppengröße gehört zu höchstens einer Regel, die Bereiche
überschneiden sich also nie.

**Puffer nach jeder Buchung** – Aufräumzeit zwischen zwei Buchungen am selben
Tisch (im Salon zwischen zwei Terminen).

**Lückenoptimierung** (Salon) – siehe Kapitel *Friseur- und
Dienstleisterbetrieb*.

### Kapazität: Tische oder Plätze?

Im Restaurant wählst du unter „Wie Swayy die Plätze zählt“ die Zählweise:

- **Nach Tischen** (Standard): Es kann gebucht werden, solange ein passender
  Tisch frei ist. Realistisch für Restaurants.
- **Nach Personen**: Es zählt die Gesamtzahl der Gäste pro Zeitfenster, bis zur
  Höchstzahl („Höchstens gleichzeitig“). Sinnvoll für offene Konzepte mit freier
  Platzwahl, etwa eine Bar.
- **Beides**: Es braucht einen passenden Tisch, und die Höchstzahl gilt
  zusätzlich.

## 5. Buchungsregeln

**Einstellungen › Buchungen › Buchungsregeln.** Was sie bewirken:

**Mindestvorlauf** – wie kurzfristig online gebucht werden darf. 60 Minuten sind
üblich; 0 heißt „bis zur letzten Sekunde“.

**Vorausbuchung** – wie weit in die Zukunft die Buchungsseite freie Tage zeigt.
90 Tage sind üblich.

**Kleinste und größte Gruppe** (Restaurant) – größere Gruppen sollen anrufen?
Dann trag als größte Gruppe zum Beispiel 8 ein und gib unter „Adresse für größere
Gruppen“ eine Mailadresse an. Gruppen darüber sehen auf der Buchungsseite diese
Adresse. Lässt du das Feld leer, entfällt der Hinweis.

**Stornofrist und Umbuchungsfrist** – bis wann der Gast selbst darf. Danach geht
es nur noch über euch: Im Reservierungsbuch ändert **Bearbeiten** Datum, Uhrzeit und
Personenzahl, und **Wieder freigeben** holt eine stornierte Reservierung zurück
(siehe *Das Reservierungsbuch*).

**Bestätigung** – „Sofort bestätigen“ oder „Von Hand freigeben“, dazu das
Kästchen „E-Mail-Adresse bestätigen lassen“. Siehe *Einrichtung*. Die
folgenreichste Einstellung.

**Warteliste und Walk-ins** – ob Gäste auf eine Warteliste kommen, wenn alles
belegt ist, und ob dein Team Walk-ins im Live-Board erfasst.

## Wenn nichts angeboten wird

Diese Kette von oben nach unten durchgehen:

1. Öffnungszeit für den Wochentag hinterlegt (Schalter „Geöffnet“) und liegt der
   Tag in der buchbaren Saison?
2. Ausnahme „Geschlossen“ für das Datum?
3. Ausnahme mit „Weniger Gäste“ oder gesperrten Räumen, die den Zeitraum
   abdeckt?
4. Mindestvorlauf – liegt der Termin zu nah?
5. „Vorausbuchung“ – liegt er zu weit weg?
6. Gruppengröße innerhalb der Online-Grenzen?
7. Gibt es überhaupt einen Tisch mit passender Kapazität – und ist er „online
   buchbar“?
8. Ist er womöglich gesperrt?

In neun von zehn Fällen steckt die Antwort in Punkt 1, 3 oder 7. Die Vorschau
„So sehen Gäste es“ unter den Öffnungszeiten zeigt dir Punkt 1 auf einen Blick.
