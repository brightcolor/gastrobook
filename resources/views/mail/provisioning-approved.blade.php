@if ($du)
Hallo {!! $recipientName !!},

dein Betrieb „{!! $tenantName !!}“ ist freigeschaltet. Ab jetzt nehmen deine Buchungsseiten Online-Reservierungen von Gästen an.

Zur Anmeldung: {!! $loginUrl !!}

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

Ihr Betrieb „{!! $tenantName !!}“ ist freigeschaltet. Ab jetzt nehmen Ihre Buchungsseiten Online-Reservierungen von Gästen an.

Zur Anmeldung: {!! $loginUrl !!}

Viele Grüße
Ihr Swayy-Team
@endif
