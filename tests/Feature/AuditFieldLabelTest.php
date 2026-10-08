<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\TestCase;

/**
 * Felder, die Standorte, Team und „Allgemein“ protokollieren, erscheinen im
 * Protokoll mit deutschem Namen; Rollen mit ihrer Bezeichnung aus
 * lang/de/roles.php.
 */
class AuditFieldLabelTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{label: string, from: ?string, to: ?string}>
     */
    private function changes(array $old, array $new): array
    {
        $log = new AuditLog(['action' => 'location.updated', 'old_values' => $old, 'new_values' => $new]);

        return collect($log->fieldChanges())->keyBy('field')->all();
    }

    public function test_location_profile_fields_have_german_labels(): void
    {
        $changes = $this->changes(
            ['address_line1' => 'Alt 1', 'postal_code' => '11111', 'city' => 'Altstadt', 'public_intro' => 'Alt'],
            ['address_line1' => 'Neu 2', 'postal_code' => '22222', 'city' => 'Neustadt', 'public_intro' => 'Neu'],
        );

        $this->assertSame('Straße und Hausnummer', $changes['address_line1']['label']);
        $this->assertSame('PLZ', $changes['postal_code']['label']);
        $this->assertSame('Ort', $changes['city']['label']);
        $this->assertSame('Begrüßung', $changes['public_intro']['label']);
    }

    public function test_team_fields_have_german_labels_and_role_names(): void
    {
        $changes = $this->changes(
            ['role' => 'staff', 'all_locations' => true, 'location_ids' => [1], 'user_id' => 7],
            ['role' => 'host', 'all_locations' => false, 'location_ids' => [1, 2], 'user_id' => 8],
        );

        $this->assertSame('Rolle', $changes['role']['label']);
        $this->assertSame('Service', $changes['role']['from']);
        $this->assertSame('Empfang', $changes['role']['to']);
        $this->assertSame('Alle Standorte', $changes['all_locations']['label']);
        $this->assertSame('Standorte', $changes['location_ids']['label']);
        $this->assertSame('Benutzer-Nr.', $changes['user_id']['label']);
    }

    public function test_general_settings_fields_have_german_labels(): void
    {
        $changes = $this->changes(
            ['imprint_url' => 'https://a.test/i', 'privacy_url' => 'https://a.test/d', 'terms_url' => 'https://a.test/a', 'brand_primary_color' => '#000000', 'type' => 'restaurant'],
            ['imprint_url' => 'https://b.test/i', 'privacy_url' => 'https://b.test/d', 'terms_url' => 'https://b.test/a', 'brand_primary_color' => '#ffffff', 'type' => 'salon'],
        );

        $this->assertSame('Impressum', $changes['imprint_url']['label']);
        $this->assertSame('Datenschutzhinweise', $changes['privacy_url']['label']);
        $this->assertSame('AGB', $changes['terms_url']['label']);
        $this->assertSame('Markenfarbe', $changes['brand_primary_color']['label']);
        $this->assertSame('Art', $changes['type']['label']);
    }
}
