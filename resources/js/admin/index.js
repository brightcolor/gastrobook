// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initCopyButtons } from './copy.js';
import { initDrawers } from './drawer.js';
import { initAjaxForms } from './forms.js';
import { initInlineConfirms } from './inline-confirm.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initSaveBar } from './savebar.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initSaveBar();
    initInlineConfirms();
    initDrawers();
    initCopyButtons();
}
