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
| **Svi fajlovi zapisa po folderima** (arhiva predmeta, isti spisak kao mrežni disk/WebDAV) | **Lightbox stabla** (odeljak 9) — folderi + pretraga levo, pregled desno |

Svaki format mora imati pregled, ne samo PDF/DOCX: stari Word **.doc/.rtf/.odt → PDF preko
LibreOffice-a** (odeljak 7), Outlook **.msg** se čita u PHP-u (odeljak 8), **ZIP** kao lista fajlova
(odeljak 8), `.txt/.csv` kao tekst. „Preuzmi" je poslednja opcija (rar, 7z…). Svako čekanje ima
spinner sa porukom (odeljak 7).

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
  Za `doc, rtf` koristi konverziju u PDF preko LibreOffice-a (odeljak 7) — ne ostavljaj samo „preuzmi".

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

## 7. Stari Office (.doc, .rtf, .odt) → PDF preko LibreOffice-a

Korisnici su izričito tražili da se i `.doc` prikazuje („zašto .doc neće a .docx hoće"). `.docx` je ZIP+XML
i `docx-preview` ga crta u pregledaču; `.doc` je binarni OLE format koji nijedna JS biblioteka ne čita
pouzdano → **server ga pretvara u PDF**, kešira, a pregled prikazuje PDF. U stvarnoj arhivi predmeta
`.doc` i `.msg` su stotine fajlova — nije retkost.

Ruta: `GET /preview?f=<fajl>&kao=pdf` (u čistom PHP-u `pregled.php`), JS `window.prikaziFajl(el, url, ime)`
bira način po ekstenziji (PDF, slika, DOCX, XLS(X), tekst, ZIP, .msg, a za .doc/.rtf/.odt — ova ruta).

```dockerfile
# Poseban sloj (≈ +500 MB slike). Fontovi: ćirilica + metrički isti kao Calibri/Cambria/Arial/Times
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice-writer-nogui fonts-dejavu-core fonts-liberation2 \
        fonts-crosextra-carlito fonts-crosextra-caladea \
    && rm -rf /var/lib/apt/lists/*
```

```php
session_write_close();                                   // dugo pretvaranje ne sme da zaključa sesiju
$kes = "$uploads/.pregled/" . sha1($fajl . '#' . filemtime($putanja)) . '.pdf';   // keš u trajnom volumenu
if (!is_file($kes)) {
    $brava = fopen(sys_get_temp_dir() . '/app-soffice.lock', 'c'); flock($brava, LOCK_EX);  // jedan soffice odjednom
    if (!is_file($kes)) {                                // možda ga je napravio zahtev koji je čekao ispred
        // kopija u privremeni folder pod imenom ulaz.<ext>, pa:
        shell_exec('HOME=/tmp timeout 120 soffice --headless --norestore --nolockcheck'
            . ' -env:UserInstallation=file:///tmp/app-lo-profil --convert-to pdf --outdir ' . escapeshellarg($tmp)
            . ' ' . escapeshellarg("$tmp/ulaz.$ext") . ' 2>&1');
        rename("$tmp/ulaz.pdf", $kes);
    }
    flock($brava, LOCK_UN);
}
header('Content-Type: application/pdf'); readfile($kes);
```

- **Brzina:** prvi put 1–5 s (hladan start LibreOffice-a), posle iz keša odmah.
- **Zamke:** `www-data` nema upisiv `HOME` → `HOME=/tmp`; dva `soffice` procesa sa istim profilom se
  sudaraju → `flock` + zaseban `UserInstallation`; bez `timeout` zaglavljen dokument drži PHP proces.
- Ista ruta prima i prilog iz `.msg` poruke i fajl iz ZIP-a (`&prilog=<i>`, `&zip=<i>`) — `.doc` prosleđen
  mejlom ili spakovan u ponudu se takođe pretvara.
- **AJAX spinner sa porukom** za svako čekanje: `cekanje(el, 'Pretvaram .doc u PDF — prvi put može da
  potraje nekoliko sekundi…')` — spinner + tekst šta se radi + brojač sekundi posle 2 s (stane sam kad se
  sadržaj zameni; `cekanje()` je u `app-ui.js`). Bez praznog spinera.
- Brzi klik na drugi fajl: brojač `body._tok` — odgovor starog zahteva se odbacuje.
- Ako se Docker slika gradi ponovo zbog LibreOffice-a, vidi zamku „Ponovno kreiranje kontejnera" u `SKILL.md`.

## 8. Outlook .msg i ZIP — u čistom PHP-u

**`.msg`** — `assets/php/msg.php`, bez biblioteka i ekstenzija:
- `CfbFajl` čita OLE compound file (sektori, DIFAT/FAT, mini stream, stablo direktorijuma);
- `msgProcitaj($putanja)` → `naslov, od, za, cc, datum, html, tekst, prilozi[{i, ime, mime, vel, cid, skriven, ugradjena}]`;
- `msgPrilog($putanja, $i)` → `{ime, mime, sadrzaj}` jednog priloga.

MAPI svojstva: `__substg1.0_PPPPTTTT` (TTTT `001F` UTF-16, `001E` ANSI po `PR_INTERNET_CPID 3FDE` /
`PR_MESSAGE_CODEPAGE 3FFD`, inače Windows-1250; `0102` binarno), fiksna u `__properties_version1.0`
(zaglavlje 32 B za poruku, 8 B za prilog; 16 B po svojstvu; datum `0039/0E06` je FILETIME).
Naslov `0037`, pošiljalac `0C1A` + `5D01` (Exchange DN „/O=…" nije adresa), Za `0E04`, Cc `0E03`,
tekst `1000`, HTML `1013`; prilog: ime `3707/3704/3001`, sadržaj `3701 0102`, `3701 000D` = ugrađena poruka
(nema bajtove), `3712` content-id, `7FFE` skriven (slike u potpisu).

Prikaz: zaglavlje (Od/Za/Cc/Datum), telo — HTML u `<iframe sandbox="allow-same-origin allow-popups" srcdoc>`
(bez skripti; `cid:` zamenjen URL-om priloga) ili `<pre>` tekst; prilozi kao dugmad → **prilog se otvara u
istom prozoru** (`prikaziFajl` rekurzivno) sa „← Poruka"; skriveni prilozi sa `cid` se ne nude.
Outlook često čuva samo RTF + tekst (bez HTML-a) — tekst je dovoljan; iz teksta ukloni oznake slika iz
potpisa (`[cid:image001.jpg@…]`).

**ZIP** (npr. ponude se šalju kao `.zip`): `?kao=zip` → JSON lista stavki (`ZipArchive`, imena čitaj sa
`FL_ENC_RAW` i pretvori iz CP852/Windows-1250 ako nisu UTF-8 — Windows arhive), `&zip=<redni broj>` → stavka
inline. Lista u pregledu, klik otvara fajl u istom prozoru sa „← Arhiva". Stavka se bira po indeksu, ne po
imenu (imena su često u pogrešnom kodiranju). RAR/7z — samo „Preuzmi".

## 9. Lightbox stabla fajlova zapisa (isti spisak kao mrežni disk)

Jedna funkcija vraća sve fajlove zapisa kao `[relativna putanja => fajl]` (podfolderi arhive, priloženi
dokumenti, generisani Word) i koriste je **i** WebDAV/mrežni disk **i** lightbox — jedan izvor istine.
Lightbox: levo folderi (sticky zaglavlja) + pretraga, desno `prikaziFajl` sa Štampaj / Otvori u novom tabu /
Preuzmi, ↑/↓ prelazi na sledeći fajl. Okidači: dugme „Fajlovi" u zaglavlju detalja i naziv svakog fajla
(`data-fajlovi-lightbox data-pocetni="dok:<id>"`). Podaci su JSON u stranici (bez dodatnog zahteva).
Ista komponenta služi za svaku listu fajlova (npr. revizije plana) — prosledi naslov i stavke.
Aktivna stavka: `.list-group-item` van `.list-group` nema boje aktivne stavke — zadaj ih eksplicitno.

