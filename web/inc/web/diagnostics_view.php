<?php
/**
 * Datei: inc/web/diagnostics_view.php
 * Zweck: Übersetzung der DiagnosticReadService-Übersicht in die bisherige Darstellungsstruktur von
 *        getDiagnosticResults() für die unveränderte Ausgabe auf der Systemstatus-Seite.
 *
 * Ergebnis wie bisher:
 * - 'server' => [serverId => [checkType => ['status', 'message', 'server' => Servername|null]]]
 * - 'zone'   => [['zone' => Zonenname|'(unbekannt)', 'server' => Servername|'(unbekannt)',
 *                 checkType => ['status', 'message']], ...]
 */

/**
 * @param array{serverChecks: array, zoneChecks: array}|null $overview null = keine Berechtigung (leere Struktur)
 * @return array{server: array, zone: array}
 */
function diagnosticsLegacyView(?array $overview): array
{
    $view = ['server' => [], 'zone' => []];
    if ($overview === null) {
        return $view;
    }

    foreach ($overview['serverChecks'] as $serverId => $checks) {
        foreach ($checks as $checkType => $c) {
            $view['server'][$serverId][$checkType] = [
                'status'  => $c['status'],
                'message' => $c['message'],
                'server'  => $c['serverName'],
            ];
        }
    }

    foreach ($overview['zoneChecks'] as $z) {
        $view['zone'][] = [
            'zone'   => $z['zoneName'] ?? '(unbekannt)',
            'server' => $z['serverName'] ?? '(unbekannt)',
            $z['checkType'] => [
                'status'  => $z['status'],
                'message' => $z['message'],
            ],
        ];
    }

    return $view;
}
