# Tema (shadcn izgled nad Bootstrap 5.3)

Izvor: `starter/laravel/public/css/theme.css`, `starter/laravel/resources/views/layouts/{app,_head,_errors}.blade.php`,
`starter/laravel/resources/views/dashboard.blade.php` (primer KPI kartica). Uzor: next-shadcn-admin-dashboard
(Next.js) — namerno **bez** prelaska na React: svi postojeći Bootstrap prikazi rade, samo dobiju nov izgled.

## Šta čini temu
- Tokeni `--app-*` za `[data-bs-theme="light"]` i `"dark"`, vezani za `--bs-*` (body, border,
  primary, link…), brend `#2563eb` (tamna: `#60a5fa`) — za drugi brend promeni `--app-brand*` i `--bs-primary-*` u oba bloka i dve fiksne boje (`.brand-mark`, `.auth-aside`). Inter + JetBrains Mono (Google Fonts).
- Override postojećih klasa: `.card.border-0` dobija tanku ivicu, `.bg-white`/`.table-light`/`.bg-light`
  prate temu, `.badge.bg-*` postaju „soft“, dugmad/forme/paginacija/dropdown/alert restilizovani.
- Raspored: `.app-sidebar` (grupe, sklapanje `html.sidebar-collapsed`, off-canvas `html.sidebar-open`
  ispod 992 px), `.app-main > .app-inset` (zaobljeni panel), `.app-header` (sticky, „?“, jezik, tema),
  `.app-content`. Stranični naslovi `h2.fw-bold` se automatski smanjuju.
- `_head.blade.php`: favicon, fontovi, Bootstrap, ikone, theme.css sa `?v=filemtime`, i skripta koja
  pre iscrtavanja postavlja `data-bs-theme` (localStorage `app-theme` ili sistemska tema) i
  sklopljen meni — nema treptanja.
- Login: `.auth-shell` (levo panel u boji brenda sa belim znakom, desno forma sa punim logotipom; u tamnoj
  temi logotip `filter: brightness(0) invert(1)`).
- Kontrolna tabla: `.kpi-card` (klik → filtrirana lista), `.stack-bar`, `.meter`, `.alert-row`.

## Primena
1. Kopiraj theme.css u `public/css/`, `_head` u layouts; zameni `<nav class="navbar">` layout
   strukturom iz `starter/laravel/resources/views/layouts/app.blade.php` (meni iz `config/navigation.php`).
2. `config/brand.php` + logotipe iz `starter/laravel/public/images/` kopiraj u aplikaciju
   (`brand-logo.svg`, `brand-mark.svg`, `brand-mark-white.svg`, `favicon.svg`, `favicon-32.png`).
   Generički „Generic Corp“ znak zameni pravim logotipom organizacije (isti nazivi fajlova; znak
   kvadratnog formata, beli za plavu podlogu), a naziv postavi u `.env`: `ORG_NAME`, `ORG_LOCATION`.
3. Laravel: `Paginator::useBootstrapFive()` u AppServiceProvider (inače Tailwind markup bez stila);
   `lang/sr.json` za „Showing … results“.
4. Prevedi nove ključeve (nav grupe, toggle_sidebar, toggle_theme, language…).
5. Proveri: hardkodovane boje/`bg-white` u pogledima, `sticky-top` offseti, mape u tamnoj temi,
   širine 390/768/1440 bez horizontalnog skrola, ikonice u `.card-header .btn` (pravilo `color: inherit`).

## Čist PHP
Isti CSS i isti HTML skelet (sidebar, header, `.app-content`) iz `layouts/app.blade.php`, prepisan u
`pageStart()` / `pageEnd()` funkcije; login iz `auth/login.blade.php`.

## https iza Caddy-ja
Laravel: `$middleware->trustProxies(at: '*');` — bez toga `asset()` daje `http://` i CSS se blokira
kao mixed content samo na produkciji. Proveri: `curl -s https://<domen>/login | grep theme.css` mora biti https.
