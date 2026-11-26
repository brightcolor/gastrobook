{{-- Sagt dem Gast VOR dem Absenden, wie es weitergeht: Bestätigungslink per
     E-Mail und/oder Freigabe durch den Betrieb. Erwartet $settings, $du und
     $isSalon. Bei sofortiger Bestätigung ohne Link gibt es nichts zu sagen. --}}
@php
    $confirmByMail = (bool) $settings->require_email_confirmation;
    $manualApproval = $settings->freigabeVonHand();
    $place = $isSalon ? ($du ? 'dein Termin' : 'Ihr Termin') : ($du ? 'dein Tisch' : 'Ihr Tisch');
    $booked = $isSalon ? 'gebucht' : 'reserviert';
    $confirmationName = $isSalon ? 'Terminbestätigung' : 'Reservierungsbestätigung';
    $linkValidity = \App\Support\Duration::minutes((int) config('swayy.guest_auth.verify_minutes', 1440));
@endphp
@if($confirmByMail || $manualApproval)
    <div role="note" data-booking-next-steps class="rounded-xl bg-amber-50 p-4 text-left text-sm leading-relaxed text-amber-900">
        <p class="font-bold">So geht es weiter</p>
        @if($confirmByMail && $manualApproval)
            <p class="mt-1">
                Nach dem Absenden {{ $du ? 'bekommst du' : 'bekommen Sie' }} eine E-Mail mit einem Bestätigungslink.
                Mit dem Klick darauf geht {{ $du ? 'deine' : 'Ihre' }} Anfrage an uns.
                Wir prüfen sie und {{ $du ? 'schicken dir' : 'schicken Ihnen' }} dann die {{ $confirmationName }}.
            </p>
            <p class="mt-1 text-amber-800">Der Link gilt {{ $linkValidity }}.</p>
        @elseif($confirmByMail)
            <p class="mt-1">
                Nach dem Absenden {{ $du ? 'bekommst du' : 'bekommen Sie' }} eine E-Mail mit einem Bestätigungslink.
                <strong>{{ ucfirst($place) }} ist {{ $booked }}, sobald {{ $du ? 'du den Link anklickst' : 'Sie den Link anklicken' }}.</strong>
            </p>
            <p class="mt-1 text-amber-800">Der Link gilt {{ $linkValidity }}.</p>
        @else
            <p class="mt-1">
                Wir prüfen jede Anfrage persönlich und
                @if($settings->bookingFieldRule('email') !== 'hidden')
                    {{ $du ? 'schicken dir' : 'schicken Ihnen' }} dann die {{ $confirmationName }} per E-Mail.
                @else
                    melden uns bei {{ $du ? 'dir' : 'Ihnen' }}, sobald {{ $place }} feststeht.
                @endif
            </p>
        @endif
    </div>
@endif
