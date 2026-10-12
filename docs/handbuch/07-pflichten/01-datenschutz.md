# Datenschutz im Alltag

Du verarbeitest Namen, Kontaktdaten, Besuchshistorien und manchmal
Gesundheitsangaben (Allergien). Dieses Kapitel zeigt, welche Werkzeuge Swayy
dafür hat und was du selbst tun musst.

*Es ersetzt keine Rechtsberatung. Im Zweifel frag deine Datenschutzbeauftragte
oder einen Anwalt.*

## Was gespeichert wird

- **Buchungsdaten**: Name, Kontakt, Zeit, Personenzahl, Anmerkungen
- **Gastprofil**: Besuche, No-Shows, Vorlieben, Tags, Notizen
- **Allergien und Barrierefreiheit**, wenn der Gast sie angibt – das sind
  besondere Daten, deshalb freiwillig und nur zur Berücksichtigung beim Besuch
- **Einwilligungen** mit Zeitpunkt und Kanal
- **Dateien**, die ihr an eine Buchung hängt
- **Protokoll** (Auditlog): wer im System was getan hat

## Einwilligungen

Bei der Buchung stimmt der Gast der Datenschutzerklärung zu; die
Marketing-Einwilligung ist ein **eigenes, freiwilliges** Häkchen. Beides wird mit
Zeitpunkt festgehalten.

Auf dem Gastprofil siehst du die **Einwilligungshistorie**: erteilt, widerrufen,
wann, über welchen Kanal. Diese Nachweispflicht ist der Grund, warum die Liste
existiert – ohne sie kannst du im Streitfall nichts belegen.

Ein Widerruf über den Abmeldelink landet automatisch dort.

## Auskunft (Art. 15/20)

Ein Gast fragt, was ihr über ihn gespeichert habt?

**Gäste (im Salon: Kunden) → Gast öffnen → Datenexport.** Das erzeugt eine Datei mit Profil,
Buchungshistorie, Einwilligungen, Notizen und den Namen angehängter Dateien.
Diese Datei gibst du dem Gast.

## Löschen (Art. 17)

**Gäste (im Salon: Kunden) → Gast öffnen → Anonymisieren.**

Was passiert: Name, Kontaktdaten, Adresse, Geburtstag, Vorlieben, Allergien und
Notizen werden unwiderruflich entfernt. Ebenso Dateien an seinen Buchungen und
Kopien aus zusammengeführten Profilen. Die Buchungen selbst bleiben **anonym**
bestehen, damit deine Auslastungszahlen nicht zerfallen.

**Das lässt sich nicht rückgängig machen.** Und ein Hinweis für die Praxis:
Wenn an einer Buchung ein Dokument hängt, das du aus steuerlichen Gründen
aufbewahren musst, gehört es in deine Buchhaltung – nicht nur hierher.

## Aufbewahrung

Gastprofile ohne Aktivität anonymisiert Swayy automatisch nach einer festen
Aufbewahrungsfrist. Die Vorgabe sind 36 Monate; ein nächtlicher Lauf erledigt
das. Die Frist gilt je Betrieb und liegt beim Betreiber deiner Swayy-Installation.
Eine Seite in den Einstellungen gibt es dafür bisher nicht. Brauchst du eine
andere Frist, frag den Betreiber und halte die gewählte Frist in deiner
Datenschutzerklärung fest.

Kürzere Fristen sind datenschutzfreundlicher, längere besser für die
Stammgastpflege. 24 bis 36 Monate sind ein üblicher Kompromiss – entscheide es
bewusst und schreib es in deine Datenschutzerklärung.

## Interne Notizen

Notizen über Menschen sind personenbezogene Daten und können Gegenstand einer
Auskunft sein. Halte fest, was für den Service nötig ist. Bewertungen von
Personen gehören nicht hinein.

Für heikle, aber notwendige Vermerke gibt es die Markierung **sensibel** – die
sehen nur Rollen mit dem entsprechenden Recht.

## Wer darf was sehen

Die Rechte sind eng geschnitten: Notizen, sensible Notizen, Einwilligungen,
Export und Anonymisierung hängen an eigenen Berechtigungen. Vergib Rollen so
knapp wie möglich – das ist gelebte Datenminimierung und im Zweifel dein bester
Nachweis.

## Auftragsverarbeiter

Sobald du Dienste einbindest, verarbeiten Dritte Daten deiner Gäste:

| Dienst | Wofür | Was übermittelt wird |
|---|---|---|
| seven.io | SMS-Erinnerungen (Einstellungen › Gästekommunikation › SMS-Versand) | Mobilnummer, Nachrichtentext |
| Stripe / PayPal | Anzahlungen (Einstellungen › Zahlungen › Zahlungsanbieter) | Zahlungsdaten, Betrag, Referenz |
| GoCardless | dein eigenes Abo | deine Bankdaten (nicht die der Gäste) |
| MailWizz | Newsletter, falls genutzt (Einstellungen › Verbindungen › Newsletter) | E-Mail-Adresse, Name |

Für jeden dieser Dienste brauchst du einen **Auftragsverarbeitungsvertrag** und
einen Eintrag in deinem **Verarbeitungsverzeichnis**. Die Anbieter stellen
Musterverträge bereit. Nutzt du einen Dienst nicht, entfällt das.

## Zugriff durch den Anbieter

Wenn der Plattformbetreiber zur Fehlersuche in deinen Betrieb schaut, wird das
im **Protokoll** vermerkt (**Einstellungen › Betrieb › Protokoll**). Da kannst du
jederzeit nachsehen.
