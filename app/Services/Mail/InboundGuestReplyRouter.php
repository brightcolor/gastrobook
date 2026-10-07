<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\ForwardedGuestReplyMail;
use App\Models\GuestMailReply;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ordnet eine eingehende Gästeantwort ihrer Buchung zu, legt sie ab und leitet
 * sie an den Betrieb weiter. Läuft ohne Mandantenkontext (Webhook), deshalb
 * überall explizit ohne Tenant-Scope mit gesetztem tenant_id.
 */
final class InboundGuestReplyRouter
{
    public function route(string $recipient, string $mailFrom, string $postalMessageId, InboundMessage $message): GuestMailReply
    {
        // Idempotenz: schon verarbeitet → denselben Datensatz zurückgeben.
        $existing = GuestMailReply::withoutGlobalScopes()->where('postal_message_id', $postalMessageId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $parsed = GuestReplyAddress::parse($recipient);
        $tenant = $parsed ? Tenant::withoutGlobalScopes()->where('slug', $parsed['slug'])->first() : null;

        $reservation = null;
        if ($tenant !== null && $parsed) {
            $candidate = Reservation::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', $parsed['code'])
                ->first();
            if ($candidate !== null
                && hash_equals(GuestReplyAddress::hmac((int) $tenant->id, (string) $candidate->code), $parsed['hmac'])) {
                $reservation = $candidate;
            }
        }

        // Unbekannter Betrieb: nur protokollieren, nichts ablegen oder
        // weiterleiten (Entscheidung „Slug unbekannt → nur Protokoll").
        if ($tenant === null) {
            Log::warning('[swayy] Eingehende Antwort ohne bekannten Betrieb verworfen.', [
                'recipient' => $recipient,
                'postal_message_id' => $postalMessageId,
            ]);

            return new GuestMailReply([
                'postal_message_id' => $postalMessageId,
                'to_recipient' => $recipient,
                'match_status' => 'unmatched',
                'forward_status' => 'skipped',
                'received_at' => now(),
            ]);
        }

        $matchStatus = $reservation !== null ? 'matched' : 'slug_only';
        $automatic = $message->isAutomatic || trim($mailFrom) === '' || trim($mailFrom) === '<>';
        $overLimit = $this->senderOverLimit($tenant, $message->fromEmail);
        $willForward = ! $automatic && ! $overLimit;

        $reply = GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $reservation?->location_id,
            'reservation_id' => $reservation?->id,
            'guest_id' => $reservation?->guest_id,
            'postal_message_id' => $postalMessageId,
            'message_id_header' => $message->messageId,
            'from_email' => $message->fromEmail,
            'from_name' => $message->fromName,
            'to_recipient' => $recipient,
            'subject' => $message->subject,
            'body_text' => $message->textBody,
            'has_attachments' => $message->attachments !== [],
            'attachments_meta' => $message->attachmentsMeta(),
            'match_status' => $matchStatus,
            'forward_status' => $willForward ? 'queued' : 'skipped',
            'received_at' => now(),
        ]);

        if ($willForward) {
            $this->forward($reply, $tenant, $reservation, $message);
        }

        return $reply;
    }

    /**
     * Ratenbegrenzung je Absender und Betrieb (Einstellung rate_per_sender).
     * Über der Grenze wird abgelegt, aber nicht weitergeleitet.
     */
    private function senderOverLimit(?Tenant $tenant, ?string $fromEmail): bool
    {
        if ($tenant === null || $fromEmail === null) {
            return false;
        }

        $limit = (int) config('swayy.guest_mail_relay.rate_per_sender', 20);
        $key = 'guest-mail-relay:'.$tenant->id.':'.sha1($fromEmail);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    private function forward(GuestMailReply $reply, Tenant $tenant, ?Reservation $reservation, InboundMessage $message): void
    {
        $location = $reservation !== null
            ? $reservation->location()->withoutGlobalScope('tenant')->first()
            : $tenant->locations()->withoutGlobalScope('tenant')->first();

        $to = $this->operatorAddress($location);
        if ($to === null) {
            $reply->update(['forward_status' => 'failed']);

            return;
        }

        $prefix = (string) config('swayy.guest_mail_relay.forward_subject_prefix', 'Antwort von');
        $gastName = $message->fromName ?: ($message->fromEmail ?? 'Gast');
        $kopf = $reservation !== null
            ? "Antwort zur Buchung {$reservation->code} vom ".$reservation->localStart()->format('d.m.Y').":\n\n"
            : "Antwort ohne eindeutige Zuordnung (bitte selbst zuordnen):\n\n";

        Mail::to($to)->queue(new ForwardedGuestReplyMail(
            forwardSubject: trim("{$prefix} {$gastName}: ".(string) $message->subject),
            forwardBody: $kopf.$message->textBody,
            fromName: $tenant->mail_from_name ?: $tenant->name,
            guestReplyTo: (string) $message->fromEmail,
            forwardedAttachments: array_map(
                fn (array $a) => ['name' => $a['name'], 'mime' => $a['mime'], 'data' => $a['data']],
                $message->attachments,
            ),
        ));

        $reply->update(['forwarded_to' => $to, 'forwarded_at' => now()]);
    }

    private function operatorAddress(?Location $location): ?string
    {
        if ($location === null) {
            return null;
        }
        $to = $location->email ?: $location->effectiveSettings()->owner_notification_email;

        return is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : null;
    }
}
