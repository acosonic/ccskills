<?php
/**
 * Слање мејла преко SMTP-а (без библиотека): подешавања у admin_settings (Подешавања → Мејл).
 *
 *   posaljiMejl($za, $naslov, $html, $tekst = null) → true, или баца RuntimeException са одговором сервера
 *   mejlUkljucen() — укључено и подешено (сервер и пошиљалац)
 *   mejlSablon($naslov, $sadrzajHtml, $dugme = [текст, url]) — HTML у боји апликације
 *
 * Подржава: порт 25/587 са STARTTLS (smtp_secure = tls), 465 са SSL-ом (ssl), без шифровања (празно);
 * AUTH LOGIN/PLAIN ако је унет корисник. Писмо је multipart/alternative (текст + HTML), UTF-8.
 *
 * Зависи од апликације: getDB() (PDO) и getSetting() над табелом admin_settings(naziv, vrednost) — прилагоди
 * mejlPodesavanja()/aplikacijaUrl() свом складишту подешавања. Бренд: константе испод.
 */
const MAIL_ORG      = 'Generic Corp';         // назив организације у заглављу мејла
const MAIL_APP      = 'Интерна апликација';   // назив апликације (подразумевано име пошиљаоца)
const MAIL_BRAND    = '#2563eb';              // боја бренда (дугме, линија)
const MAIL_BRAND_DK = '#1e40af';
const MAIL_APP_PATH = '/app';                 // путања апликације кад app_url није подешен

function mejlPodesavanja(): array {
    $k = ['mail_enabled', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'smtp_verify'];
    $o = [];
    $st = getDB()->prepare("SELECT vrednost FROM admin_settings WHERE naziv = ?");   // без кеша getSetting() — тест одмах после чувања
    foreach ($k as $n) { $st->execute([$n]); $o[$n] = (string)($st->fetchColumn() ?: ''); }
    return $o;
}

function mejlUkljucen(): bool {
    $p = mejlPodesavanja();
    return $p['mail_enabled'] === '1' && $p['smtp_host'] !== '' && $p['smtp_from'] !== '';
}

/** Адреса апликације за линкове у мејлу: подешавање app_url, иначе APP_URL из .env, иначе тренутни host. */
function aplikacijaUrl(): string {
    $u = getSetting('app_url') ?: (getenv('APP_URL') ?: '');
    if ($u === '' && !empty($_SERVER['HTTP_HOST'])) {
        $u = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
           . '://' . $_SERVER['HTTP_HOST'] . MAIL_APP_PATH;
    }
    return rtrim($u, '/');
}

function mejlIspravan(?string $a): bool {
    return $a !== null && filter_var(trim($a), FILTER_VALIDATE_EMAIL) !== false;
}

/** RFC 2047 за заглавља са ћирилицом. */
function mejlZaglavlje(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Шаље мејл једном или више прималаца. $p — подешавања (за тест пре чувања), иначе из базе.
 */
function posaljiMejl(string|array $za, string $naslov, string $html, ?string $tekst = null, ?array $p = null): bool {
    $p ??= mejlPodesavanja();
    $za = array_values(array_filter(array_map('trim', (array)$za), 'mejlIspravan'));
    if (!$za) throw new RuntimeException('Нема исправне адресе примаоца.');
    if ($p['smtp_host'] === '' || !mejlIspravan($p['smtp_from'])) throw new RuntimeException('SMTP сервер или адреса пошиљаоца нису подешени.');
    $tekst ??= trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(preg_replace(
        ['#<li[^>]*>#i', '#<br\s*/?>#i', '#</(p|div|tr|h\d|li|ul)>#i', '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#i'],
        ['- ', "\n", "\n", '$2 ($1)'], $html)), ENT_QUOTES, 'UTF-8')));

    $secure = strtolower($p['smtp_secure']);
    $port   = (int)($p['smtp_port'] ?: ($secure === 'ssl' ? 465 : ($secure === 'tls' ? 587 : 25)));
    $verify = $p['smtp_verify'] !== '0';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]]);
    $adresa = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $p['smtp_host'] . ':' . $port;
    $s = @stream_socket_client($adresa, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("Веза са {$p['smtp_host']}:$port није успела: $errstr");
    stream_set_timeout($s, 15);

    $citaj = function () use ($s): string {
        $o = '';
        while (($l = fgets($s, 1024)) !== false) { $o .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $o;
    };
    $komanda = function (?string $c, array $ocekivano) use ($s, $citaj): string {
        if ($c !== null) fwrite($s, $c . "\r\n");
        $o = $citaj();
        if (!in_array((int)substr($o, 0, 3), $ocekivano, true)) {
            $prikaz = $c === null ? 'повезивање' : (str_starts_with($c, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $c) ? 'пријава' : strtok($c, ' '));
            throw new RuntimeException("SMTP ($prikaz): " . trim($o ?: 'нема одговора'));
        }
        return $o;
    };

    try {
        $host = gethostname() ?: 'localhost';
        $komanda(null, [220]);
        $ehlo = $komanda("EHLO $host", [250]);
        if ($secure === 'tls') {
            $komanda('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('STARTTLS није успео (сертификат?). Пробајте „Провера сертификата" = не.');
            }
            $ehlo = $komanda("EHLO $host", [250]);
        }
        if ($p['smtp_user'] !== '') {
            if (stripos($ehlo, 'LOGIN') !== false) {
                $komanda('AUTH LOGIN', [334]);
                $komanda(base64_encode($p['smtp_user']), [334]);
                $komanda(base64_encode($p['smtp_pass']), [235]);
            } else {
                $komanda('AUTH PLAIN ' . base64_encode("\0" . $p['smtp_user'] . "\0" . $p['smtp_pass']), [235]);
            }
        }
        $komanda('MAIL FROM:<' . $p['smtp_from'] . '>', [250]);
        foreach ($za as $a) $komanda("RCPT TO:<$a>", [250, 251]);
        $komanda('DATA', [354]);

        $granica = 'b' . bin2hex(random_bytes(8));
        $domen = substr(strrchr($p['smtp_from'], '@'), 1);
        $zaglavlja = [
            'Date: ' . date('r'),
            'From: ' . mejlZaglavlje($p['smtp_from_name'] ?: MAIL_APP) . ' <' . $p['smtp_from'] . '>',
            'To: ' . implode(', ', $za),
            'Subject: ' . mejlZaglavlje($naslov),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domen . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            "Content-Type: multipart/alternative; boundary=\"$granica\"",
        ];
        $telo = "--$granica\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($tekst))
              . "--$granica\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$granica--\r\n";
        fwrite($s, implode("\r\n", $zaglavlja) . "\r\n\r\n" . $telo . ".\r\n");
        $komanda(null, [250]);
        @fwrite($s, "QUIT\r\n");
    } finally {
        @fclose($s);
    }
    return true;
}

/** Једноставан HTML мејл у боји апликације, са дугметом. */
function mejlSablon(string $naslov, string $sadrzajHtml, ?array $dugme = null): string {
    $d = $dugme ? '<p style="margin:22px 0"><a href="' . htmlspecialchars($dugme[1]) . '" style="background:' . MAIL_BRAND . ';color:#fff;'
        . 'padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">' . htmlspecialchars($dugme[0]) . '</a></p>'
        . '<p style="font-size:12px;color:#687a8a">Ако дугме не ради, отворите: <a href="' . htmlspecialchars($dugme[1]) . '">' . htmlspecialchars($dugme[1]) . '</a></p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f3f6f9;font-family:Segoe UI,Arial,sans-serif;color:#243749">'
        . '<div style="max-width:620px;margin:0 auto;padding:24px">'
        . '<div style="border-top:4px solid ' . MAIL_BRAND . ';background:#fff;border-radius:8px;padding:24px 26px">'
        . '<div style="font-size:12px;color:' . MAIL_BRAND . ';font-weight:bold;margin-bottom:6px">' . htmlspecialchars(MAIL_ORG . ' · ' . MAIL_APP) . '</div>'
        . '<h2 style="margin:0 0 14px;font-size:19px;color:' . MAIL_BRAND_DK . '">' . htmlspecialchars($naslov) . '</h2>'
        . $sadrzajHtml . $d . '</div>'
        . '<div style="font-size:11px;color:#8a99a6;margin-top:12px">Аутоматска порука апликације ' . htmlspecialchars(MAIL_APP) . ' — не одговарајте на њу.</div>'
        . '</div></body></html>';
}
