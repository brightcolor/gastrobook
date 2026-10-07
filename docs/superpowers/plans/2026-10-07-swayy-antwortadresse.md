# Swayy-eigene Antwortadresse je Betrieb — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gäste beantworten Buchungsmails an eine Swayy-eigene Adresse; Swayy ordnet die Antwort der Buchung zu, legt sie im Verlauf ab und leitet sie an den Betrieb weiter.

**Architecture:** Eingehende Mail → Postal-Eingangsroute → `POST /webhooks/postal`. Der Controller prüft die Postal-Signatur und übergibt an einen Router, der die Empfängeradresse `<slug>+<code>.<hmac>@<domain>` zerlegt, den HMAC prüft, die Buchung findet, einen `GuestMailReply` anlegt und die Nachricht mit Reply-To = Gast an den Betrieb weiterleitet. Die Antwortadresse trägt eine Buchungsmail nur, wenn der Betrieb Relay aktiviert hat; sonst gilt das direkte Reply-To aus PR #45.

**Tech Stack:** Laravel 13, PostgreSQL, Redis (Queue), Postal (SMTP + HTTP-Eingangsroute), `zbateson/mail-mime-parser` (neu), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-07-swayy-antwortadresse-design.md`

## Global Constraints

- **Mandantentrennung:** Jede Abfrage auf mandantengebundene Daten filtert auf Tenant/Standort. Im Webhook gibt es keinen aktiven Tenant-Kontext → `withoutGlobalScope('tenant')` plus explizites `where('tenant_id', …)`, wie `Reservation::generateCode()` und `GuestPrivacyService`.
- **„Genau einmal":** Doppelzustellung wird über die `unique`-Spalte `postal_message_id` abgefangen (Insert, Unique-Verletzung = schon verarbeitet), analog `GoCardlessWebhookController::markSeen`.
- **Idempotente Migrationen:** `docker/entrypoint.sh` läuft mit `set -e`. Jede Migration prüft vorher Existenz (`Schema::hasTable`, `Schema::hasColumn`), sonst Neustartschleife des Containers.
- **Nichts fest im Code:** Domain, HMAC-Länge, Trenner, Public Key, Signatur-Verfahren, Größengrenze, Betreff-Präfix, Schleifen-Marker, Ratengrenze stehen in `config/swayy.php` → `guest_mail_relay`, mit Vorgabe, Grenzen und verständlicher Warnung (`EnvSetting` für Zahlen).
- **Verständliche Fehlermeldungen mit echten Umlauten:** Jede nutzersichtbare Meldung nennt Ursache und nächsten Schritt.
- **Datenschutzerklärung im selben Schritt:** `resources/legal/datenschutz.md` mitpflegen (Task 12).
- **Keine echten Daten** in Tests, Kommentaren, Changelog, Historie. Testadressen auf `@example.test`.
- **Commits:** deutsche Conventional-Commit-Betreffe. Jede Commit-Nachricht endet mit der Zeile `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **Prüfen:** `php artisan test --filter=…` je Task, am Ende `php artisan test` und `vendor/bin/phpstan analyse`.
- **Adressdetail:** HMAC ist Kleinbuchstaben-Hex (`substr(bin2hex(hash_hmac('sha256', "<tenant_id>:<code>", config('app.key'), true)), 0, hmac_length)`). Kein base32.

---

### Task 1: Einstellungen und Opt-in-Spalte

**Files:**
- Modify: `config/swayy.php` (neuer Block nach `webhooks`)
- Create: `database/migrations/2026_10_07_100000_add_mail_relay_enabled_to_tenants.php`
- Modify: `app/Models/Tenant.php:25-44` (`$fillable`, `casts`)
- Test: `tests/Unit/GuestMailRelayConfigTest.php`

**Interfaces:**
- Produces: Konfigurationsschlüssel `swayy.guest_mail_relay.*`; Tenant-Attribut `mail_relay_enabled` (bool).

- [ ] **Step 1: Failing test für Config-Vorgaben**

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

class GuestMailRelayConfigTest extends TestCase
{
    public function test_defaults_are_present(): void
    {
        $this->assertSame('', config('swayy.guest_mail_relay.domain'));
        $this->assertSame(8, config('swayy.guest_mail_relay.hmac_length'));
        $this->assertSame('.', config('swayy.guest_mail_relay.separator_hmac'));
        $this->assertSame('sha256', config('swayy.guest_mail_relay.inbound_signature_algo'));
        $this->assertContains('Auto-Submitted', config('swayy.guest_mail_relay.auto_mail_headers'));
    }

    public function test_hmac_length_respects_env_bounds(): void
    {
        putenv('SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH=4'); // unter min 6
        $this->assertSame(6, \App\Support\EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH', 8, 6, 32));
        putenv('SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH');
    }
}
```

- [ ] **Step 2: Test läuft, schlägt fehl**

Run: `php artisan test --filter=GuestMailRelayConfigTest`
Expected: FAIL (Schlüssel fehlen)

- [ ] **Step 3: Config-Block ergänzen**

In `config/swayy.php` nach dem `webhooks`-Block, Kommentar im Stil der Datei:

```php
    /*
    |--------------------------------------------------------------------------
    | Antwortadresse über Swayy (eingehende Gästeantworten)
    |--------------------------------------------------------------------------
    |
    | Gäste antworten an <slug>+<code>.<hmac>@<domain>. Postal nimmt die Mail an
    | und ruft POST /webhooks/postal. Leere Domain heißt: Relay aus, es gilt das
    | direkte Reply-To (guest_mail). Ein ungültiger Wert legt nichts lahm.
    |
    */
    'guest_mail_relay' => [
        'domain' => env('SWAYY_GUEST_MAIL_RELAY_DOMAIN', ''),
        'hmac_length' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH', default: 8, min: 6, max: 32),
        'separator_tag' => env('SWAYY_GUEST_MAIL_RELAY_SEP_TAG', '+'),
        'separator_hmac' => env('SWAYY_GUEST_MAIL_RELAY_SEP_HMAC', '.'),
        'inbound_public_key' => env('SWAYY_GUEST_MAIL_RELAY_PUBLIC_KEY', ''),
        'inbound_signature_algo' => env('SWAYY_GUEST_MAIL_RELAY_SIGNATURE_ALGO', 'sha256'),
        'inbound_shared_secret' => env('SWAYY_GUEST_MAIL_RELAY_SHARED_SECRET', ''),
        'max_message_bytes' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_MAX_BYTES', default: 10485760, min: 65536, max: 52428800),
        'forward_subject_prefix' => env('SWAYY_GUEST_MAIL_RELAY_SUBJECT_PREFIX', 'Antwort von'),
        // Marker einer automatischen Mail (Schleifenschutz). Komma-Liste, überschreibbar.
        'auto_mail_headers' => array_values(array_filter(array_map('trim', explode(
            ',',
            env('SWAYY_GUEST_MAIL_RELAY_AUTO_HEADERS', 'Auto-Submitted,Precedence,List-Id,List-Unsubscribe')
        )))),
        'rate_per_sender' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_RATE_PER_SENDER', default: 20, min: 1, max: 1000),
    ],
```

- [ ] **Step 4: Migration (idempotent)**

```php
<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'mail_relay_enabled')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('mail_relay_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'mail_relay_enabled')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('mail_relay_enabled');
            });
        }
    }
};
```

- [ ] **Step 5: Tenant-Modell**

`$fillable`: `'mail_from_name', 'mail_reply_to', 'mail_relay_enabled',` (ergänzen). In `casts()`: `'mail_relay_enabled' => 'boolean',`.

- [ ] **Step 6: Test grün + Commit**

Run: `php artisan test --filter=GuestMailRelayConfigTest` → PASS

```bash
git add config/swayy.php database/migrations/2026_10_07_100000_add_mail_relay_enabled_to_tenants.php app/Models/Tenant.php tests/Unit/GuestMailRelayConfigTest.php
git commit -m "feat: Einstellungen und Opt-in-Spalte fuer die Antwortadresse"   # + Co-Authored-By-Zeile
```

---

### Task 2: GuestReplyAddress (bauen, parsen, HMAC prüfen)

**Files:**
- Create: `app/Services/Mail/GuestReplyAddress.php`
- Test: `tests/Unit/GuestReplyAddressTest.php`

**Interfaces:**
- Consumes: `swayy.guest_mail_relay.*`, `Reservation` (`code`, `tenant_id`), `Tenant` (`slug`).
- Produces:
  - `GuestReplyAddress::forReservation(Reservation $r): string` — vollständige Adresse.
  - `GuestReplyAddress::parse(string $recipient): ?array` — `['slug'=>…, 'code'=>…, 'hmac'=>…]` oder `null`, wenn die Struktur nicht passt.
  - `GuestReplyAddress::hmac(int $tenantId, string $code): string`.
  - `GuestReplyAddress::isConfigured(): bool` — Domain gesetzt.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;
use Tests\TestCase;

class GuestReplyAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
        config(['swayy.guest_mail_relay.hmac_length' => 8]);
    }

    public function test_build_and_parse_roundtrip(): void
    {
        $r = new Reservation(['code' => 'R-7KQ2M9']);
        $r->tenant_id = 42;
        $r->setRelation('tenant', new \App\Models\Tenant(['slug' => 'baeckerei-mueller']));

        $addr = GuestReplyAddress::forReservation($r);
        $this->assertStringContainsString('baeckerei-mueller+R-7KQ2M9.', $addr);
        $this->assertStringEndsWith('@antwort.swayy.de', $addr);

        $parsed = GuestReplyAddress::parse($addr);
        $this->assertSame('baeckerei-mueller', $parsed['slug']);
        $this->assertSame('R-7KQ2M9', $parsed['code']);  // enthält selbst ein -
        $this->assertSame(GuestReplyAddress::hmac(42, 'R-7KQ2M9'), $parsed['hmac']);
    }

    public function test_parse_rejects_missing_tag(): void
    {
        $this->assertNull(GuestReplyAddress::parse('baeckerei-mueller@antwort.swayy.de'));
        $this->assertNull(GuestReplyAddress::parse('not-an-address'));
    }

    public function test_hmac_changes_with_length_setting(): void
    {
        $this->assertSame(8, strlen(GuestReplyAddress::hmac(1, 'R-AAAAAA')));
        config(['swayy.guest_mail_relay.hmac_length' => 12]);
        $this->assertSame(12, strlen(GuestReplyAddress::hmac(1, 'R-AAAAAA')));
    }
}
```

- [ ] **Step 2: Fail** — Run: `php artisan test --filter=GuestReplyAddressTest` → FAIL (Klasse fehlt)

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Reservation;

final class GuestReplyAddress
{
    public static function isConfigured(): bool
    {
        return trim((string) config('swayy.guest_mail_relay.domain')) !== '';
    }

    public static function hmac(int $tenantId, string $code): string
    {
        $len = (int) config('swayy.guest_mail_relay.hmac_length', 8);
        $raw = hash_hmac('sha256', $tenantId.':'.$code, (string) config('app.key'), true);

        return substr(bin2hex($raw), 0, $len);
    }

    public static function forReservation(Reservation $r): string
    {
        $slug = (string) $r->tenant->slug;
        $sepTag = (string) config('swayy.guest_mail_relay.separator_tag', '+');
        $sepHmac = (string) config('swayy.guest_mail_relay.separator_hmac', '.');
        $domain = (string) config('swayy.guest_mail_relay.domain');

        return $slug.$sepTag.$r->code.$sepHmac.self::hmac((int) $r->tenant_id, (string) $r->code).'@'.$domain;
    }

    /**
     * @return array{slug:string,code:string,hmac:string}|null
     */
    public static function parse(string $recipient): ?array
    {
        $sepTag = (string) config('swayy.guest_mail_relay.separator_tag', '+');
        $sepHmac = (string) config('swayy.guest_mail_relay.separator_hmac', '.');

        $local = strstr($recipient, '@', true);
        if ($local === false || ! str_contains($local, $sepTag)) {
            return null;
        }

        [$slug, $tag] = explode($sepTag, $local, 2);
        $pos = strrpos($tag, $sepHmac);
        if ($slug === '' || $pos === false || $pos === 0) {
            return null;
        }

        return [
            'slug' => $slug,
            'code' => substr($tag, 0, $pos),
            'hmac' => substr($tag, $pos + strlen($sepHmac)),
        ];
    }
}
```

- [ ] **Step 4: Pass** — Run: `php artisan test --filter=GuestReplyAddressTest` → PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Mail/GuestReplyAddress.php tests/Unit/GuestReplyAddressTest.php
git commit -m "feat: Antwortadresse bauen und pruefen (HMAC aus Code)"   # + Co-Authored-By-Zeile
```

---

### Task 3: PostalSignatureVerifier

**Files:**
- Create: `app/Services/Mail/PostalSignatureVerifier.php`
- Test: `tests/Unit/PostalSignatureVerifierTest.php`

**Interfaces:**
- Produces: `PostalSignatureVerifier::verify(string $payload, string $signatureHeader): bool` — prüft die base64-RSA-Signatur gegen `inbound_public_key` mit `inbound_signature_algo`. Leerer Key oder leere Signatur → false.

- [ ] **Step 1: Failing test** (Schlüsselpaar im Test erzeugen, kein echtes Postal)

```php
<?php

namespace Tests\Unit;

use App\Services\Mail\PostalSignatureVerifier;
use Tests\TestCase;

class PostalSignatureVerifierTest extends TestCase
{
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $this->keys = ['private' => $privateKey, 'public' => openssl_pkey_get_details($res)['key']];
        config(['swayy.guest_mail_relay.inbound_public_key' => $this->keys['public']]);
        config(['swayy.guest_mail_relay.inbound_signature_algo' => 'sha256']);
    }

    private function sign(string $payload): string
    {
        openssl_sign($payload, $sig, $this->keys['private'], OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    public function test_valid_signature_passes(): void
    {
        $payload = '{"rcpt_to":"x"}';
        $this->assertTrue((new PostalSignatureVerifier())->verify($payload, $this->sign($payload)));
    }

    public function test_tampered_payload_fails(): void
    {
        $this->assertFalse((new PostalSignatureVerifier())->verify('{"rcpt_to":"y"}', $this->sign('{"rcpt_to":"x"}')));
    }

    public function test_without_key_fails(): void
    {
        config(['swayy.guest_mail_relay.inbound_public_key' => '']);
        $this->assertFalse((new PostalSignatureVerifier())->verify('x', 'x'));
    }
}
```

- [ ] **Step 2: Fail** — `php artisan test --filter=PostalSignatureVerifierTest` → FAIL

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail;

final class PostalSignatureVerifier
{
    public function verify(string $payload, string $signatureHeader): bool
    {
        $publicKey = trim((string) config('swayy.guest_mail_relay.inbound_public_key'));
        $signature = base64_decode(trim($signatureHeader), true);
        if ($publicKey === '' || $signature === false || $signature === '') {
            return false;
        }

        $algo = config('swayy.guest_mail_relay.inbound_signature_algo', 'sha256') === 'sha1'
            ? OPENSSL_ALGO_SHA1
            : OPENSSL_ALGO_SHA256;

        return openssl_verify($payload, $signature, $publicKey, $algo) === 1;
    }
}
```

- [ ] **Step 4: Pass** → **Step 5: Commit**

```bash
git add app/Services/Mail/PostalSignatureVerifier.php tests/Unit/PostalSignatureVerifierTest.php
git commit -m "feat: Postal-Signatur eingehender Webhooks pruefen"   # + Co-Authored-By-Zeile
```

---

### Task 4: Speicher — Migration, Modell, Relationen

**Files:**
- Create: `database/migrations/2026_10_07_100100_create_guest_mail_replies_table.php`
- Create: `app/Models/GuestMailReply.php`
- Modify: `app/Models/Reservation.php` (Relation `mailReplies`)
- Modify: `app/Models/Guest.php` (Relation `mailReplies`)
- Test: `tests/Feature/GuestMailReplyModelTest.php`

**Interfaces:**
- Produces: Modell `GuestMailReply` mit Spalten aus dem Spec; `Reservation::mailReplies()`, `Guest::mailReplies()` (HasMany). `match_status` ∈ `matched|slug_only|unmatched`, `forward_status` ∈ `queued|sent|failed|skipped`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\GuestMailReply;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestMailReplyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_belongs_to_reservation_and_dedupes(): void
    {
        $reservation = Reservation::factory()->create();

        $reply = GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id,
            'postal_message_id' => 'msg-1',
            'from_email' => 'gast@example.test',
            'to_recipient' => 'slug+R-X.y@antwort.swayy.de',
            'subject' => 'Re: Buchung',
            'body_text' => 'Danke!',
            'has_attachments' => false,
            'match_status' => 'matched',
            'forward_status' => 'queued',
            'received_at' => now(),
        ]);

        $this->assertTrue($reservation->mailReplies()->whereKey($reply->id)->exists());

        $this->expectException(QueryException::class);
        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'postal_message_id' => 'msg-1', // Duplikat
            'to_recipient' => 'x', 'match_status' => 'unmatched', 'forward_status' => 'skipped', 'received_at' => now(),
        ]);
    }

    public function test_reply_is_deleted_with_reservation(): void
    {
        $reservation = Reservation::factory()->create();
        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id, 'reservation_id' => $reservation->id,
            'postal_message_id' => 'msg-2', 'to_recipient' => 'x',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);
        $reservation->forceDelete();
        $this->assertSame(0, GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'msg-2')->count());
    }
}
```

- [ ] **Step 2: Fail** — `php artisan test --filter=GuestMailReplyModelTest` → FAIL

- [ ] **Step 3: Migration (idempotent)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_mail_replies')) {
            return;
        }
        Schema::create('guest_mail_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('postal_message_id')->unique();
            $table->string('message_id_header')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('to_recipient');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->json('attachments_meta')->nullable();
            $table->string('match_status'); // matched, slug_only, unmatched
            $table->string('forwarded_to')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->string('forward_status')->default('queued'); // queued, sent, failed, skipped
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['tenant_id', 'reservation_id']);
            $table->index(['tenant_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_mail_replies');
    }
};
```

- [ ] **Step 4: Modell + Relationen**

`app/Models/GuestMailReply.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestMailReply extends Model
{
    protected $fillable = [
        'tenant_id', 'location_id', 'reservation_id', 'guest_id',
        'postal_message_id', 'message_id_header', 'from_email', 'from_name',
        'to_recipient', 'subject', 'body_text', 'has_attachments', 'attachments_meta',
        'match_status', 'forwarded_to', 'forwarded_at', 'forward_status', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'has_attachments' => 'boolean',
            'attachments_meta' => 'array',
            'forwarded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
```

In `app/Models/Reservation.php` eine Relation ergänzen (bei den übrigen `HasMany`):

```php
/** @return \Illuminate\Database\Eloquent\Relations\HasMany<GuestMailReply, $this> */
public function mailReplies(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(GuestMailReply::class);
}
```

In `app/Models/Guest.php` dieselbe Relation `mailReplies()`.

- [ ] **Step 5: Pass** — `php artisan test --filter=GuestMailReplyModelTest` → PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_07_100100_create_guest_mail_replies_table.php app/Models/GuestMailReply.php app/Models/Reservation.php app/Models/Guest.php tests/Feature/GuestMailReplyModelTest.php
git commit -m "feat: Tabelle und Modell fuer eingehende Gaesteantworten"   # + Co-Authored-By-Zeile
```

---

### Task 5: MIME-Parser — InboundMessage

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require zbateson/mail-mime-parser`)
- Create: `app/Services/Mail/InboundMessage.php`
- Test: `tests/Unit/InboundMessageTest.php`

**Interfaces:**
- Consumes: roher MIME-String.
- Produces: `InboundMessage::fromRaw(string $mime): self` mit öffentlichen, nur lesbaren Feldern: `fromEmail`, `fromName`, `subject`, `textBody`, `messageId`, `isAutomatic` (bool, aus `auto_mail_headers`), `attachments` (list von `['name'=>…, 'mime'=>…, 'size'=>int, 'data'=>string]`), `attachmentsMeta()` (dieselbe Liste ohne `data`).

- [ ] **Step 1: Abhängigkeit**

Run: `composer require zbateson/mail-mime-parser` (reiner PHP-Parser, kein Remote-Asset — berührt den Vite-Build nicht).

- [ ] **Step 2: Failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Mail\InboundMessage;
use Tests\TestCase;

class InboundMessageTest extends TestCase
{
    public function test_parses_text_and_attachment(): void
    {
        $raw = implode("\r\n", [
            'From: Max Gast <gast@example.test>',
            'Subject: Re: Ihre Reservierung',
            'Message-ID: <abc@example.test>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Können wir auf 20 Uhr verschieben?',
            '--b',
            'Content-Type: text/plain; name="notiz.txt"',
            'Content-Disposition: attachment; filename="notiz.txt"',
            '',
            'Hallo',
            '--b--',
            '',
        ]);

        $m = InboundMessage::fromRaw($raw);
        $this->assertSame('gast@example.test', $m->fromEmail);
        $this->assertSame('Max Gast', $m->fromName);
        $this->assertStringContainsString('20 Uhr', $m->textBody);
        $this->assertSame('<abc@example.test>', $m->messageId);
        $this->assertFalse($m->isAutomatic);
        $this->assertCount(1, $m->attachments);
        $this->assertSame('notiz.txt', $m->attachments[0]['name']);
    }

    public function test_detects_auto_submitted(): void
    {
        config(['swayy.guest_mail_relay.auto_mail_headers' => ['Auto-Submitted']]);
        $raw = "From: x@example.test\r\nAuto-Submitted: auto-replied\r\nSubject: Abwesend\r\n\r\nWeg.";
        $this->assertTrue(InboundMessage::fromRaw($raw)->isAutomatic);
    }
}
```

- [ ] **Step 3: Fail** — `php artisan test --filter=InboundMessageTest` → FAIL

- [ ] **Step 4: Implementierung** (Parser kapseln, damit der Rest des Codes die Bibliothek nicht kennt)

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail;

use ZBateson\MailMimeParser\Message;

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
        $message = Message::from($mime, false);

        $fromHeader = $message->getHeader('From');
        $fromEmail = $fromHeader?->getEmail();
        $fromName = $fromHeader && method_exists($fromHeader, 'getPersonName') ? $fromHeader->getPersonName() : null;

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $name = $part->getFilename() ?? 'anhang';
            $data = (string) $part->getContent();
            $attachments[] = [
                'name' => $name,
                'mime' => (string) $part->getContentType(),
                'size' => strlen($data),
                'data' => $data,
            ];
        }

        $markers = (array) config('swayy.guest_mail_relay.auto_mail_headers', []);
        $isAutomatic = false;
        foreach ($markers as $header) {
            if ($message->getHeaderValue($header) !== null) {
                $isAutomatic = true;
                break;
            }
        }

        return new self(
            $fromEmail ? strtolower(trim($fromEmail)) : null,
            $fromName !== null && trim($fromName) !== '' ? trim($fromName) : null,
            $message->getHeaderValue('Subject'),
            (string) $message->getTextContent(),
            $message->getHeaderValue('Message-ID'),
            $isAutomatic,
            $attachments,
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
}
```

> Hinweis für den Umsetzer: Methodennamen der Bibliothek beim Schreiben gegen die installierte Version prüfen (`getAllAttachmentParts`, `getTextContent`, `getHeaderValue`). Diese Kapselung ist die einzige Stelle, die `ZBateson\…` kennt.

- [ ] **Step 5: Pass** → **Step 6: Commit**

```bash
git add composer.json composer.lock app/Services/Mail/InboundMessage.php tests/Unit/InboundMessageTest.php
git commit -m "feat: eingehende MIME-Nachricht einlesen (Text, Anhaenge, Auto-Marker)"   # + Co-Authored-By-Zeile
```

---

### Task 6: ForwardedGuestReplyMail

**Files:**
- Create: `app/Mail/ForwardedGuestReplyMail.php`
- Test: `tests/Feature/ForwardedGuestReplyMailTest.php`

**Interfaces:**
- Consumes: `InboundMessage` (Betreff, Text, Anhänge), Betriebsname, Gastadresse, optional Buchungsbezug (`code`, Datum) und Vermerk `nicht zugeordnet`.
- Produces: `new ForwardedGuestReplyMail(string $subject, string $body, ?string $fromName, string $guestReplyTo, array $attachments)` — From = `MAIL_FROM_ADDRESS` + Betriebsname, Reply-To = Gast, Anhänge über `attachData`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use Tests\TestCase;

class ForwardedGuestReplyMailTest extends TestCase
{
    public function test_envelope_sets_reply_to_guest_and_attaches(): void
    {
        $mail = new ForwardedGuestReplyMail(
            subject: 'Antwort von Max: Re: Reservierung',
            body: 'Können wir verschieben?',
            fromName: 'Bäckerei Müller',
            guestReplyTo: 'gast@example.test',
            attachments: [['name' => 'notiz.txt', 'mime' => 'text/plain', 'data' => 'Hallo']],
        );

        $envelope = $mail->envelope();
        $this->assertSame('gast@example.test', $envelope->replyTo[0]->address);
        $mail->assertHasAttachment(
            \Illuminate\Mail\Mailables\Attachment::fromData(fn () => 'Hallo', 'notiz.txt')
        );
    }
}
```

- [ ] **Step 2: Fail** → **Step 3: Implementierung** (an `TemplatedMail` orientiert)

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ForwardedGuestReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<array{name:string,mime:string,data:string}> $attachments */
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $fromName,
        public readonly string $guestReplyTo,
        public readonly array $attachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->fromName ?: config('mail.from.name')),
            replyTo: [new Address($this->guestReplyTo)],
            subject: $this->subject,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.templated-text', with: ['body' => $this->body, 'fromName' => $this->fromName]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromData(fn () => $a['data'], $a['name'])->withMime($a['mime']),
            $this->attachments,
        );
    }
}
```

- [ ] **Step 4: Pass** → **Step 5: Commit**

```bash
git add app/Mail/ForwardedGuestReplyMail.php tests/Feature/ForwardedGuestReplyMailTest.php
git commit -m "feat: Weiterleitungsmail an den Betrieb mit Reply-To Gast"   # + Co-Authored-By-Zeile
```

---

### Task 7: InboundGuestReplyRouter (zuordnen, speichern, weiterleiten)

**Files:**
- Create: `app/Services/Mail/InboundGuestReplyRouter.php`
- Test: `tests/Feature/InboundGuestReplyRouterTest.php`

**Interfaces:**
- Consumes: `GuestReplyAddress`, `InboundMessage`, `GuestMailReply`, `ForwardedGuestReplyMail`, Config, `Tenant`, `Reservation`.
- Produces: `InboundGuestReplyRouter::route(string $recipient, string $mailFrom, string $postalMessageId, InboundMessage $message): GuestMailReply`.
  - Zuordnung: Slug → Tenant (`withoutGlobalScope('tenant')`), Code → Reservierung (tenant-gebunden), HMAC per `hash_equals`.
  - `match_status`: `matched` (Tenant+Buchung+HMAC ok), `slug_only` (Tenant ok, Rest nicht), `unmatched` (kein Tenant).
  - Idempotenz: existiert `postal_message_id` schon, denselben Datensatz zurückgeben.
  - Schleifenschutz: `message->isAutomatic` oder leerer `mailFrom` → `forward_status=skipped`, keine Weiterleitung.
  - Ratenbegrenzung: mehr als `rate_per_sender` Nachrichten je Absender und Betrieb pro Minute → `forward_status=skipped` (abgelegt, nicht weitergeleitet).
  - Weiterleitung bei `matched`/`slug_only` an die Betriebsadresse (`reply_to_fallbacks`-Auflösung über Location), sonst keine.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use App\Models\GuestMailReply;
use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;
use App\Services\Mail\InboundGuestReplyRouter;
use App\Services\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InboundGuestReplyRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
    }

    private function message(bool $auto = false): InboundMessage
    {
        $raw = "From: Gast <gast@example.test>\r\nSubject: Re: Buchung\r\nMessage-ID: <m1@example.test>\r\n"
            .($auto ? "Auto-Submitted: auto-replied\r\n" : '')."\r\nText hier.";
        return InboundMessage::fromRaw($raw);
    }

    public function test_matched_reply_is_stored_and_forwarded(): void
    {
        Mail::fake();
        $reservation = Reservation::factory()->create();
        $reservation->location->update(['email' => 'betrieb@example.test']);
        $addr = GuestReplyAddress::forReservation($reservation->load('tenant'));

        $reply = app(InboundGuestReplyRouter::class)->route($addr, 'gast@example.test', 'pm-1', $this->message());

        $this->assertSame('matched', $reply->match_status);
        $this->assertSame($reservation->id, $reply->reservation_id);
        $this->assertSame($reservation->guest_id, $reply->guest_id);
        Mail::assertQueued(ForwardedGuestReplyMail::class, fn ($m) => $m->guestReplyTo === 'gast@example.test');
    }

    public function test_slug_only_is_forwarded_without_booking(): void
    {
        Mail::fake();
        $reservation = Reservation::factory()->create();
        $reservation->location->update(['email' => 'betrieb@example.test']);
        $slug = $reservation->tenant->slug;

        $reply = app(InboundGuestReplyRouter::class)
            ->route($slug.'+R-WRONG9.deadbeef@antwort.swayy.de', 'gast@example.test', 'pm-2', $this->message());

        $this->assertSame('slug_only', $reply->match_status);
        $this->assertNull($reply->reservation_id);
        Mail::assertQueued(ForwardedGuestReplyMail::class);
    }

    public function test_unknown_slug_is_dropped(): void
    {
        Mail::fake();
        $reply = app(InboundGuestReplyRouter::class)
            ->route('nobody+R-X.y@antwort.swayy.de', 'gast@example.test', 'pm-3', $this->message());

        $this->assertSame('unmatched', $reply->match_status);
        Mail::assertNothingQueued();
    }

    public function test_auto_reply_is_not_forwarded(): void
    {
        Mail::fake();
        $reservation = Reservation::factory()->create();
        $reservation->location->update(['email' => 'betrieb@example.test']);
        $addr = GuestReplyAddress::forReservation($reservation->load('tenant'));

        $reply = app(InboundGuestReplyRouter::class)->route($addr, 'gast@example.test', 'pm-4', $this->message(auto: true));

        $this->assertSame('skipped', $reply->forward_status);
        Mail::assertNothingQueued();
    }

    public function test_sender_over_rate_limit_is_not_forwarded(): void
    {
        Mail::fake();
        config(['swayy.guest_mail_relay.rate_per_sender' => 1]);
        $reservation = Reservation::factory()->create();
        $reservation->location->update(['email' => 'betrieb@example.test']);
        $addr = GuestReplyAddress::forReservation($reservation->load('tenant'));
        $router = app(InboundGuestReplyRouter::class);

        $router->route($addr, 'gast@example.test', 'pm-6a', $this->message());
        $second = $router->route($addr, 'gast@example.test', 'pm-6b', $this->message());

        $this->assertSame('skipped', $second->forward_status);
        Mail::assertQueued(ForwardedGuestReplyMail::class, 1);
    }

    public function test_replay_is_idempotent(): void
    {
        Mail::fake();
        $reservation = Reservation::factory()->create();
        $addr = GuestReplyAddress::forReservation($reservation->load('tenant'));
        $router = app(InboundGuestReplyRouter::class);

        $first = $router->route($addr, 'gast@example.test', 'pm-5', $this->message());
        $second = $router->route($addr, 'gast@example.test', 'pm-5', $this->message());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'pm-5')->count());
    }
}
```

- [ ] **Step 2: Fail** — `php artisan test --filter=InboundGuestReplyRouterTest` → FAIL

- [ ] **Step 3: Implementierung**

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\ForwardedGuestReplyMail;
use App\Models\GuestMailReply;
use App\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

final class InboundGuestReplyRouter
{
    public function route(string $recipient, string $mailFrom, string $postalMessageId, InboundMessage $message): GuestMailReply
    {
        // Idempotenz: schon verarbeitet → denselben Datensatz zurueckgeben.
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

        $matchStatus = match (true) {
            $reservation !== null => 'matched',
            $tenant !== null => 'slug_only',
            default => 'unmatched',
        };

        $automatic = $message->isAutomatic || trim($mailFrom) === '' || trim($mailFrom) === '<>';
        $overLimit = $this->senderOverLimit($tenant, $message->fromEmail);
        $willForward = $matchStatus !== 'unmatched' && ! $automatic && ! $overLimit;

        $reply = GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $tenant?->id,
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

        if ($tenant === null) {
            return $reply; // unmatched: nichts weiterleiten (Entscheidung 4)
        }

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
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        return false;
    }

    private function forward(GuestMailReply $reply, Tenant $tenant, ?Reservation $reservation, InboundMessage $message): void
    {
        $location = $reservation?->location()->withoutGlobalScope('tenant')->first();
        $to = $location?->email ?: $location?->effectiveSettings()->owner_notification_email;
        if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $reply->update(['forward_status' => 'failed']);
            return;
        }

        $prefix = (string) config('swayy.guest_mail_relay.forward_subject_prefix', 'Antwort von');
        $gastName = $message->fromName ?: ($message->fromEmail ?? 'Gast');
        $kopf = $reservation !== null
            ? "Antwort zur Buchung {$reservation->code} vom ".$reservation->localStart()->format('d.m.Y').":\n\n"
            : "Antwort ohne eindeutige Zuordnung (bitte selbst zuordnen):\n\n";

        Mail::to($to)->queue(new ForwardedGuestReplyMail(
            subject: trim("{$prefix} {$gastName}: ".(string) $message->subject),
            body: $kopf.$message->textBody,
            fromName: $tenant->mail_from_name ?: $tenant->name,
            guestReplyTo: (string) $message->fromEmail,
            attachments: array_map(
                fn (array $a) => ['name' => $a['name'], 'mime' => $a['mime'], 'data' => $a['data']],
                $message->attachments,
            ),
        ));

        $reply->update(['forwarded_to' => $to, 'forwarded_at' => now()]);
    }
}
```

> Mandantentrennung: Der Webhook hat keinen aktiven Tenant → überall `withoutGlobalScope(s)` mit explizitem `tenant_id`. „Genau einmal" über die Vorab-Abfrage plus `unique`-Spalte.

- [ ] **Step 4: Pass** → **Step 5: Commit**

```bash
git add app/Services/Mail/InboundGuestReplyRouter.php tests/Feature/InboundGuestReplyRouterTest.php
git commit -m "feat: eingehende Antworten zuordnen, ablegen und weiterleiten"   # + Co-Authored-By-Zeile
```

---

### Task 8: Webhook-Endpunkt (Route, CSRF, Controller)

**Files:**
- Create: `app/Http/Controllers/PostalInboundController.php`
- Modify: `routes/web.php:35` (Import), `routes/web.php:111` (Route)
- Modify: `bootstrap/app.php:95-98` (CSRF-Ausnahme)
- Test: `tests/Feature/PostalInboundWebhookTest.php`

**Interfaces:**
- Consumes: `PostalSignatureVerifier`, `InboundGuestReplyRouter`, `InboundMessage`.
- Produces: `POST /webhooks/postal` (Name `webhooks.postal`). Postal-Payload-Felder: `rcpt_to`, `mail_from`, `message` (base64), `id`. Signatur im Header `X-Postal-Signature`.

- [ ] **Step 1: Failing test** (Schlüsselpaar wie Task 3, Reservierung wie Task 7)

```php
<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;
use App\Mail\ForwardedGuestReplyMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PostalInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $this->keys = ['private' => $priv, 'public' => openssl_pkey_get_details($res)['key']];
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
        config(['swayy.guest_mail_relay.inbound_public_key' => $this->keys['public']]);
    }

    private function payload(string $rcpt): string
    {
        $raw = "From: Gast <gast@example.test>\r\nSubject: Re: Buchung\r\nMessage-ID: <w1@example.test>\r\n\r\nHallo.";
        return json_encode(['id' => 1, 'rcpt_to' => $rcpt, 'mail_from' => 'gast@example.test', 'message' => base64_encode($raw)]);
    }

    private function sign(string $p): string
    {
        openssl_sign($p, $sig, $this->keys['private'], OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    public function test_valid_signed_reply_is_accepted_and_forwarded(): void
    {
        Mail::fake();
        $reservation = Reservation::factory()->create();
        $reservation->location->update(['email' => 'betrieb@example.test']);
        $addr = GuestReplyAddress::forReservation($reservation->load('tenant'));
        $body = $this->payload($addr);

        $this->call('POST', '/webhooks/postal', [], [], [], [
            'HTTP_X-Postal-Signature' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Mail::assertQueued(ForwardedGuestReplyMail::class);
        $this->assertDatabaseHas('guest_mail_replies', ['reservation_id' => $reservation->id, 'match_status' => 'matched']);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $body = $this->payload('x+y.z@antwort.swayy.de');
        $this->call('POST', '/webhooks/postal', [], [], [], [
            'HTTP_X-Postal-Signature' => 'wrong', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $body = 'not json';
        $this->call('POST', '/webhooks/postal', [], [], [], [
            'HTTP_X-Postal-Signature' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);
    }
}
```

- [ ] **Step 2: Fail** — `php artisan test --filter=PostalInboundWebhookTest` → FAIL

- [ ] **Step 3: CSRF-Ausnahme** — in `bootstrap/app.php` die Liste ergänzen:

```php
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/gocardless',
            'webhooks/postal',
        ]);
```

- [ ] **Step 4: Route** — in `routes/web.php` Import bei den übrigen (`use App\Http\Controllers\PostalInboundController;`) und nach Zeile 111:

```php
Route::post('/webhooks/postal', [PostalInboundController::class, 'handle'])->name('webhooks.postal');
```

- [ ] **Step 5: Controller** (Vorbild `GoCardlessWebhookController`; verständliche Meldungen)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Mail\InboundGuestReplyRouter;
use App\Services\Mail\InboundMessage;
use App\Services\Mail\PostalSignatureVerifier;
use Illuminate\Http\Request;

/**
 * Nimmt eingehende Gästeantworten von Postal an (Eingangsroute → HTTP).
 * CSRF-ausgenommen; die Echtheit sichert die Postal-Signatur, nicht die Session.
 */
class PostalInboundController extends Controller
{
    public function __construct(
        private readonly PostalSignatureVerifier $verifier,
        private readonly InboundGuestReplyRouter $router,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->getContent();

        if (! $this->verifier->verify($payload, (string) $request->header('X-Postal-Signature'))) {
            return response()->json(['error' => 'Signatur der Anfrage fehlt oder stimmt nicht. Die Nachricht wurde nicht verarbeitet.'], 401);
        }

        $shared = trim((string) config('swayy.guest_mail_relay.inbound_shared_secret'));
        if ($shared !== '' && ! hash_equals($shared, (string) $request->header('X-Postal-Shared-Secret'))) {
            return response()->json(['error' => 'Zugangskennung fehlt oder stimmt nicht.'], 401);
        }

        $data = json_decode($payload, true);
        $rcpt = $data['rcpt_to'] ?? null;
        $encoded = $data['message'] ?? null;
        if (! is_array($data) || ! is_string($rcpt) || ! is_string($encoded)) {
            return response()->json(['error' => 'Die Anfrage enthält keine lesbare Nachricht (Felder rcpt_to und message erwartet).'], 400);
        }

        $maxBytes = (int) config('swayy.guest_mail_relay.max_message_bytes');
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) > $maxBytes) {
            return response()->json(['error' => 'Die Nachricht fehlt oder ist größer als erlaubt.'], 400);
        }

        try {
            $this->router->route(
                recipient: $rcpt,
                mailFrom: (string) ($data['mail_from'] ?? ''),
                postalMessageId: (string) ($data['id'] ?? ($data['message_id'] ?? md5($raw))),
                message: InboundMessage::fromRaw($raw),
            );
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => 'Die Nachricht konnte nicht verarbeitet werden. Der Fall wurde protokolliert.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
```

- [ ] **Step 6: Pass** — `php artisan test --filter=PostalInboundWebhookTest` → PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PostalInboundController.php routes/web.php bootstrap/app.php tests/Feature/PostalInboundWebhookTest.php
git commit -m "feat: Webhook-Endpunkt fuer eingehende Postal-Antworten"   # + Co-Authored-By-Zeile
```

---

### Task 9: Relay-Reply-To in GuestMailSender + Aufrufer

**Files:**
- Modify: `app/Services/GuestMailSender.php` (Methode `toGuest`, neue private `relayReplyTo`)
- Modify: `app/Services/ReservationLifecycleService.php:538`, `:917`
- Test: `tests/Feature/GuestMailReplyToTest.php` (vorhandene Datei erweitern)

**Interfaces:**
- Consumes: `GuestReplyAddress`, `Reservation`, Tenant-Flag `mail_relay_enabled`.
- Produces: `GuestMailSender::toGuest(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?Reservation $reservation = null)` — bei aktivem Relay, gesetzter Domain und vorhandener Reservierung ist das Reply-To die Relay-Adresse, sonst wie bisher (PR #45).

- [ ] **Step 1: Failing test** (im Stil der vorhandenen `GuestMailReplyToTest`)

```php
public function test_relay_address_used_when_tenant_opted_in(): void
{
    config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
    $reservation = \App\Models\Reservation::factory()->create();
    $reservation->tenant->update(['mail_relay_enabled' => true, 'mail_reply_to' => 'direkt@example.test']);

    $mail = app(\App\Services\GuestMailSender::class)->toGuest('Betreff', 'Text',
        $reservation->tenant, $reservation->location, $reservation->load('tenant'));

    $this->assertStringContainsString($reservation->tenant->slug.'+'.$reservation->code.'.', $mail->envelope()->replyTo[0]->address);
}

public function test_direct_reply_to_when_relay_disabled(): void
{
    config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
    $reservation = \App\Models\Reservation::factory()->create();
    $reservation->tenant->update(['mail_relay_enabled' => false, 'mail_reply_to' => 'direkt@example.test']);

    $mail = app(\App\Services\GuestMailSender::class)->toGuest('Betreff', 'Text',
        $reservation->tenant, $reservation->location, $reservation->load('tenant'));

    $this->assertSame('direkt@example.test', $mail->envelope()->replyTo[0]->address);
}
```

- [ ] **Step 2: Fail** — `php artisan test --filter=GuestMailReplyToTest` → FAIL

- [ ] **Step 3: GuestMailSender erweitern**

```php
use App\Models\Reservation;
use App\Services\Mail\GuestReplyAddress;

public function toGuest(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?Reservation $reservation = null): TemplatedMail
{
    $replyTo = $this->relayReplyTo($tenant, $reservation) ?? $this->replyTo($tenant, $location);

    return new TemplatedMail($subject, $body, $this->fromName($tenant, $location), $replyTo);
}

/**
 * Antwortadresse über Swayy, wenn der Betrieb Relay aktiviert hat, die Domain
 * gesetzt ist und eine Reservierung vorliegt. Sonst null → Ersatz aus replyTo().
 */
private function relayReplyTo(?Tenant $tenant, ?Reservation $reservation): ?string
{
    if ($tenant === null || $reservation === null || ! $tenant->mail_relay_enabled || ! GuestReplyAddress::isConfigured()) {
        return null;
    }

    $reservation->loadMissing('tenant');

    return GuestReplyAddress::forReservation($reservation);
}
```

- [ ] **Step 4: Aufrufer** — in `ReservationLifecycleService.php` beide `toGuest(...)`-Aufrufe um `$reservation` als letztes Argument ergänzen:
  - Zeile 538-547: `…, $tenant, $location, $reservation));`
  - Zeile 917 (`sendGuestMail`): `$this->mailSender->toGuest($rendered['subject'], $rendered['body'], $tenant, $location, $reservation)`

- [ ] **Step 5: Pass + phpstan**

Run: `php artisan test --filter=GuestMailReplyToTest` → PASS
Run: `vendor/bin/phpstan analyse app/Services/GuestMailSender.php app/Services/Mail` → keine neuen Fehler

- [ ] **Step 6: Commit**

```bash
git add app/Services/GuestMailSender.php app/Services/ReservationLifecycleService.php tests/Feature/GuestMailReplyToTest.php
git commit -m "feat: Relay-Antwortadresse in Buchungsmails, sonst direktes Reply-To"   # + Co-Authored-By-Zeile
```

---

### Task 10: Oberfläche — Verlauf und Gästeprofil

**Files:**
- Modify: `app/Http/Controllers/Admin/ReservationBookController.php:255` (Eager Load `mailReplies`)
- Modify: `resources/views/admin/reservations/show.blade.php` (Abschnitt nach „Verlauf", ~Zeile 124)
- Modify: `resources/views/admin/guests/show.blade.php` (Kommunikationsabschnitt)
- Modify: `app/Http/Controllers/Admin/GuestController.php` (Eager Load der Antworten des Gastes, falls nötig)
- Test: `tests/Feature/ReservationRepliesViewTest.php`

**Interfaces:**
- Consumes: `Reservation::mailReplies`, `Guest::mailReplies`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\GuestMailReply;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationRepliesViewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_reservation_show_lists_guest_reply(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = Reservation::factory()->create(['location_id' => $setup['location']->id]);
        $this->clearTenantContext();

        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id, 'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id, 'guest_id' => $reservation->guest_id,
            'postal_message_id' => 'v-1', 'from_email' => 'gast@example.test',
            'to_recipient' => 'x', 'subject' => 'Bitte 20 Uhr', 'body_text' => 'Geht das?',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.reservations.show', $reservation))
            ->assertOk()->assertSee('Bitte 20 Uhr');
    }
}
```

> `createTenantSetup()`/`createMember()` stammen aus `Tests\Concerns\CreatesTenants`.

- [ ] **Step 2: Fail** → **Step 3: Eager Load** — in `ReservationBookController::show` die `load([...])`-Liste um `'mailReplies'` ergänzen.

- [ ] **Step 4: Blade-Abschnitt** in `reservations/show.blade.php` nach dem Verlauf-Block (Markup an die vorhandenen Abschnitte angelehnt, kein globales CSS):

```blade
@if($reservation->mailReplies->isNotEmpty())
<div class="mt-6">
    <h2 class="mb-3 font-bold">Antworten vom Gast</h2>
    <ul class="space-y-3">
        @foreach($reservation->mailReplies->sortByDesc('received_at') as $reply)
            <li class="rounded-lg bg-stone-50 p-3 text-sm">
                <div class="flex justify-between text-xs text-stone-400">
                    <span>{{ $reply->from_email }}</span>
                    <span>{{ $reply->received_at->format('d.m.Y H:i') }} Uhr</span>
                </div>
                @if($reply->subject)<p class="font-semibold text-stone-700">{{ $reply->subject }}</p>@endif
                <p class="whitespace-pre-line text-stone-600">{{ $reply->body_text }}</p>
                @if($reply->has_attachments)
                    <p class="mt-1 text-xs text-stone-400">Anhänge (beim Betrieb): {{ collect($reply->attachments_meta)->pluck('name')->implode(', ') }}</p>
                @endif
                @if($reply->forward_status === 'failed')
                    <p class="mt-1 text-xs text-red-500">Weiterleitung an den Betrieb fehlgeschlagen.</p>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endif
```

- [ ] **Step 5: Gästeprofil** — in `guests/show.blade.php` einen gleichartigen Abschnitt, der `$guest->mailReplies` listet (Eager Load im `GuestController` ergänzen). Mandantentrennung ist über die Relation (tenant-gebunden) gegeben.

- [ ] **Step 6: Pass + Commit**

```bash
git add app/Http/Controllers/Admin/ReservationBookController.php app/Http/Controllers/Admin/GuestController.php resources/views/admin/reservations/show.blade.php resources/views/admin/guests/show.blade.php tests/Feature/ReservationRepliesViewTest.php
git commit -m "feat: Gaesteantworten im Buchungsverlauf und Gaesteprofil"   # + Co-Authored-By-Zeile
```

---

### Task 11: Opt-in-Schalter in den Einstellungen

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php` → `updateGuestMail` (PUT `/admin/settings/guest-mail`, ~Zeile 978)
- Modify: `resources/views/admin/settings/index.blade.php` (Schalter im Abschnitt „E-Mails an Gäste")
- Test: `tests/Feature/GeneralSettingsTest.php` (erweitern, nutzt bereits `CreatesTenants`)

**Interfaces:**
- Consumes: `tenants.mail_relay_enabled`, `GuestReplyAddress::isConfigured()`.

- [ ] **Step 1: Failing test**

```php
// in tests/Feature/GeneralSettingsTest.php ergänzen (Klasse nutzt bereits CreatesTenants)
public function test_relay_cannot_be_enabled_without_domain(): void
{
    config(['swayy.guest_mail_relay.domain' => '']);
    $setup = $this->createTenantSetup();
    $admin = $this->createMember($setup['tenant'], 'tenant_admin');
    $this->clearTenantContext();

    $this->actingAs($admin)->from('/admin/settings')
        ->put('/admin/settings/guest-mail', ['mail_relay_enabled' => '1'])
        ->assertSessionHasErrors('mail_relay_enabled');

    $this->assertFalse($setup['tenant']->fresh()->mail_relay_enabled);
}

public function test_relay_enabled_with_domain(): void
{
    config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
    $setup = $this->createTenantSetup();
    $admin = $this->createMember($setup['tenant'], 'tenant_admin');
    $this->clearTenantContext();

    $this->actingAs($admin)->put('/admin/settings/guest-mail', ['mail_relay_enabled' => '1'])
        ->assertSessionHasNoErrors();

    $this->assertTrue($setup['tenant']->fresh()->mail_relay_enabled);
}
```

> Route: `PUT /admin/settings/guest-mail` → `SettingsController@updateGuestMail` (Berechtigung `tenant.settings.manage`).

- [ ] **Step 2: Fail** → **Step 3: Controller**

Validierung ergänzen und Speichern. Vor dem `update` die Abhängigkeit prüfen:

```php
$validated = $request->validate([
    'mail_from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[<>@\r\n"]/'],
    'mail_reply_to' => ['nullable', 'email:rfc', 'max:200'],
    'mail_relay_enabled' => ['sometimes', 'boolean'],
], [ /* vorhandene Meldungen … */ ]);

$relayAn = $request->boolean('mail_relay_enabled');
if ($relayAn && ! \App\Services\Mail\GuestReplyAddress::isConfigured()) {
    return back()->withErrors([
        'mail_relay_enabled' => __('Die Antwortadresse über Swayy ist noch nicht eingerichtet (es fehlt die Domain). Bitte wende dich an den Betreiber, bevor du sie aktivierst.'),
    ]);
}

$neu = [
    'mail_from_name' => trim((string) ($validated['mail_from_name'] ?? '')) ?: null,
    'mail_reply_to' => trim((string) ($validated['mail_reply_to'] ?? '')) ?: null,
    'mail_relay_enabled' => $relayAn,
];
```

`$old`/`audit` um `mail_relay_enabled` ergänzen.

- [ ] **Step 4: Blade-Schalter** im Abschnitt „E-Mails an Gäste" (an vorhandene Checkbox-Felder angelehnt), mit Hilfetext, der die Beispieladresse `<slug>+<code>.<hmac>@<domain>` nennt und erklärt, dass Antworten dann im Verlauf erscheinen.

- [ ] **Step 5: Pass + Commit**

```bash
git add app/Http/Controllers/Admin/SettingsController.php resources/views/admin/settings/index.blade.php tests/Feature/GeneralSettingsTest.php
git commit -m "feat: Opt-in-Schalter fuer die Antwortadresse je Betrieb"   # + Co-Authored-By-Zeile
```

---

### Task 12: Datenschutz — Anonymisierung und Datenschutzerklärung

**Files:**
- Modify: `app/Services/GuestPrivacyService.php:94` (`anonymize`, innerhalb der Transaktion)
- Modify: `resources/legal/datenschutz.md`
- Test: `tests/Feature/GuestMailReplyPrivacyTest.php`

**Interfaces:**
- Consumes: `GuestMailReply`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\GuestMailReply;
use App\Models\Reservation;
use App\Services\GuestPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestMailReplyPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymize_scrubs_reply_pii(): void
    {
        $reservation = Reservation::factory()->create();
        $guest = $reservation->guest()->associate(\App\Models\Guest::factory()->create(['tenant_id' => $reservation->tenant_id]));
        $reservation->save();

        GuestMailReply::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id, 'reservation_id' => $reservation->id,
            'guest_id' => $reservation->guest_id, 'postal_message_id' => 'p-1',
            'from_email' => 'gast@example.test', 'from_name' => 'Max Gast',
            'to_recipient' => 'x', 'subject' => 'Geheim', 'body_text' => 'Privat',
            'match_status' => 'matched', 'forward_status' => 'sent', 'received_at' => now(),
        ]);

        app(GuestPrivacyService::class)->anonymize($reservation->guest);

        $reply = GuestMailReply::withoutGlobalScopes()->where('postal_message_id', 'p-1')->first();
        $this->assertNull($reply->from_email);
        $this->assertNull($reply->body_text);
        $this->assertNull($reply->subject);
    }
}
```

- [ ] **Step 2: Fail** → **Step 3: GuestPrivacyService** — in der Transaktion von `anonymize` ergänzen:

```php
GuestMailReply::withoutGlobalScopes()
    ->where('guest_id', $guest->id)
    ->update(['from_email' => null, 'from_name' => null, 'subject' => null, 'body_text' => null]);
```

- [ ] **Step 4: Datenschutzerklärung** — in `resources/legal/datenschutz.md` Abschnitt „Kommunikation über die Antwortadresse": Beschreibung, dass Antworten des Gastes an eine Swayy-Adresse angenommen, der Buchung zugeordnet, gespeichert (Text und Metadaten der Anhänge, keine Dateien) und an den Betrieb weitergeleitet werden; Kopplung an Buchung und Gast; Löschung mit der Buchung; Postal als annehmender Mailserver.

- [ ] **Step 5: Pass + Commit**

```bash
git add app/Services/GuestPrivacyService.php resources/legal/datenschutz.md tests/Feature/GuestMailReplyPrivacyTest.php
git commit -m "feat: Antworten anonymisieren und in der Datenschutzerklaerung fuehren"   # + Co-Authored-By-Zeile
```

---

### Task 13: Gesamtprüfung

- [ ] **Step 1:** `php artisan test` → alles grün
- [ ] **Step 2:** `vendor/bin/phpstan analyse` → keine neuen Fehler
- [ ] **Step 3:** Prüfen, dass ohne gesetzte `SWAYY_GUEST_MAIL_RELAY_DOMAIN` jede Buchungsmail das direkte Reply-To behält (Regressionslauf `GuestMailReplyToTest`).
- [ ] **Step 4:** Commit etwaiger Nacharbeiten.

Version, Changelog und Tag übernimmt danach die `release`-Skill. DNS- und Postal-Schritte folgen erst nach Freigabe (siehe unten).

---

## Nach dem Code: DNS und Postal (erst nach Freigabe)

Diese Schritte ändern Fremdsysteme und werden ins jeweilige Serverprotokoll geschrieben.

1. **DNS:** `antwort.swayy.de` anlegen, MX → `mx.mailware.cc`. SPF/DMARC für die Subdomain nach Bedarf.
2. **Postal:** Mailserver/Domain `antwort.swayy.de`, Eingangsroute Catch-all → HTTP-Endpunkt `https://swayy.de/webhooks/postal`. Public Key aus Postal in `SWAYY_GUEST_MAIL_RELAY_PUBLIC_KEY` übernehmen, `SWAYY_GUEST_MAIL_RELAY_DOMAIN=antwort.swayy.de` setzen.
3. **Testbetrieb:** Bei einem Betrieb Relay aktivieren, eine echte Buchungsmail beantworten, Verlauf und Weiterleitung prüfen.
4. **Protokoll:** Einträge in `mx.mailware.cc.md` und das Protokoll von `swayy.de`.
