<?php
/**
 * Datei: inc/web/dyndns_messages.php
 * Zweck: Web-Darstellung der DynDNS-ServiceMessages: Zuordnung der fachlichen Fehler-/Hinweiscodes des
 *        DyndnsAccountService zu Sprachschlüsseln und Aufbau der Toast-Texte.
 *
 * Ergibt dieselben Toast-Texte wie bisher in den DynDNS-Actions: Platzhalterwerte werden HTML-maskiert,
 * Meldungen ohne Platzhalter unverändert aus dem Sprachpaket übernommen.
 */

/**
 * Sprachschlüssel zu einem fachlichen Code des DyndnsAccountService.
 *
 * Codes aus inc/dyndns_guard.php (DYNDNS_ZONE_NOT_ALLOWED, DYNDNS_ZONE_NOT_FORWARD, DYNDNS_HOST_*,
 * DYNDNS_CONFLICT_*) entsprechen wie bisher dem kleingeschriebenen Sprachschlüssel; die übrigen Codes sind
 * hier ausdrücklich zugeordnet.
 */
function dyndnsLangKeyForReason(string $reason): string
{
    $map = [
        'DYNDNS_ZONE_NOT_FOUND'         => 'dyndns_error_zone_not_allowed',
        'DYNDNS_ACCOUNT_NOT_FOUND'      => 'dyndns_error_not_found',
        'DYNDNS_CONCURRENT_CHANGE'      => 'dyndns_error_concurrent_change',
        'ZONE_REBUILD_FAILED'           => 'zone_rebuild_failed',
        'ZONE_REBUILD_WARNING'          => 'zone_rebuild_warning',
        'DYNDNS_SHARED_HOSTNAME_KEPT'   => 'dyndns_shared_hostname_kept',
        'DYNDNS_RECORDS_KEPT_AMBIGUOUS' => 'dyndns_records_kept_ambiguous',
        'DYNDNS_NO_IP'                  => 'dyndns_no_ip_warning',
    ];

    return $map[$reason] ?? strtolower($reason);
}

/**
 * Toast-Text einer Ablehnung ("dyndns.<op>.rejected": reason, param).
 *
 * @param array<string, string> $lang
 * @param array{reason: string, param: string|int|null} $ctx
 */
function dyndnsToast(array $lang, array $ctx): string
{
    $key = dyndnsLangKeyForReason($ctx['reason']);

    return $ctx['param'] === null
        ? $lang[$key]
        : sprintf($lang[$key], htmlspecialchars((string)$ctx['param']));
}

/**
 * Toast-Text eines Hinweises ("dyndns.<op>.warning": reason, params).
 *
 * @param array<string, string> $lang
 * @param array{reason: string, params: array<int, string|int>} $ctx
 */
function dyndnsWarningToast(array $lang, array $ctx): string
{
    $key = dyndnsLangKeyForReason($ctx['reason']);

    if ($ctx['params'] === []) {
        return $lang[$key];
    }

    return sprintf($lang[$key], ...array_map(fn($p) => htmlspecialchars((string)$p), $ctx['params']));
}
