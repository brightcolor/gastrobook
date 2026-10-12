<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Liest aus dem HTML einer Seite, ob ein Kästchen angehakt ist. Maßgeblich ist
 * das Attribut checked am Kästchen selbst.
 */
trait ReadsCheckboxes
{
    /**
     * @param  string  $id  id des Kästchens, etwa "f-enabled"
     */
    private function checkboxIsChecked(string $html, string $id): bool
    {
        $found = preg_match('/<input\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*\btype="checkbox"[^>]*>/', $html, $match);
        $this->assertSame(1, $found, "Das Kästchen {$id} steht nicht auf der Seite.");

        return preg_match('/\schecked(\s|>|=)/', $match[0]) === 1;
    }
}
