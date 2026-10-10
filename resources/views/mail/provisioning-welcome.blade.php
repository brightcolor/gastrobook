@if ($du)
Hallo {!! $recipientName !!},

dein Betrieb „{!! $tenantName !!}“ ist bei Swayy eingerichtet. In Swayy kommst du jederzeit über deinen Kundenbereich: Unter „Verträge“ führt „Zum Dienst“ direkt hinein.

Zum Kundenbereich: {!! $panelUrl !!}

Möchtest du dich auch direkt bei Swayy anmelden, lege dir einmal ein eigenes Passwort fest und melde dich danach an.

Passwort festlegen: {!! $passwordUrl !!}
Zur Anmeldung: {!! $loginUrl !!}
@if ($waitsForApproval)

Deine Verwaltung kannst du schon einrichten. Online-Reservierungen von Gästen nimmt Swayy an, sobald wir deinen Betrieb freigeschaltet haben. Dazu bekommst du eine eigene Mail.
@endif

Tarif, Verträge und Rechnungen findest du im Kundenbereich.

Viele Grüße
Dein Swayy-Team
@else
Hallo {!! $recipientName !!},

Ihr Betrieb „{!! $tenantName !!}“ ist bei Swayy eingerichtet. In Swayy kommen Sie jederzeit über Ihren Kundenbereich: Unter „Verträge“ führt „Zum Dienst“ direkt hinein.

Zum Kundenbereich: {!! $panelUrl !!}

Möchten Sie sich auch direkt bei Swayy anmelden, legen Sie sich einmal ein eigenes Passwort fest und melden sich danach an.

Passwort festlegen: {!! $passwordUrl !!}
Zur Anmeldung: {!! $loginUrl !!}
@if ($waitsForApproval)

Ihre Verwaltung können Sie schon einrichten. Online-Reservierungen von Gästen nimmt Swayy an, sobald wir Ihren Betrieb freigeschaltet haben. Dazu bekommen Sie eine eigene Mail.
@endif

Tarif, Verträge und Rechnungen finden Sie im Kundenbereich.

Viele Grüße
Ihr Swayy-Team
@endif
