<?php

declare(strict_types=1);

namespace App\Provisioning;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wiederholte Aufrufe des Panels bekommen die Antwort des ersten (Vertrag, Idempotenz).
 *
 * Der Eintrag entsteht vor der Arbeit und wird mit ihr festgeschrieben. Ein
 * zweiter Aufruf mit derselben Kennung wartet am eindeutigen Index, bis der
 * erste festgeschrieben ist, und liest dann dessen Antwort. Ablehnungen bleiben
 * ungespeichert: derselbe Aufruf darf gelingen, sobald die Ursache weg ist.
 */
final class Idempotency
{
    public const KEY_PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/';

    public static function requestHash(string $method, string $target, string $body): string
    {
        return hash('sha256', implode("\n", [strtoupper($method), $target, $body]));
    }

    /**
     * Belegt die Kennung innerhalb der laufenden Transaktion.
     *
     * @return array{replay: bool, id: int|null, status: int, response: array<string, mixed>|null}
     */
    public function claim(string $key, string $digest): array
    {
        $bekannt = $this->find($key);
        if ($bekannt !== null) {
            return $this->answer($bekannt, $digest);
        }

        try {
            // Verschachtelte Transaktion = Sicherungspunkt: Bei PostgreSQL brache
            // ein Schluesselkonflikt sonst die ganze aeussere Transaktion ab.
            $id = DB::transaction(fn () => DB::table('provisioning_requests')->insertGetId([
                'idempotency_key' => $key,
                'request_hash' => $digest,
                'status_code' => 0,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Ein gleichzeitiger Aufruf war schneller; seine Antwort gilt.
            // Andere Datenbankfehler gehen weiter an den Controller, der mit
            // 503 antwortet; das Panel wiederholt den Aufruf dann.
            $bekannt = $this->find($key);
            if ($bekannt === null) {
                throw new ProvisioningError(409, 'idempotency_busy', 'Ein Aufruf mit dieser Kennung läuft gerade. Wiederhole ihn gleich mit derselben Kennung.');
            }

            return $this->answer($bekannt, $digest);
        }

        return ['replay' => false, 'id' => (int) $id, 'status' => 0, 'response' => null];
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function finish(int $id, int $status, ?array $response): void
    {
        DB::table('provisioning_requests')->where('id', $id)->update([
            'status_code' => $status,
            'response' => $response === null ? null : json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Loescht Kennungen aelter als PROVISIONING_IDEMPOTENCY_DAYS; liefert die Zahl. */
    public function cleanup(): int
    {
        return DB::table('provisioning_requests')
            ->where('created_at', '<', now()->subDays((int) config('provisioning.idempotency_days', 7)))
            ->delete();
    }

    private function find(string $key): ?object
    {
        return DB::table('provisioning_requests')->where('idempotency_key', $key)->first();
    }

    /**
     * @return array{replay: bool, id: int|null, status: int, response: array<string, mixed>|null}
     */
    private function answer(object $row, string $digest): array
    {
        if (! hash_equals((string) $row->request_hash, $digest)) {
            throw new ProvisioningError(409, 'idempotency_mismatch', 'Diese Aufrufkennung gehört zu einem anderen Aufruf. Nimm für jeden neuen Aufruf eine neue Kennung und für eine Wiederholung dieselbe mit genau demselben Inhalt.');
        }

        $antwort = $row->response === null ? null : json_decode((string) $row->response, true);

        return ['replay' => true, 'id' => (int) $row->id, 'status' => (int) $row->status_code, 'response' => is_array($antwort) ? $antwort : null];
    }
}
