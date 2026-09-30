/*
 * Guided tour (driver.js 1.3.1) — house app standard (skill: corp-webapp).
 *
 *  - Mandatory: until completed once in this browser it starts by itself and cannot be closed
 *    (no ×, Esc or click outside) — only "Finish" on the last step ends it.
 *  - Completion is stored in the browser: localStorage "app-tutorial-done" and the cookie
 *    "app_tutorial_done" (1 year; excluded from Laravel cookie encryption in bootstrap/app.php).
 *  - The "?" button in the header replays it at any time (then it can be closed).
 *
 * All texts and page steps come from lang/{sr,en}/tour.php via window.APP_TOUR:
 *   common: {key: [title, text]}, finish: [title, text],
 *   pages:  {"<path regex>": [[selector, title, text, side?], ...]}
 * Selectors may list alternatives separated by commas; the first visible one is used.
 */
(function () {
    'use strict';

    var T = window.APP_TOUR;
    if (!T || !window.driver || !window.driver.js) return;

    var STORAGE_KEY = 'app-tutorial-done';
    var COOKIE = 'app_tutorial_done';

    function isDone() {
        try { if (localStorage.getItem(STORAGE_KEY) === '1') return true; } catch (e) {}
        return document.cookie.split('; ').indexOf(COOKIE + '=1') !== -1;
    }

    function markDone() {
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
        document.cookie = COOKIE + '=1; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function step(selector, title, text, side) {
        return { element: selector, popover: { title: title, description: text, side: side || 'bottom', align: 'start' } };
    }

    function intro(pair) {
        return { popover: { title: pair[0], description: pair[1], side: 'over', align: 'center' } };
    }

    function commonSteps() {
        var C = T.common;
        return [
            intro(C.welcome),
            step('#appSidebar .app-sidebar-nav', C.sidebar[0], C.sidebar[1], 'right'),
            step('[data-sidebar-toggle]', C.toggle[0], C.toggle[1]),
            step('.app-header .app-segmented', C.language[0], C.language[1]),
            step('.app-header [data-theme-toggle]', C.theme[0], C.theme[1]),
            step('[data-tour-start]', C.help[0], C.help[1]),
            step('#appSidebar .app-user', C.user[0], C.user[1], 'right'),
        ];
    }

    function pageSteps() {
        var path = location.pathname.replace(/\/+$/, '') || '/';
        var out = [];
        Object.keys(T.pages || {}).forEach(function (pattern) {
            var re;
            try { re = new RegExp(pattern); } catch (e) { return; }
            if (!re.test(path)) return;
            T.pages[pattern].forEach(function (s) { out.push(step(s[0], s[1], s[2], s[3])); });
        });
        return out;
    }

    // An element counts only if it exists and is on screen (the sidebar is off-canvas on phones).
    function visible(selector) {
        var parts = selector.split(',');
        for (var i = 0; i < parts.length; i++) {
            var el;
            try { el = document.querySelector(parts[i].trim()); } catch (e) { el = null; }
            if (!el) continue;
            var r = el.getBoundingClientRect();
            if (r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth) return parts[i].trim();
        }
        return null;
    }

    function resolve(steps) {
        return steps.reduce(function (out, s) {
            if (!s.element) { out.push(s); return out; }
            var sel = visible(s.element);
            if (sel) out.push(Object.assign({}, s, { element: sel }));
            return out;
        }, []);
    }

    var active = null;

    function start(mandatory) {
        if (active) return;
        var steps = resolve((mandatory ? commonSteps() : [intro(T.common.welcome)]).concat(pageSteps()));
        steps.push(intro(T.finish));

        var d = window.driver.js.driver({
            steps: steps,
            showProgress: true,
            animate: true,
            overlayOpacity: 0.55,
            stagePadding: 6,
            stageRadius: 8,
            allowClose: !mandatory,
            disableActiveInteraction: true,
            showButtons: mandatory ? ['next', 'previous'] : ['next', 'previous', 'close'],
            nextBtnText: T.next,
            prevBtnText: T.prev,
            doneBtnText: T.done,
            progressText: T.progress,
            popoverClass: 'app-tour-popover',
            onNextClick: function () {
                if (d.isLastStep()) {
                    markDone();
                    active = null;
                    d.destroy();
                } else {
                    d.moveNext();
                }
            },
            onDestroyStarted: function () {
                // Mandatory run: only "Finish" (handled above) may end it.
                if (mandatory && !isDone()) return;
                active = null;
                d.destroy();
            },
        });
        active = d;
        d.drive();
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-tour-start]')) {
            e.preventDefault();
            start(false);
        }
    });

    if (!isDone()) {
        // Let maps and fonts settle so highlighted positions are right.
        setTimeout(function () { start(true); }, 700);
    }

    window.appStartTour = start;
})();
