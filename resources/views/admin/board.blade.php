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
        .chip.vip { background: #fef9c3; color: #854d0e; border: none; }
        html.dark .chip.vip { background: #422006; color: #fde68a; }
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

        /* ---- View switcher ---- */
        .seg { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .seg button { cursor: pointer; border: none; background: var(--panel-2); color: var(--text); padding: 8px 14px; font-size: 14px; font-weight: 600; }
        .seg button.on { background: var(--brand); color: #fff; }

        /* ---- Floor plan ---- */
        #planView { display: none; padding: 0 18px 18px; }
        .plan-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; }
        .rooms { display: flex; flex-wrap: wrap; gap: 6px; }
        .room-tab { cursor: pointer; border: 1px solid var(--border); background: var(--panel); color: var(--text); border-radius: 999px; padding: 7px 14px; font-size: 14px; font-weight: 700; box-shadow: var(--shadow); }
        .room-tab.on { background: var(--brand); color: #fff; border-color: var(--brand); }
        .room-tab .cnt { font-weight: 600; opacity: .75; font-size: 12px; margin-left: 6px; }
        .zoom { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }
        .zoom button { cursor: pointer; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); border-radius: 8px; width: 36px; height: 36px; font-size: 18px; font-weight: 800; line-height: 1; }
        .zoom .zlabel { font-size: 13px; color: var(--muted); min-width: 44px; text-align: center; font-variant-numeric: tabular-nums; }
        .legend { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; color: var(--muted); font-size: 12px; }
        .legend span { display: inline-flex; align-items: center; gap: 5px; }
        .legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
        .l-free { background: var(--green); } .l-soon { background: var(--amber); }
        .l-awaiting { background: var(--blue); } .l-occupied { background: var(--red); }
        .l-blocked { background: var(--muted); }
        .stage { position: relative; overflow: auto; border: 1px solid var(--border); border-radius: 14px; background: var(--panel-2);
            background-image: radial-gradient(var(--border) 1px, transparent 1px); background-size: 26px 26px;
            box-shadow: var(--shadow); max-height: calc(100vh - 230px); touch-action: pan-x pan-y; }
        .canvas { position: relative; transform-origin: 0 0; }
        .roomname { position: absolute; top: 14px; left: 18px; font-size: 38px; font-weight: 800; letter-spacing: .01em;
            color: var(--text); opacity: .12; pointer-events: none; user-select: none; white-space: nowrap; }
        .tbl { position: absolute; display: flex; flex-direction: column; align-items: center; justify-content: center;
            border: 2px solid; box-sizing: border-box; padding: 2px; overflow: hidden; box-shadow: var(--shadow); }
        .tbl .tn { font-weight: 800; font-size: 14px; line-height: 1.05; }
        .tbl .tg { font-size: 11px; font-weight: 600; line-height: 1.1; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tbl .tt { font-size: 10px; opacity: .85; }
        .tbl.free { background: var(--green-bg); border-color: var(--green); color: var(--green); }
        .tbl.soon { background: var(--amber-bg); border-color: var(--amber); color: var(--amber); }
        .tbl.awaiting { background: var(--blue-bg); border-color: var(--blue); color: var(--blue); }
        .tbl.no_show_risk { background: var(--amber-bg); border-color: var(--red); color: var(--red); }
        .tbl.occupied { background: var(--red-bg); border-color: var(--red); color: var(--red); }
        .tbl.blocked { background: var(--panel); border-color: var(--muted); color: var(--muted); border-style: dashed; }
        .tbl.round { border-radius: 50%; }
        .tbl { cursor: pointer; transition: filter .12s, box-shadow .12s; }
        .tbl:hover { filter: brightness(1.04); box-shadow: 0 0 0 3px var(--border), var(--shadow); }
        .tbl.sel { box-shadow: 0 0 0 3px var(--brand), var(--shadow); }
        .plan-empty { color: var(--muted); font-size: 14px; padding: 40px; text-align: center; }

        /* ---- Table detail modal ---- */
        .drawer-back { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 40; opacity: 0; pointer-events: none; transition: opacity .18s; backdrop-filter: blur(2px); }
        .drawer-back.open { opacity: 1; pointer-events: auto; }
        .drawer { position: fixed; top: 50%; left: 50%; width: 460px; max-width: 94vw; max-height: 88vh; z-index: 41; background: var(--panel);
            border: 1px solid var(--border); border-radius: 18px; overflow: hidden;
            box-shadow: 0 24px 60px -12px rgba(0,0,0,.55); transform: translate(-50%, -48%) scale(.96); opacity: 0; pointer-events: none;
            transition: transform .18s ease, opacity .18s ease; display: flex; flex-direction: column; }
        .drawer.open { transform: translate(-50%, -50%) scale(1); opacity: 1; pointer-events: auto; }
        .dwr-head { padding: 16px 18px; border-bottom: 1px solid var(--border); }
        .dwr-head.st-free { background: var(--green-bg); } .dwr-head.st-soon { background: var(--amber-bg); }
        .dwr-head.st-awaiting { background: var(--blue-bg); } .dwr-head.st-occupied { background: var(--red-bg); }
        .dwr-head.st-no_show_risk { background: var(--amber-bg); } .dwr-head.st-blocked { background: var(--panel-2); }
        .bs-free { background: var(--green); color: #fff; } .bs-soon { background: var(--amber); color: #fff; }
        .bs-awaiting { background: var(--blue); color: #fff; } .bs-occupied { background: var(--red); color: #fff; }
        .bs-no_show_risk { background: var(--red); color: #fff; } .bs-blocked { background: var(--muted); color: #fff; }
        .dwr-top { display: flex; align-items: center; gap: 10px; }
        .dwr-title { font-size: 22px; font-weight: 800; }
        .dwr-x { margin-left: auto; cursor: pointer; border: none; background: transparent; font-size: 22px; line-height: 1; color: var(--muted); padding: 4px 8px; border-radius: 8px; }
        .dwr-x:hover { background: rgba(0,0,0,.08); }
        .dwr-sub { margin-top: 6px; font-size: 14px; font-weight: 700; }
        .dwr-body { padding: 14px 18px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px; }
        .dwr-sec h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 8px; }
        .res { border: 1px solid var(--border); border-radius: 12px; padding: 11px 12px; background: var(--panel-2); }
        .res + .res { margin-top: 8px; }
        .res.now { border-color: var(--brand); }
        .res .r1 { display: flex; align-items: baseline; gap: 8px; }
        .res .r1 .t { font-weight: 800; font-variant-numeric: tabular-nums; }
        .res .r1 .nm { font-weight: 700; }
        .res .rl { color: var(--muted); font-size: 13px; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 6px 12px; }
        .res .rl a { color: var(--brand); text-decoration: none; font-weight: 600; }
        .dwr-party { margin-top: 10px; padding: 8px 10px; background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; font-weight: 600; color: var(--muted); }
        .dwr-step-row { display: flex; align-items: center; gap: 10px; color: var(--text); }
        .dwr-step-row b { font-size: 15px; font-variant-numeric: tabular-nums; min-width: 44px; text-align: center; }
        .bd-step { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: var(--panel); color: var(--text); font-size: 17px; font-weight: 800; cursor: pointer; line-height: 1; }
        .bd-step:hover:not(:disabled) { border-color: var(--brand); color: var(--brand); }
        .bd-step:disabled { opacity: .4; cursor: default; }
        .dwr-note { margin-top: 6px; font-size: 12px; color: var(--amber); }
        .dwr-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .field label { font-size: 13px; font-weight: 600; color: var(--muted); }
        .field input { border: 1px solid var(--border); border-radius: 8px; padding: 9px 11px; font-size: 15px; background: var(--panel-2); color: var(--text); }
        .field input:focus { outline: none; border-color: var(--brand); }
        .seatpick { display: flex; flex-wrap: wrap; gap: 6px; }
        .seatpick .seatbtn { min-width: 42px; height: 42px; padding: 0 8px; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); border-radius: 9px; font-size: 15px; font-weight: 800; cursor: pointer; }
        .seatpick .seatbtn:hover { border-color: var(--brand); }
        .seatpick .seatbtn.on { background: var(--brand); color: #fff; border-color: var(--brand); }
        .btn-block { width: 100%; padding: 11px; border: none; border-radius: 10px; background: var(--brand); color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
        .btn-block:disabled { opacity: .5; cursor: default; }
        .btn-ghost { display: block; text-align: center; width: 100%; padding: 11px; border: 1px solid var(--border); border-radius: 10px; background: var(--panel-2); color: var(--text); font-size: 15px; font-weight: 700; cursor: pointer; text-decoration: none; }
        @media (max-width: 520px) { .drawer { max-width: 96vw; } }
    </style>
</head>
<body>
<header>
    <span class="brand">🟢 Live-Board</span>
    <span class="loc">{{ $location->name }}</span>
    <span class="clock" id="clock">–:–</span>
    <span class="seg" id="viewSeg" hidden>
        <button data-view="list" class="on">📋 Liste</button>
        <button data-view="plan">🍽 Tischplan</button>
    </span>
    <span class="spacer"></span>
    <span class="live"><span class="dot" id="liveDot"></span><span id="liveText">aktualisiere…</span></span>
    <a class="btn btn-brand" href="{{ route('admin.reservations.create') }}">+ Neue Buchung</a>
    <button class="btn" id="darkBtn" title="Dark Mode">🌙</button>
    <button class="btn" id="fsBtn" title="Vollbild">⛶</button>
    <a class="btn" href="{{ route('admin.dashboard') }}">← Admin</a>
</header>

<div class="kpis" id="kpis"></div>

<section id="planView">
    <div class="plan-bar">
        <div class="rooms" id="roomTabs"></div>
        <div class="zoom">
            <button id="zoomOut" title="Verkleinern">−</button>
            <span class="zlabel" id="zoomLabel">100 %</span>
            <button id="zoomIn" title="Vergrößern">+</button>
            <button id="zoomFit" title="Einpassen" style="width:auto;padding:0 12px;font-size:14px;">Einpassen</button>
        </div>
    </div>
    <div class="legend">
        <span><i class="l-free"></i> frei</span>
        <span><i class="l-soon"></i> Ankunft bald</span>
        <span><i class="l-awaiting"></i> erwartet</span>
        <span><i class="l-occupied"></i> belegt</span>
        <span><i class="l-blocked"></i> gesperrt</span>
    </div>
    <div class="stage" id="stage">
        <div class="canvas" id="canvas"></div>
    </div>
</section>

<div class="drawer-back" id="drawerBack"></div>
<aside class="drawer" id="drawer" aria-hidden="true">
    <div class="dwr-head" id="dwrHead">
        <div class="dwr-top">
            <span class="dwr-title" id="dwrTitle">Tisch</span>
            <span class="badge" id="dwrBadge"></span>
            <button class="dwr-x" id="dwrClose" title="Schließen">×</button>
        </div>
        <div class="dwr-sub" id="dwrSub"></div>
    </div>
    <div class="dwr-body" id="dwrBody"></div>
</aside>

<main id="listView">
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
    let selectedTableId = null;   // currently opened table in the detail drawer
    let meta = {};                // can_walkin, walkin_url, create_url

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
        const regular = r.regular ? '<span class="chip vip">⭐ Stammgast</span>' : '';
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
            + '<div class="meta">' + (when ? '<span>' + esc(when) + '</span>' : '') + tables + staff + services + regular + allergy + risk + '</div>'
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

        meta = { can_walkin: d.can_walkin, walkin_url: d.walkin_url, create_url: d.create_url };
        plan.update(d.floorplan);
        drawer.refresh();   // live-update the open table panel
    }

    // ---- Floor plan view ----
    const plan = (function () {
        const seg = document.getElementById('viewSeg');
        const listView = document.getElementById('listView');
        const planView = document.getElementById('planView');
        const roomTabs = document.getElementById('roomTabs');
        const stage = document.getElementById('stage');
        const canvas = document.getElementById('canvas');
        const zoomLabel = document.getElementById('zoomLabel');

        let rooms = [];
        let activeRoom = 0;
        let zoom = 1;
        let autoFit = true;       // until the user zooms manually
        let view = localStorage.getItem('gb_board_view') === 'plan' ? 'plan' : 'list';

        function setView(v) {
            view = v;
            localStorage.setItem('gb_board_view', v);
            const showPlan = v === 'plan' && rooms.length > 0;
            planView.style.display = showPlan ? 'block' : 'none';
            listView.style.display = showPlan ? 'none' : 'grid';
            seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.view === v));
            if (showPlan) { fit(); }
        }
        seg.querySelectorAll('button').forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));

        function room() { return rooms[activeRoom]; }

        function renderTabs() {
            roomTabs.innerHTML = rooms.map((r, i) => {
                const free = r.tables.filter(t => t.status === 'free').length;
                return '<button class="room-tab ' + (i === activeRoom ? 'on' : '') + '" data-room="' + i + '">'
                    + (r.is_outdoor ? '🌤 ' : '') + esc(r.name)
                    + '<span class="cnt">' + free + '/' + r.tables.length + ' frei</span></button>';
            }).join('');
            roomTabs.querySelectorAll('.room-tab').forEach(b =>
                b.addEventListener('click', () => selectRoom(+b.dataset.room)));
            // single room → tabs not needed, but keep name for context
            roomTabs.style.display = rooms.length > 1 ? 'flex' : 'none';
        }

        function selectRoom(i) {
            if (i < 0 || i >= rooms.length) return;
            activeRoom = i;
            renderTabs();
            renderCanvas();
            if (autoFit) fit(); else applyZoom();
        }

        function renderCanvas() {
            const r = room();
            if (!r) { canvas.innerHTML = '<div class="plan-empty">Kein Tischplan hinterlegt.</div>'; return; }
            canvas.style.width = r.plan_width + 'px';
            canvas.style.height = r.plan_height + 'px';
            const tables = r.tables.map(t => {
                const guest = t.guest ? '<span class="tg">' + esc(t.guest) + (t.party ? ' · ' + t.party + 'P' : '') + '</span>' : '';
                const time = t.time ? '<span class="tt">' + esc(t.time) + '</span>' : '';
                const rot = t.rotation ? 'transform:rotate(' + t.rotation + 'deg);' : '';
                return '<div class="tbl ' + t.status + (t.shape === 'round' ? ' round' : '')
                    + (t.id === selectedTableId ? ' sel' : '') + '" data-table="' + t.id + '" title="' + esc(t.name) + ' (' + esc(t.capacity) + ')'
                    + (t.guest ? ' — ' + esc(t.guest) : '') + '" style="left:' + t.pos_x + 'px;top:' + t.pos_y
                    + 'px;width:' + t.width + 'px;height:' + t.height + 'px;' + rot + '">'
                    + '<span class="tn">' + esc(t.name) + '</span>' + guest + time + '</div>';
            }).join('');
            canvas.innerHTML = '<div class="roomname">' + esc(r.name) + '</div>' + tables;
        }

        canvas.addEventListener('click', e => {
            const el = e.target.closest('.tbl');
            if (el) drawer.open(+el.dataset.table);
        });

        function markSelected() {
            canvas.querySelectorAll('.tbl').forEach(el =>
                el.classList.toggle('sel', +el.dataset.table === selectedTableId));
        }

        function applyZoom() {
            const r = room();
            if (!r) return;
            // Box takes the scaled size so the scroll container measures correctly;
            // children stay in logical coords and are scaled via the transform.
            canvas.style.width = (r.plan_width * zoom) + 'px';
            canvas.style.height = (r.plan_height * zoom) + 'px';
            canvas.style.transform = 'scale(' + zoom + ')';
            zoomLabel.textContent = Math.round(zoom * 100) + ' %';
        }

        function fit() {
            const r = room();
            if (!r) return;
            const pad = 24;
            const sw = stage.clientWidth - pad;
            const sh = stage.clientHeight - pad;
            zoom = Math.min(sw / r.plan_width, sh / r.plan_height, 1.6);
            if (!isFinite(zoom) || zoom <= 0) zoom = 1;
            autoFit = true;
            applyZoom();
        }

        function setZoom(z) { zoom = Math.max(0.3, Math.min(2.5, z)); autoFit = false; applyZoom(); }
        document.getElementById('zoomIn').addEventListener('click', () => setZoom(zoom + 0.15));
        document.getElementById('zoomOut').addEventListener('click', () => setZoom(zoom - 0.15));
        document.getElementById('zoomFit').addEventListener('click', fit);
        window.addEventListener('resize', () => { if (autoFit && view === 'plan') fit(); });

        // Touch swipe between rooms (horizontal, ignores mostly-vertical scrolls)
        let tx = 0, ty = 0, tracking = false;
        stage.addEventListener('touchstart', e => {
            if (e.touches.length !== 1) { tracking = false; return; }
            tx = e.touches[0].clientX; ty = e.touches[0].clientY; tracking = true;
        }, { passive: true });
        stage.addEventListener('touchend', e => {
            if (!tracking || rooms.length < 2) return;
            const dx = e.changedTouches[0].clientX - tx;
            const dy = e.changedTouches[0].clientY - ty;
            if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                selectRoom((activeRoom + (dx < 0 ? 1 : -1) + rooms.length) % rooms.length);
            }
            tracking = false;
        }, { passive: true });

        function update(fp) {
            const has = Array.isArray(fp) && fp.length > 0;
            seg.hidden = !has;
            if (!has) { rooms = []; if (view === 'plan') setView('list'); return; }
            rooms = fp;
            if (activeRoom >= rooms.length) activeRoom = 0;
            renderTabs();
            renderCanvas();
            if (view === 'plan') { planView.style.display = 'block'; listView.style.display = 'none';
                seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.view === 'plan'));
                if (autoFit) fit(); else applyZoom();
            }
        }

        // restore persisted view once data lands (handled in update)
        return { update, markSelected, findTable: (id) => {
            for (const r of rooms) {
                const t = r.tables.find(x => x.id === id);
                if (t) return { table: t, room: r };
            }
            return null;
        } };
    })();

    // ---- Table detail drawer ----
    const drawer = (function () {
        const back = document.getElementById('drawerBack');
        const el = document.getElementById('drawer');
        const head = document.getElementById('dwrHead');
        const title = document.getElementById('dwrTitle');
        const badge = document.getElementById('dwrBadge');
        const sub = document.getElementById('dwrSub');
        const body = document.getElementById('dwrBody');

        const LABEL = { free: 'Frei', soon: 'Ankunft bald', awaiting: 'Erwartet',
            occupied: 'Belegt', no_show_risk: 'No-Show-Risiko', blocked: 'Gesperrt' };

        function open(id) {
            selectedTableId = id;
            plan.markSelected();
            render();
            back.classList.add('open');
            el.classList.add('open');
            el.setAttribute('aria-hidden', 'false');
        }
        function close() {
            selectedTableId = null;
            plan.markSelected();
            back.classList.remove('open');
            el.classList.remove('open');
            el.setAttribute('aria-hidden', 'true');
        }
        function refresh() { if (selectedTableId !== null) render(); }

        function resCard(r, seats) {
            const seated = r.seated_since ? '<span>🪑 sitzt seit ' + esc(r.seated_since) + '</span>' : '';
            const phone = r.phone ? '<a href="tel:' + esc(r.phone) + '">📞 ' + esc(r.phone) + '</a>' : '';
            const src = r.source === 'walk_in' ? '<span>🚶 Walk-in</span>' : '';
            const regular = r.regular ? '<span style="color:#a16207;font-weight:700">⭐ Stammgast</span>' : '';
            const risk = r.risk >= 50 ? '<span style="color:var(--red)">⚠ No-Show-Risiko</span>' : '';
            const note = r.note ? '<div class="rl">📝 ' + esc(r.note) + '</div>' : '';
            const allergy = r.allergy ? '<div class="rl" style="color:var(--red)">⚠ ' + esc(r.allergy) + '</div>' : '';
            const actions = (r.actions || []).map(a =>
                '<button class="act ' + a.style + '" data-id="' + r.id + '" data-status="' + a.status + '">' + esc(a.label) + '</button>'
            ).join('');
            // Guests at the table can grow (e.g. a walk-in that more people join)
            let stepper = '';
            if (r.is_current && seats) {
                const full = r.party >= seats;
                stepper = '<div class="dwr-party"><span>Gäste am Tisch</span><div class="dwr-step-row">'
                    + '<button class="bd-step" data-id="' + r.id + '" data-cur="' + r.party + '" data-d="-1"' + (r.party <= 1 ? ' disabled' : '') + '>−</button>'
                    + '<b>' + r.party + '/' + seats + '</b>'
                    + '<button class="bd-step" data-id="' + r.id + '" data-cur="' + r.party + '" data-d="1"' + (full ? ' disabled' : '') + '>＋</button>'
                    + '</div></div>'
                    + (full ? '<div class="dwr-note">Tisch voll – für mehr Gäste größeren/zusätzlichen Tisch nutzen.</div>' : '');
            }
            return '<div class="res ' + (r.is_current ? 'now' : '') + '">'
                + '<div class="r1"><span class="t">' + esc(r.from) + '–' + esc(r.to) + '</span>'
                + '<span class="nm">' + esc(r.name) + '</span>'
                + '<span class="party">' + r.party + ' P</span>'
                + '<span style="flex:1"></span><span class="badge s-' + r.status + '">' + esc(r.status_label) + '</span></div>'
                + '<div class="rl">' + seated + src + regular + phone + risk + '</div>'
                + note + allergy + stepper
                + (actions ? '<div class="dwr-actions">' + actions + '</div>' : '')
                + '</div>';
        }

        async function setParty(id, size) {
            const res = await fetch(transitionBase + '/' + id + '/party', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ party_size: size }),
            });
            if (!res.ok) {
                const j = await res.json().catch(() => ({}));
                alert(j.message || 'Personenzahl konnte nicht geändert werden.');
                return;
            }
            await load(); // refresh board + drawer
        }

        function render() {
            const found = plan.findTable(selectedTableId);
            if (!found) { close(); return; }
            const t = found.table;

            head.className = 'dwr-head st-' + t.status;
            title.textContent = 'Tisch ' + t.name;
            badge.textContent = LABEL[t.status] || t.status;
            badge.className = 'badge bs-' + t.status;
            sub.textContent = found.room.name + ' · ' + t.capacity + ' Pers.';

            const resv = t.reservations || [];
            let html = '';

            if (resv.length) {
                const current = resv.filter(r => r.is_current);
                const later = resv.filter(r => !r.is_current);
                if (current.length) html += '<div class="dwr-sec"><h3>Aktuell</h3>' + current.map(r => resCard(r, t.max_capacity)).join('') + '</div>';
                if (later.length) html += '<div class="dwr-sec"><h3>' + (current.length ? 'Weitere heute' : 'Heute') + '</h3>' + later.map(r => resCard(r, t.max_capacity)).join('') + '</div>';
            } else if (t.status === 'blocked') {
                html += '<div class="dwr-sec"><p style="color:var(--muted);font-size:14px;margin:0">Dieser Tisch ist aktuell gesperrt.</p></div>';
            } else {
                html += '<div class="dwr-sec"><p style="color:var(--muted);font-size:14px;margin:0">Aktuell frei – keine Buchungen für heute.</p></div>';
            }

            // Walk-in / table-sharing form. Free table → place a walk-in;
            // occupied but seats left → seat an additional separate group.
            const occupied = resv.some(r => r.is_current);
            const remaining = (t.max_capacity || 0) - (t.occupied || 0);
            if (t.status !== 'blocked' && meta.can_walkin && remaining > 0) {
                const share = occupied;
                const maxSeats = share ? remaining : (t.max_capacity || 8);
                const def = Math.min(share ? 2 : (t.min_capacity || 2), maxSeats);
                let seats = '';
                for (let i = 1; i <= maxSeats; i++) {
                    seats += '<button type="button" class="seatbtn' + (i === def ? ' on' : '') + '" data-n="' + i + '">' + i + '</button>';
                }
                html += '<div class="dwr-sec"><h3>' + (share ? 'Tisch teilen – weitere Gruppe' : 'Walk-in platzieren') + '</h3>'
                    + (share ? '<p style="color:var(--muted);font-size:12px;margin:-4px 0 8px">Noch ' + remaining + ' Plätze frei.</p>' : '')
                    + '<form id="walkinForm">'
                    + '<input type="hidden" name="shared" value="' + (share ? 1 : 0) + '">'
                    + '<div class="field"><label>Personen (mögliche Plätze)</label><div class="seatpick" id="walkinSeats">' + seats + '</div>'
                    + '<input type="hidden" name="party_size" value="' + def + '"></div>'
                    + '<div class="field"><label>Name (optional)</label><input type="text" name="name" maxlength="120" placeholder="Laufkundschaft"></div>'
                    + '<div class="field"><label>Telefon (optional)</label><input type="tel" name="phone" maxlength="40"></div>'
                    + '<button type="submit" class="btn-block" id="walkinSubmit">' + (share ? '➕ Gruppe setzen' : '🚶 Hier platzieren') + '</button>'
                    + '<div id="walkinErr" style="color:var(--red);font-size:13px;margin-top:8px;display:none"></div>'
                    + '</form></div>';
            }

            // Always offer a full booking for this table
            html += '<div class="dwr-sec"><a class="btn-ghost" href="' + meta.create_url + '?table_id=' + t.id + '">＋ Reservierung für diesen Tisch</a></div>';

            body.innerHTML = html;

            // wire status actions (reuse the board transition action)
            body.querySelectorAll('.act').forEach(b =>
                b.addEventListener('click', () => act(b.dataset.id, b.dataset.status, b)));
            body.querySelectorAll('.bd-step').forEach(b => b.addEventListener('click', () => {
                const next = (+b.dataset.cur) + (+b.dataset.d);
                if (next >= 1) setParty(+b.dataset.id, next);
            }));

            const form = document.getElementById('walkinForm');
            if (form) {
                form.addEventListener('submit', e => submitWalkin(e, t.id));
                const seatWrap = document.getElementById('walkinSeats');
                seatWrap?.querySelectorAll('.seatbtn').forEach(b => b.addEventListener('click', () => {
                    seatWrap.querySelectorAll('.seatbtn').forEach(x => x.classList.remove('on'));
                    b.classList.add('on');
                    form.party_size.value = b.dataset.n;
                }));
            }
        }

        async function submitWalkin(e, tableId) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('walkinSubmit');
            const errEl = document.getElementById('walkinErr');
            errEl.style.display = 'none';
            btn.disabled = true;
            try {
                const res = await fetch(meta.walkin_url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        table_id: tableId,
                        party_size: +form.party_size.value,
                        name: form.name.value || null,
                        phone: form.phone.value || null,
                        shared: form.shared ? +form.shared.value : 0,
                    }),
                });
                if (!res.ok) {
                    const j = await res.json().catch(() => ({}));
                    throw new Error(j.message || (j.errors ? Object.values(j.errors)[0][0] : 'Walk-in fehlgeschlagen.'));
                }
                await load();   // refresh board + drawer (table becomes occupied)
            } catch (err) {
                btn.disabled = false;
                errEl.textContent = err.message;
                errEl.style.display = 'block';
            }
        }

        back.addEventListener('click', close);
        document.getElementById('dwrClose').addEventListener('click', close);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

        return { open, close, refresh };
    })();

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
