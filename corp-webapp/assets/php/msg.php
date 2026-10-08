<?php
/**
 * Читач Outlook .msg порука (без спољних библиотека).
 *
 * .msg је OLE „compound file" (CFB, исти контејнер као стари .doc): фајл-систем у фајлу са
 * секторима, FAT табелом и стаблом директоријума. Порука је скуп MAPI својстава:
 *   __substg1.0_PPPPTTTT          стрим са вредношћу својства PPPP типа TTTT (001F UTF-16, 001E ANSI, 0102 бинарно)
 *   __properties_version1.0       својства фиксне дужине (датуми, бројеви) — 16 бајтова по својству
 *   __attach_version1.0_#NNNNNNNN прилог (исти облик; 3701 = садржај, 3707 = име)
 *
 * msgProcitaj($putanja) → ['naslov','od','za','cc','datum','html','tekst','prilozi'=>[…]]
 * msgPrilog($putanja, $i) → ['ime','mime','sadrzaj'] једног прилога.
 */

final class CfbFajl {
    private string $b;
    private int $sek;          // величина сектора (512 или 4096)
    private int $miniSek;      // величина мини сектора (64)
    private int $miniGranica;  // стримови мањи од овога су у мини стриму (4096)
    private array $fat = [];
    private array $miniFat = [];
    private string $miniStrim = '';
    /** @var array<int,array{ime:string,tip:int,levo:int,desno:int,dete:int,start:int,vel:int}> */
    public array $unosi = [];

    public function __construct(string $bajtovi) {
        if (strlen($bajtovi) < 512 || substr($bajtovi, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Фајл није Outlook порука (OLE).');
        }
        $this->b = $bajtovi;
        $h = unpack('vsekPom/vminiPom', substr($bajtovi, 0x1E, 4));
        $this->sek = 1 << $h['sekPom'];
        $this->miniSek = 1 << $h['miniPom'];
        $p = unpack('VbrFat/VdirStart/Vx/VminiGr/VminiFatStart/VbrMiniFat/VdifatStart/VbrDifat', substr($bajtovi, 0x2C, 32));
        $this->miniGranica = $p['miniGr'];

        // DIFAT: првих 109 FAT сектора у заглављу, остали у ланцу DIFAT сектора
        $fatSektori = array_values(unpack('V109', substr($bajtovi, 0x4C, 436)));
        $d = $p['difatStart'];
        for ($i = 0; $i < $p['brDifat'] && $d < 0xFFFFFFFA; $i++) {
            $vr = array_values(unpack('V*', $this->sektor($d)));
            $d = array_pop($vr);
            array_push($fatSektori, ...$vr);
        }
        foreach (array_slice($fatSektori, 0, $p['brFat']) as $s) {
            if ($s >= 0xFFFFFFFA) continue;
            array_push($this->fat, ...array_values(unpack('V*', $this->sektor($s))));
        }
        if ($p['brMiniFat'] > 0) $this->miniFat = array_values(unpack('V*', $this->lanac($p['miniFatStart'])));

        $dir = $this->lanac($p['dirStart']);
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $e = substr($dir, $o, 128);
            $duz = unpack('v', substr($e, 0x40, 2))[1];
            $x = unpack('Ctip', substr($e, 0x42, 1)) + unpack('Vlevo/Vdesno/Vdete', substr($e, 0x44, 12)) + unpack('Vstart/Vvel', substr($e, 0x74, 8));
            $this->unosi[] = [
                'ime'  => $duz > 2 ? mb_convert_encoding(substr($e, 0, $duz - 2), 'UTF-8', 'UTF-16LE') : '',
                'tip'  => $x['tip'], 'levo' => $x['levo'], 'desno' => $x['desno'], 'dete' => $x['dete'],
                'start'=> $x['start'], 'vel' => $x['vel'],
            ];
        }
        if ($this->unosi) $this->miniStrim = $this->lanac($this->unosi[0]['start']);   // корен носи мини стрим
    }

    private function sektor(int $s): string { return (string)substr($this->b, ($s + 1) * $this->sek, $this->sek); }

    private function lanac(int $s): string {
        $out = ''; $bez = 0;
        while ($s < 0xFFFFFFFA && isset($this->fat[$s]) && $bez++ < 1_000_000) { $out .= $this->sektor($s); $s = $this->fat[$s]; }
        return $out;
    }

    private function miniLanac(int $s): string {
        $out = ''; $bez = 0;
        while ($s < 0xFFFFFFFA && isset($this->miniFat[$s]) && $bez++ < 1_000_000) {
            $out .= substr($this->miniStrim, $s * $this->miniSek, $this->miniSek);
            $s = $this->miniFat[$s];
        }
        return $out;
    }

    /** Садржај стрима (индекс уноса). */
    public function strim(int $i): string {
        $e = $this->unosi[$i];
        $podaci = $e['vel'] < $this->miniGranica ? $this->miniLanac($e['start']) : $this->lanac($e['start']);
        return substr($podaci, 0, $e['vel']);
    }

    /** Деца складишта: [име => индекс уноса] (стабло браће је бинарно, обилази се рекурзивно). */
    public function deca(int $i): array {
        $out = []; $stek = [$this->unosi[$i]['dete']]; $bez = 0;
        while ($stek && $bez++ < 100_000) {
            $k = array_pop($stek);
            if ($k >= 0xFFFFFFFA || !isset($this->unosi[$k])) continue;
            $out[$this->unosi[$k]['ime']] = $k;
            $stek[] = $this->unosi[$k]['levo'];
            $stek[] = $this->unosi[$k]['desno'];
        }
        return $out;
    }
}

/** MAPI својства једног складишта (поруке или прилога). */
final class MsgSvojstva {
    public function __construct(private CfbFajl $cfb, private int $skladiste, private int $zaglavlje, private ?string $kodnaStrana = null) {}

    public function deca(): array { return $this->cfb->deca($this->skladiste); }

    /** Текстуално својство (Unicode или ANSI), нпр. 0x0037 = наслов. */
    public function tekst(int $id): ?string {
        $d = $this->deca(); $h = sprintf('__substg1.0_%04X', $id);
        if (isset($d[$h . '001F'])) return rtrim(mb_convert_encoding($this->cfb->strim($d[$h . '001F']), 'UTF-8', 'UTF-16LE'), "\0");
        if (isset($d[$h . '001E'])) return rtrim($this->uUtf8($this->cfb->strim($d[$h . '001E'])), "\0");
        return null;
    }

    public function binarno(int $id): ?string {
        $d = $this->deca(); $k = sprintf('__substg1.0_%04X0102', $id);
        return isset($d[$k]) ? $this->cfb->strim($d[$k]) : null;
    }

    /** Својства фиксне дужине: [id => ['tip'=>…, 'v'=>8 бајтова]]. */
    public function fiksna(): array {
        $d = $this->deca();
        if (!isset($d['__properties_version1.0'])) return [];
        $s = substr($this->cfb->strim($d['__properties_version1.0']), $this->zaglavlje);
        $out = [];
        for ($o = 0; $o + 16 <= strlen($s); $o += 16) {
            $t = unpack('V', substr($s, $o, 4))[1];
            $out[$t >> 16] = ['tip' => $t & 0xFFFF, 'v' => substr($s, $o + 8, 8)];
        }
        return $out;
    }

    public function uUtf8(string $s): string {
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        return @mb_convert_encoding($s, 'UTF-8', $this->kodnaStrana ?: 'Windows-1250') ?: $s;
    }
}

/** Windows кодна страна (PR_INTERNET_CPID / PR_MESSAGE_CODEPAGE) → име за mbstring. */
function msgKodnaStrana(?int $cp): ?string {
    return [1250 => 'Windows-1250', 1251 => 'Windows-1251', 1252 => 'Windows-1252', 65001 => 'UTF-8', 28592 => 'ISO-8859-2', 28595 => 'ISO-8859-5'][$cp] ?? null;
}

function msgOtvori(string $putanja): array {
    $bajtovi = @file_get_contents($putanja);
    if ($bajtovi === false) throw new RuntimeException('Фајл не постоји.');
    $cfb = new CfbFajl($bajtovi);
    $koren = new MsgSvojstva($cfb, 0, 32);
    $fx = $koren->fiksna();
    foreach ([0x3FDE, 0x3FFD] as $cpId) {
        if (isset($fx[$cpId])) { $cp = msgKodnaStrana(unpack('V', $fx[$cpId]['v'])[1]); if ($cp) { $koren = new MsgSvojstva($cfb, 0, 32, $cp); break; } }
    }
    return [$cfb, $koren, $fx];
}

/** Прилози поруке: [редни број => MsgSvojstva], редом као у Outlook-у. */
function msgPriloziSkladista(CfbFajl $cfb, MsgSvojstva $poruka): array {
    $out = [];
    foreach ($poruka->deca() as $ime => $i) {
        if (preg_match('/^__attach_version1\.0_#([0-9A-F]{8})$/i', $ime, $m)) $out[hexdec($m[1])] = new MsgSvojstva($cfb, $i, 8);
    }
    ksort($out);
    return array_values($out);
}

function msgImePriloga(MsgSvojstva $a, int $i): string {
    $ime = $a->tekst(0x3707) ?: $a->tekst(0x3704) ?: $a->tekst(0x3001);
    if ($a->deca()['__substg1.0_3701000D'] ?? null) {
        // Уграђена порука (прослеђени мејл) — нема свој фајл, само наслов
        return ($ime ?: 'Уграђена порука') . (str_ends_with(strtolower((string)$ime), '.msg') ? '' : '.msg');
    }
    return $ime ?: 'prilog-' . ($i + 1);
}

function msgProcitaj(string $putanja): array {
    [$cfb, $m, $fx] = msgOtvori($putanja);
    $datum = null;
    foreach ([0x0039, 0x0E06, 0x3007] as $id) {        // послато, примљено, креирано
        if (isset($fx[$id]) && $fx[$id]['tip'] === 0x0040) {
            $ft = unpack('P', $fx[$id]['v'])[1];
            if ($ft > 0) { $datum = intdiv($ft, 10_000_000) - 11_644_473_600; break; }
        }
    }
    $odIme  = $m->tekst(0x0C1A) ?: $m->tekst(0x0042);
    $odAdr  = $m->tekst(0x5D01) ?: $m->tekst(0x5D02) ?: $m->tekst(0x0C1F) ?: $m->tekst(0x0065);
    if ($odAdr && !str_contains($odAdr, '@')) $odAdr = null;   // Exchange DN („/O=…") није адреса

    $html = $m->binarno(0x1013) ?? ($m->tekst(0x1013) ?? null);
    if ($html !== null) {
        $cs = preg_match('/charset=["\']?([\w-]+)/i', substr($html, 0, 2000), $x) ? $x[1] : null;
        if (!mb_check_encoding($html, 'UTF-8')) $html = @mb_convert_encoding($html, 'UTF-8', $cs ?: 'Windows-1250') ?: $html;
    }

    $prilozi = [];
    foreach (msgPriloziSkladista($cfb, $m) as $i => $a) {
        $afx = $a->fiksna();
        $podaci = $a->binarno(0x3701);
        $prilozi[] = [
            'i'        => $i,
            'ime'      => msgImePriloga($a, $i),
            'mime'     => $a->tekst(0x370E),
            'vel'      => $podaci !== null ? strlen($podaci) : null,
            'cid'      => $a->tekst(0x3712),
            'skriven'  => isset($afx[0x7FFE]) && unpack('v', $afx[0x7FFE]['v'])[1] !== 0,
            'ugradjena'=> $podaci === null,
        ];
    }
    return [
        'naslov' => $m->tekst(0x0037) ?? '',
        'od'     => trim(($odIme ?? '') . ($odAdr && $odAdr !== $odIme ? " <$odAdr>" : '')),
        'za'     => $m->tekst(0x0E04) ?? '',
        'cc'     => $m->tekst(0x0E03) ?? '',
        'datum'  => $datum,
        'html'   => $html,
        'tekst'  => $m->tekst(0x1000),
        'prilozi'=> $prilozi,
    ];
}

function msgPrilog(string $putanja, int $i): ?array {
    [$cfb, $m] = msgOtvori($putanja);
    $a = msgPriloziSkladista($cfb, $m)[$i] ?? null;
    if (!$a) return null;
    $podaci = $a->binarno(0x3701);
    if ($podaci === null) return null;   // уграђена порука нема бајтове
    return ['ime' => msgImePriloga($a, $i), 'mime' => $a->tekst(0x370E), 'sadrzaj' => $podaci];
}
