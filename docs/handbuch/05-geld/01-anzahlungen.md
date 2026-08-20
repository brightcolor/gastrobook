# Anzahlungen und No-Show-Schutz

Eine Anzahlung ist das wirksamste Mittel gegen Gäste, die nicht kommen und nicht
absagen. Sie kostet dich Gebühren und ein bisschen Buchungsbereitschaft –
deshalb lohnt sich der Blick auf beide Seiten der Rechnung.

## Voraussetzungen

1. Ein **Zahlungsanbieter** ist verbunden: **Stripe** oder **PayPal** (oder
   beide). Einzurichten unter **Einstellungen → Zahlungen**.
2. Dein Tarif enthält Anzahlungen.
3. Mindestens eine **Anzahlungsregel** ist angelegt.

Zum Verbinden brauchst du ein Konto beim jeweiligen Anbieter und die
Zugangsdaten von dort. Die Daten werden verschlüsselt gespeichert und nie wieder
angezeigt. **Karten- oder Kontodaten deiner Gäste liegen zu keinem Zeitpunkt in
Swayy** – die Zahlung läuft auf den Seiten des Anbieters.

Sind beide Anbieter aktiv, darf der Gast an der Kasse wählen.

## Eine Regel anlegen

**Einstellungen → Zahlungen → Anzahlungsregeln.** Eine Regel besteht aus
Bedingungen und einem Betrag:

**Bedingungen** (alle optional, wirken zusammen):

- ab Personenzahl (z. B. ab 6)
- Wochentage
- ab/bis Uhrzeit (z. B. erst ab 18:00)
- bestimmter Raum
- bestimmtes Event
- im Salon: bestimmte Leistung

**Betrag:** Grundbetrag + Betrag pro Person.
Beispiel: 20 € Grundbetrag + 5 € pro Person → bei vier Gästen 40 €.

**Zahlungsfrist:** Wie lange der Gast Zeit hat (üblich 60 Minuten).

**Automatisch stornieren:** Ob eine unbezahlte Buchung nach Fristablauf von
selbst frei wird. An = der Platz wird wieder verkauft. Aus = die Buchung bleibt
offen und ihr fasst selbst nach.

Trifft eine Buchung mehrere Regeln, gewinnt die spezifischere.

## Was der Gast erlebt

Greift eine Regel, ist die Buchung zunächst **„Zahlung ausstehend"**. Der Gast
bekommt einen Zahlungslink, zahlt beim Anbieter und die Buchung bestätigt sich
automatisch.

Drei Nachrichten laufen dabei von selbst:

| Wann | Nachricht |
|---|---|
| sofort nach der Buchung | Zahlungsaufforderung mit Betrag, Link und Frist |
| nach der halben Frist | Erinnerung, dass die Anzahlung noch aussteht |
| nach Fristablauf | Absage – nur wenn „Automatisch stornieren" an ist |

Der Tisch bleibt bis zum Fristende reserviert und wird danach wieder verkauft.
Hat der Gast den Bezahlvorgang **kurz vor Schluss noch gestartet**, wartet
Swayy eine Viertelstunde länger – wer pünktlich klickt, soll seinen Tisch nicht
deshalb verlieren, weil die Kartenzahlung ein paar Minuten dauert.

Steht „Automatisch stornieren" auf Aus, bleibt die Buchung nach Fristablauf
stehen und wartet auf euch. Sie steht dann im Reservierungsbuch weiterhin unter
**Zahlung ausstehend** – bitte regelmäßig durchsehen, sonst blockieren diese
Buchungen Plätze, für die nie Geld gekommen ist.

An jeder Zahlungsstelle steht der Hinweis: *Die Vorauszahlung wird beim Besuch
vollständig mit der Rechnung verrechnet. Bei Nichterscheinen erfolgt keine
Rückerstattung.* Damit ist die Einbehaltung transparent vereinbart – nimm den
Punkt zusätzlich in deine AGB auf.

## Was es kostet

Swayy nimmt für Zahlungen **keine eigene Provision**. Es fallen nur die Gebühren
deines Zahlungsanbieters an, und die rechnest du direkt mit ihm ab.

> **Zu den Zahlen unten:** Das sind marktübliche Größenordnungen für
> europäische Karten, Stand August 2026. Die genauen Konditionen stehen in
> deinem Vertrag mit Stripe bzw. PayPal und ändern sich – bitte dort prüfen.

**Typisch:**

- Stripe: rund **1,5 % + 0,25 €** je Kartenzahlung (EU-Karten)
- PayPal: rund **2,5 % + 0,35 €** je Zahlung

### Rechenbeispiel 1: eine einzelne Anzahlung

Anzahlung 40 € (20 € Grund + 5 € × 4 Personen):

| | Stripe | PayPal |
|---|---|---|
| Gebühr | 1,5 % von 40 € = 0,60 € + 0,25 € = **0,85 €** | 2,5 % von 40 € = 1,00 € + 0,35 € = **1,35 €** |
| Bei dir ankommend | 39,15 € | 38,65 € |

### Rechenbeispiel 2: ein Monat

Angenommen: 400 Reservierungen im Monat, davon greift bei **50** eine
Anzahlungsregel (große Gruppen, Freitag/Samstag abends), im Schnitt 40 €.

| | Stripe | PayPal |
|---|---|---|
| Gebühren gesamt | 50 × 0,85 € = **42,50 €** | 50 × 1,35 € = **67,50 €** |

### Rechenbeispiel 3: lohnt sich das?

Ohne Anzahlung: 400 Buchungen, No-Show-Quote 6 % = 24 ausgefallene Tische.
Bei durchschnittlich 3 Personen und 35 € Umsatz pro Person sind das
**2.520 € entgangener Umsatz** – Tische, die niemand mehr bekommen hat.

Erfahrungsgemäß sinkt die No-Show-Quote bei den betroffenen Buchungen deutlich,
sobald Geld im Spiel ist. Selbst wenn nur ein Drittel dieser Ausfälle vermieden
wird, stehen rund 840 € gerettetem Umsatz etwa 42,50 € Gebühren gegenüber.

Genau deshalb lohnt es sich, die Regel **eng zu schneiden**: nur große Gruppen,
nur Stoßzeiten. Dort sitzt der Schaden. Eine Anzahlung für den Zweiertisch am
Dienstagmittag ärgert die Gäste und bringt fast nichts.

### Was bei einer Rückerstattung passiert

Bei einer Rückerstattung bekommt der Gast seinen Betrag, **die Gebühr des
Anbieters bekommst du in der Regel nicht zurück**. Ein voll erstatteter 40-€-
Deposit kostet dich also die 0,85 € (Stripe) bzw. 1,35 € (PayPal). Für den
Einzelfall vernachlässigbar, aber ein Grund, Regeln nicht wahllos zu streuen.

## Wenn du unsicher bist

Fang klein an: eine Regel, nur für Gruppen ab 6 Personen, nur Freitag und
Samstag ab 18:00, 10 € pro Person, Frist 60 Minuten, automatisches Stornieren
an. Nach zwei Monaten siehst du in den Berichten, was die No-Show-Quote gemacht
hat, und kannst nachjustieren.
