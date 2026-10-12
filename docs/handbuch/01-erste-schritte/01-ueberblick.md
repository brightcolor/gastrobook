# Was Swayy ist – und wie alles zusammenhängt

Swayy nimmt Reservierungen und Termine an, verteilt sie auf Tische oder
Mitarbeiter, erinnert die Gäste, merkt sich, wer schon einmal da war, und sagt
dir hinterher, wie der Laden lief. Alles in einem System, ohne dass du im
Kalender, im Notizbuch und im Mailpostfach nebeneinander arbeiten musst.

Dieses Handbuch beschreibt, was du im Betrieb damit tust. Es setzt keine
technischen Kenntnisse voraus.

## Die zwei Betriebsarten

Swayy kann als **Restaurantbetrieb** oder als **Friseur-/Dienstleisterbetrieb**
laufen. Das entscheidet, wie das System denkt:

| | Restaurant | Salon / Dienstleister |
|---|---|---|
| Gebucht wird | ein **Tisch** für eine Personenzahl | eine **Leistung** bei einer **Person** |
| Dauer ergibt sich aus | der Gruppengröße | der gewählten Leistung |
| Zentrale Ansicht | Tischplan | Terminplan (Spalte je Mitarbeiter) |
| Menü heißt | „Reservierungen", „Tischplan" | „Termine", „Terminplan", „Leistungen", „Mitarbeiter" |

Umgestellt wird das unter **Einstellungen › Betrieb › Allgemein**. Du kannst
wechseln. Dabei ändert sich der ganze Buchungsablauf, deshalb entscheidest du
das am besten am Anfang.

## Die Bausteine und ihr Zusammenspiel

Es hilft, sich das als Kette vorzustellen:

**Öffnungszeiten** sagen, wann überhaupt gebucht werden kann.
→ **Buchungsregeln** (Vorlauf, Dauer, maximale Gruppengröße) engen das weiter ein.
→ **Ausnahmen** (Feiertage, Betriebsferien, gesperrte Räume und Tische) nehmen
einzelne Zeiträume wieder heraus oder ändern sie.
→ Was übrig bleibt, sind die **freien Zeitfenster**, die ein Gast auf der
**Buchungsseite** sieht.
→ Bucht er, entsteht eine **Reservierung**, die automatisch einem **Tisch**
(oder im Salon einer **Person**) zugeteilt wird.
→ Der Gast bekommt eine **Bestätigungsmail**, später eine **Erinnerung**,
danach eine **Feedback-Anfrage**.
→ Aus seinen Daten entsteht ein **Gästeprofil** mit Besuchszähler – die Basis
für Stammgast-Erkennung und **Marketing-Nachrichten**.
→ Im Alltag arbeitest du auf dem **Live-Board** und im **Reservierungsbuch**.
→ Am Monatsende zeigen die **Berichte**, was daraus geworden ist.

Wenn eine Buchung „nicht geht", liegt es fast immer an einem Glied dieser Kette.
Die Reihenfolge oben ist gleichzeitig die Reihenfolge, in der du suchen
solltest.

## Was gehört wem: Betrieb, Standort, Benutzer

- Ein **Betrieb** ist dein Unternehmen. Alles, was du anlegst, gehört dazu und
  ist von anderen Betrieben strikt getrennt.
- Ein **Standort** ist ein Lokal, ein Salon, eine Filiale. Öffnungszeiten,
  Tische, Buchungsregeln und die Buchungsseite hängen **am Standort**. Wer
  mehrere Standorte hat, stellt sie einzeln ein.
- **Benutzer** sind deine Mitarbeiter. Sie haben eine Rolle, die bestimmt, was
  sie sehen und dürfen (siehe *Anmelden, Rollen und Rechte*).
- **Gäste** gehören dem Betrieb. Ein Gast, der in zwei Filialen war, hat ein
  einziges Profil.

Oben links im Menü kannst du zwischen Standorten umschalten, wenn du mehrere
hast. **Achte darauf, in welchem Standort du gerade arbeitest** – die meisten
Einstellungen wirken nur dort.

## Wo du was findest

| Ich will … | Menüpunkt |
|---|---|
| sehen, was jetzt gerade los ist | Service › Live-Board |
| jemanden ohne Reservierung setzen | Service › Walk-ins |
| Gäste vertrösten und später anbieten | Service › Warteliste |
| sehen, wer wo sitzt | Service › Tischplan (im Salon: Terminplan) |
| eine Buchung suchen, anlegen, ändern | Buchungen › Reservierungen (im Salon: Termine) |
| Events und Tickets verwalten | Buchungen › Events |
| Rückerstattungen freigeben | Buchungen › Erstattungen |
| einen Gast nachschlagen | Gäste (im Salon: Kunden) |
| automatische Gästepost einrichten | Gäste › Marketing |
| Zahlen sehen | Berichte |
| Öffnungszeiten, Regeln, Tische, Texte, Zahlungen ändern | Einstellungen |

Auf dem Smartphone steht unten eine Leiste mit den häufigsten Zielen. Alles
andere liegt unter **Mehr**.

## Die Einstellungen

Alle Einstellungen liegen unter **Einstellungen** am Ende der Seitenleiste,
geordnet in Gruppen. Jede Seite hat ein eigenes Thema und speichert nur ihre
Felder. Das Handbuch nennt den Weg immer so: **Einstellungen › Gruppe › Seite**.
Mit „Zurück zum Betrieb“ kehrst du in die Arbeitsansicht zurück.

| Gruppe | Was du dort findest |
|---|---|
| **Betrieb** | Name und Betriebstyp (Allgemein), Standorte, Team & Rollen, Abo & Rechnungen, Protokoll, Daten (Export, Import, Betrieb löschen) |
| **Platz & Zeiten** | Öffnungszeiten, Ausnahmen, Räume & Tische (im Salon Leistungen und Mitarbeiter), Kapazität & Dauer |
| **Buchungen** | Buchungsregeln, Buchungsformular, Buchungsseite & Widget, Tags |
| **Gästekommunikation** | Benachrichtigungen, Vorlagen, Absender & Antworten, SMS-Versand (im Salon heißt die Gruppe Kundenkommunikation) |
| **Zahlungen** | Zahlungsanbieter, Anzahlungen samt Erstattung |
| **Verbindungen** | Newsletter, API-Schlüssel, Webhooks |

Mit der **Suche** („Einstellungen durchsuchen“) über der Liste der Seiten findest
du eine Einstellung über ihre Stichwörter. Gibst du „Feiertag“, „Stornofrist“
oder „Stripe“ ein, führt die Suche dich an die passende Stelle. Auf dem
Smartphone erreichst du die Einstellungen über **Mehr**. Die Liste zeigt die
Seiten, für die deine Rolle berechtigt ist.

Oben auf jeder Seite steht, wofür sie gilt: **Gilt für alle Standorte** oder
**Gilt für Standort** mit einer Auswahl. Sobald du etwas änderst, erscheint die
Speicherleiste mit **Speichern** und **Verwerfen**.

## Was du regelmäßig anfassen wirst – und was einmal

**Einmal einrichten:** Öffnungszeiten, Räume und Tische (bzw. Leistungen und
Mitarbeiter), Buchungsregeln, Texte, Rechtstexte, Team.

**Ab und zu:** Ausnahmen für Feiertage und Betriebsferien, Anzahlungsregeln,
Events, Marketing-Kampagnen.

**Täglich:** Live-Board, Reservierungsbuch, Walk-ins, Warteliste.

Wenn die einmaligen Dinge sauber stehen, ist der tägliche Betrieb wenig Arbeit.
Deshalb lohnt sich der Einrichtungsteil.
