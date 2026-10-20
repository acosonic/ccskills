<?php

namespace App\Services\Ai;

use RuntimeException;
use ZipArchive;

/**
 * Tekst dokumenta za alat koji čita priloge: docx iz XML-a, PDF preko pdftotext (poppler-utils),
 * .doc/.xls/.odt/.xlsx preko LibreOffice-a (soffice — isti kontejner kao pregled fajlova). Seče na $max znakova.
 */
class TextExtractor
{
    public static function extract(string $path, string $name, int $max = 40000): array
    {
        if (! is_file($path)) throw new RuntimeException('Fajl ne postoji na disku.');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: pathinfo($path, PATHINFO_EXTENSION));

        $text = match (true) {
            $ext === 'docx' => self::docx($path),
            $ext === 'pdf' => self::pdf($path),
            in_array($ext, ['doc', 'rtf', 'odt', 'xls', 'xlsx', 'ods'], true) => self::office($path, $ext),
            in_array($ext, ['txt', 'csv', 'md', 'html', 'htm', 'xml', 'json'], true) => strip_tags((string) file_get_contents($path)),
            default => throw new RuntimeException("Format .$ext se ne čita kao tekst."),
        };
        $text = trim($text);
        $cut = mb_strlen($text) > $max;

        return ['text' => $cut ? mb_substr($text, 0, $max) : $text, 'truncated' => $cut];
    }

    private static function docx(string $path): string
    {
        $z = new ZipArchive();
        if ($z->open($path) !== true) throw new RuntimeException('Word fajl nije moguće otvoriti.');
        $x = (string) $z->getFromName('word/document.xml');
        $z->close();
        $x = preg_replace(['#</w:p>#', '#<w:tab/>#', '#</w:tc>#'], ["\n", "\t", "\t"], $x);

        return html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function pdf(string $path): string
    {
        $t = (string) shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        if (! trim($t)) throw new RuntimeException('PDF nema tekstualni sloj (skeniran) — tekst se ne može pročitati.');

        return $t;
    }

    private static function office(string $path, string $ext): string
    {
        $dir = sys_get_temp_dir() . '/ai_' . bin2hex(random_bytes(4));
        @mkdir($dir);
        $target = in_array($ext, ['xls', 'xlsx', 'ods'], true) ? 'csv' : 'txt:Text (encoded):UTF8';
        // HOME u privremenom folderu — soffice bez upisivog home-a tiho pukne
        shell_exec('HOME=' . escapeshellarg($dir) . ' timeout 60 soffice --headless --convert-to ' . escapeshellarg($target)
            . ' --outdir ' . escapeshellarg($dir) . ' ' . escapeshellarg($path) . ' 2>/dev/null');
        $f = glob($dir . '/*.{txt,csv}', GLOB_BRACE)[0] ?? null;
        $t = $f ? (string) file_get_contents($f) : '';
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
        if (! trim($t)) throw new RuntimeException('Dokument nije moguće pretvoriti u tekst (nema LibreOffice-a u kontejneru?).');

        return $t;
    }

    /** Ćirilica → latinica, bez dijakritike, mala slova — za pretragu kad korisnik i podaci pišu različitim pismom. */
    public static function latin(string $t): string
    {
        return strtr(mb_strtolower($t), [
            'љ' => 'lj', 'њ' => 'nj', 'џ' => 'dz', 'ђ' => 'dj', 'đ' => 'dj', 'ћ' => 'c', 'ч' => 'c', 'ć' => 'c', 'č' => 'c', 'ш' => 's', 'š' => 's',
            'ж' => 'z', 'ž' => 'z', 'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z', 'и' => 'i', 'ј' => 'j',
            'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
            'х' => 'h', 'ц' => 'c',
        ]);
    }

    /** Koreni reči upita (padeži): „licencama" → „licen" — zapis prolazi ako sadrži sve korene osim najviše jednog. */
    public static function roots(string $query): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', self::latin($query)) as $w) {
            $w = preg_replace('/[^\p{L}\p{N}]/u', '', $w);
            if (mb_strlen($w) >= 3) $out[] = mb_substr($w, 0, max(3, min(5, mb_strlen($w) - 1)));
        }

        return $out;
    }

    public static function matches(string $text, array $roots): bool
    {
        if (! $roots) return true;
        $t = self::latin($text); $hit = 0;
        foreach ($roots as $r) if (str_contains($t, $r)) $hit++;

        return $hit >= max(1, count($roots) - 1);
    }
}
