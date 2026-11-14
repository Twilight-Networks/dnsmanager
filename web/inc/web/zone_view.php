<?php
/**
 * Datei: inc/web/zone_view.php
 * Zweck: Web-Hilfen für Zonenliste und Dashboard (U5a). Ausschließlich Darstellung über vorhandene Read-Services:
 *        - vollständige Zonenliste über die bestehende Pagination von ZoneReadService::listZones();
 *        - Anzeigestatus je Zone ausschließlich über ZoneReadService::zoneDisplayStatus() (Diagnosestatus aus
 *          getZoneStatusOverview() und pendingPublish); keine eigenen Statusregeln;
 *        - Kennzeichen (Chips) für Status, ausstehende Veröffentlichung, Zonentyp und Serverzuweisung.
 */

require_once __DIR__ . '/../app/zones/ZoneReadService.php';

/**
 * Alle für den Akteur sichtbaren Zonen mit Anzeigestatus.
 *
 * @return array{zones: array<int, array>, overview: array} zones: Felder aus listZones() plus status
 */
function zoneViewAll(ZoneReadService $zoneReadService, Actor $actor): array
{
    $overview = $zoneReadService->getZoneStatusOverview($actor);
    $zones = [];
    $offset = 0;
    do {
        $page = $zoneReadService->listZones($actor, ZoneReadService::MAX_LIMIT, $offset);
        foreach ($page['zones'] as $z) {
            $z['status'] = ZoneReadService::zoneDisplayStatus($overview['zones'][$z['id']]['status'] ?? null, $z['pendingPublish']);
            $zones[] = $z;
        }
        $offset += ZoneReadService::MAX_LIMIT;
    } while ($page['hasMore']);
    return ['zones' => $zones, 'overview' => $overview];
}

/**
 * Statuskennzeichen einer Zone: Anzeigestatus (Fehler, Warnung, ausstehend, gültig) wie bisher; bei Diagnosefehlern
 * oder -warnungen wird eine zugleich ausstehende Veröffentlichung zusätzlich gezeigt (beide Angaben aus dem Core).
 */
function zoneViewStatusHtml(array $zone): string
{
    global $LANG;
    $map = [
        'error'   => ['err', 'x-circle', 'zone_status_error', 'zone_icon_error'],
        'warning' => ['warn', 'exclamation-triangle', 'zone_status_warning', 'zone_icon_warning'],
        'changed' => ['info', 'cloud-arrow-up', 'zone_status_changed', 'zone_icon_changed'],
        'ok'      => ['ok', 'check-circle', 'zone_status_ok', 'zone_icon_ok'],
    ];
    [$variant, $icon, $label, $title] = $map[$zone['status']] ?? $map['ok'];
    $html = '<span class="dm-zone-status" title="' . ui_e($LANG[$title]) . '">' . ui_chip($LANG[$label], $variant, $icon) . '</span>';
    if ($zone['pendingPublish'] && $zone['status'] !== 'changed') {
        $html .= ' <span title="' . ui_e($LANG['zone_icon_changed']) . '">' . ui_chip($LANG['zone_status_changed'], 'info', 'cloud-arrow-up') . '</span>';
    }
    return $html;
}

/** Serverzuweisung als Kennzeichen (Master/Slave, inaktiv) */
function zoneViewServersHtml(array $zone): string
{
    global $LANG;
    $html = '';
    foreach ($zone['servers'] as $srv) {
        $isMaster = $srv['role'] === 'master';
        $text = ($isMaster ? 'Master' : 'Slave') . ': ' . $srv['name'] . ($srv['active'] ? '' : ' (' . $LANG['inactive'] . ')');
        $html .= '<span class="dm-server-chip' . ($srv['active'] ? '' : ' dm-inactive') . '"' . ($srv['active'] ? '' : ' title="' . ui_e($LANG['server_inactive']) . '"') . '>'
            . ui_chip($text, $isMaster ? 'accent' : 'neutral') . '</span> ';
    }
    return trim($html);
}
