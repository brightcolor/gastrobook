@php
    $muted = $theme->palette('muted');
@endphp
@switch($block->type)
    @case(\App\Mail\Layout\Block::PARAGRAPH)
        @php
            $html = nl2br((string) preg_replace(
                '~(https?://[^\s<]+)~i',
                '<a href="$1" class="t-link" style="color:'.e($theme->link()).';word-break:break-all;">$1</a>',
                e($block->text),
            ));
        @endphp
        @if ($finePrint)
            <p class="t-muted" style="margin:0 0 6px;font-family:{{ $font }};font-size:13px;line-height:20px;color:{{ $muted }};">{!! $html !!}</p>
        @else
            <p class="t-body" style="margin:0 0 16px;font-family:{{ $font }};font-size:16px;line-height:26px;color:{{ $theme->palette('body') }};">{!! $html !!}</p>
        @endif
        @break

    @case(\App\Mail\Layout\Block::DETAILS)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="panel" style="background:{{ $theme->tint() }};border-radius:12px;margin:8px 0 24px;">
            <tr><td style="padding:6px 22px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    @foreach ($block->rows as $row)
                        @php $border = $loop->last ? '' : 'border-bottom:1px solid '.$theme->line().';'; @endphp
                        <tr class="panel-row">
                            <td class="t-muted" style="padding:13px 0;{{ $border }}font-family:{{ $font }};font-size:14px;line-height:20px;color:{{ $muted }};vertical-align:top;">{{ $row['label'] }}</td>
                            <td class="t-strong" align="right" style="padding:13px 0 13px 16px;{{ $border }}font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->palette('strong') }};vertical-align:top;word-break:break-word;">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        </table>
        @break

    @case(\App\Mail\Layout\Block::BUTTON)
        @if ($block->primary)
            <table role="presentation" cellpadding="0" cellspacing="0" class="btn" style="margin:4px 0 24px;"><tr><td style="border-radius:6px;background:{{ $theme->button() }};">
                <a href="{{ $block->url }}" style="display:inline-block;padding:14px 26px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->palette('on_brand') }};text-decoration:none;border-radius:6px;">{{ $block->label }}</a>
            </td></tr></table>
        @else
            <table role="presentation" cellpadding="0" cellspacing="0" class="btn" style="margin:0 0 24px;"><tr><td style="border-radius:6px;border:1px solid {{ $theme->line() }};">
                <a href="{{ $block->url }}" class="t-link" style="display:inline-block;padding:12px 22px;font-family:{{ $font }};font-size:15px;line-height:20px;font-weight:600;color:{{ $theme->link() }};text-decoration:none;border-radius:6px;">{{ $block->label }}</a>
            </td></tr></table>
        @endif
        @break

    @case(\App\Mail\Layout\Block::DIVIDER)
        <div class="rule" style="border-top:1px solid {{ $theme->palette('border') }};margin:8px 0 20px;line-height:0;font-size:0;">&nbsp;</div>
        @break
@endswitch
