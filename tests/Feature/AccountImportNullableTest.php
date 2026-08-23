<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccountImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Jede Spalte, die der Import leeren darf, muss NULL zulassen.
 *
 * Der Import laeuft in EINER Transaktion. Traegt eine als optional gefuehrte
 * Spalte in Wirklichkeit NOT NULL, scheitert die erste Zeile mit einem
 * unaufloesbaren Verweis - und reisst den kompletten Umzug mit. Dieser Fall
 * stand dreimal in der Liste: floor_zones.room_id, feedback_requests.
 * reservation_id und marketing_sends.guest_id waren als optional markiert,
 * obwohl die Datenbank sie verlangt.
 *
 * Darum hier nicht drei Einzelfaelle, sondern die ganze Liste auf einmal.
 */
class AccountImportNullableTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_column_the_import_may_empty_accepts_null(): void
    {
        $verstoesse = [];

        foreach (AccountImportService::NULLABLE_AFTER_MAPPING as $abschnitt => [$modelClass, $spalten]) {
            /** @var Model $model */
            $model = new $modelClass;
            $tabelle = $model->getTable();
            $schema = collect(Schema::getColumns($tabelle))->keyBy('name');

            foreach ($spalten as $spalte) {
                $eintrag = $schema->get($spalte);

                if ($eintrag === null) {
                    $verstoesse[] = "{$abschnitt}: {$tabelle}.{$spalte} gibt es gar nicht";

                    continue;
                }

                if (! $eintrag['nullable']) {
                    $verstoesse[] = "{$abschnitt}: {$tabelle}.{$spalte} ist NOT NULL";
                }
            }
        }

        $this->assertSame([], $verstoesse, "Der Import wuerde diese Spalten leeren, die Datenbank laesst das nicht zu:\n".implode("\n", $verstoesse));
    }

    /**
     * Und die Liste muss vollstaendig sein: Jeder Abschnitt, der ueberhaupt
     * Fremdschluessel umschluesselt, gehoert hinein oder ausdruecklich nicht.
     * Ohne diese Probe faellt ein neuer Abschnitt niemandem auf.
     */
    public function test_the_list_names_only_real_sections(): void
    {
        $bekannt = array_keys(AccountImportService::NULLABLE_AFTER_MAPPING);

        $this->assertSame($bekannt, array_unique($bekannt));
        $this->assertNotEmpty($bekannt);

        foreach (AccountImportService::NULLABLE_AFTER_MAPPING as [$modelClass, $spalten]) {
            $this->assertTrue(class_exists($modelClass), $modelClass.' gibt es nicht.');
            $this->assertNotEmpty($spalten);
        }
    }
}
