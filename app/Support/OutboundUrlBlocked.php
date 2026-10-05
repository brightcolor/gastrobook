<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Die Zielpruefung hat eine Adresse abgelehnt, bevor eine Verbindung entstand.
 *
 * Die Meldung richtet sich an die Person, die die Adresse eingetragen hat: Sie
 * nennt den Grund und den naechsten Schritt. Der Grund selbst steht in
 * $reason, damit Aufrufer dauerhafte Faelle (die Adresse selbst ist nicht
 * erlaubt) von voruebergehenden (Namensaufloesung gestoert) trennen koennen.
 */
final class OutboundUrlBlocked extends RuntimeException
{
    /** Keine vollstaendige Adresse oder Zeichen ausserhalb eines DNS-Namens. */
    public const INVALID = 'invalid';

    /** Kein https. */
    public const SCHEME = 'scheme';

    /** Benutzername oder Passwort in der Adresse. */
    public const CREDENTIALS = 'credentials';

    /** Der Name loest auf keine Adresse auf. */
    public const UNRESOLVABLE = 'unresolvable';

    /** Mindestens eine aufgeloeste Adresse liegt ausserhalb der erlaubten Netze. */
    public const INTERNAL = 'internal';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            self::INVALID => 'Die Adresse ist unvollständig oder enthält Zeichen, die in einem Servernamen nicht vorkommen. Bitte die vollständige Adresse mit https:// eintragen, Umlaute im Servernamen in Punycode-Schreibweise (xn--…).',
            self::SCHEME => 'Die Adresse muss mit https:// beginnen. Bitte die verschlüsselte Adresse des Dienstes eintragen.',
            self::CREDENTIALS => 'Die Adresse enthält Benutzername oder Passwort. Bitte die Adresse ohne Zugangsdaten eintragen.',
            self::UNRESOLVABLE => 'Der Servername in der Adresse lässt sich nicht auflösen. Bitte die Schreibweise prüfen oder es später erneut versuchen.',
            self::INTERNAL => 'Die Adresse führt in ein internes Netz. Bitte die öffentlich erreichbare Adresse des Dienstes eintragen. Soll ein internes Ziel erreichbar sein, kann der Betreiber dieser Installation das Netz freigeben.',
            default => throw new \InvalidArgumentException("Unbekannter Grund für eine abgelehnte Adresse: {$reason}"),
        });
    }

    /**
     * Ein spaeterer Versuch kann gelingen, ohne dass jemand die Adresse aendert.
     */
    public function isTemporary(): bool
    {
        return $this->reason === self::UNRESOLVABLE;
    }
}
