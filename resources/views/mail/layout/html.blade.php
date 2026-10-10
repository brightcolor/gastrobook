{{-- Gemeinsamer HTML-Rahmen aller Mails (Spec 2026-10-10 Mail-Design). --}}
@php
    $font = (string) config('swayy.mail.font_stack');
    $display = (string) config('swayy.mail.display_stack');
    $width = (int) config('swayy.mail.width');
    $breakpoint = (int) config('swayy.mail.mobile_breakpoint');
    $f = $mailFrame->footer;
    $tone = $mailFrame->du ? 'du' : 'Sie';
    $sep = '<span class="t-muted" style="color:'.e($mailTheme->palette('faint')).';padding:0 6px;">·</span>';
    $footerLink = 'class="t-muted" style="color:'.e($mailTheme->palette('muted')).';text-decoration:underline;"';
    $address = implode($sep, array_map('e', array_filter([$f['street'], $f['city']])));
    $contact = implode($sep, array_filter([
        $f['phone'] ? '<a href="tel:'.e(preg_replace('/[^+0-9]/', '', $f['phone'])).'" '.$footerLink.'>'.e($f['phone']).'</a>' : null,
        $f['email'] ? '<a href="mailto:'.e($f['email']).'" '.$footerLink.'>'.e($f['email']).'</a>' : null,
    ]));
    $legal = implode($sep, array_filter([
        $f['help'] ? '<a href="'.e($f['help']).'" '.$footerLink.'>'.e(__('mails.footer.help', [], 'de')).'</a>' : null,
        $f['imprint'] ? '<a href="'.e($f['imprint']).'" '.$footerLink.'>'.e(__('mails.footer.imprint', [], 'de')).'</a>' : null,
        $f['privacy'] ? '<a href="'.e($f['privacy']).'" '.$footerLink.'>'.e(__('mails.footer.privacy', [], 'de')).'</a>' : null,
    ]));
    $finePrint = false;
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $mailFrame->headline ?? $mailFrame->wordmark }}</title>
<style>
  body { margin:0; padding:0; -webkit-text-size-adjust:100%; }
  a { text-underline-offset:3px; }
  @media (max-width: {{ $breakpoint }}px) {
    .px { padding-left:24px !important; padding-right:24px !important; }
    .h1 { font-size:23px !important; line-height:30px !important; }
    .btn { width:100% !important; }
    .btn a { display:block !important; text-align:center !important; }
  }
  @media (prefers-color-scheme: dark) {
    .bg-page { background:{{ $mailTheme->palette('dark_page') }} !important; }
    .card { background:{{ $mailTheme->palette('dark_card') }} !important; border-color:{{ $mailTheme->palette('dark_border') }} !important; }
    .t-strong { color:{{ $mailTheme->palette('dark_strong') }} !important; }
    .t-body { color:{{ $mailTheme->palette('dark_body') }} !important; }
    .t-muted { color:{{ $mailTheme->palette('dark_muted') }} !important; }
    .t-link { color:{{ $mailTheme->darkLink() }} !important; }
    .panel { background:{{ $mailTheme->darkTint() }} !important; }
    .panel-row td { border-color:{{ $mailTheme->darkLine() }} !important; }
    .rule { border-color:{{ $mailTheme->palette('dark_border') }} !important; }
  }
  [data-ogsc] .bg-page { background:{{ $mailTheme->palette('dark_page') }} !important; }
  [data-ogsc] .card { background:{{ $mailTheme->palette('dark_card') }} !important; border-color:{{ $mailTheme->palette('dark_border') }} !important; }
  [data-ogsc] .t-strong { color:{{ $mailTheme->palette('dark_strong') }} !important; }
  [data-ogsc] .t-body { color:{{ $mailTheme->palette('dark_body') }} !important; }
  [data-ogsc] .t-muted { color:{{ $mailTheme->palette('dark_muted') }} !important; }
  [data-ogsc] .t-link { color:{{ $mailTheme->darkLink() }} !important; }
  [data-ogsc] .panel { background:{{ $mailTheme->darkTint() }} !important; }
</style>
</head>
<body class="bg-page" style="margin:0;padding:0;background:{{ $mailTheme->palette('page') }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-page" style="background:{{ $mailTheme->palette('page') }};">
<tr><td align="center" style="padding:0 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:{{ $width }}px;">
  <tr><td align="center" style="padding:40px 24px 22px;">
    @if ($mailFrame->logoPath)
      <table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:{{ $mailTheme->palette('plate') }};border-radius:10px;padding:8px 12px;">
        <img src="{{ $message->embed($mailFrame->logoPath) }}" alt="{{ $mailFrame->wordmark }}" width="{{ $mailFrame->logoWidth }}" height="{{ $mailFrame->logoHeight }}" style="display:block;width:{{ $mailFrame->logoWidth }}px;height:{{ $mailFrame->logoHeight }}px;border:0;">
      </td></tr></table>
    @else
      <div class="t-strong" style="font-family:{{ $mailFrame->isSwayy() ? $display : $font }};font-size:19px;line-height:24px;font-weight:600;letter-spacing:-0.015em;color:{{ $mailTheme->palette('strong') }};">{{ $mailFrame->wordmark }}</div>
    @endif
  </td></tr>
  <tr><td>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="card" style="background:{{ $mailTheme->palette('card') }};border:1px solid {{ $mailTheme->palette('border') }};border-radius:16px;">
      <tr><td class="px" style="padding:40px 44px 36px;">
        @if ($mailFrame->headline)
          <h1 class="h1 t-strong" style="margin:0 0 14px;font-family:{{ $font }};font-size:26px;line-height:33px;font-weight:600;letter-spacing:-0.022em;color:{{ $mailTheme->palette('strong') }};">{{ $mailFrame->headline }}</h1>
        @endif
        @foreach ($mailBlocks as $block)
          @if ($block->type === \App\Mail\Layout\Block::DIVIDER)
            @php $finePrint = true; @endphp
          @endif
          @include('mail.layout._block', ['block' => $block, 'theme' => $mailTheme, 'font' => $font, 'finePrint' => $finePrint])
        @endforeach
      </td></tr>
    </table>
  </td></tr>
  <tr><td align="center" class="t-muted" style="padding:28px 32px 44px;font-family:{{ $font }};font-size:13px;line-height:21px;color:{{ $mailTheme->palette('muted') }};">
    @if ($mailFrame->isSwayy())
      <div class="t-strong" style="font-family:{{ $display }};font-size:15px;font-weight:500;color:{{ $mailTheme->palette('body') }};">Swayy</div>
    @else
      <div class="t-strong" style="font-weight:600;color:{{ $mailTheme->palette('body') }};">{{ $f['name'] }}</div>
      @if ($address !== '')<div>{!! $address !!}</div>@endif
      @if ($contact !== '')<div>{!! $contact !!}</div>@endif
    @endif
    @if ($legal !== '')<div style="padding-top:10px;">{!! $legal !!}</div>@endif
    <div style="padding-top:18px;">{{ $mailFrame->reason }}</div>
    @if ($mailFrame->unsubscribeUrl)
      <div style="padding-top:8px;">{{ __('mails.footer.unsubscribe.'.$tone, [], 'de') }} <a href="{{ $mailFrame->unsubscribeUrl }}" {!! $footerLink !!}>{{ __('mails.footer.unsubscribe_link', [], 'de') }}</a></div>
    @endif
    @if (! $mailFrame->isSwayy() && $mailFrame->poweredBy)
      <div style="padding-top:18px;">{{ __('mails.footer.powered_by', [], 'de') }} <a href="{{ route('home') }}" class="t-muted" style="color:{{ $mailTheme->palette('muted') }};text-decoration:none;font-family:{{ $display }};font-size:14px;font-weight:500;">Swayy</a></div>
    @endif
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
