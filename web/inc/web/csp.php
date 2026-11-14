<?php
/**
 * Datei: inc/web/csp.php
 * Zweck: Content-Security-Policy der Weboberfläche (U2, Leitfaden docs/UI_GUIDELINES.md, Abschnitt 4).
 *
 * - CSP_TARGET_POLICY ist die Zielrichtlinie ohne 'unsafe-inline' und ohne 'unsafe-eval'.
 * - Report-Only nur bei ausdrücklich gesetzter Konfiguration CSP_REPORT_ONLY === true (ui_config.php);
 *   ohne diese Einstellung wird kein CSP-Header gesendet und der Report-Endpunkt ist deaktiviert.
 * - Die Durchsetzung (Content-Security-Policy) folgt erst in U6.
 * - Keine Abhängigkeit von Datenbank, Session oder Authentifizierung (auch von csp_report.php genutzt).
 */

/** Zielrichtlinie (Leitfaden 4, unverändert) */
const CSP_TARGET_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

/** Pfad des Report-Endpunkts relativ zu BASE_URL */
const CSP_REPORT_PATH = '/csp_report.php';

/**
 * Report-Only aktiv? Nur bei ausdrücklich gesetzter Konstante CSP_REPORT_ONLY mit dem Wert true.
 */
function csp_report_only_enabled(): bool
{
    return defined('CSP_REPORT_ONLY') && CSP_REPORT_ONLY === true;
}

/**
 * Wert des Headers Content-Security-Policy-Report-Only: Zielrichtlinie plus report-uri auf den eigenen Endpunkt.
 */
function csp_report_only_header_value(): string
{
    return CSP_TARGET_POLICY . '; report-uri ' . rtrim(BASE_URL, '/') . CSP_REPORT_PATH;
}
