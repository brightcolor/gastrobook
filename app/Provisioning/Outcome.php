<?php

declare(strict_types=1);

namespace App\Provisioning;

/** Das Ergebnis eines Aufrufs: Status, Koerper und was nach dem Festschreiben laeuft (Mails). */
final class Outcome
{
    /**
     * @param  array<string, mixed>|null  $body
     * @param  list<callable(): void>  $afterCommit
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
        public readonly array $afterCommit = [],
    ) {}
}
