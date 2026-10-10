Die Kontaktadresse ist bestätigt. Die Anfrage wartet auf die Freischaltung.

Betrieb: {!! $billingRequest->tenant->name !!}
Betriebs-ID: {!! $billingRequest->tenant_id !!}
Tarif-Wunsch: {!! $billingRequest->plan_key !!}

Name: {!! $billingRequest->contact_name !!}
E-Mail: {!! $billingRequest->contact_email !!}
@if ($billingRequest->phone)
Telefon: {!! $billingRequest->phone !!}
@endif
@if ($billingRequest->company_name)
Firma: {!! $billingRequest->company_name !!}
@endif
Adresse: {!! $billingRequest->address_line1 !!}{!! $billingRequest->address_line2 ? ', '.$billingRequest->address_line2 : '' !!}, {!! $billingRequest->postal_code !!} {!! $billingRequest->city !!}, {!! $billingRequest->country !!}
@if ($billingRequest->vat_id)
USt-ID: {!! $billingRequest->vat_id !!}
@endif
@if ($billingRequest->notes)

Nachricht vom Kunden:
{!! $billingRequest->notes !!}
@endif

Konto freischalten: {!! $activateUrl !!}

Anfrage ansehen: {!! route('admin.billing-requests.index') !!}

---

Eingang: {!! now()->format('d.m.Y H:i') !!} Uhr
