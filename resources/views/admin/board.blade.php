<!DOCTYPE html>
<html lang="de" id="root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Live-Board – {{ $location->name }}</title>
    <style>
        :root {
            --bg: #f5f5f4; --panel: #ffffff; --panel-2: #fafaf9; --text: #1c1917;
            --muted: #78716c; --border: #e7e5e4; --brand: #0f766e; --shadow: 0 1px 3px rgba(0,0,0,.08);
            --green: #059669; --green-bg: #d1fae5; --amber: #b45309; --amber-bg: #fef3c7;
            --red: #dc2626; --red-bg: #fee2e2; --blue: #2563eb; --blue-bg: #dbeafe;
        }
        html.dark {
            --bg: #0c0a09; --panel: #1c1917; --panel-2: #292524; --text: #f5f5f4;
            --muted: #a8a29e; --border: #44403c; --brand: #2dd4bf; --shadow: 0 1px 3px rgba(0,0,0,.5);
            --green: #34d399; --green-bg: #064e3b; --amber: #fbbf24; --amber-bg: #4a2e05;
            --red: #f87171; --red-bg: #4c1414; --blue: #60a5fa; --blue-bg: #14315e;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
        header { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding: 12px 18px; background: var(--panel); border-bottom: 1px solid var(--border); box-shadow: var(--shadow); }
        .brand { font-weight: 800; font-size: 18px; }
        .clock { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 18px; }
        .loc { color: var(--muted); font-size: 14px; }
        .spacer { flex: 1; }
        .btn { cursor: pointer; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); border-radius: 10px; padding: 8px 12px; font-size: 14px; font-weight: 600; }
        .btn:hover { border-color: var(--brand); }
        .btn-brand { background: var(--brand); color: #fff; border-color: var(--brand); }
        a.btn { text-decoration: none; }
        .kpis { display: flex; flex-wrap: wrap; gap: 10px; padding: 14px 18px 0; }
        .kpi { background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 10px 16px; min-width: 92px; box-shadow: var(--shadow); }
        .kpi .v { font-size: 24px; font-weight: 800; line-height: 1; }
        .kpi .l { color: var(--muted); font-size: 12px; margin-top: 4px; }
        .kpi.alert .v { color: var(--red); }
        main { display: grid; grid-template-columns: 1fr; gap: 18px; padding: 18px; }
        @media (min-width: 900px) { main { grid-template-columns: 360px 1fr; } }
        .col h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 10px; }
        .cards { display: flex; flex-direction: column; gap: 10px; }
        @media (min-width: 1300px) { .col-timeline .cards { display: grid; grid-template-columns: 1fr 1fr; } }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; box-shadow: var(--shadow); }
        .card.attn { border-color: var(--amber); }
        .card.overdue { border-color: var(--red); }
        .card.flash { animation: flash 1.2s ease; }
        @keyframes flash { from { background: var(--green-bg); } to { background: var(--panel); } }
        .row1 { display: flex; align-items: baseline; gap: 8px; }
        .time { font-weight: 800; font-size: 18px; font-variant-numeric: tabular-nums; }
        .name { font-weight: 700; }
        .party { background: var(--panel-2); border: 1px solid var(--border); border-radius: 20px; padding: 1px 9px; font-size: 13px; font-weight: 700; }
        .meta { color: var(--muted); font-size: 13px; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .chip { border-radius: 6px; padding: 1px 7px; font-size: 12px; font-weight: 600; background: var(--panel-2); border: 1px solid var(--border); }
        .chip.allergy { background: var(--red-bg); color: var(--red); border: none; }
        .chip.risk { background: var(--amber-bg); color: var(--amber); border: none; }
        .badge { border-radius: 6px; padding: 1px 8px; font-size: 12px; font-weight: 700; }
        .s-confirmed { background: var(--blue-bg); color: var(--blue); }
        .s-seated, .s-partially_arrived { background: var(--green-bg); color: var(--green); }
        .s-requested, .s-pending_confirmation, .s-payment_pending { background: var(--amber-bg); color: var(--amber); }
        .actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .act { cursor: pointer; border: none; border-radius: 9px; padding: 7px 11px; font-size: 13px; font-weight: 700; }
        .act.primary { background: var(--brand); color: #fff; }
        .act.warn { background: var(--amber-bg); color: var(--amber); }
        .act.danger { background: var(--red-bg); color: var(--red); }
        .act:disabled { opacity: .5; cursor: default; }
        .empty { color: var(--muted); font-size: 14px; padding: 20px; text-align: center; border: 1px dashed var(--border); border-radius: 14px; }
        .live { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--green); }
        .dot.stale { background: var(--amber); }
    </style>
</head>
<body>
<header>
    <span class="brand">🟢 Live-Board</span>
    <span class="loc">{{ $location->name }}</span>
    <span class="clock" id="clock">–:–</span>
    <span class="spacer"></span>
    <span class="live"><span class="dot" id="liveDot"></span><span id="liveText">aktualisiere…</span></span>
    <a class="btn btn-brand" href="{{ route('admin.reservations.create') }}">+ Neue Buchung</a>
    <button class="btn" id="darkBtn" title="Dark Mode">🌙</button>
    <button class="btn" id="fsBtn" title="Vollbild">⛶</button>
    <a class="btn" href="{{ route('admin.dashboard') }}">← Admin</a>
</header>

<div class="kpis" id="kpis"></div>

<main>
    <section class="col col-new">
        <h2>Neu &amp; offen</h2>
        <div class="cards" id="newCards"></div>
    </section>
    <section class="col col-timeline">
        <h2 id="timelineTitle">Heute</h2>
        <div class="cards" id="timelineCards"></div>
    </section>
</main>

<script>
(function () {
    const dataUrl = @json(route('admin.board.data'));
    const streamUrl = @json(route('admin.board.stream'));
    const useSse = @json($sse);
    const transitionBase = @json(url('/admin/reservations'));
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const root = document.getElementById('root');
    let seenIds = new Set();
    let firstLoad = true;

    // ---- Dark mode ----
    function applyDark(on) {
        root.classList.toggle('dark', on);
        document.getElementById('darkBtn').textContent = on ? '☀️' : '🌙';
        localStorage.setItem('gb_board_dark', on ? '1' : '0');
    }
    document.getElementById('darkBtn').addEventListener('click', () => applyDark(!root.classList.contains('dark')));
    applyDark(localStorage.getItem('gb_board_dark') === '1'
        || (localStorage.getItem('gb_board_dark') === null && matchMedia('(prefers-color-scheme: dark)').matches));

    // ---- Fullscreen ----
    document.getElementById('fsBtn').addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen?.();
    });

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function relTime(ts) {
        if (!ts) return '';
        const min = Math.round((Date.now() / 1000 - ts) / 60);
        if (min < 1) return 'gerade eben';
        if (min < 60) return 'vor ' + min + ' Min';
        return 'vor ' + Math.round(min / 60) + ' Std';
    }
    function startHint(r) {
        if (r.status === 'seated' || r.status === 'partially_arrived') return 'sitzt seit ' + r.time;
        const m = r.minutes_to_start;
        if (m === null) return '';
        if (m < -5) return '⚠ überfällig (' + r.time + ')';
        if (m < 0) return 'fällig (' + r.time + ')';
        if (m <= 90) return 'in ' + m + ' Min';
        return '';
    }

    function card(r, isNew) {
        const cls = ['card'];
        if (r.needs_action) cls.push('attn');
        if (r.overdue) cls.push('overdue');
        if (isNew && !firstLoad && !seenIds.has(r.id)) cls.push('flash');

        const tables = (r.tables || []).map(t => '<span class="chip">🪑 ' + esc(t) + '</span>').join('');
        const services = (r.services || []).map(s => '<span class="chip">' + esc(s) + '</span>').join('');
        const staff = r.staff ? '<span class="chip">✂ ' + esc(r.staff) + '</span>' : '';
        const allergy = r.allergy ? '<span class="chip allergy">⚠ ' + esc(r.allergy) + '</span>' : '';
        const risk = r.risk >= 50 ? '<span class="chip risk">No-Show-Risiko</span>' : '';
        const note = r.note ? '<div class="meta">📝 ' + esc(r.note) + '</div>' : '';
        const when = isNew
            ? (r.is_today ? 'heute ' + r.time : r.date + ' ' + r.time) + ' · ' + relTime(r.created_ts)
            : startHint(r);

        const actions = (r.actions || []).map(a =>
            '<button class="act ' + a.style + '" data-id="' + r.id + '" data-status="' + a.status + '">' + esc(a.label) + '</button>'
        ).join('');

        return '<div class="' + cls.join(' ') + '" data-card="' + r.id + '">'
            + '<div class="row1"><span class="time">' + esc(r.time) + '</span>'
            + '<span class="name">' + esc(r.name) + '</span>'
            + '<span class="party">' + r.party + ' P</span>'
            + '<span class="spacer" style="flex:1"></span>'
            + '<span class="badge s-' + r.status + '">' + esc(r.status_label) + '</span></div>'
            + '<div class="meta">' + (when ? '<span>' + esc(when) + '</span>' : '') + tables + staff + services + allergy + risk + '</div>'
            + note
            + (actions ? '<div class="actions">' + actions + '</div>' : '')
            + '</div>';
    }

    function renderKpis(k) {
        const items = [
            ['today', 'Heute', false], ['covers', 'Gäste', false],
            ['seated', 'Anwesend', false], ['arrivals_soon', 'Ankunft <1h', false],
            ['open_requests', 'Offen', true], ['waitlist', 'Warteliste', false],
        ];
        document.getElementById('kpis').innerHTML = items.map(([key, label, alert]) =>
            '<div class="kpi ' + (alert && k[key] > 0 ? 'alert' : '') + '"><div class="v">' + (k[key] ?? 0) + '</div><div class="l">' + label + '</div></div>'
        ).join('');
    }

    async function act(id, status, btn) {
        const danger = ['rejected', 'cancelled_by_restaurant', 'no_show'].includes(status);
        if (danger && !confirm('Wirklich als „' + btn.textContent + '" markieren?')) return;
        btn.disabled = true;
        try {
            const res = await fetch(transitionBase + '/' + id + '/transition', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ status }),
            });
            if (!res.ok) throw new Error('http ' + res.status);
            await load();
        } catch (e) {
            btn.disabled = false;
            alert('Aktion fehlgeschlagen. Bitte erneut versuchen.');
        }
    }

    function wire(container) {
        container.querySelectorAll('.act').forEach(b =>
            b.addEventListener('click', () => act(b.dataset.id, b.dataset.status, b)));
    }

    function setLive(ok) {
        document.getElementById('liveDot').classList.toggle('stale', !ok);
        document.getElementById('liveText').textContent = ok
            ? 'aktualisiert ' + new Date().toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'})
            : 'offline – versuche erneut';
    }

    function applyData(d) {
        document.getElementById('clock').textContent = d.now;
        document.getElementById('timelineTitle').textContent = d.is_salon ? 'Heute (Termine)' : 'Heute';
        renderKpis(d.kpis);

        const newEl = document.getElementById('newCards');
        const tlEl = document.getElementById('timelineCards');
        newEl.innerHTML = d.new.length ? d.new.map(r => card(r, true)).join('') : '<div class="empty">Keine neuen oder offenen Buchungen.</div>';
        tlEl.innerHTML = d.timeline.length ? d.timeline.map(r => card(r, false)).join('') : '<div class="empty">Heute keine aktiven Buchungen.</div>';
        wire(newEl); wire(tlEl);

        d.new.forEach(r => seenIds.add(r.id));
        d.timeline.forEach(r => seenIds.add(r.id));
        firstLoad = false;
        setLive(true);
    }

    async function load() {
        try {
            const res = await fetch(dataUrl, { headers: { Accept: 'application/json' } });
            applyData(await res.json());
        } catch (e) {
            setLive(false);
        }
    }

    // Realtime via SSE with automatic fallback to polling
    let pollTimer = null;
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => { if (!document.hidden) load(); }, 20000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    }

    function startStream() {
        if (!useSse || !('EventSource' in window)) { startPolling(); return; }
        let es;
        try { es = new EventSource(streamUrl); } catch (e) { startPolling(); return; }
        es.onmessage = (e) => { try { applyData(JSON.parse(e.data)); } catch (_) {} };
        es.onerror = () => {
            // Browser auto-reconnects EventSource; show stale + ensure polling backup
            setLive(false);
            startPolling();
        };
    }

    load();          // instant first paint
    startStream();   // then live updates (SSE → fallback polling)
})();
</script>
</body>
</html>
