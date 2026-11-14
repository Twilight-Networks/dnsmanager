<?php
/**
 * Datei: tests/ui/serve_router.php
 * Zweck: Router für den PHP-Entwicklungsserver (php -S) im Screenshot-Modus der UI-Golden-Basis (U0).
 *        Liefert die Testkopie unter /dnsmanager-ui/ aus. Für angemeldete Rollen setzt er die zuvor per regulärem
 *        Login erzeugte Session-ID serverseitig (Umgebungsvariable UIG_SID), weil das Session-Cookie der Anwendung
 *        „Secure“ ist und der Server nur lokal über HTTP (127.0.0.1, SSH-Tunnel) erreichbar ist.
 *        UIG_NOJS=1 entfernt alle <script>-Elemente (Darstellung ohne JavaScript).
 *        /dnsmanager-ui/__ui/ liefert die Testseiten aus tests/ui/showcase (U3), nicht Teil der Anwendung.
 *        Nur für Screenshots der isolierten Testkopie; kein Bestandteil der Anwendung.
 */
$app = '/tmp/dnsmgr-uig/app/web';
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!str_starts_with($uri, '/dnsmanager-ui/')) { http_response_code(404); echo 'nicht gefunden'; return true; }
// U4-Core: Ablage der im Browser erfassten JS-Formulardaten der Record-Matrix (nur mit UIG_UPLOAD=1, d. h.
// UIG_RECORDS_MATRIX=1 im Serve-Modus; Server lauscht nur auf 127.0.0.1). Feste Zieldatei, höchstens 2 MiB.
if ($uri === '/dnsmanager-ui/__ui/upload' && getenv('UIG_UPLOAD') === '1' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $data = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
    $ok = strlen($data) <= 2 * 1024 * 1024 && is_array(json_decode($data, true))
        && file_put_contents('/tmp/dnsmgr-uig/upload/js_posts.json', $data) !== false;
    http_response_code($ok ? 204 : 400);
    return true;
}
// U3: Testseiten des Harness (Bausteinübersicht, DIFF-Prüfseite) unter /dnsmanager-ui/__ui/, aus /tmp/dnsmgr-uig/ui
$base = $app;
$rel = substr($uri, strlen('/dnsmanager-ui/'));
if (str_starts_with($rel, '__ui/')) { $base = '/tmp/dnsmgr-uig/ui'; $rel = substr($rel, strlen('__ui/')); }
$file = realpath($base . '/' . $rel);
if ($file === false || !str_starts_with($file, $base . '/') || is_dir($file)) { http_response_code(404); echo 'nicht gefunden'; return true; }

if (!str_ends_with($file, '.php')) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'map' => 'application/json'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// No-JS-Modus (UIG_NOJS=1): alle <script>-Elemente aus der Antwort entfernen, damit der Browser die Seite wie mit
// abgeschaltetem JavaScript rendert (Edge headless erzeugt mit scriptEnabled=false keine Screenshots)
if (getenv('UIG_NOJS') === '1') {
    // Ohne Regex: große Inline-Skripte (z. B. window.LANG) könnten sonst das PCRE-Backtracking-Limit überschreiten
    ob_start(function (string $html): string {
        while (($start = stripos($html, '<script')) !== false) {
            $end = stripos($html, '</script>', $start);
            $html = substr($html, 0, $start) . ($end === false ? '' : substr($html, $end + 9));
        }
        return $html;
    });
}

$sid = (string)getenv('UIG_SID');
if ($sid !== '' && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sid)) { $_COOKIE['twl_dnsmgr_sid'] = $sid; }
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $uri;
$_SERVER['PHP_SELF'] = $uri;
$_SERVER['HTTPS'] = 'on';
chdir(dirname($file));
require $file;
return true;
