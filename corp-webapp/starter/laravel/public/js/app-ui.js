/*
 * App UI: SPA-like behaviour on top of server-rendered Blade pages.
 *
 *  - URLs listed in config/ui.php open in a modal (short forms) or a side sheet
 *    (record details) instead of navigating.
 *  - Forms inside those overlays, forms with [data-ajax] and delete forms submit
 *    in the background; the resulting page replaces .app-content without a reload.
 *  - Delete confirmations use a styled dialog instead of window.confirm().
 *  - Flash messages are shown as toasts.
 *
 * The server returns only the page content when the request carries "X-Fragment: 1"
 * (see layouts/app.blade.php). Without JavaScript every link and form still works
 * as a normal page request.
 */
(function () {
    'use strict';

    var T = window.APP_UI || {};
    // URL patterns come from config/ui.php (layout passes them in window.APP_UI).
    function patterns(list) {
        return (list || []).map(function (p) { try { return new RegExp(p); } catch (e) { return null; } }).filter(Boolean);
    }
    var MODAL_PATTERNS = patterns(T.modal);
    var SHEET_PATTERNS = patterns(T.sheet);
    function matches(list, path) { return list.some(function (re) { return re.test(path); }); }

    var content = document.querySelector('.app-content');
    var headerTitle = document.querySelector('.app-header-title');
    var modalEl = document.getElementById('appModal');
    var sheetEl = document.getElementById('appSheet');
    var confirmEl = document.getElementById('appConfirm');
    if (!content || !modalEl || !sheetEl || !window.bootstrap) return;

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var sheet = bootstrap.Offcanvas.getOrCreateInstance(sheetEl);
    var confirmDialog = bootstrap.Modal.getOrCreateInstance(confirmEl);
    var loadedScripts = new Set(Array.from(document.querySelectorAll('script[src]')).map(function (s) { return s.src; }));

    // ── Helpers ───────────────────────────────────────────────────────────

    function toast(message, type) {
        if (!message) return;
        var el = document.createElement('div');
        el.className = 'toast app-toast align-items-center border-0';
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi ' +
            (type === 'danger' ? 'bi-exclamation-circle text-danger' : 'bi-check-circle-fill text-success') +
            ' me-2"></i><span></span></div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        el.querySelector('span').textContent = message;
        document.getElementById('appToasts').appendChild(el);
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        bootstrap.Toast.getOrCreateInstance(el, { delay: 4000 }).show();
    }

    function flashFrom(el) {
        if (!el) return;
        toast(el.dataset.success, 'success');
        toast(el.dataset.error, 'danger');
    }

    function spinner() {
        return '<div class="app-loading"><div class="spinner-border spinner-border-sm me-2" role="status"></div>' + (T.loading || '') + '</div>';
    }

    function samePath(a, b) {
        return a.replace(/\/$/, '') === b.replace(/\/$/, '');
    }

    function fetchFragment(url, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'X-Fragment': '1', 'Accept': 'text/html' }, options.headers || {});
        return fetch(url, options).then(function (res) {
            return res.text().then(function (html) {
                var tpl = document.createElement('template');
                tpl.innerHTML = html;
                return { res: res, frag: tpl.content.querySelector('.fragment') };
            });
        });
    }

    // Anything that is not a fragment (login page after session expiry, error pages): fall back to a real request.
    function fallback(result, url) {
        if (result.res.ok) {
            window.location.href = result.res.url || url;
        } else if (result.res.status === 419) {
            window.location.reload();
        } else {
            toast(T.error, 'danger');
        }
    }

    // Inline scripts are wrapped in a block so their const/let can be declared again on the next swap.
    function runScripts(root) {
        var chain = Promise.resolve();
        Array.from(root.querySelectorAll('script')).forEach(function (old) {
            chain = chain.then(function () {
                var s = document.createElement('script');
                if (old.src) {
                    if (loadedScripts.has(old.src)) { old.remove(); return; }
                    return new Promise(function (resolve) {
                        s.onload = s.onerror = resolve;
                        s.src = old.src;
                        loadedScripts.add(old.src);
                        document.head.appendChild(s);
                        old.remove();
                    });
                }
                s.textContent = '{\n' + old.textContent + '\n}';
                old.replaceWith(s);
            });
        });
        return chain;
    }

    // Page content takes the fragment's children; overlays keep the .fragment wrapper (styled in theme.css).
    function fill(target, frag) {
        if (target === content) {
            target.replaceChildren.apply(target, Array.from(frag.childNodes));
        } else {
            target.replaceChildren(frag);
        }
        initSort(target);
        return runScripts(target);
    }

    // Leaflet sizes maps from their container; recalc once an overlay has finished animating.
    function refreshMaps() {
        window.dispatchEvent(new Event('resize'));
    }

    function cleanupModals() {
        document.querySelectorAll('.app-content .modal.show').forEach(function (m) {
            var inst = bootstrap.Modal.getInstance(m);
            if (inst) inst.dispose();
        });
        if (!modalEl.classList.contains('show') && !confirmEl.classList.contains('show')) {
            document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    // Replace the page content with a fragment (after a form submit or a background refresh).
    function applyPage(frag, url) {
        cleanupModals();
        var changed = url && !samePath(new URL(url, location.href).href, location.href);
        var scrollY = window.scrollY;
        return fill(content, frag).then(function () {
            var title = frag.dataset.title;
            if (title) {
                if (headerTitle) headerTitle.textContent = title;
                document.title = title + document.title.replace(/^.*?( · [^·]+)$/, '$1');
            }
            if (changed) {
                history.pushState({ app: true }, '', url);
                window.scrollTo(0, 0);
            } else {
                window.scrollTo(0, scrollY);
            }
            flashFrom(frag);
        });
    }

    // ── Overlays ──────────────────────────────────────────────────────────

    function openInModal(url) {
        modalEl.dataset.url = url;
        modalEl.querySelector('.modal-title').textContent = '';
        var body = modalEl.querySelector('.modal-body');
        body.innerHTML = spinner();
        modal.show();
        return fetchFragment(url).then(function (r) {
            if (!r.frag) { modal.hide(); return fallback(r, url); }
            modalEl.querySelector('.modal-title').textContent = r.frag.dataset.title || '';
            return fill(body, r.frag).then(function () {
                var first = body.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
                if (first) first.focus();
            });
        }).catch(function () { modal.hide(); toast(T.error, 'danger'); });
    }

    function openInSheet(url) {
        sheetEl.dataset.url = url;
        sheetEl.querySelector('[data-sheet-full]').href = url;
        sheetEl.querySelector('.offcanvas-title').textContent = '';
        var body = sheetEl.querySelector('.offcanvas-body');
        body.innerHTML = spinner();
        sheet.show();
        return fetchFragment(url).then(function (r) {
            if (!r.frag) { sheet.hide(); return fallback(r, url); }
            sheetEl.querySelector('.offcanvas-title').textContent = r.frag.dataset.title || '';
            return fill(body, r.frag).then(function () {
                body.scrollTop = 0;
                sheetEl.focus();
                setTimeout(refreshMaps, 50);
            });
        }).catch(function () { sheet.hide(); toast(T.error, 'danger'); });
    }

    sheetEl.addEventListener('shown.bs.offcanvas', refreshMaps);
    modalEl.addEventListener('shown.bs.modal', refreshMaps);
    [modalEl, sheetEl].forEach(function (el) {
        el.addEventListener(el === modalEl ? 'hidden.bs.modal' : 'hidden.bs.offcanvas', function () {
            el.querySelector('.modal-body, .offcanvas-body').replaceChildren();
        });
    });

    function askConfirm(message) {
        return new Promise(function (resolve) {
            var done = false;
            var okBtn = confirmEl.querySelector('[data-confirm-ok]');
            confirmEl.querySelector('[data-confirm-message]').textContent = message || T.confirmDelete || '';

            function finish(result) {
                if (done) return;
                done = true;
                okBtn.removeEventListener('click', onOk);
                confirmEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(result);
            }
            function onOk() {
                finish(true);
                // A click during the opening animation is ignored by hide(); close once it has finished.
                confirmEl.addEventListener('shown.bs.modal', function () { confirmDialog.hide(); }, { once: true });
                confirmDialog.hide();
            }
            function onHidden() { finish(false); }

            okBtn.addEventListener('click', onOk);
            confirmEl.addEventListener('hidden.bs.modal', onHidden);
            confirmDialog.show();
        });
    }

    // ── Forms ─────────────────────────────────────────────────────────────

    function closeStaticModal(el) {
        var inst = el && bootstrap.Modal.getInstance(el);
        if (!inst || !el.classList.contains('show')) return Promise.resolve();
        return new Promise(function (resolve) {
            var timer = setTimeout(resolve, 700);
            el.addEventListener('hidden.bs.modal', function () { clearTimeout(timer); resolve(); }, { once: true });
            inst.hide();
        });
    }

    function setBusy(form, busy) {
        form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
            b.disabled = busy;
            if (busy) {
                b.dataset.label = b.innerHTML;
                b.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>' + b.textContent.trim();
            } else if (b.dataset.label) {
                b.innerHTML = b.dataset.label;
            }
        });
    }

    function submitInBackground(form, submitter) {
        var data = new FormData(form);
        if (submitter && submitter.name) data.append(submitter.name, submitter.value);
        var inModal = modalEl.contains(form);
        var inSheet = sheetEl.contains(form);
        var staticModal = form.closest('.app-content .modal');

        // Laravel's back() (validation errors, "cannot delete") uses the Referer: point it at the page
        // the form was shown on — the overlay's URL, not the page underneath.
        var source = inModal ? modalEl.dataset.url : inSheet ? sheetEl.dataset.url : location.href;

        setBusy(form, true);
        return fetchFragment(form.action, { method: 'POST', body: data, referrer: source }).then(function (r) {
            if (!r.frag) return fallback(r, form.action);

            // Validation errors and refused actions stay in the overlay the form came from.
            if ((inModal || inSheet) && (r.frag.dataset.errors === '1' || r.frag.dataset.error)) {
                var target = inModal ? modalEl.querySelector('.modal-body') : sheetEl.querySelector('.offcanvas-body');
                return fill(target, r.frag).then(function () {
                    target.scrollTop = 0;
                    toast(r.frag.dataset.error, 'danger');
                });
            }

            if (inModal) modal.hide();
            if (inSheet) sheet.hide();
            // A modal that lives inside the page must finish closing before the page content is replaced,
            // otherwise Bootstrap's pending transition callbacks run against removed elements.
            return closeStaticModal(staticModal).then(function () {
                return applyPage(r.frag, r.res.url);
            });
        }).catch(function () {
            toast(T.error, 'danger');
        }).finally(function () {
            if (document.contains(form)) setBusy(form, false);
        });
    }

    // Capture phase: runs before inline onsubmit="return confirm(...)" handlers and suppresses them.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || (form.method || 'get').toLowerCase() !== 'post') return;
        if (form.target && form.target !== '_self') return;

        var needsConfirm = /confirm\(/.test(form.getAttribute('onsubmit') || '') || form.hasAttribute('data-confirm');
        var background = needsConfirm || form.hasAttribute('data-ajax') ||
            modalEl.contains(form) || sheetEl.contains(form) || !!form.closest('.app-content .modal');
        if (!background) return;

        e.preventDefault();
        e.stopPropagation();
        var submitter = e.submitter;

        if (needsConfirm) {
            askConfirm(form.getAttribute('data-confirm')).then(function (ok) {
                if (ok) submitInBackground(form, submitter);
            });
        } else {
            submitInBackground(form, submitter);
        }
    }, true);

    // ── Links ─────────────────────────────────────────────────────────────

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('download') || (a.target && a.target !== '_self') || a.hasAttribute('data-sheet-full')) return;
        var url = new URL(a.href, location.href);
        if (url.origin !== location.origin || a.getAttribute('href').charAt(0) === '#') return;

        var inOverlay = modalEl.contains(a) || sheetEl.contains(a);

        if (matches(MODAL_PATTERNS, url.pathname)) {
            e.preventDefault();
            if (sheetEl.classList.contains('show')) sheet.hide();
            openInModal(url.href);
            return;
        }
        if (matches(SHEET_PATTERNS, url.pathname)) {
            e.preventDefault();
            if (modalEl.classList.contains('show')) modal.hide();
            openInSheet(url.href);
            return;
        }
        // "Cancel" / back links inside an overlay that point to the page underneath just close it.
        if (inOverlay && samePath(url.pathname + url.search, location.pathname + location.search)) {
            e.preventDefault();
            if (modalEl.contains(a)) modal.hide(); else sheet.hide();
        }
    });

    sheetEl.querySelector('[data-sheet-full]').addEventListener('click', function (e) {
        if (e.metaKey || e.ctrlKey) return;
        e.preventDefault();
        window.location.href = sheetEl.dataset.url;
    });

    // Clickable names (<span class="klik-naziv" role="button" tabindex="0">): Enter/Space act like a click.
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.klik-naziv[role="button"]')) {
            e.preventDefault();
            e.target.click();
        }
    });

    // Sortable tables: <table class="table-sort"> + <th data-sort="text|num" [data-sort-default="asc|desc"]>.
    // Cell value from data-v (dates as YYYY-MM-DD, amounts as numbers), otherwise its text.
    // First click: numbers largest first, text A→Z; next click reverses. Works in modals/sheets too.
    function sortTable(th, dir) {
        var table = th.closest('table'), tbody = table && table.tBodies[0];
        if (!tbody) return;
        var ths = Array.from(th.parentNode.children), i = ths.indexOf(th), num = th.dataset.sort === 'num';
        var val = function (r) {
            var c = r.cells[i], v = c.dataset.v != null ? c.dataset.v : c.textContent.trim();
            return num ? (parseFloat(v) || 0) : v.toLowerCase();
        };
        var rows = Array.from(tbody.rows).filter(function (r) { return r.cells.length > i; });
        rows.sort(function (a, b) {
            var x = val(a), y = val(b);
            return (num ? x - y : String(x).localeCompare(String(y))) * (dir === 'asc' ? 1 : -1);
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        ths.forEach(function (t) {
            t.removeAttribute('aria-sort');
            var ind = t.querySelector('.sort-ind'); if (ind) ind.remove();
        });
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        th.insertAdjacentHTML('beforeend', '<i class="bi bi-caret-' + (dir === 'asc' ? 'up' : 'down') + '-fill sort-ind ms-1"></i>');
    }
    function initSort(root) {
        root.querySelectorAll('table.table-sort th[data-sort-default]').forEach(function (th) {
            sortTable(th, th.dataset.sortDefault);
        });
    }
    document.addEventListener('click', function (e) {
        var th = e.target.closest && e.target.closest('table.table-sort th[data-sort]');
        if (!th) return;
        var cur = th.getAttribute('aria-sort');
        sortTable(th, cur ? (cur === 'ascending' ? 'desc' : 'asc') : (th.dataset.sort === 'num' ? 'desc' : 'asc'));
    });
    initSort(document);

    // Pages changed via pushState: going back/forward reloads the real page.
    window.addEventListener('popstate', function () { window.location.reload(); });

    flashFrom(document.getElementById('appFlash'));
})();
