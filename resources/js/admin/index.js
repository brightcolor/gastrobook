// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
}
