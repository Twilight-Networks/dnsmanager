<?php
/**
 * Datei: monitoring_mailer.php
 * Zweck: Versand von E-Mail-Benachrichtigungen bei Statuswechseln im Diagnosesystem
 *
 * Beschreibung:
 * Diese Datei enthält Funktionen zur automatisierten Benachrichtigung per E-Mail bei Statusänderungen
 * von Überwachungsprüfungen an DNS-Zonen oder Servern. Es werden ausschließlich relevante Statuswechsel
 * (z. B. von "ok" nach "error") gemeldet. Erfolgreich versandte Meldungen werden in der Datenbank markiert.
 *
 * Abhängigkeiten:
 * - PHPMailer (vendor/phpmailer/)
 * - Konstante MAILER_ENABLED sowie zugehörige SMTP- oder Mail()-Konfigurationskonstanten
 * - Funktionen: appLog()
 *
 * Hinweis:
 * Diese Datei wird typischerweise durch das CLI-Skript `monitoring_run-cli.php` aufgerufen.
 */

require_once __DIR__ . '/../config/ui_config.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Versendet E-Mail-Benachrichtigungen bei Statuswechseln in Diagnosedaten
 *
 * Prüft die Tabelle `diagnostic_log` auf noch nicht gemeldete Statuswechsel
 * und versendet E-Mail-Benachrichtigungen über `sendMail()`. Erfolgreich
 * gemeldete Einträge werden anschließend mit `notified = 1` markiert.
 *
 * Rückgabeformat:
 * - status: "ok" oder "error"
 * - errors: Liste aller Fehler
 * - output: Zusammenfassung der Versendungen
 *
 * @param PDO $db Datenbankverbindung
 * @return array Rückgabestatus und Fehlerliste
 */
function sendDiagnosticAlerts(PDO $db): array {
    if (!defined('MAILER_ENABLED') || MAILER_ENABLED !== true) {
        return [
            'status' => 'ok',
            'errors' => [],
            'output' => 'E-Mail-Benachrichtigungen deaktiviert (MAILER_ENABLED = false)'
        ];
    }

    $stmt = $db->query("
        SELECT dl.*, d.target_type, d.target_id, d.check_type,
               s.name AS server_name, z.name AS zone_name
        FROM diagnostic_log dl
        JOIN diagnostics d ON d.id = dl.diagnostic_id
        LEFT JOIN servers s ON s.id = d.server_id
        LEFT JOIN zones z ON z.id = d.target_id AND d.target_type = 'zone'
        WHERE dl.notified = 0
          AND (
            (dl.old_status = 'ok' AND dl.new_status IN ('warning', 'error', 'not_found')) OR
            (dl.old_status IN ('warning', 'error', 'not_found') AND dl.new_status = 'ok')
          )
        ORDER BY dl.changed_at ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $errors = [];
    $output_lines = [];

    foreach ($rows as $row) {
        $namePart = $row['target_type'] === 'zone'
            ? "Zone: {$row['zone_name']}"
            : "Server: {$row['server_name']}";

        $subject = "[DNSManager] {$namePart} ({$row['check_type']}) Status: {$row['new_status']}";
        $target  = $row['target_type'] === 'zone' ? "Zone: {$row['zone_name']}" : "Server: {$row['server_name']}";
        $body = <<<TEXT
Statuswechsel erkannt:

Typ: {$row['target_type']}
Check: {$row['check_type']}
$target

Zeitpunkt: {$row['changed_at']}
Alter Status: {$row['old_status']}
Neuer Status: {$row['new_status']}

Meldung:
{$row['message']}
TEXT;

        $mailResult = sendMail($subject, $body);

        if ($mailResult['status'] === 'ok') {
            $update = $db->prepare("UPDATE diagnostic_log SET notified = 1 WHERE id = ?");
            $update->execute([$row['id']]);
            $output_lines[] = "Versand: {$subject}";
        } else {
            foreach ($mailResult['errors'] as $msg) {
                $errors[] = "Fehler beim Versand von ({$subject}): {$msg}";
            }
        }
    }

    return [
        'status' => empty($errors) ? 'ok' : 'error',
        'errors' => $errors,
        'output' => implode("\n", $output_lines)
    ];
}

/**
 * Sendet eine formatierte E-Mail mit PHPMailer
 *
 * Rückgabe erfolgt im strukturierten DNSManager-Stil mit status, errors, output.
 *
 * @param string $subject Betreff der Nachricht
 * @param string $body Inhalt der Nachricht (Text, wird automatisch HTML-formatiert)
 * @return array Ergebnisarray mit status, errors und output
 */
function sendMail(string $subject, string $body): array {
    $mail = new PHPMailer(true);

    try {
        if (defined('MAILER_USE_SMTP') && MAILER_USE_SMTP === true) {
            $mail->isSMTP();
            $mail->Host = MAILER_SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAILER_SMTP_USER;
            $mail->Password = MAILER_SMTP_PASS;
            $mail->SMTPSecure = MAILER_SMTP_SECURE;
            $mail->Port = MAILER_SMTP_PORT;
        } else {
            $mail->isMail(); // sendmail oder mail()
        }

        $mail->setFrom(MAILER_FROM_ADDRESS, MAILER_FROM_NAME);
        $mail->addAddress(MAILER_TO_ADDRESS);
        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $htmlBody = <<<HTML
        <html><body>
        <pre style="font-family: monospace; font-size: 13px; color: #333; background: #f9f9f9; padding: 1em; border: 1px solid #ccc;">
        {$body}
        </pre>
        </body></html>
        HTML;

        $mail->Body = $htmlBody;
        $mail->send();

        return [
            'status' => 'ok',
            'errors' => [],
            'output' => "E-Mail erfolgreich versendet an " . MAILER_TO_ADDRESS
        ];

    } catch (Exception $e) {
        $errorMessage = "Fehler beim Mailversand: " . $mail->ErrorInfo;
        appLog('error', $errorMessage);

        return [
            'status' => 'error',
            'errors' => [$errorMessage],
            'output' => $errorMessage
        ];
    }
}
