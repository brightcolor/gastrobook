// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initAjaxForms } from './forms.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initSaveBar } from './savebar.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initSaveBar();
}
