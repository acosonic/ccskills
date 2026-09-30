# Modali, bočni paneli, potvrde, toast (bez prelaska na React)

Izvor: `starter/laravel/public/js/app-ui.js` + fragment grana u `starter/laravel/resources/views/layouts/app.blade.php`;
koje adrese idu u modal/panel određuje `config/ui.php`.

## Kako radi
- Zahtev sa `X-Fragment: 1` → layout vraća samo `<div class="fragment" data-title data-errors
  data-success data-error>` sa `@stack('styles')`, greškama, `@yield('content')`, `@stack('scripts')`.
- `app-ui.js`:
  - `config('ui.modal')` (kratke forme, npr. `/users/create|{id}/edit`) → `#appModal`;
  - `config('ui.sheet')` (detalji zapisa, npr. `/<resurs>/{id}`) → `#appSheet` (offcanvas-end, 720 px);
  - forme u overlay-u, sa `data-ajax` ili sa `onsubmit="return confirm(…)"` → `fetch` u pozadini;
    potvrda preko `#appConfirm` (capture-phase listener potiskuje native confirm);
  - uspeh → zatvori overlay, zameni `.app-content` (pushState ako se URL promenio), toast iz `data-success`;
  - greška validacije/odbijanje → ostaje u overlay-u;
  - skripte iz fragmenta se ponovo izvršavaju (inline umotane u `{…}` zbog const/let; `src` samo jednom);
  - Ctrl/srednji klik i bez-JS rade kao obični linkovi; popstate → reload.
- Statički modali u stranici (npr. promena statusa ili dodela na detalju zapisa) su forme sa `data-ajax`;
  pre zamene sadržaja sačekaj `hidden.bs.modal` (inače Bootstrap pada na `focus` null).

## Zamke
- `back()` koristi Referer → `fetch(action, {referrer: urlOverlaya})`.
- Session flash postaje toast (`#appFlash` na punoj strani, `data-success` u fragmentu).
- CSS: `.app-modal`/`.app-sheet` forsiraju jednu kolonu, sakrivaju zaglavlje strane i back dugme.
- Test klikom „Obriši“ odmah posle otvaranja dijaloga (animacija) — rešeno u `askConfirm`.

## Prilagođavanje
Promeni regexe u `config/ui.php` prema rutama aplikacije (postojeća aplikacija bez startera: `MODAL_RE`/`SHEET_RE`). Dodaj overlay kontejnere i
`window.APP_UI = {confirmDelete, error, loading}` u layout pre `app-ui.js`.
