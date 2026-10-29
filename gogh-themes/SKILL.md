---
name: gogh-themes
description: Add selectable colour schemes from Gogh (github.com/Gogh-Co/Gogh, ~1,250 terminal palettes such as Dracula, Nord, Gruvbox, Catppuccin, Solarized, Tokyo Night, Rosé Pine) to ANY web app — a curated, WCAG-AA contrast-checked list plus an in-browser search over every Gogh palette with accent choice, no flash on load, default palette left pixel-identical. Ships a generator (Python), a dependency-free runtime + picker (JS/CSS) and puppeteer checks for leaking hard-coded colours and rendered contrast. Use whenever the user wants themes, colour schemes, palettes, skins or "theme switcher" for a web app, theme, dashboard or admin panel — Laravel, Rails/Redmine, React/Vue, Bootstrap, Tailwind or plain HTML — or says "dodaj Gogh teme", "šeme boja", "izbor teme", "dark/light teme kao u Redmine-u", "Dracula/Nord tema za aplikaciju", even if they don't name Gogh.
---

# Gogh colour schemes for any web app

Turns Gogh terminal palettes into app colour schemes the way it was done — and debugged —
for the Modern Redmine Theme (live on
redmine.inctime.com). The idea in one line: **every scheme only sets nine raw `--s-*`
values; one CSS layer derives all of the app's tokens from them**, so schemes stay tiny,
contrast can be computed up front, and the app's default look is untouched.

```
Gogh palette ──gen_schemes.py──▶ html[data-scheme="nord"] { --s-bg … --s-blue }   (curated, CSS)
             └─gogh-schemes.js build()──▶ same nine values, inline on <html>        (any palette, browser)
--s-*  ──gogh-derive.css──▶  app tokens (--bg, --surface, --text, --primary, --on-primary …)
```

## Files

| Path | What | Goes into the app? |
|---|---|---|
| `assets/gogh-schemes.js` | runtime: `configure`, `build`, `apply`, `applyGogh`, `renderPicker`, `renderRecent`, `loadPalettes`, `urlFromCss` | yes |
| `assets/gogh-early.html` | synchronous `<head>` snippet — applies the stored scheme before first paint | yes (inline) |
| `assets/gogh-derive.css` | `--s-*` → app-token layer + `@gogh:begin/end` markers for the curated blocks | yes, tokens renamed |
| `assets/gogh-picker.css` | picker styles (cards, search, accent dots, recent dots) | if using the built-in UI |
| `assets/curated-default.json` | 29 well-known schemes with hand-picked accents (27 pass) | input to the generator |
| `scripts/gen_schemes.py` | curated CSS blocks, `schemes.json` manifest, `palettes.json`, contrast report | **no** — keep out of served dirs |
| `scripts/leak_scan.mjs`, `contrast_check.mjs`, `_browser.mjs` | verification in a real browser | no |
| `references/lessons.md` | 24 pitfalls from the reference implementation | **read before measuring** |
| `references/integration.md` | per-stack notes: URLs, early snippet, dark mode, Bootstrap/Tailwind mapping | read for the app's stack |

## Workflow

### 1. Understand the app's colours first
Find how colours are defined today: CSS custom properties, Sass variables, Tailwind
config, Bootstrap vars, or hard-coded hex. Grep for `#[0-9a-f]{3,6}`, `rgb(`, `white`,
and the dark-mode mechanism (class on `<html>`? which `localStorage` key?).
- If there are no tokens, introduce a small token layer **first** (bg, surface,
  surface-2, border, border-2, text, text-muted, primary, on-primary, success, warning,
  danger, info) and prove the default renders pixel-identical before adding schemes.
- Add an `--on-primary` token and replace hard-coded `white` on primary-coloured
  backgrounds — light accents (Dracula, bright cyans) need dark text.
Read `references/integration.md` for the app's stack.

### 2. Generate the schemes
Download Gogh's `data/themes.json` into its own scratch folder (it's third-party data), then:
```bash
python3 -I scripts/gen_schemes.py themes.json --check                 # report only
python3 -I scripts/gen_schemes.py themes.json \
  --css <app>/…/gogh-derive.css --manifest <app>/…/schemes.json --palettes <app>/…/palettes.json
```
Adjust the list with `--curated my.json` (`{"Gogh name": null | "color_0N" | {"accent": …, "name": …}}`;
names must match `themes.json` exactly). Schemes that cannot pass without changing
character are dropped and reported — tell the user which and why.

### 3. Wire it into the app
- Copy `gogh-derive.css`; rename `--app-*` to the app's tokens; load it **after** the
  app's dark-mode CSS (same specificity, later wins). Keep the surface percentages as
  they are — `build()` and the generator assume them.
- Inline `gogh-early.html` in `<head>` before the stylesheet; set `PREFIX`/`DARK`.
- Load `gogh-schemes.js` and `configure({ curated, palettesUrl, darkClass, darkStorageKey, defaultScheme })`.
  `defaultScheme.swatch` = the app's own bg / text / accent.
- UI: `renderPicker(el)` on a settings/profile page (curated cards + Gogh search with
  accent dots and a readability note); `renderRecent(el)` in a header/user menu with a
  link to the full picker. Ask the user where they want it if it isn't obvious.
- Choosing light/dark explicitly in the app should fall back to the default scheme
  (`apply('')`) — a scheme fixes its own variant.
- Storage is per browser (`localStorage`). Per-user server persistence needs a backend
  field; offer it, don't fake it.

### 4. Hunt leaks, scoped to schemes
Run `scripts/leak_scan.mjs` on the main pages for one dark and one light scheme **and**
for the default. Colours in the report are hard-coded somewhere (app, framework, plugins,
SVG icons). Fix each under `html[data-scheme] …` in the derive file so the default stays
identical; fix the default itself only for real contrast bugs, and show before/after.
`references/lessons.md` lists the usual suspects (white-on-accent, framework greys, icon
strokes, plugin cards, `button { height }`, focus rings under toolbars, dark-mode icons).

### 5. Verify — numbers, not impressions
Use a current Chrome (`CHROME=`; the scripts refuse a browser without `color-mix()`).
The scripts are ES modules and resolve `puppeteer` from their own folder: copy
`scripts/*.mjs` into a scratch folder and put a `node_modules` with puppeteer next to
them (a symlink to any existing install is enough). Env options are documented at the
top of `_browser.mjs` (login by cookies or form, scheme, dark key).
1. **Default unchanged**: control capture (change stashed) vs. after, main pages, light + dark.
2. **Leaks**: scan sample schemes until only intentional colours remain (avatars, native
   checkboxes, semantic reds…).
3. **Contrast**: `contrast_check.mjs` over **all** curated schemes on a form page;
   then a seeded random sample of ~40 Gogh palettes through `GOGH_ROW`, including hard
   ones (Solarized Dark, Material Palenight, Nep).
4. **Parity**: for the curated list, `require('gogh-schemes.js').build(row, slot).vars`
   in Node must equal the generated CSS blocks (±1/255).
5. **UI**: no overlapping cards at desktop and 390px, no horizontal scroll, reload keeps
   the scheme with no flash, stale stored id falls back to default, no JS errors, the
   palettes file is fetched only on the first search keystroke.
Report what was measured, the minimum contrast values, what was dropped, and what still
differs from the default — not "looks good".

### 6. Finish
- Credit Gogh (MIT / Apache-2.0) in the README/about and in `palettes.json` (`source`).
- Keep the generator and verification scripts out of served/published paths; after a
  deploy, check they 404 and that the new digests are live.
- Note in the app's docs where the derive layer and markers live and how to regenerate.
