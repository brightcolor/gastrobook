{{-- Hinweis auf den Spam-Ordner für jede Meldung, die eine Mail ankündigt
     (Text in App\Support\MailNotice). --}}
@props(['du' => false])
<p data-spam-hint {{ $attributes->merge(['class' => 'mt-2 text-sm']) }}>{{ \App\Support\MailNotice::spamHint((bool) $du) }}</p>
