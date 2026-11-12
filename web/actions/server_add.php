<?php
/**
 * Datei: server_add.php
 * Zweck: Fügt einen neuen DNS-Server in die Datenbank ein.
 *
 * Funktionen:
 * - Erwartet POST-Anfrage mit Serverdaten.
 * - Validierung (Name, IP-Adressen, Token), "nur ein lokaler Server" und Anlage im ServerService.
 * - Setzt Feedback-Nachricht und leitet zurück zur Serverübersicht.
 *
 * Zugriff:
 * - Nur eingeloggte Benutzer (admin) dürfen diesen Endpunkt aufrufen.
 *
 * Web-Adapter: Eingaben lesen, ServerService::createServer() aufrufen, ServiceMessages in die bisherigen
 * Toasts/Logs/Redirects übersetzen.
 */

require_once __DIR__ . '/../common.php';
verify_csrf_token();
requireRole(['admin']);
require_once __DIR__ . '/../inc/app/Actor.php';
require_once __DIR__ . '/../inc/app/TopologyLock.php';
require_once __DIR__ . '/../inc/app/servers/ServerService.php';
require_once __DIR__ . '/../inc/deploy/BindZoneBuildBackend.php';
require_once __DIR__ . '/../inc/web/web_adapter.php';

// Nur POST-Anfragen erlaubt
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Nur POST erlaubt');
}

// Felder einlesen und bereinigen
$name = trim($_POST['name'] ?? '');
$input = new ServerInput(
    $name,
    trim($_POST['dns_ip4'] ?? ''),
    trim($_POST['dns_ip6'] ?? ''),
    trim($_POST['api_ip'] ?? ''),
    trim($_POST['api_token'] ?? ''),
    isset($_POST['is_local']),
    isset($_POST['active'])
);

$serverService = new ServerService($pdo, new BindZoneBuildBackend($pdo), new TopologyLock($pdo));
$result = $serverService->createServer(actorFromSession(), $input);

$redirect = "/pages/servers.php?add_new=1";

// ServiceMessages in bestehende Toasts/Logs übersetzen
foreach ($result->messages as $message) {
    $ctx = $message->context;

    switch ($message->code) {
        case 'server.create.access_denied':
            logAccessDenied('server_add', "Benutzer-ID {$ctx['actorId']} ist laut Datenbank kein aktiver Administrator.");
            http_response_code(403);
            exit('Zugriff verweigert: Unzureichende Berechtigungen.');

        case 'server.create.invalid_input':
            foreach ($ctx['errors'] as $code) {
                $err = $LANG['server_error_' . $code] ?? $code;
                toastError(
                    $LANG['server_error_invalid_input'] . ': ' . htmlspecialchars($err),
                    "Validierungsfehler beim Hinzufügen von Server: {$err}"
                );
            }
            break;

        case 'server.create.failed':
            toastError(
                $LANG['server_error_db_save_failed'],
                "Datenbankfehler beim Hinzufügen von Server '{$name}': " . $ctx['detail']
            );
            break;

        case 'server.create.success':
            toastSuccess(
                sprintf($LANG['server_created_success'], htmlspecialchars($name)),
                "Neuer Server hinzugefügt: {$name} ({$ctx['dnsIp4']}) ({$ctx['dnsIp6']}), lokal={$ctx['isLocal']}, aktiv={$ctx['active']}"
            );
            $redirect = "/pages/servers.php";
            break;

        default:
            // Unbekannte ServiceMessages nie still verwerfen
            if ($message->kind === 'success') {
                appLog('warning', "Unbehandelte ServiceMessage '{$message->code}' (server_add)");
            } else {
                toastError(
                    $LANG['server_error_db_save_failed'],
                    "Unbehandelte ServiceMessage '{$message->code}' (server_add)"
                );
            }
            break;
    }
}

header("Location: " . rtrim(BASE_URL, '/') . $redirect);
exit;
