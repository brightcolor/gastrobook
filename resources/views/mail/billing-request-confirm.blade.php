Bitte bestätigen Sie Ihre Anfrage
==================================

Hallo {{ $billingRequest->contact_name }},

vielen Dank für Ihre Anfrage! Um sicherzustellen, dass diese E-Mail-
Adresse korrekt ist, klicken Sie bitte auf den folgenden Link:

{{ $confirmUrl }}

Erst nach dieser Bestätigung wird Ihre Anfrage an uns weitergeleitet
und Ihr Konto wieder freigeschaltet.

Der Link ist 72 Stunden gültig.

Falls Sie diese Anfrage nicht gestellt haben, können Sie diese
E-Mail einfach ignorieren.

Gewünschter Tarif: {{ $billingRequest->plan_key }}
Betrieb: {{ $billingRequest->tenant->name }}

Viele Grüße,
Ihr Swayy-Team
