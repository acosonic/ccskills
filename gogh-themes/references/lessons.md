# Lessons — what went wrong the first time (Modern Redmine Theme, 2026-10)

Every item below cost real time on the reference implementation
(live on redmine.inctime.com). Read before you measure
anything, and before you declare a scheme "done".

## Measuring

1. **Check the browser version before believing any number.** A borrowed puppeteer
   bundled Chromium 80: no `color-mix()`, so every `@supports (color: color-mix(…))`
   block was skipped and a whole session of measurements described the fallbacks, not
   what users see. Point puppeteer at a current Chrome (`executablePath`) and assert
   `CSS.supports('color','color-mix(in srgb, red, white)')` first. `_browser.mjs` does.
2. **Always run a control.** Stash the change, capture, unstash, capture again, diff.
   Also capture the control twice: some pages are not deterministic (an empty wiki page
   differed between two runs of the same CSS) — otherwise noise looks like a regression.
3. **The default palette must stay pixel-identical.** Put scheme-only overrides under
   `[data-scheme]` and prove it with a pixel diff of the main pages in light and dark.
   If a fix *must* touch the default (a contrast bug of its own), show before/after.
4. **Headless hides scrollbars** (overlay scrollbars, zero layout width). Use a real
   browser under `xvfb-run` for anything about overflow or reserved width.
5. **Don't compute contrast through a canvas.** Painting `color(srgb …)` or rgba to a
   canvas and reading it back gave wrong numbers. Parse the computed strings and composite
   translucent backgrounds up the tree yourself (`contrast_check.mjs`).
6. **Set every state a test needs explicitly.** A contrast run once measured light schemes
   with the dark class still on from the previous scheme, because the click that would
   have switched it targeted a page that was no longer loaded. Write `localStorage`
   directly per case.
7. **Puppeteer quirks of old versions:** no `waitForNetworkIdle` (sleep instead);
   `page.screenshot({clip})` coordinates go wrong after scrolling — use
   `elementHandle.screenshot()`; `goto()` to the same URL with only a different `#hash`
   does not reload (add a query param).

## Where colours leak

8. **White text on the accent is hard-coded everywhere** (`color: white` on buttons,
   badges, current page, avatars). A light accent (Dracula `#BD93F9`, bright dark-mode
   cyan) then fails. Introduce `--on-primary` and replace them; pick it per scheme.
9. **The framework's own greys** (`#eee` table headers, `#ddd` / `#d0d7de` borders, `#f7f7f7`
   toolbars, `#888` / `#666` secondary text, selected-item blues) look neutral next to the
   default palette and stick out on a tinted one (Solarized cream). The leak scan lists
   them; map each to a token under `[data-scheme]`.
10. **SVG icon strokes** are their own leak class — a scan that only checks background /
    text / border misses them. Route them through a token at the *same specificity* as the
    framework rule and without `!important`, so its more specific colours (error red,
    success green, readonly grey) still win.
11. **Plugins / third-party widgets hard-code light backgrounds** (`#ffffdd` cards,
    `#fff` panels, `!important` sidebars). Override with a more specific selector and
    `!important` only where the plugin uses it, so their inline colour-coding still wins;
    turn per-card colours into a left stripe in dark mode.
12. **"Subtle" text tokens usually fail AA** (2.4–2.7:1). The lightest text that passes
    4.5:1 sits very close to "muted" — accept a two-step hierarchy instead of a third
    shade nobody can read.

## Layout traps

13. **`button { height: 24px }`-style legacy rules collapse picker cards** — every card is a
    `<button>`. Set `height: auto; margin: 0` on them.
14. **`display: flex !important` on flash/alert boxes beats jQuery's inline
    `display: none`** — hidden empty error boxes appear as coloured bars. Add
    `[style*="display: none"] { display: none !important; }`.
15. **Focus rings vs. toolbars glued to a field**: when a toolbar overlaps a textarea by
    1px, the field's outer ring shows only between the buttons — a dashed line. Make the
    layering explicit: field `z-index: 1`, transparent toolbar row `z-index: 2`, buttons
    lifted clear of the ring (watch for a fixed `height` with `border-box` eating the
    padding), the selected tab coloured like the field so it covers the ring where they
    join.
16. **Dark-mode icon images** (SVG/PNG backgrounds) disappear on dark buttons. Invert only
    the image: hide it on the button (`background-size: 0 0`) and redraw it in `::after`
    with `background-image: inherit; filter: invert(1)` — the button keeps its themed
    background. Leave third-party icons alone.

## Data

17. **Gogh data has quirks**: "One Dark" uses the comment grey as its foreground — take
    "One Dark Pro". Names differ from marketing names ("TokyoNight", "GitHub Light
    Default"). Some "dark" palettes have a mid-grey background (Nep, Jup: `#758480`) where
    even pure white text cannot reach 7:1 — the browser builder moves the background too.
18. **Pick iconic accents by hand for the curated list** (Nord frost cyan `color_07`,
    Gruvbox yellow `color_04`, Rosé Pine iris `color_06`); the default `color_05` is
    sometimes a dull blue. Offer accent dots for the search results.
19. **Expect ~2 of 29 well-known schemes to fail** without changing character (Material
    Palenight, Solarized Dark) — the generator drops them from the curated list; the
    search still offers them, adjusted, with a note.

## Runtime

20. **No flash**: the stored scheme must be applied by a synchronous inline script in
    `<head>`; for a Gogh pick that includes setting its nine `--s-*` values inline.
21. **Guard against stale ids** (a scheme removed from the list, storage edited): fall back
    to the default, or the page renders with undefined tokens — no colours at all.
22. **Debounced search must not clear results before the new ones are ready** — the list
    flashes empty on every keystroke and clicks land on nothing.
23. **Per-user server-side persistence needs the backend.** If the app's API ignores the
    session cookie (Redmine's JSON API does), a theme cannot read a per-user setting
    without a plugin — `localStorage` per browser is the honest default; say so in the UI.
24. **Don't publish tooling by accident.** Some asset pipelines serve every directory of a
    theme/package (Redmine serves all but `src/`). Keep generator scripts out of served
    paths; check after deploy that they 404, and delete stale compiled copies.
