@php
    // $body is plain text with placeholders already substituted. Escape it,
    // turn URLs into clickable links, then keep line breaks.
    $safe = e($body);
    $safe = preg_replace(
        '~(https?://[^\s<]+)~',
        '<a href="$1" style="color:#0f766e;">$1</a>',
        $safe
    );
    $safe = nl2br($safe);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $fromName ?? config('mail.from.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e7e5e4;">
                    <tr>
                        <td style="padding:20px 28px;background:#0f766e;">
                            <span style="font-size:16px;font-weight:700;color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
                                {{ $fromName ?? config('mail.from.name') }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-size:15px;line-height:1.6;color:#1c1917;font-family:Arial,Helvetica,sans-serif;">
                            {!! $safe !!}
                        </td>
                    </tr>
                </table>
                <p style="max-width:560px;margin:14px auto 0;font-size:11px;color:#a8a29e;font-family:Arial,Helvetica,sans-serif;">
                    {{ $fromName ?? config('mail.from.name') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
