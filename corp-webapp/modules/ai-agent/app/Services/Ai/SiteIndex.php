<?php

namespace App\Services\Ai;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use PDO;

/**
 * Indeks javnog sajta za asistenta sajta (varijanta B): robots.txt / sitemap → glavni tekst strane (bez menija,
 * podnožja, skripti) → SQLite FTS5 u zasebnom fajlu (config('ai.site.index')). Kolona `lat` je isti tekst
 * latinicom bez dijakritike — posetilac piše „radionica", sajt „радионица"; pretraga ide po njoj.
 */
class SiteIndex
{
    public static function db(): PDO
    {
        $path = config('ai.site.index');
        @mkdir(dirname($path), 0775, true);
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE IF NOT EXISTS pages (url TEXT PRIMARY KEY, title TEXT, text TEXT, updated_at TEXT)');
        $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS pages_fts USING fts5(url UNINDEXED, title, passage, lat, tokenize = 'unicode61 remove_diacritics 2')");

        return $db;
    }

    /** Obiđe sajt i (ponovo) indeksira strane. Vraća broj strana. $log(string) za ispis u komandi. */
    public static function crawl(string $base, int $max = 500, ?callable $log = null): int
    {
        $db = self::db();
        $host = parse_url($base, PHP_URL_HOST);
        $urls = self::sitemapUrls($base);
        if (! $urls) {   // bez sitemap-a: početna + linkovi sa nje
            $urls[] = $base;
            if ($h = self::get($base)) {
                preg_match_all('#href="([^"\#?]+)"#', $h, $m);
                foreach ($m[1] as $l) $urls[] = str_starts_with($l, 'http') ? $l : rtrim($base, '/') . '/' . ltrim($l, '/');
            }
        }
        $urls = array_slice(array_values(array_unique(array_filter($urls, fn ($u) => parse_url($u, PHP_URL_HOST) === $host
            && ! preg_match('#\.(pdf|jpe?g|png|gif|zip|docx?|xlsx?)$#i', $u)))), 0, $max);

        $n = 0;
        foreach ($urls as $u) {
            if (! ($html = self::get($u))) continue;
            [$title, $text] = self::mainText($html);
            if (mb_strlen($text) < 80) continue;
            $db->prepare('INSERT INTO pages (url, title, text, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT(url) DO UPDATE SET title = excluded.title, text = excluded.text, updated_at = excluded.updated_at')
               ->execute([$u, $title, $text, now()->toDateTimeString()]);
            $db->prepare('DELETE FROM pages_fts WHERE url = ?')->execute([$u]);
            foreach (preg_split('/\n(?=## )/u', $text) as $part) {
                foreach (mb_str_split($part, 1500) as $passage) {
                    $db->prepare('INSERT INTO pages_fts (url, title, passage, lat) VALUES (?, ?, ?, ?)')
                       ->execute([$u, $title, $passage, TextExtractor::latin($title . ' ' . $passage)]);
                }
            }
            $n++;
            $log && $log("  $u");
            usleep(200000);   // pristojno prema serveru
        }

        return $n;
    }

    public static function search(string $query, int $limit = 8): array
    {
        $words = array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', TextExtractor::latin($query))), fn ($w) => mb_strlen($w) >= 3);
        if (! $words) return [];
        // koren reči (padeži) kao FTS5 prefiks, samo u latiničnoj koloni
        $q = 'lat : (' . implode(' OR ', array_map(fn ($w) => '"' . mb_substr($w, 0, max(4, mb_strlen($w) - 2)) . '"*', $words)) . ')';
        $st = self::db()->prepare("SELECT url, title, snippet(pages_fts, 2, '', '', ' … ', 60) AS passage FROM pages_fts WHERE pages_fts MATCH ? ORDER BY rank LIMIT $limit");
        $st->execute([$q]);

        return $st->fetchAll();
    }

    public static function page(string $url): ?array
    {
        $st = self::db()->prepare('SELECT url, title, text, updated_at FROM pages WHERE url = ?');
        $st->execute([$url]);

        return $st->fetch() ?: null;
    }

    private static function sitemapUrls(string $base): array
    {
        $cand = [];
        if ($robots = self::get(rtrim($base, '/') . '/robots.txt', false)) {
            preg_match_all('/^\s*Sitemap:\s*(\S+)/mi', $robots, $m);
            $cand = $m[1];
        }
        foreach (['sitemap.xml', 'sitemap_index.xml', 'wp-sitemap.xml'] as $f) $cand[] = rtrim($base, '/') . '/' . $f;
        foreach ($cand as $c) {
            if (! ($xml = self::get($c, false))) continue;
            preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#', $xml, $m);
            $out = [];
            foreach ($m[1] as $u) {
                if (str_ends_with($u, '.xml') && ($sub = self::get($u, false))) {   // sitemap indeks
                    preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#', $sub, $mm);
                    array_push($out, ...$mm[1]);
                } else {
                    $out[] = $u;
                }
            }
            if ($out) return $out;
        }

        return [];
    }

    private static function get(string $url, bool $htmlOnly = true): ?string
    {
        try {
            $r = Http::timeout(20)->connectTimeout(10)->withUserAgent('Mozilla/5.0 (compatible; SiteAssistant/1.0)')->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $r->successful()) return null;
        if ($htmlOnly && ! str_contains((string) $r->header('Content-Type'), 'html')) return null;

        return $r->body();
    }

    /** Glavni tekst strane; naslovi h1–h3 kao „## " da se strana deli na odlomke. */
    private static function mainText(string $html): array
    {
        $d = new DOMDocument();
        @$d->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $x = new DOMXPath($d);
        foreach (iterator_to_array($x->query('//script|//style|//noscript|//nav|//footer|//header|//form|//*[@aria-hidden="true"]')) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $title = trim($x->evaluate('string(//title)')) ?: trim($x->evaluate('string(//h1)'));
        $root = $x->query('//main')->item(0) ?? $x->query('//article')->item(0) ?? $d->getElementsByTagName('body')->item(0);
        if (! $root) return [$title, ''];
        foreach (iterator_to_array($x->query('.//h1|.//h2|.//h3', $root)) as $h) $h->nodeValue = "\n## " . trim($h->textContent) . "\n";
        foreach (iterator_to_array($x->query('.//p|.//li|.//tr|.//br', $root)) as $p) $p->appendChild($d->createTextNode("\n"));
        $t = preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/u"], [' ', "\n\n"], (string) $root->textContent);

        return [$title, trim($t)];
    }
}
