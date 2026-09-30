# Mape: CARTO Positron / Dark Matter

Sve Leaflet mape zovu `appBaseLayer(map)` (definisana u layout skripti), ne `L.tileLayer`.
Zamena u postojećim pogledima: regex `L\.tileLayer\('https://\{s\}\.tile\.openstreetmap…\)\.addTo\((\w+)\);` → `appBaseLayer(\1);`.

- Ključ: `.env` `CARTO=` → `config('app.carto_key')` → `@json(...)` u layout. Bez ključa CARTO vraća
  vodeni žig „API KEY REQUIRED“. URL: `https://basemaps.cartocdn.com/rastertiles/<style>/{z}/{x}/{y}{r}.png?key=…`.
- Ključ je vezan za domen (dashboard.basemaps.carto.com) → na localhost 403 → `tileerror` prebacuje
  sve slojeve na OSM (`html.tiles-osm`, tamna tema tada preko CSS invert filtera).
- Svetla: `light_all`. Tamna: `dark_nolabels` + sloj `dark_only_labels` u pane-u `appLabels`
  (z-index 390, bez pointer-events) sa klasom `app-map-labels` → `filter: brightness(2.1) contrast(1.15)`
  (originalni Dark Matter nazivi su nečitljivi).
- Promena teme: `refreshBaseLayers()` → `setUrl` + dodaj/ukloni sloj nazivâ; prazni slojevi bez `_map` se čiste.
- Test lokalno sa pravim ključem: u puppeteer-u prepiši `Referer` na produkcijski domen za cartocdn zahteve.
- Podrazumevani centar mapa: `.env` `MAP_CENTER="lat,lng"` (grad organizacije) → `config('maps.center')` →
  `window.APP_MAP_CENTER` u layout-u; `L.map(el).setView(APP_MAP_CENTER, 13)`. Ne hardkoduj koordinate u pogledima.
- Uslovi: besplatno 5M pločica mesečno, obavezna atribucija OSM + CARTO.
