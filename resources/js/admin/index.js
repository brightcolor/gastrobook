// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initCopyButtons } from './copy.js';
import { initDrawers } from './drawer.js';
import { initExceptions } from './exceptions.js';
import { initAjaxForms } from './forms.js';
import { initHours } from './hours.js';
import { initInlineConfirms } from './inline-confirm.js';
import { initMinuteHints } from './minutes.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initReveals } from './reveal.js';
import { initRows } from './rows.js';
import { initSaveBar } from './savebar.js';
import { initSettingsAnchors } from './settings-anchors.js';
import { initWidgetBuilder } from './widget.js';

export function initAdmin() {
    // Zuerst: Eine alte Sprungmarke führt auf die neue Seite, bevor die übrigen Bausteine ihr Ziel suchen.
    initSettingsAnchors();
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initRows();
    initHours();
    initExceptions();
    initReveals();
    initMinuteHints();
    initSaveBar();
    initInlineConfirms();
    initDrawers();
    initCopyButtons();
    initWidgetBuilder();
}
