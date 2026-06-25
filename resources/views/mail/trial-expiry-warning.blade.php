Ihr Swayy-Testzeitraum endet in 5 Tagen
========================================

Hallo {{ $recipientName }},

der kostenlose Testzeitraum für "{{ $tenant->name }}" läuft am
{{ $tenant->trial_ends_at->format('d.m.Y') }} um
{{ $tenant->trial_ends_at->format('H:i') }} Uhr ab.

Nach Ablauf können Sie und Ihre Mitarbeiter sich zwar noch einloggen,
haben aber keinen Zugriff mehr auf die Admin-Funktionen.

Um nahtlos weiterzumachen, füllen Sie bitte das kurze Formular aus –
Ihr Wunsch-Tarif und Ihre Rechnungsanschrift werden dort abgefragt.
Wir melden uns dann direkt bei Ihnen, um alles Weitere zu klären.

Formular aufrufen:
{{ route('admin.trial.expired') }}

Haben Sie Fragen? Schreiben Sie uns an info@swayy.de.

Viele Grüße,
Ihr Swayy-Team
