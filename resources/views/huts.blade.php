<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Hut beds, last minute — Austria</title>
    <meta
      name="description"
      content="Austrian Alpine huts with free beds in the next two weeks, sorted by distance from you."
    />
    <style>
      :root {
        --bg: #f6f7f5; --card: #ffffff; --border: #e3e6e1; --fg: #1a1c19;
        --muted: #6b7280; --accent: #059669; --accent-fg: #ffffff;
        --amber: #b45309; --green: #059669;
      }
      @media (prefers-color-scheme: dark) {
        :root {
          --bg: #0f1310; --card: #171c18; --border: #2a312b; --fg: #e8eae6;
          --muted: #9aa39a; --accent: #10b981; --amber: #f59e0b; --green: #34d399;
        }
      }
      * { box-sizing: border-box; }
      body {
        margin: 0; background: var(--bg); color: var(--fg);
        font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      }
      .wrap { max-width: 820px; margin: 0 auto; padding: 2rem 1rem 4rem; }
      h1 { font-size: 1.7rem; margin: 0 0 .3rem; display: flex; align-items: center; gap: .5rem; }
      .sub { color: var(--muted); font-size: .9rem; margin: 0 0 1.5rem; }
      .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; }
      .controls { padding: 1rem; margin-bottom: 1.25rem; }
      .row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
      button {
        font: inherit; cursor: pointer; border-radius: 9px;
        border: 1px solid var(--border); background: var(--card); color: var(--fg);
        padding: .5rem .8rem; transition: background .15s, border-color .15s;
      }
      button:hover { background: rgba(125,125,125,.08); }
      button.primary { background: var(--accent); color: var(--accent-fg); border-color: var(--accent); }
      button.active { border-color: var(--accent); background: rgba(16,185,129,.12); }
      .chips { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .5rem; margin-bottom: 1rem; }
      .chip { border-radius: 999px; padding: .35rem .75rem; font-size: .8rem; white-space: nowrap; flex: 0 0 auto; }
      .muted { color: var(--muted); }
      .small { font-size: .8rem; }
      .count { margin: 0 0 .75rem; color: var(--muted); font-size: .9rem; }
      ul.huts { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .75rem; }
      li.hut { padding: 1rem; }
      .hut-head { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start; }
      .hut-name { font-weight: 600; margin: 0; }
      .meta { display: flex; flex-wrap: wrap; gap: .25rem .75rem; margin-top: .35rem; font-size: .8rem; color: var(--muted); }
      .badge { background: rgba(125,125,125,.12); border-radius: 5px; padding: .05rem .4rem; }
      .book { text-decoration: none; background: var(--accent); color: var(--accent-fg); border-radius: 9px; padding: .4rem .7rem; font-size: .8rem; white-space: nowrap; flex: 0 0 auto; }
      .selected-night { margin-top: .75rem; display: flex; align-items: center; gap: .5rem; }
      .free { font-weight: 700; }
      .free.some { color: var(--amber); } .free.lots { color: var(--green); } .free.none { color: var(--muted); }
      .strip { margin-top: .75rem; display: flex; gap: .4rem; overflow-x: auto; padding-bottom: .25rem; }
      .night { flex: 0 0 auto; border: 1px solid var(--border); border-radius: 7px; padding: .25rem .45rem; text-align: center; min-width: 48px; }
      .night .d { font-size: .65rem; color: var(--muted); }
      .night .n { font-weight: 700; font-size: .95rem; }
      .empty { text-align: center; color: var(--muted); border: 1px dashed var(--border); border-radius: 12px; padding: 2.5rem 1rem; }
      footer { margin-top: 2.5rem; text-align: center; color: var(--muted); font-size: .78rem; }
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>⛰️ Hut beds, last minute</h1>
      <p class="sub" id="subtitle"></p>

      <section class="card controls">
        <div class="row">
          <button class="primary" id="locate">📍 Use my location</button>
          <span class="muted small">or</span>
          <span id="presets" class="row"></span>
        </div>
        <p class="small" id="origin" style="margin:.6rem 0 0"></p>
      </section>

      <div class="chips" id="dates"></div>

      <p class="count" id="count"></p>
      <ul class="huts" id="results"></ul>
      <div class="empty" id="empty" hidden>No huts with free beds for this selection.</div>

      <footer>
        Data from the Alpenverein / SAC Hut Reservation Service. Availability is cached and may be a
        little out of date — always confirm on the booking page before travelling.
      </footer>
    </div>

    <script>
      const DATA = @json($payload);

      const PRESETS = {
        Innsbruck: [47.2692, 11.4041], Salzburg: [47.8095, 13.055],
        Wien: [48.2082, 16.3738], Graz: [47.0707, 15.4395],
      };
      const state = { origin: null, originLabel: null, selectedDate: null };
      const $ = (id) => document.getElementById(id);

      function haversineKm(a, b) {
        const R = 6371, toRad = (x) => (x * Math.PI) / 180;
        const dLat = toRad(b[0] - a[0]), dLng = toRad(b[1] - a[1]);
        const s = Math.sin(dLat / 2) ** 2 +
          Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLng / 2) ** 2;
        return 2 * R * Math.asin(Math.sqrt(s));
      }
      function fmtDate(iso) {
        return new Date(iso + 'T00:00:00').toLocaleDateString(undefined,
          { weekday: 'short', day: 'numeric', month: 'short' });
      }
      function tone(free) { return free <= 0 ? 'none' : free < 5 ? 'some' : 'lots'; }

      function dateList() {
        const out = [], start = new Date(DATA.today + 'T00:00:00');
        for (let i = 0; i < DATA.days; i++) {
          const d = new Date(start); d.setDate(start.getDate() + i);
          out.push(d.toISOString().slice(0, 10));
        }
        return out;
      }
      function setOrigin(coords, label) {
        state.origin = coords; state.originLabel = label;
        $('origin').textContent = coords ? `Sorted by distance from ${label}.` : '';
        render();
      }
      function locate() {
        if (!navigator.geolocation) { $('origin').textContent = 'Geolocation unavailable — pick a city.'; return; }
        $('locate').textContent = 'Locating…';
        navigator.geolocation.getCurrentPosition(
          (p) => { $('locate').textContent = '📍 Use my location'; setOrigin([p.coords.latitude, p.coords.longitude], 'your location'); },
          () => { $('locate').textContent = '📍 Use my location'; $('origin').textContent = 'Could not get your location — pick a city below.'; },
          { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
        );
      }
      function render() {
        let huts = DATA.huts.map((h) => {
          const night = state.selectedDate ? h.nights.find((n) => n.date === state.selectedDate) : null;
          return { ...h, distance: state.origin ? haversineKm(state.origin, [h.lat, h.lng]) : null,
            night, maxFree: h.nights.reduce((m, n) => Math.max(m, n.freeBeds), 0) };
        });
        if (state.selectedDate) huts = huts.filter((h) => (h.night?.freeBeds ?? 0) > 0);
        huts.sort((a, b) =>
          a.distance != null && b.distance != null ? a.distance - b.distance : b.maxFree - a.maxFree);

        $('count').textContent = `${huts.length} hut${huts.length === 1 ? '' : 's'} with free beds`
          + (state.selectedDate ? ` on ${fmtDate(state.selectedDate)}` : '') + '.';
        $('empty').hidden = huts.length > 0;
        $('results').innerHTML = huts.map(hutHtml).join('');
      }
      function hutHtml(h) {
        const meta = [
          h.club ? `<span class="badge">${h.club}</span>` : '',
          h.altitude ? `⛰ ${h.altitude} m` : '',
          h.distance != null ? `📍 ${h.distance.toFixed(0)} km` : '',
        ].filter(Boolean).join(' ');
        let body;
        if (h.night) {
          body = `<div class="selected-night">🛏
            <span class="free ${tone(h.night.freeBeds)}">${h.night.freeBeds} free</span>
            <span class="small muted">on ${fmtDate(h.night.date)}${h.totalBeds ? ` · ${h.totalBeds} total` : ''}</span>
          </div>`;
        } else {
          body = `<div class="strip">${h.nights.map((n) => `
            <div class="night" title="${n.percentage ?? ''}">
              <div class="d">${fmtDate(n.date).replace(/,/, '')}</div>
              <div class="n free ${tone(n.freeBeds)}">${n.freeBeds}</div>
            </div>`).join('')}</div>`;
        }
        return `<li class="hut card">
          <div class="hut-head">
            <div><p class="hut-name">${h.name}</p><div class="meta">${meta}</div></div>
            <a class="book" href="${h.bookingUrl}" target="_blank" rel="noopener">Book ↗</a>
          </div>${body}</li>`;
      }
      function build() {
        $('presets').innerHTML = Object.keys(PRESETS)
          .map((n) => `<button data-preset="${n}">${n}</button>`).join('');
        $('presets').querySelectorAll('button').forEach((b) =>
          b.addEventListener('click', () => {
            $('presets').querySelectorAll('button').forEach((x) => x.classList.remove('active'));
            b.classList.add('active'); setOrigin(PRESETS[b.dataset.preset], b.dataset.preset);
          }));
        $('locate').addEventListener('click', locate);

        $('dates').innerHTML = `<button class="chip active" data-date="">Any night</button>`
          + dateList().map((d) => `<button class="chip" data-date="${d}">${fmtDate(d)}</button>`).join('');
        $('dates').querySelectorAll('button').forEach((b) =>
          b.addEventListener('click', () => {
            $('dates').querySelectorAll('button').forEach((x) => x.classList.remove('active'));
            b.classList.add('active'); state.selectedDate = b.dataset.date || null; render();
          }));
      }
      const upd = DATA.updatedAt
        ? new Date(DATA.updatedAt).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
        : null;
      $('subtitle').textContent = `Austrian Alpine huts with free beds in the next ${DATA.days} days.`
        + (upd ? ` Updated ${upd}.` : '');
      build();
      render();
    </script>
  </body>
</html>
