<?php
/**
 * Datei: csp_report.php
 * Zweck: Empfang von CSP-Verstoßberichten im Report-Only-Modus (U2, Leitfaden docs/UI_GUIDELINES.md, Abschnitt 4).
 *
 * Verhalten:
 * - Nur aktiv bei CSP_REPORT_ONLY === true (ui_config.php); sonst 404 ohne Protokollierung.
 * - Ausschließlich POST (sonst 405), Inhaltstyp application/csp-report, application/json oder
 *   application/reports+json (sonst 415), höchstens 8 KiB (sonst 413).
 * - Ratenbegrenzung ohne Session: je Quelladresse und insgesamt je Zeitfenster (sonst 429; die erste
 *   Überschreitung je Fenster wird einmal protokolliert).
 * - Je gültigem Verstoß eine Logzeile über appLog(): Seite und blockierte Quelle ohne Query/Fragment, Direktive,
 *   Quelldatei/Zeile/Spalte, Disposition. Alle Felder werden als nicht vertrauenswürdig behandelt (Typ, Länge,
 *   zulässige Werte; Steuerzeichen, Anführungszeichen und Backslashes entschärft). Keine Cookies, Tokens,
 *   Codebeispiele (script-sample) oder vollständigen Berichtsinhalte im Log.
 * - Ungültige Berichte: 400 ohne Protokollierung. Erfolg: 204 ohne Inhalt.
 *
 * Sicherheit:
 * - Bewusst ohne CSRF-Prüfung (Browser senden Berichte ohne Token) und ohne fachliche Schreiboperation.
 * - Keine Datenbank, keine Authentifizierung, keine Anwendungssession (common.php wird nicht geladen); deshalb
 *   enthält die Logzeile keine Benutzer-ID.
 */

require_once __DIR__ . '/config/ui_config.php';
require_once __DIR__ . '/inc/logging.php';
require_once __DIR__ . '/inc/web/csp.php';

const CSP_REPORT_MAX_BYTES = 8192;
const CSP_REPORT_WINDOW_SECONDS = 600;
const CSP_REPORT_MAX_PER_IP = 60;
const CSP_REPORT_MAX_TOTAL = 600;
const CSP_REPORT_MAX_ENTRIES = 10;      // Berichte je Anfrage (Reporting-API bündelt)
const CSP_REPORT_MAX_TRACKED_IPS = 2000;

/** Bekannte Direktiven (effective-directive / violated-directive) */
const CSP_REPORT_DIRECTIVES = [
    'default-src', 'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem', 'style-src-attr',
    'img-src', 'font-src', 'connect-src', 'object-src', 'base-uri', 'form-action', 'frame-ancestors', 'frame-src',
    'child-src', 'worker-src', 'manifest-src', 'media-src', 'prefetch-src', 'trusted-types', 'require-trusted-types-for',
];

/** Schlüsselwörter, die Browser statt einer URL als blockierte Quelle melden */
const CSP_REPORT_BLOCKED_KEYWORDS = ['inline', 'eval', 'wasm-eval', 'self', 'data', 'blob', 'trusted-types-policy', 'trusted-types-sink'];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

/** Antwort ohne Inhalt senden und beenden */
function csp_report_finish(int $status, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $h) {
        header($h);
    }
    exit;
}

if (!csp_report_only_enabled()) {
    csp_report_finish(404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    csp_report_finish(405, ['Allow: POST']);
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($contentType, ['application/csp-report', 'application/json', 'application/reports+json'], true)) {
    csp_report_finish(415);
}

$declared = $_SERVER['CONTENT_LENGTH'] ?? null;
if ($declared !== null && $declared !== '' && (!ctype_digit((string)$declared) || (int)$declared > CSP_REPORT_MAX_BYTES)) {
    csp_report_finish(413);
}

/**
 * Ratenbegrenzung ohne Session: Zähler je Quelladresse (gehasht) und gesamt im festen Zeitfenster, in einer
 * Statusdatei im temporären Verzeichnis (unter Apache mit PrivateTmp dienstbezogen), mit exklusiver Dateisperre.
 * Fehlerfälle (Datei nicht nutzbar) führen zur Ablehnung (Fail-Closed).
 *
 * @return array{allowed: bool, firstExceed: bool}
 */
function csp_report_rate_check(string $ip): array
{
    $file = sys_get_temp_dir() . '/dnsmanager-csp-report-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return ['allowed' => false, 'firstExceed' => false];
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return ['allowed' => false, 'firstExceed' => false];
        }
        $raw = stream_get_contents($fh, 262144);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($state) || !is_int($state['start'] ?? null) || $now - $state['start'] >= CSP_REPORT_WINDOW_SECONDS || $state['start'] > $now) {
            $state = ['start' => $now, 'total' => 0, 'ips' => [], 'exceeded' => false];
        }
        $key = substr(hash('sha256', $ip), 0, 16);
        $perIp = (int)($state['ips'][$key] ?? 0);
        $tracked = isset($state['ips'][$key]) || count($state['ips']) < CSP_REPORT_MAX_TRACKED_IPS;
        $allowed = $tracked && $perIp < CSP_REPORT_MAX_PER_IP && (int)$state['total'] < CSP_REPORT_MAX_TOTAL;
        $firstExceed = false;
        if ($allowed) {
            $state['ips'][$key] = $perIp + 1;
            $state['total'] = (int)$state['total'] + 1;
        } elseif (empty($state['exceeded'])) {
            $state['exceeded'] = true;
            $firstExceed = true;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
        flock($fh, LOCK_UN);
        return ['allowed' => $allowed, 'firstExceed' => $firstExceed];
    } finally {
        fclose($fh);
    }
}

$rate = csp_report_rate_check((string)($_SERVER['REMOTE_ADDR'] ?? ''));
if (!$rate['allowed']) {
    if ($rate['firstExceed']) {
        appLog('warning', 'event=csp_report_rate_limited window_seconds=' . CSP_REPORT_WINDOW_SECONDS);
    }
    csp_report_finish(429);
}

// Inhalt höchstens bis zur Grenze + 1 Byte lesen (auch ohne oder mit falscher Content-Length)
$in = fopen('php://input', 'rb');
$body = $in === false ? false : stream_get_contents($in, CSP_REPORT_MAX_BYTES + 1);
if ($in !== false) {
    fclose($in);
}
if (!is_string($body) || $body === '') {
    csp_report_finish(400);
}
if (strlen($body) > CSP_REPORT_MAX_BYTES) {
    csp_report_finish(413);
}

$data = json_decode($body, true, 8);
if (!is_array($data)) {
    csp_report_finish(400);
}

/**
 * Text für die Logausgabe entschärfen: nur UTF-8, Steuerzeichen (inkl. Zeilenumbrüche) durch '?' ersetzt,
 * Backslash und Anführungszeichen maskiert, Länge begrenzt.
 */
function csp_report_clean(string $value, int $max): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    $value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '?', $value) ?? '';
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max) . '…';
    }
    return addcslashes($value, '\\"');
}

/**
 * URL ohne Query, Fragment und Zugangsdaten (Schema, Host, Port, Pfad); nur http/https.
 * Bei blockierten Quellen sind zusätzlich die Schlüsselwörter der Browser zulässig.
 */
function csp_report_url(mixed $value, bool $allowKeywords): ?string
{
    if (!is_string($value) || $value === '' || strlen($value) > 2048) {
        return null;
    }
    if ($allowKeywords && in_array($value, CSP_REPORT_BLOCKED_KEYWORDS, true)) {
        return $value;
    }
    $p = parse_url($value);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        // Schema-Quellen wie data: oder blob: nur als Schema protokollieren
        if ($allowKeywords && preg_match('/^(data|blob|filesystem|about):/i', $value, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }
    $host = strtolower($p['host']);
    if (!preg_match('/^[a-z0-9.\-]{1,253}$|^\[[0-9a-f:.]{2,45}\]$/', $host)) {
        return null;
    }
    $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
    return strtolower($p['scheme']) . '://' . $host . $port . ($p['path'] ?? '');
}

/** Direktive prüfen (bekannt, ggf. aus "violated-directive" mit Quellangaben die erste Komponente) */
function csp_report_directive(mixed $value): ?string
{
    if (!is_string($value) || $value === '' || strlen($value) > 512) {
        return null;
    }
    $d = strtolower(trim(explode(' ', trim($value))[0]));
    return in_array($d, CSP_REPORT_DIRECTIVES, true) ? $d : null;
}

/** Ganzzahl 0..10 000 000 (Zeile/Spalte), sonst 0 */
function csp_report_int(mixed $value): int
{
    if (is_int($value) && $value >= 0 && $value <= 10000000) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value) && strlen($value) <= 8) {
        return (int)$value;
    }
    return 0;
}

// Formate: application/csp-report {"csp-report": {...}} bzw. Reporting-API [{"type": "csp-violation", "body": {...}}]
$entries = [];
if (isset($data['csp-report']) && is_array($data['csp-report'])) {
    $r = $data['csp-report'];
    $entries[] = [
        'page'        => $r['document-uri'] ?? null,
        'directive'   => $r['effective-directive'] ?? ($r['violated-directive'] ?? null),
        'blocked'     => $r['blocked-uri'] ?? null,
        'source'      => $r['source-file'] ?? null,
        'line'        => $r['line-number'] ?? null,
        'column'      => $r['column-number'] ?? null,
        'disposition' => $r['disposition'] ?? null,
    ];
} elseif (array_is_list($data) && count($data) <= CSP_REPORT_MAX_ENTRIES) {
    foreach ($data as $item) {
        if (!is_array($item) || ($item['type'] ?? null) !== 'csp-violation' || !is_array($item['body'] ?? null)) {
            continue;
        }
        $b = $item['body'];
        $entries[] = [
            'page'        => $b['documentURL'] ?? ($item['url'] ?? null),
            'directive'   => $b['effectiveDirective'] ?? null,
            'blocked'     => $b['blockedURL'] ?? null,
            'source'      => $b['sourceFile'] ?? null,
            'line'        => $b['lineNumber'] ?? null,
            'column'      => $b['columnNumber'] ?? null,
            'disposition' => $b['disposition'] ?? null,
        ];
    }
}

$lines = [];
foreach ($entries as $e) {
    $page = csp_report_url($e['page'], false);
    $directive = csp_report_directive($e['directive']);
    if ($page === null || $directive === null) {
        continue; // unvollständig oder ungültig
    }
    $blocked = csp_report_url($e['blocked'], true) ?? '-';
    $source = csp_report_url($e['source'], false) ?? '-';
    $disposition = in_array($e['disposition'], ['report', 'enforce'], true) ? $e['disposition'] : '-';
    $lines[] = sprintf(
        'event=csp_violation page="%s" directive="%s" blocked="%s" source="%s" line=%d column=%d disposition="%s"',
        csp_report_clean($page, 200),
        $directive,
        csp_report_clean($blocked, 200),
        csp_report_clean($source, 200),
        csp_report_int($e['line']),
        csp_report_int($e['column']),
        $disposition
    );
}

if ($lines === []) {
    csp_report_finish(400);
}
foreach ($lines as $line) {
    appLog('warning', $line);
}
csp_report_finish(204);
