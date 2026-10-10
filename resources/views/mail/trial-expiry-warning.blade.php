@if ($du)
Hallo {!! $recipientName !!},

die kostenlose Testphase für „{!! $tenant->name !!}“ endet am {!! $tenant->trial_ends_at->format('d.m.Y') !!} um {!! $tenant->trial_ends_at->format('H:i') !!} Uhr. Danach kannst du dich weiter anmelden; die Verwaltung öffnet sich wieder, sobald ein Tarif gewählt ist.

Damit es nahtlos weitergeht, füll das kurze Formular aus. Wir fragen dort deinen Wunsch-Tarif und deine Rechnungsanschrift ab und melden uns dann bei dir.

Tarif wählen: {!! route('admin.trial.expired') !!}

Fragen beantworten wir gern unter {!! $support !!}.

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

die kostenlose Testphase für „{!! $tenant->name !!}“ endet am {!! $tenant->trial_ends_at->format('d.m.Y') !!} um {!! $tenant->trial_ends_at->format('H:i') !!} Uhr. Danach können Sie sich weiter anmelden; die Verwaltung öffnet sich wieder, sobald ein Tarif gewählt ist.

Damit es nahtlos weitergeht, füllen Sie bitte das kurze Formular aus. Wir fragen dort Ihren Wunsch-Tarif und Ihre Rechnungsanschrift ab und melden uns dann bei Ihnen.

Tarif wählen: {!! route('admin.trial.expired') !!}

Fragen beantworten wir gern unter {!! $support !!}.

Viele Grüße
Ihr Swayy-Team
@endif
