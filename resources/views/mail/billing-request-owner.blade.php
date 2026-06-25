Neue bestätigte Billing-Anfrage – {{ $billingRequest->tenant->name }}
=======================================================================

Der Kunde hat seine E-Mail-Adresse bestätigt. Details:

BETRIEB
  Name:        {{ $billingRequest->tenant->name }}
  Tenant-ID:   {{ $billingRequest->tenant_id }}

KONTAKT / RECHNUNGSANSCHRIFT
  Name:        {{ $billingRequest->contact_name }}
  E-Mail:      {{ $billingRequest->contact_email }}
@if($billingRequest->phone)
  Telefon:     {{ $billingRequest->phone }}
@endif
@if($billingRequest->company_name)
  Firma:       {{ $billingRequest->company_name }}
@endif
  Adresse:     {{ $billingRequest->address_line1 }}
@if($billingRequest->address_line2)
               {{ $billingRequest->address_line2 }}
@endif
               {{ $billingRequest->postal_code }} {{ $billingRequest->city }}, {{ $billingRequest->country }}
@if($billingRequest->vat_id)
  USt-ID:      {{ $billingRequest->vat_id }}
@endif

TARIF-WUNSCH
  {{ $billingRequest->plan_key }}

@if($billingRequest->notes)
NACHRICHT VOM KUNDEN
{{ $billingRequest->notes }}

@endif
AKTION
  Konto freischalten (Klick aktiviert sofort):
  {{ $activateUrl }}

  Anfrage im Admin ansehen:
  {{ route('admin.billing-requests.index') }}

---
Swayy-Plattform · Eingang: {{ now()->format('d.m.Y H:i') }} Uhr
