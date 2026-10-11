<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * SWAYY_GUEST_PHONE_MATCH_SOURCES: Welche Buchungswege einen Gast über die
 * Telefonnummer zuordnen dürfen.
 */
class GuestProfileConfigTest extends TestCase
{
    private const VARIABLE = 'SWAYY_GUEST_PHONE_MATCH_SOURCES';

    /**
     * @return list<string>
     */
    private function quellen(?string $wert): array
    {
        if ($wert === null) {
            unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
        } else {
            $_SERVER[self::VARIABLE] = $wert;
            $_ENV[self::VARIABLE] = $wert;
        }

        try {
            $config = require base_path('config/swayy.php');

            return $config['guest_profiles']['phone_match_sources'];
        } finally {
            unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
        }
    }

    public function test_ohne_eintrag_ordnen_die_wege_des_personals_zu(): void
    {
        $this->assertSame(['manual', 'phone', 'walk_in', 'api', 'phone_assistant'], $this->quellen(null));
    }

    public function test_eine_eigene_liste_gilt(): void
    {
        $this->assertSame(['walk_in', 'manual'], $this->quellen(' walk_in , manual '));
    }

    public function test_ein_strich_schaltet_die_telefonzuordnung_ganz_ab(): void
    {
        $this->assertSame([], $this->quellen('-'));
    }

    /**
     * Die Online-Buchung lässt sich über die Umgebung nicht freischalten: Die
     * Nummer im öffentlichen Formular kann jeder eintippen.
     */
    public function test_die_online_buchung_ist_kein_erlaubter_eintrag(): void
    {
        $this->assertSame(['manual', 'phone', 'walk_in', 'api', 'phone_assistant'], $this->quellen('phone,online_booking'));
    }
}
