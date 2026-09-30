# Obavezni tutorijal (driver.js)

Izvor: `starter/laravel/public/js/tour.js`, `starter/laravel/lang/{sr,en}/tour.php`. Varijanta koja
pamti završetak u bazi (kolona `users.tutorial_done` + mala POST ruta) moguća je kad se traži po korisniku.

## Uključivanje (layout, samo pun režim — ne u fragmentima)
```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<!-- dugme u gornjoj traci -->
<button type="button" class="btn-icon bordered" data-tour-start title="Pokreni vodič"><i class="bi bi-question-lg"></i></button>
<!-- na kraju body -->
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script>window.APP_TOUR = @json(__('tour'));</script>
<script src="/js/tour.js"></script>
```
Čist PHP: `window.APP_TOUR = <?= json_encode($tekstovi, JSON_UNESCAPED_UNICODE) ?>`.

## Obavezni režim
`allowClose: false`, `showButtons: ['next','previous']`, `disableActiveInteraction: true`;
`onNextClick` na poslednjem koraku → `markDone()` + `destroy()`; `onDestroyStarted` ignoriše sve
dok nije završeno. Escape, klik van prozora i × tada ne rade. Ponovno pokretanje dugmetom „?“ je
neobavezno (može da se zatvori).

## Pamćenje
`localStorage["app-tutorial-done"]="1"` + kolačić `app_tutorial_done=1; max-age=31536000; SameSite=Lax; Secure (na https)`.
Bilo koji od ta dva = završeno. U Laravelu izuzmi kolačić iz šifrovanja. Posledica (reci korisniku):
stanje je po pregledaču, ne po korisniku. Ako traže po korisniku → kolona u bazi (vidi gore).

## Koraci
**Starter (nove aplikacije):** koraci po stranicama su u `lang/{sr,en}/tour.php` → `pages`
(`'^/putanja$' => [[selektor, naslov, tekst, strana?], ...]`); `tour.js` se ne menja.
- Zajednički: dobrodošlica, meni (`#appSidebar .app-sidebar-nav`), sklapanje (`[data-sidebar-toggle]`),
  jezik, tema, dugme „?“, nalog (`#appSidebar .app-user`).
- Po stranici: `tour.js` bira korake iz `pages` po `location.pathname` (regex); korak je `[selektor, naslov, opis, strana]`.
  Selektor može imati varijante odvojene zarezom — uzima se prva **vidljiva** (off-canvas meni na
  telefonu se preskače jer mu je `getBoundingClientRect().right <= 0`).
- Kraj: `finish` korak.
Prilagodi ključeve pamćenja i tekstove (`lang/*/tour.php`) nazivu aplikacije.

## Stil
`.app-tour-popover` u theme.css (kartica, brend dugme, tamni režim, strelice u boji kartice).

## Test
Nov kontekst pregledača → posle prijave vodič je vidljiv; Escape i klik pored ga ne zatvaraju;
„Sledeće“ do kraja → localStorage i kolačić postavljeni; reload → nema vodiča; „?“ → vodič sa ×.
Na 390 px meni koraci su preskočeni.
