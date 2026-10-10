@if ($du)
Hallo {!! $billingRequest->contact_name !!},

danke für deine Anfrage. Bestätige bitte noch deine E-Mail-Adresse, dann geht sie an uns, und dein Konto wird wieder freigeschaltet.

Gewünschter Tarif: {!! $billingRequest->plan_key !!}
Betrieb: {!! $billingRequest->tenant->name !!}

Anfrage bestätigen: {!! $confirmUrl !!}

---

Der Link gilt {!! $hours !!} Stunden. Hast du keine Anfrage gestellt, kannst du diese Mail löschen.
@else
Hallo {!! $billingRequest->contact_name !!},

vielen Dank für Ihre Anfrage. Bitte bestätigen Sie noch Ihre E-Mail-Adresse, dann geht sie an uns, und Ihr Konto wird wieder freigeschaltet.

Gewünschter Tarif: {!! $billingRequest->plan_key !!}
Betrieb: {!! $billingRequest->tenant->name !!}

Anfrage bestätigen: {!! $confirmUrl !!}

---

Der Link gilt {!! $hours !!} Stunden. Haben Sie keine Anfrage gestellt, können Sie diese Mail löschen.
@endif
