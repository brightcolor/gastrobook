// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initCopyButtons } from './copy.js';
import { initDrawers } from './drawer.js';
import { initAjaxForms } from './forms.js';
import { initInlineConfirms } from './inline-confirm.js';
import { initMinuteHints } from './minutes.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initReveals } from './reveal.js';
import { initRows } from './rows.js';
import { initSaveBar } from './savebar.js';
import { initWidgetBuilder } from './widget.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initRows();
    initReveals();
    initMinuteHints();
    initSaveBar();
    initInlineConfirms();
    initDrawers();
    initCopyButtons();
    initWidgetBuilder();
}
