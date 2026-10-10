@php
    $f = $mailFrame->footer;
    $lines = array_values(array_filter([
        implode(' · ', array_filter([$f['street'], $f['city']])),
        implode(' · ', array_filter([$f['phone'], $f['email']])),
        $f['help'] ? __('mails.footer.help', [], 'de').': '.$f['help'] : '',
        $f['imprint'] ? __('mails.footer.imprint', [], 'de').': '.$f['imprint'] : '',
        $f['privacy'] ? __('mails.footer.privacy', [], 'de').': '.$f['privacy'] : '',
    ]));
@endphp
{!! $mailPlain !!}

--
{!! $f['name'] !!}
@foreach ($lines as $line)
{!! $line !!}
@endforeach

{!! $mailFrame->reason !!}
@if (! $mailFrame->isSwayy() && $mailFrame->poweredBy)
{!! __('mails.footer.powered_by', [], 'de') !!} Swayy
@endif
