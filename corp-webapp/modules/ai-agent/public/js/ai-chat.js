/*
 * AI asistent — klijent (modul ai-agent iz corp-webapp). Učitava ga layout posle app-ui.js; tekstovi iz window.AI.
 *
 *   aiAsk({ q, conversation_id }, onStep)  → Promise {answer, conversation_id, proposals}; onStep({label}) za svaki alat (SSE)
 *   aiMarkdown(text)                       → bezbedan HTML (sve se escape-uje pre formatiranja): naslovi, **bold**, linkovi, liste, tabele, kod
 *   aiProposals(proposals)                 → kartice „Potvrdi / Odustani" (klik obrađuje delegirani handler ispod)
 *   aiWidget()                             → plutajući mehur u uglu (sve strane osim /ai)
 */
(function () {
    'use strict';
    var T = window.AI || {};
    var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };

    window.aiAsk = function (body, onStep) {
        return fetch(T.askUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        }).then(function (r) {
            if (!r.ok || !r.body) throw new Error(T.serverError + ' (' + r.status + ')');
            var reader = r.body.getReader(), dec = new TextDecoder(), buf = '', done = null;
            function handle(block) {
                var ev = (block.match(/^event: (.*)$/m) || [])[1], data = (block.match(/^data: (.*)$/m) || [])[1];
                if (!ev || !data) return;
                var d = JSON.parse(data);
                if (ev === 'step' && onStep) onStep(d);
                if (ev === 'done') done = d;
                if (ev === 'error') throw new Error(d.error);
            }
            function read() {
                return reader.read().then(function (x) {
                    if (x.value) buf += dec.decode(x.value, { stream: true });
                    var i;
                    while ((i = buf.indexOf('\n\n')) !== -1) { handle(buf.slice(0, i)); buf = buf.slice(i + 2); }
                    if (x.done) { if (buf.trim()) handle(buf); if (!done) throw new Error(T.incomplete); return done; }
                    return read();
                });
            }
            return read();
        });
    };

    window.aiMarkdown = function (t) {
        var line = function (s) {
            s = esc(s);
            s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
            s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\s][^*]*?)\*/g, '$1<em>$2</em>').replace(/_\((.+?)\)_/g, '<em>($1)</em>');
            // URL bez navodnika (posle esc: &quot;) — nema izlaska iz atributa
            s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)"&]+(?:&amp;[^\s)"&]+)*)\)/g, function (m, txt, url) {
                var ext = url.indexOf(location.origin) !== 0;
                return '<a href="' + url + '"' + (ext ? ' target="_blank" rel="noopener"' : '') + '>' + txt + (ext ? ' <i class="bi bi-box-arrow-up-right small"></i>' : '') + '</a>';
            });
            return s;
        };
        var rows = String(t || '').split('\n'), out = '', list = null, i = 0;
        var close = function () { if (list) { out += '</' + list + '>'; list = null; } };
        while (i < rows.length) {
            var l = rows[i];
            if (/^\s*```/.test(l)) {
                close(); var code = []; i++;
                while (i < rows.length && !/^\s*```/.test(rows[i])) code.push(rows[i++]);
                out += '<pre class="small">' + esc(code.join('\n')) + '</pre>'; i++; continue;
            }
            if (/^\s*\|.*\|\s*$/.test(l) && i + 1 < rows.length && /^\s*\|[\s:|-]+\|\s*$/.test(rows[i + 1])) {
                close();
                var cells = function (r) { return r.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return line(c.trim()); }); };
                out += '<div class="table-responsive"><table class="table table-sm table-bordered small my-2"><thead><tr>'
                    + cells(l).map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
                i += 2;
                while (i < rows.length && /^\s*\|.*\|\s*$/.test(rows[i])) { out += '<tr>' + cells(rows[i]).map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; i++; }
                out += '</tbody></table></div>'; continue;
            }
            var h = l.match(/^(#{1,4})\s+(.*)$/), ul = l.match(/^\s*[-•*]\s+(.*)$/), ol = l.match(/^\s*\d+[.)]\s+(.*)$/);
            if (h) { close(); out += '<p class="fw-bold mb-1 mt-2">' + line(h[2]) + '</p>'; }
            else if (ul || ol) {
                var kind = ul ? 'ul' : 'ol';
                if (list !== kind) { close(); out += '<' + kind + ' class="mb-1 ps-3">'; list = kind; }
                out += '<li>' + line((ul || ol)[1]) + '</li>';
            } else if (l.trim() === '') { close(); }
            else { close(); out += '<p class="mb-1">' + line(l) + '</p>'; }
            i++;
        }
        close();
        return out;
    };

    var state = function (p) {
        if (p.status === 'proposed') {
            return '<button type="button" class="btn btn-sm btn-primary me-1" data-ai-decide="confirm"><i class="bi bi-check-lg me-1"></i>' + esc(T.confirm) + '</button>'
                 + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ai-decide="cancel">' + esc(T.cancel) + '</button>';
        }
        var c = { done: 'success', cancelled: 'secondary', failed: 'danger', running: 'warning' }[p.status] || 'secondary';
        return '<span class="badge bg-' + c + '-subtle text-' + c + '-emphasis me-1">' + esc((T.status || {})[p.status] || p.status) + '</span>'
             + '<span class="small">' + esc(p.message || p.result || '') + '</span>' + (p.link ? ' <a class="small" href="' + esc(p.link) + '">' + esc(T.open) + '</a>' : '');
    };
    window.aiProposals = function (list) {
        return (list || []).map(function (p) {
            return '<div class="ai-proposal border rounded p-2 mt-2 bg-body" data-id="' + p.id + '">'
                 + '<div class="small fw-semibold mb-1"><i class="bi bi-pencil-square me-1 text-primary"></i>' + esc(p.title) + '</div>'
                 + '<ul class="small mb-2 ps-3">' + (p.lines || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>'
                 + '<div class="ai-proposal-state">' + state(p) + '</div></div>';
        }).join('');
    };
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-ai-decide]'); if (!b) return;
        var card = b.closest('.ai-proposal'), st = card.querySelector('.ai-proposal-state');
        st.innerHTML = '<span class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>' + esc(b.dataset.aiDecide === 'confirm' ? T.saving : T.cancelling) + '</span>';
        fetch(T.decideUrl.replace('__ID__', card.dataset.id), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ decision: b.dataset.aiDecide }),
        }).then(function (r) { return r.json(); })
          .then(function (d) { st.innerHTML = state(d); })
          .catch(function () { st.innerHTML = '<span class="small text-danger">' + esc(T.serverError) + '</span>'; });
    });

    // Razgovor u kontejneru (strana /ai i widget dele isti kod)
    window.aiChat = function (root, opts) {
        opts = opts || {};
        var msgs = root.querySelector('[data-ai-messages]'), input = root.querySelector('[data-ai-input]'), send = root.querySelector('[data-ai-send]');
        var conv = opts.conversationId || null, busy = false;
        function add(role, html) {
            var w = document.createElement('div');
            w.className = 'ai-msg ai-msg-' + role;
            w.innerHTML = '<div class="ai-bubble">' + html + '</div>';
            msgs.appendChild(w); msgs.scrollTop = msgs.scrollHeight; return w;
        }
        function ask() {
            var q = input.value.trim(); if (!q || busy) return;
            busy = true; send.disabled = true; input.value = '';
            add('user', esc(q));
            var wait = add('assistant', '<span class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>' + esc(T.thinking) + '</span>');
            aiAsk({ q: q, conversation_id: conv }, function (s) {
                wait.querySelector('.ai-bubble').innerHTML = '<span class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>' + esc(s.label) + '…</span>';
            }).then(function (d) {
                conv = d.conversation_id;
                wait.querySelector('.ai-bubble').innerHTML = aiMarkdown(d.answer) + aiProposals(d.proposals);
                if (opts.onAnswer) opts.onAnswer(d);
            }).catch(function (e) {
                wait.querySelector('.ai-bubble').innerHTML = '<span class="text-danger">⚠ ' + esc(e.message) + '</span>';
            }).finally(function () { busy = false; send.disabled = false; input.focus(); msgs.scrollTop = msgs.scrollHeight; });
        }
        send.addEventListener('click', ask);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(); } });
        return {
            add: add, reset: function () { conv = null; msgs.innerHTML = ''; },
            load: function (id, data) {
                conv = id; msgs.innerHTML = '';
                data.messages.forEach(function (m) { add(m.role, m.role === 'user' ? esc(m.content) : aiMarkdown(m.content) + aiProposals((data.proposals || {})[m.id])); });
            },
        };
    };
})();
