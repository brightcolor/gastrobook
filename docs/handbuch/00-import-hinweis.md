# Hinweis für den Import (nicht Teil des Handbuchs)

Diese Seite richtet sich an die Person, die das Handbuch in BookStack einspielt.
Sie gehört **nicht** in das fertige Buch.

## Aufbau

Ein Ordner = ein Kapitel, eine Datei = eine Seite. Die Nummern im Dateinamen
geben die Reihenfolge vor, die Überschrift `# …` in der ersten Zeile ist der
Seitentitel.

```
01-erste-schritte/      Kapitel „Erste Schritte"
02-alltag/              Kapitel „Der tägliche Betrieb"
03-online-buchungen/    Kapitel „Buchungen von außen"
04-salon/               Kapitel „Friseur- und Dienstleisterbetrieb"
05-geld/                Kapitel „Geld"
06-nachrichten/         Kapitel „Nachrichten an Gäste"
07-pflichten/           Kapitel „Datenschutz und Pflichten"
08-verwaltung/          Kapitel „Verwaltung"
```

## Import

BookStack legt Bücher, Kapitel und Seiten nicht automatisch aus einem
Ordnerbaum an. Zwei Wege:

1. **Von Hand** (für einmalig 26 Seiten gut machbar): Buch „Swayy – Handbuch"
   anlegen, je Ordner ein Kapitel, je Datei eine Seite. In der Seite den
   Markdown-Editor wählen und den Dateiinhalt **ohne die erste
   Überschriftszeile** einfügen – die steht schon im Seitentitel.
2. **Über die BookStack-API** (`/api/books`, `/api/chapters`, `/api/pages` mit
   `markdown`-Feld). Reihenfolge: Buch → Kapitel → Seiten, jeweils mit
   `priority` entsprechend der Dateinummer.

## Beim Aktualisieren beachten

Preise Dritter (SMS, Kartenzahlung) stehen in den Texten als **Beispielwerte mit
Datum**. Sie sind bewusst als „bitte prüfen" markiert, damit niemand veraltete
Zahlen für bare Münze nimmt. Wenn du das Handbuch pflegst: Zahlen aktualisieren
oder den Hinweis stehen lassen, aber nicht kommentarlos als Tatsache verkaufen.
