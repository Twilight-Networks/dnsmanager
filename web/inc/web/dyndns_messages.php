<?php
/**
 * Datei: inc/web/dyndns_messages.php
 * Zweck: Web-Darstellung der DynDNS-ServiceMessages (Toast-Texte aus Sprachschlüssel und Rohwerten).
 *
 * Ergibt dieselben Toast-Texte wie bisher in den DynDNS-Actions: Platzhalterwerte werden HTML-maskiert,
 * Meldungen ohne Platzhalter unverändert aus dem Sprachpaket übernommen.
 */

/**
 * Toast-Text einer Ablehnung ("dyndns.<op>.rejected": key, param).
 *
 * @param array<string, string> $lang
 * @param array{key: string, param: string|int|null} $ctx
 */
function dyndnsToast(array $lang, array $ctx): string
{
    return $ctx['param'] === null
        ? $lang[$ctx['key']]
        : sprintf($lang[$ctx['key']], htmlspecialchars((string)$ctx['param']));
}

/**
 * Toast-Text eines Hinweises ("dyndns.<op>.warning": key, params).
 *
 * @param array<string, string> $lang
 * @param array{key: string, params: array<int, string|int>} $ctx
 */
function dyndnsWarningToast(array $lang, array $ctx): string
{
    if ($ctx['params'] === []) {
        return $lang[$ctx['key']];
    }

    return sprintf($lang[$ctx['key']], ...array_map(fn($p) => htmlspecialchars((string)$p), $ctx['params']));
}
