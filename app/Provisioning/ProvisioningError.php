<?php

declare(strict_types=1);

namespace App\Provisioning;

use RuntimeException;

/**
 * Eine Ablehnung mit Status, Code und einem Satz fuer den Betreiber des Panels.
 *
 * Der Satz nennt Ursache und naechsten Schritt; das Panel zeigt ihn unter
 * „Zu pruefen“. Codes und Status stehen im Vertrag (Skill kundenpanel-saas,
 * aufrufe.md).
 */
final class ProvisioningError extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /** @return array{error: array<string, mixed>} */
    public function body(): array
    {
        $fehler = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== null) {
            $fehler['details'] = $this->details;
        }

        return ['error' => $fehler];
    }
}
