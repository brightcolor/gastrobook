{{--
    Cap CAPTCHA field for a public form.

    Usage: <x-cap-widget form="booking" /> right above the submit button. The
    form name must match the one on the route's cap middleware, otherwise the
    visitor solves a challenge nobody checks – or gets checked with no widget
    to solve.

    Renders nothing when Cap is off or that form is not protected, so every
    form can carry the tag unconditionally.
--}}
@props(['form'])

@php
    $capVerifier = app(\App\Services\Captcha\CapVerifier::class);
    $capActive = $capVerifier->protects($form);
@endphp

@if ($capActive)
    @php
        $capCfg = $capVerifier->widgetConfig();
        // $errors only exists inside the web middleware stack; the embed and
        // popup scripts render views outside of it.
        $capError = isset($errors) ? $errors->first(\App\Services\Captcha\CapVerifier::TOKEN_FIELD) : null;
    @endphp

    @once
        <script>
            // Point the widget at our own copies before it loads; without this
            // it fetches WebAssembly and pako from a public CDN – a third
            // party in the request chain of every guest booking page.
            window.CAP_CUSTOM_WASM_URL = @js($capCfg['wasm_url']);
            window.CAP_PAKO_URL = @js($capCfg['pako_url']);
        </script>
        <script src="{{ $capCfg['widget_url'] }}" async></script>
        <style>
            .cap-guard cap-widget { display: block; width: 100%; }
        </style>
    @endonce

    <div class="cap-guard mt-4" data-cap-guard>
        <cap-widget data-cap-api-endpoint="{{ $capCfg['api_endpoint'] }}"></cap-widget>
        <p data-cap-hint hidden class="mt-2 text-sm font-semibold text-red-600">
            Bitte zuerst die Sicherheitsprüfung abschließen.
        </p>
        @if ($capError)
            <p class="mt-2 text-sm font-semibold text-red-600">{{ $capError }}</p>
        @endif
    </div>

    @once
        <script>
        (function () {
            'use strict';

            // Holds back a form until the widget has produced its token. The
            // server checks this again – this is only to save the visitor a
            // round trip and a lost form.
            function guard(box) {
                if (box.dataset.capGuarded === '1') { return; }
                box.dataset.capGuarded = '1';

                var form = box.closest('form');
                if (!form) { return; }  // waitlist block sends via fetch instead

                var hint = box.querySelector('[data-cap-hint]');

                form.addEventListener('submit', function (event) {
                    var token = form.querySelector('input[name="cap-token"]');
                    if (token && token.value) { return; }

                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (hint) { hint.hidden = false; }
                    box.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, true);
            }

            function run() {
                document.querySelectorAll('[data-cap-guard]').forEach(guard);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }
        })();
        </script>
    @endonce
@endif
