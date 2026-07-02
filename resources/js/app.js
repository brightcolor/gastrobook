// Global, dependency-free UX behaviors for admin + public pages.

// ---------------------------------------------------------------------------
// 1) Double-submit guard with loading state.
//    Every natively submitted form disables its submit buttons and shows a
//    subtle spinner so slow requests (payments, mails) can't be fired twice.
//    Opt out per form with data-no-loading. Programmatic form.submit() does
//    not fire the submit event, so JS-driven flows are unaffected.
// ---------------------------------------------------------------------------
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('data-no-loading')) {
        return;
    }

    const buttons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
    // Defer disabling until after the submit has been dispatched — a disabled
    // button's name/value would otherwise be dropped from the request.
    setTimeout(() => {
        buttons.forEach((btn) => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.classList.add('is-loading');
        });
        form.setAttribute('data-submitting', '');
    }, 0);

    // Safety net: re-enable if we're still on the page (validation error page
    // replaced the DOM anyway; this covers aborted navigations / offline).
    setTimeout(() => releaseForm(form), 15000);
});

function releaseForm(form) {
    if (!form.isConnected || !form.hasAttribute('data-submitting')) return;
    form.removeAttribute('data-submitting');
    form.querySelectorAll('.is-loading').forEach((btn) => {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('is-loading');
    });
}

// Restore buttons when the page is served from the back/forward cache.
window.addEventListener('pageshow', (e) => {
    if (e.persisted) {
        document.querySelectorAll('form[data-submitting]').forEach(releaseForm);
    }
});

// ---------------------------------------------------------------------------
// 2) Flash messages: dismiss button + gentle auto-hide for success notes.
//    Mark elements with data-flash (data-flash="sticky" disables auto-hide).
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash]').forEach((el) => {
        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Meldung schließen');
        close.className = 'ml-auto -mr-1 shrink-0 rounded p-1 leading-none opacity-50 hover:opacity-100';
        close.textContent = '✕';
        close.addEventListener('click', () => dismiss(el));
        el.appendChild(close);

        if (el.dataset.flash !== 'sticky') {
            setTimeout(() => dismiss(el), 6000);
        }
    });

    function dismiss(el) {
        el.style.transition = 'opacity .25s ease, transform .25s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-4px)';
        setTimeout(() => el.remove(), 260);
    }
});
