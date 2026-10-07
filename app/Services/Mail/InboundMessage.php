<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Liest eine eingehende Mail (roher MIME-Text) in die Felder ein, die der
 * Rest des Codes braucht: Absender, Betreff, Textkörper, Message-ID, Marker
 * einer automatischen Mail und Anhänge (Name, MIME, Größe, Inhalt).
 *
 * Bewusst ohne Fremdbibliothek, mit PHP-Bordmitteln (iconv/mbstring). Deckt
 * die üblichen Gästeantworten ab: einteilig, multipart/alternative und
 * multipart/mixed mit Anhängen, base64 und quoted-printable.
 */
final class InboundMessage
{
    /** @param list<array{name:string,mime:string,size:int,data:string}> $attachments */
    private function __construct(
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly ?string $subject,
        public readonly string $textBody,
        public readonly ?string $messageId,
        public readonly bool $isAutomatic,
        public readonly array $attachments,
    ) {}

    public static function fromRaw(string $mime): self
    {
        [$headerRaw, $body] = self::splitHeadersBody($mime);
        $headers = self::parseHeaders($headerRaw);

        [$name, $email] = self::parseAddress(self::decodeHeader($headers['from'] ?? ''));

        $out = ['text' => null, 'html' => null, 'attachments' => []];
        self::walk($headers, $body, $out);

        $text = $out['text'];
        if ($text === null && $out['html'] !== null) {
            $text = trim(html_entity_decode(strip_tags($out['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return new self(
            $email !== null ? strtolower($email) : null,
            $name,
            self::decodeHeader($headers['subject'] ?? '') ?: null,
            (string) ($text ?? ''),
            isset($headers['message-id']) ? trim($headers['message-id']) : null,
            self::looksAutomatic($headers),
            $out['attachments'],
        );
    }

    /** @return list<array{name:string,mime:string,size:int}> */
    public function attachmentsMeta(): array
    {
        return array_map(
            fn (array $a) => ['name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size']],
            $this->attachments,
        );
    }

    /**
     * @param  array<string,string>  $headers
     * @param  array{text:?string,html:?string,attachments:list<array{name:string,mime:string,size:int,data:string}>}  $out
     */
    private static function walk(array $headers, string $body, array &$out): void
    {
        $contentType = strtolower($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = self::param($headers['content-type'] ?? '', 'boundary');
            if ($boundary === null) {
                return;
            }
            foreach (explode('--'.$boundary, $body) as $chunk) {
                $chunk = ltrim($chunk, "\r\n");
                if ($chunk === '' || str_starts_with($chunk, '--')) {
                    continue; // Präambel oder Abschluss (--boundary--)
                }
                [$ph, $pb] = self::splitHeadersBody($chunk);
                self::walk(self::parseHeaders($ph), $pb, $out);
            }

            return;
        }

        $filename = self::param($headers['content-disposition'] ?? '', 'filename')
            ?? self::param($headers['content-type'] ?? '', 'name');
        $disposition = strtolower($headers['content-disposition'] ?? '');
        $decoded = self::decodeBody($body, $headers['content-transfer-encoding'] ?? '', self::param($headers['content-type'] ?? '', 'charset'));

        $isAttachment = str_contains($disposition, 'attachment')
            || ($filename !== null && ! str_starts_with($contentType, 'text/'));

        if ($isAttachment) {
            $out['attachments'][] = [
                'name' => $filename !== null ? self::decodeHeader($filename) : 'anhang',
                'mime' => explode(';', $contentType)[0],
                'size' => strlen($decoded),
                'data' => $decoded,
            ];

            return;
        }

        if (str_starts_with($contentType, 'text/plain') && $out['text'] === null) {
            $out['text'] = rtrim($decoded);
        } elseif (str_starts_with($contentType, 'text/html') && $out['html'] === null) {
            $out['html'] = $decoded;
        }
    }

    /** @return array{0:string,1:string} headers, body */
    private static function splitHeadersBody(string $raw): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @return array<string,string> lowercased name → value, folded lines joined */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '') {
                continue;
            }
            if ($current !== null && ($line[0] === ' ' || $line[0] === "\t")) {
                $headers[$current] .= ' '.trim($line); // Fortsetzungszeile

                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $current = strtolower(trim(substr($line, 0, $pos)));
            $headers[$current] = trim(substr($line, $pos + 1));
        }

        return $headers;
    }

    private static function decodeBody(string $body, string $cte, ?string $charset): string
    {
        $decoded = match (strtolower(trim($cte))) {
            'base64' => (string) base64_decode($body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        $charset = $charset !== null ? strtolower($charset) : null;
        if ($charset !== null && $charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        return $decoded;
    }

    private static function decodeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $value;
    }

    /** @return array{0:?string,1:?string} name, email */
    private static function parseAddress(string $from): array
    {
        $from = trim($from);
        if ($from === '') {
            return [null, null];
        }
        if (preg_match('/^(.*)<([^>]+)>\s*$/', $from, $m)) {
            $name = trim($m[1], " \t\"'");

            return [$name !== '' ? $name : null, trim($m[2])];
        }

        return [null, $from];
    }

    /** Wert eines Parameters aus einem Header, z. B. boundary oder filename. */
    private static function param(string $header, string $name): ?string
    {
        if (preg_match('/;\s*'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $header, $m)) {
            return $m[1];
        }
        if (preg_match('/;\s*'.preg_quote($name, '/').'\s*=\s*([^;\s]+)/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @param array<string,string> $headers */
    private static function looksAutomatic(array $headers): bool
    {
        foreach ((array) config('swayy.guest_mail_relay.auto_mail_headers', []) as $marker) {
            $key = strtolower(trim((string) $marker));
            if (! array_key_exists($key, $headers)) {
                continue;
            }
            // Auto-Submitted: no heißt ausdrücklich „keine automatische Mail".
            if ($key === 'auto-submitted' && strtolower(trim($headers[$key])) === 'no') {
                continue;
            }

            return true;
        }

        return false;
    }
}
