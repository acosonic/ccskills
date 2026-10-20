/*
 * Javni asistent sajta — mehur za ugradnju na sajt (varijanta B). Na sajt (pre </body>):
 *   <script src="https://APP/js/ai-chat.js"></script>
 *   <script src="https://APP/js/ai-site-widget.js" data-endpoint="https://APP/ai/site/ask"
 *           data-title="Pitajte nas" data-hello="Zdravo! Pitajte o …" data-color="#2563eb"
 *           data-chips="Radno vreme|Kontakt|Cenovnik"></script>
 * Bez naloga: istorija je u memoriji strane (poslednjih 6 poruka). Server ograničava po IP-u i danu.
 */
(function () {
    'use strict';
    var me = document.currentScript, cfg = me.dataset;
    var color = cfg.color || '#2563eb', history = [], busy = false;
    var esc = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var md = window.aiMarkdown || function (t) { return '<p>' + esc(t).replace(/\n/g, '<br>') + '</p>'; };

    var css = document.createElement('style');
    css.textContent = '#asFab{position:fixed;right:20px;bottom:20px;z-index:2147483000;width:56px;height:56px;border-radius:50%;border:0;color:#fff;font-size:24px;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25)}'
        + '#asBox{position:fixed;right:20px;bottom:88px;z-index:2147483000;width:min(380px,calc(100vw - 24px));height:min(560px,calc(100vh - 120px));display:none;flex-direction:column;background:#fff;color:#1f2937;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);font:14px/1.5 system-ui,sans-serif;overflow:hidden}'
        + '#asBox.open{display:flex}#asHead{padding:12px 14px;color:#fff;font-weight:600;display:flex;align-items:center}#asHead button{margin-left:auto;background:none;border:0;color:#fff;font-size:20px;cursor:pointer}'
        + '#asMsgs{flex:1;overflow:auto;padding:12px}.as-m{margin:0 0 10px;display:flex}.as-u{justify-content:flex-end}.as-b{max-width:85%;padding:8px 11px;border-radius:12px;background:#f3f4f6}'
        + '.as-u .as-b{color:#fff}.as-b p{margin:0 0 6px}.as-b p:last-child{margin:0}.as-b table{border-collapse:collapse;font-size:12px}.as-b td,.as-b th{border:1px solid #ddd;padding:3px 6px}'
        + '#asChips{display:flex;flex-wrap:wrap;gap:6px;padding:0 12px 8px}#asChips button{border:1px solid #d1d5db;background:#fff;border-radius:99px;padding:3px 10px;font-size:12px;cursor:pointer}'
        + '#asForm{display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb}#asForm input{flex:1;border:1px solid #d1d5db;border-radius:8px;padding:8px}#asForm button{border:0;border-radius:8px;color:#fff;padding:0 14px;cursor:pointer}'
        + '@media(max-width:576px){#asBox{right:0;bottom:0;width:100vw;height:100dvh;border-radius:0}}';
    document.head.appendChild(css);

    var fab = document.createElement('button'); fab.id = 'asFab'; fab.style.background = color; fab.innerHTML = '💬'; fab.setAttribute('aria-label', cfg.title || 'Asistent');
    var box = document.createElement('div'); box.id = 'asBox'; box.setAttribute('role', 'dialog');
    box.innerHTML = '<div id="asHead" style="background:' + color + '">' + esc(cfg.title || 'Asistent') + '<button type="button" aria-label="Zatvori">×</button></div>'
        + '<div id="asMsgs"></div><div id="asChips"></div><form id="asForm"><input placeholder="…" aria-label="Pitanje"><button style="background:' + color + '">➤</button></form>';
    document.body.appendChild(fab); document.body.appendChild(box);
    var msgs = box.querySelector('#asMsgs'), input = box.querySelector('input');

    function add(role, html) {
        var w = document.createElement('div'); w.className = 'as-m ' + (role === 'user' ? 'as-u' : '');
        w.innerHTML = '<div class="as-b"' + (role === 'user' ? ' style="background:' + color + '"' : '') + '>' + html + '</div>';
        msgs.appendChild(w); msgs.scrollTop = msgs.scrollHeight; return w.firstChild;
    }
    function ask(q) {
        if (!q || busy) return; busy = true; add('user', esc(q));
        var b = add('assistant', '…');
        fetch(cfg.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ q: q, history: history.slice(-6) }) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { b.innerHTML = esc(d.error); return; }
                b.innerHTML = md(d.answer);
                history.push({ role: 'user', content: q }, { role: 'assistant', content: d.answer.slice(0, 2000) });
            })
            .catch(function () { b.innerHTML = 'Greška u vezi — pokušajte ponovo.'; })
            .finally(function () { busy = false; msgs.scrollTop = msgs.scrollHeight; });
    }
    if (cfg.hello) add('assistant', md(cfg.hello));
    (cfg.chips || '').split('|').filter(Boolean).forEach(function (c) {
        var x = document.createElement('button'); x.type = 'button'; x.textContent = c; x.onclick = function () { ask(c); };
        box.querySelector('#asChips').appendChild(x);
    });
    fab.onclick = function () { box.classList.toggle('open'); if (box.classList.contains('open')) input.focus(); };
    box.querySelector('#asHead button').onclick = function () { box.classList.remove('open'); };
    box.querySelector('#asForm').onsubmit = function (e) { e.preventDefault(); var q = input.value.trim(); input.value = ''; ask(q); };
})();
