<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Die Export-Datei taugt nicht — mit einem Satz, den man dem Betrieb zeigen kann.
 *
 * Eigene Klasse, weil `RuntimeException` als Fangnetz nicht reicht:
 * `QueryException` erbt über `PDOException` ebenfalls davon. Ein `catch
 * (RuntimeException)` fing damit auch jeden Datenbankfehler ab — und dessen
 * Meldung trägt die fehlgeschlagene Anweisung samt eingesetzter Werte, also
 * Gastdaten aus der Datei, direkt ins Formular.
 */
class AccountImportException extends RuntimeException {}
