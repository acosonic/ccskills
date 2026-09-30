# Pregled fajlova — lightbox (PDF, slike, Office)

Kućni lightbox za pregled priloga bez napuštanja stranice: **PDF i slike** se otvaraju
direktno, a **DOCX/ODT** i **XLS/XLSX/ODS** se renderuju u HTML na serveru i prikazuju u
izolovanom `<iframe>`-u. Radi i na sadržaju ubačenom u modal/sheet (delegacija događaja).

Skript je već u starteru: `public/js/lightbox.js`, učitan u `layouts/app.blade.php`
(posle `tour.js`). Ne treba ništa da se uključuje — aktivira se sam na linkovima sa
`data-lightbox`. Bez JavaScript-a link i dalje radi (otvara/preuzima fajl).

## 0. Koji lightbox kada

| Situacija | Oblik |
|---|---|
| **Više priloga jednog zapisa** (ugovor, predmet, nabavka) — korisnik ih pregleda redom i posle nešto uradi (poveži, završi, odobri) | **Lightbox sa listom** (odeljak 6) — ovo korisnik preferira |
| Pojedinačni fajl ili galerija slika bez akcije | `lightbox.js` overlay sa ‹ › (odeljci 1–5) |

U oba slučaja **naziv fajla je okidač**, ne samo ikonica 👁 — vidi „Klikabilni nazivi" u `SKILL.md`.

## 1. HTML — kako se označava link

```blade
<a href="{{ route('...prilog', [...]) }}"          {{-- sirov fajl (fallback + PDF/slika izvor) --}}
   data-lightbox="prilozi"                          {{-- ista vrednost = galerija sa ‹ › --}}
   data-type="pdf|image|office"                     {{-- opciono; pogađa se iz ekstenzije --}}
   data-ext="{{ $ext }}"
   data-title="{{ $naziv }}"
   @if($office) data-preview="{{ route('...prilog.preview', [...]) }}" @endif  {{-- office → HTML --}}
   target="_blank" rel="noopener">…</a>
```

- **pdf / image** — lightbox koristi `href` (iframe za PDF, `<img>` za sliku).
- **office** — lightbox učitava `data-preview` (HTML render) u `<iframe sandbox>`.
- Tipovi koje `data-preview` pokriva: `docx, odt` (pandoc) i `xls, xlsx, ods` (PhpSpreadsheet).
  Za `doc, ppt, pptx` i sl. ne stavljaj `data-preview` → lightbox ponudi „otvori/preuzmi".

## 2. Backend — dve rute

```php
// Sirov fajl inline (PDF/slika se otvara u pregledu, ne preuzima):
Route::get('/.../prilog/{id}',         [C::class, 'prilog'])->name('...prilog');
// Office → HTML za iframe:
Route::get('/.../prilog/{id}/pregled', [C::class, 'prilogPreview'])->name('...prilog.preview');
```

Inline stream (ključno: `response()->stream`, NE `streamDownload` — ovaj drugi šalje
`attachment` pa se PDF ne otvara u pregledu):

```php
return response()->stream(fn () => print($data), 200, [
    'Content-Type'          => $mime,                 // application/pdf, image/*, …
    'Content-Length'        => (string) strlen($data),
    'Content-Disposition'   => 'inline; filename="'.$naziv.'.'.$ext.'"',
    'X-Content-Type-Options' => 'nosniff',
]);
```

Office pregled vraća ceo HTML dokument (za iframe):

```php
public function prilogPreview(int $id, PreviewConverterService $conv): Response
{
    $html = $conv->html($id);          // konvertuje i kešira; null ako nije podržano
    abort_if($html === null, 404);
    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8',
                                 'X-Content-Type-Options' => 'nosniff']);
}
```

## 3. Konverter (DOCX→pandoc, XLSX→PhpSpreadsheet, keširano)

`app/Services/PreviewConverterService.php` (prilagodi izvor fajla — disk, BLOB, S3…):

```php
private const PANDOC = ['docx','odt'];
private const SHEET  = ['xls','xlsx','ods'];

public function html(...): ?string
{
    // ... dohvati bajtove fajla i ekstenziju ...
    if (!in_array($ext, [...self::PANDOC, ...self::SHEET])) return null;

    $cache = 'preview-cache/'.$kljuc.'-'.md5($data).'.html';   // keš po sadržaju
    if (Storage::exists($cache)) return Storage::get($cache);

    $tmp = tempnam(sys_get_temp_dir(), 'prev_').'.'.$ext;
    file_put_contents($tmp, $data);
    try {
        $html = in_array($ext, self::SHEET) ? $this->fromSpreadsheet($tmp) : $this->fromPandoc($tmp);
    } finally { @unlink($tmp); }
    if ($html !== null) Storage::put($cache, $html);
    return $html;
}

private function fromPandoc(string $f): ?string {
    $p = new \Symfony\Component\Process\Process(['pandoc',$f,'-t','html','--embed-resources','--standalone']);
    $p->setTimeout(60); $p->run();
    return $p->isSuccessful() ? $this->withStyle($p->getOutput()) : null;
}
private function fromSpreadsheet(string $f): ?string {
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($f);
    $w  = new \PhpOffice\PhpSpreadsheet\Writer\Html($ss);
    $w->setPreCalculateFormulas(false);
    return $this->withStyle($w->generateHTMLAll());
}
// withStyle(): ubaci mali CSS (Inter, tabele border-collapse, img max-width:100%) u <head>.
```

## 4. Docker — zavisnosti

Dodaci na starter sliku:

```dockerfile
# u postojeći apt sloj dodaj: pandoc  (DOCX/ODT → HTML)
RUN apt-get update && apt-get install -y git curl unzip pandoc libpng-dev … \
    && docker-php-ext-install … && rm -rf /var/lib/apt/lists/*
```
```bash
composer require phpoffice/phpspreadsheet     # XLS/XLSX/ODS → HTML (pandoc ne čita tabele)
```

## 5. Zamke (već viđene)

- **pandoc NE čita XLSX** — zato PhpSpreadsheet za tabele; pandoc samo DOCX/ODT.
- **`--embed-resources`** (slike u DOCX-u kao data URI) traži pandoc ≥ 3.1.7 (Debian trixie: 3.1.11 ✓).
- **Office se prikazuje u `<iframe sandbox>`** — izolacija stilova/skripti; ne ubacuj HTML direktno u DOM.
- **BLOB iz oci8** dolazi kao resource ili string → `is_resource($v) ? stream_get_contents($v) : $v`.
- **TIFF** pregledači ne prikazuju kao `<img>` → tretiraj kao „other" (download) ili konvertuj.
- **Keš** obavezan (`storage/app/preview-cache`) — konverzija je skupa; ključ po `md5` sadržaja.
- Ako u kontejneru ručno pokreneš `composer install --no-dev`, obriši `bootstrap/cache/packages.php`
  pa `php artisan package:discover` (inače „PailServiceProvider not found").
- Docker ume da kešira `composer install` sloj — posle `composer require` gradi sa `--no-cache`
  ili proveri da je paket stvarno u `vendor/` u slici.
```


## 6. Lightbox sa listom (prilozi jednog zapisa + akcije)

Korisnici su ga izričito izabrali umesto overlay-a sa ‹ › i umesto otvaranja u novom tabu: pregledaju
priloge zapisa (ugovor, predmet, nabavka) redom i odmah nešto urade. Tri dela (bilo koji backend):

| Deo | Uloga |
|---|---|
| komponenta `recordLightbox($akcijeHtml)` (Blade komponenta ili PHP funkcija) | ispisuje modal + JS **jednom** po stranici |
| JSON ruta, npr. `GET /records/{id}/attachments` | lista priloga (`id, naziv, ext, tip, datum, velicina, url`) — učitava se tek na klik |
| ruta za fajl (odeljak 2) | sam fajl inline; ako fajl dolazi iz spoljnog API-ja, ruta je proxy i token ostaje na serveru |

**Izgled:** `modal-xl modal-fullscreen-lg-down` (max ~1400px). Levo lista (`list-group`, ~340px, skrol):
naziv (fw-semibold) + `.ext`, ispod tip · datum · veličina. Desno pregled (PDF u `iframe` visine ~78vh,
slika `img-fluid`, ostalo „Preuzmi"). Zaglavlje: broj/opis zapisa. Podnožje levo: na šta se odnosi
(npr. „Nabavka: …"), desno „Otvori u novom tabu" + akcije stranice.

**Ponašanje:**
- Okidač je bilo koji element sa `data-record-lightbox`; podaci se čitaju sa njega ili najbližeg roditelja
  sa `data-record` (`data-oznaka`, `data-opis`, `data-info`, `data-pocetni`). Tako isti red tabele ima
  i dugme „Prilozi (N)" i klikabilan broj zapisa, a oba otvaraju isto.
- **Prvi prilog se prikazuje odmah**; `data-pocetni="<id>"` otvara baš kliknuti prilog (👁 ili naziv u tabeli).
- Lista se **učitava tek na klik** (fetch JSON-a) — stranica sa stotinama redova ostaje brza.
- PDF: `fetch(url) → blob → URL.createObjectURL → iframe` (zaobilazi `X-Frame-Options: deny` i čuva
  sesiju), `revokeObjectURL` pri promeni i zatvaranju.
- Akcije su HTML koji stranica prosledi komponenti; komponenta na otvaranju šalje događaj
  `record-lightbox:open` (`detail.izvor` = red), pa stranica sakrije akcije koje za taj red nemaju smisla
  (npr. „Poveži" za već povezan red). `RecordLightbox.izvor()` vraća red dok je modal otvoren,
  `RecordLightbox.zatvori()` ga zatvara posle uspešne akcije.

```blade
<x-record-lightbox>
  <button type="button" class="btn btn-primary btn-sm btn-povezi" data-zavrsi="0">Poveži</button>
</x-record-lightbox>

<tr data-record="18897" data-oznaka="10631-5/2026" data-opis="…" data-info="Nabavka: 1.6.3 · …">
  <td><span class="klik-naziv" role="button" tabindex="0" data-record-lightbox>10631-5/2026</span></td>
  <td><button type="button" class="btn btn-outline-info btn-sm" data-record-lightbox>Prilozi (7)</button></td>
</tr>
```

Office tipove prikaži preko `data-preview` HTML-a u `iframe sandbox` kao u odeljku 1.

**Zamke:**
- **Naziv u izvoru nije uvek naziv**: spoljni sistemi (DMS, delovodnik) često imaju prazno polje naziva
  ili „PRILOG", a pravi opis u drugom polju („UGOVOR …", „ZAPISNIK O … PRIJEMU …"); tip „Nedefinisano" ne prikazuj.
- Aktivna stavka `list-group` je u boji brenda — sivi tekst u njoj (`.text-muted`) posvetli, inače se ne čita.
- JSON ruta i proxy imaju ista prava kao stranica koja ih koristi (ako detalj vide i radnici,
  samo prijava, ne uloga službenika).
