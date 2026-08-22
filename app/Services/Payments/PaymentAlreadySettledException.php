<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * Der Anbieter kennt zu dieser Buchung bereits eine abgeschlossene Zahlung.
 *
 * PayPal erzwingt die Eindeutigkeit der Rechnungsnummer und meldet
 * `DUPLICATE_INVOICE_ID` genau dann, wenn zu derselben Nummer schon einmal
 * kassiert wurde. Das ist kein Fehler, den man wegdrücken darf: Ein zweiter
 * Anlauf ohne Rechnungsnummer würde den Gast ein zweites Mal zahlen lassen.
 *
 * Der übliche Auslöser ist eine Zahlung, die beim Anbieter durchging, aber
 * hier nie ankam – abgebrochener Rückweg, kein Webhook. Dann gehört der
 * Vorgang auf den Tisch des Betriebs, nicht noch einmal in den Bezahlvorgang.
 */
class PaymentAlreadySettledException extends RuntimeException {}
