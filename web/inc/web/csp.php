<?php
/**
 * Datei: inc/web/csp.php
 * Zweck: Content-Security-Policy der Weboberfläche (U2, U6; Leitfaden docs/UI_GUIDELINES.md, Abschnitt 4).
 *
 * - CSP_TARGET_POLICY ist die Zielrichtlinie ohne 'unsafe-inline' und ohne 'unsafe-eval' (seit U2 unverändert).
 * - Modus (U6-P1) über CSP_MODE in ui_config.php:
 *     'enforce'      Content-Security-Policy mit der Zielrichtlinie (ohne report-uri; Report-Endpunkt deaktiviert)
 *     'report-only'  Content-Security-Policy-Report-Only mit der Zielrichtlinie plus report-uri; Endpunkt aktiv
 *     'off'          kein CSP-Header, Endpunkt deaktiviert
 *   Ein definiertes CSP_MODE hat immer Vorrang. Nur ohne CSP_MODE gilt die U2-Einstellung CSP_REPORT_ONLY === true als
 *   Kompatibilitätsfall 'report-only'. Ohne beide Einstellungen: 'enforce'. Ein ungültiger CSP_MODE-Wert wird
 *   sicherheitshalber als 'enforce' behandelt (Fail-Closed).
 * - Keine Abhängigkeit von Datenbank, Session oder Authentifizierung (auch von csp_report.php genutzt).
 */

/** Zielrichtlinie (Leitfaden 4, unverändert) */
const CSP_TARGET_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

/** Pfad des Report-Endpunkts relativ zu BASE_URL */
const CSP_REPORT_PATH = '/csp_report.php';

/** Zulässige Werte für CSP_MODE */
const CSP_MODES = ['enforce', 'report-only', 'off'];

/**
 * Wirksamer CSP-Modus: 'enforce', 'report-only' oder 'off' (Vorrang siehe Dateikopf).
 */
function csp_mode(): string
{
    if (defined('CSP_MODE')) {
        return in_array(CSP_MODE, CSP_MODES, true) ? CSP_MODE : 'enforce';
    }
    if (defined('CSP_REPORT_ONLY') && CSP_REPORT_ONLY === true) {
        return 'report-only';
    }
    return 'enforce';
}

/**
 * Report-Only aktiv? Steuert auch den Report-Endpunkt csp_report.php (nur in diesem Modus erreichbar).
 */
function csp_report_only_enabled(): bool
{
    return csp_mode() === 'report-only';
}

/**
 * Wert des Headers Content-Security-Policy-Report-Only: Zielrichtlinie plus report-uri auf den eigenen Endpunkt.
 */
function csp_report_only_header_value(): string
{
    return CSP_TARGET_POLICY . '; report-uri ' . rtrim(BASE_URL, '/') . CSP_REPORT_PATH;
}

/**
 * Zu sendender CSP-Header als vollständige Headerzeile oder null (Modus 'off').
 */
function csp_header_line(): ?string
{
    return match (csp_mode()) {
        'enforce' => 'Content-Security-Policy: ' . CSP_TARGET_POLICY,
        'report-only' => 'Content-Security-Policy-Report-Only: ' . csp_report_only_header_value(),
        default => null,
    };
}
