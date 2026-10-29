# Integration notes per stack

The skill's pieces are framework-agnostic; what changes per stack is (a) where the files
live and get served, (b) how JS learns the palettes URL, (c) how the app's dark mode is
persisted, (d) where a picker UI fits.

## Contents
- Learning a digested URL from CSS (all asset pipelines)
- Plain HTML / static site
- Laravel (Vite, Blade) — incl. corp-webapp apps
- Rails (Propshaft / Sprockets) and Redmine themes
- React / Vue / Svelte SPAs (Vite, webpack)
- Bootstrap 5 / Tailwind token mapping
- Reference implementation

## Learning a digested URL from CSS

Fingerprinting pipelines rename `palettes.json` to `palettes-ab12cd.json`; JS cannot
guess that. They all rewrite `url()` inside CSS, custom properties included:

```css
:root { --gogh-palettes: url('gogh/palettes.json'); }   /* relative to this CSS file */
```
```js
GoghSchemes.configure({ palettesUrl: () => GoghSchemes.urlFromCss('--gogh-palettes') });
```
If the pipeline is fine with plain paths (static site, `public/`), pass the path.

## Plain HTML / static site
- Copy `gogh-schemes.js`, the derive CSS, `schemes.json`, `palettes.json`.
- Inline `gogh-early.html` in `<head>` before the stylesheet.
- `GoghSchemes.configure({curated: await (await fetch('schemes.json')).json(), palettesUrl: 'palettes.json'})`
  — or inline the manifest as a JS literal to avoid a request on every page.

## Laravel (Vite, Blade)
- `palettes.json` and `schemes.json` → `public/gogh/` (served as-is, no hashing needed),
  or import the manifest in JS (`import curated from './gogh/schemes.json'` — Vite inlines it).
- Early snippet → the main layout's `<head>` (e.g. `resources/views/layouts/app.blade.php`)
  before `@vite(...)`.
- Derive CSS → `resources/css/` and import it AFTER the app's theme/dark-mode CSS.
- Picker → the profile/settings page; recent dots → the user dropdown.
- corp-webapp apps (shadcn-style Bootstrap theme, light/dark): map `--app-*` to the
  starter's tokens; the starter already persists dark mode — pass its storage key as
  `darkStorageKey` so a scheme keeps the toggle in step. Respect the house tutorial: add a
  driver.js step for the picker when it is added to an existing app.

## Rails (Propshaft / Sprockets) and Redmine themes
- Files under the asset path; reference `palettes.json` from CSS with `url()` (above).
- New files/directories are registered at boot → restart the server after adding them.
- Redmine: themes serve every top-level directory except `src/` and dot-dirs — keep the
  generator in `src/`. Production precompiles: with `redmine_detect_update = true` a
  restart recompiles; check `public/assets/.manifest.json` points at the new digests.
- Full worked example: the Modern Redmine Theme (`stylesheets/application.css`
  section 2b + "Colour schemes" blocks at the end, `javascripts/theme.js` COLOUR SCHEMES,
  `src/gen-schemes.py`, HANDOFF.md).

## React / Vue / Svelte SPAs
- `gogh-schemes.js` works as an ES/CommonJS import (`module.exports`) or a global.
- Keep the early snippet in `index.html` — the framework mounts after first paint.
- Either use `renderPicker()` inside a `ref`'d container, or build native components on
  `GoghSchemes.build / apply / applyGogh / recent / loadPalettes` and keep the same
  storage contract (`<prefix>-scheme`, `<prefix>-scheme-gogh`, `<prefix>-recent`).
- SSR (Next/Nuxt): `data-scheme` is client-only state — set it in the inline snippet,
  never render it on the server, or hydration will mismatch.

## Token mapping cheatsheet
| `--s-*` derived role | Bootstrap 5.3 | Tailwind (CSS vars / shadcn) |
|---|---|---|
| bg | `--bs-body-bg` | `--background` |
| text | `--bs-body-color` | `--foreground` |
| muted | `--bs-secondary-color` | `--muted-foreground` |
| surface | `--bs-tertiary-bg`, `.card` bg | `--card`, `--popover` |
| border | `--bs-border-color` | `--border`, `--input` |
| primary / on-primary | `--bs-primary`, `--bs-primary-rgb` (!), button text | `--primary` / `--primary-foreground` |
| danger/success/warning/info | `--bs-danger` … | `--destructive` … |

Bootstrap needs the `-rgb` triplets too (`--bs-primary-rgb`) — derive them in JS from the
built hex values if components use `rgba(var(--bs-primary-rgb), …)`. shadcn stores HSL
triplets without `hsl()` — convert likewise, or switch those tokens to full colours.
