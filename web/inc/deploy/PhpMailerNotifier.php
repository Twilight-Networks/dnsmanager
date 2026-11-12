<?php
/**
 * Datei: inc/deploy/PhpMailerNotifier.php
 * Zweck: MonitoringNotifier über PHPMailer (SMTP oder mail()) – bisher sendMail() in inc/monitoring_mailer.php.
 *
 * Versandlogik, HTML-Aufbereitung und Fehlerergebnis unverändert. Die bisherige Fehler-Logzeile
 * ("Fehler beim Mailversand: …") schreibt der Adapter über den übergebenen Logger. Konfiguration aus den
 * bestehenden Konstanten (MAILER_*), die der Aufrufer geladen hat. Kein Web-Bootstrap, keine Datenbank.
 */

require_once __DIR__ . '/../app/monitoring/MonitoringNotifier.php';
require_once __DIR__ . '/../../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class PhpMailerNotifier implements MonitoringNotifier
{
    private Closure $logger;

    /**
     * @param callable|null $logger callable(string $level, string $message): void
     */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger !== null ? Closure::fromCallable($logger) : static function (string $level, string $message): void {
        };
    }

    public function send(string $subject, string $body): array
    {
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
            ($this->logger)('error', $errorMessage);

            return [
                'status' => 'error',
                'errors' => [$errorMessage],
                'output' => $errorMessage
            ];
        }
    }
}
