<?php
/**
 * Datei: inc/app/history/ZoneSnapshotDiff.php
 * Zweck: Reiner, deterministischer Vergleich zweier Zonen-Snapshots (AP1/H2a, E22).
 *
 * Keine Datenbank, keine Session, kein Logging.
 *
 * Semantik:
 * - Zonenfelder: jedes abweichende Feld des Vertrags mit altem und neuem Wert (Reihenfolge des Vertrags).
 * - Serverzuweisung: hinzugefügte und entfernte Server sowie geänderte Master-Kennzeichen (aufsteigend nach ID).
 * - Records über den Inhaltsschlüssel (name, type, content) als Multimenge, nie über Record-IDs:
 *   1. Je Schlüssel werden zuerst exakt gleiche Einträge (gleiche ttl und server_id) paarweise entfernt.
 *   2. Verbleibende alte und neue Einträge desselben Schlüssels werden in kanonischer Reihenfolge paarweise als
 *      "changed" (ttl und/oder server_id geändert) zugeordnet; ein Überschuss ist "removed" bzw. "added".
 *   Ergebnislisten sind in kanonischer Record-Reihenfolge sortiert.
 * - Ohne alten Snapshot (Anlage) ist der gesamte Inhalt hinzugefügt; die Zonenfelder erscheinen mit old = null.
 * - Beide Snapshots müssen denselben Vertrag haben.
 */

require_once __DIR__ . '/ZoneStateSnapshot.php';

final class ZoneSnapshotDiff
{
    /**
     * @return array{
     *   zone: array<string, array{old: mixed, new: mixed}>,
     *   servers: array{added: list<array{0: int, 1: int}>, removed: list<array{0: int, 1: int}>,
     *                  changed: list<array{server_id: int, old: int, new: int}>},
     *   records: array{added: list<array>, removed: list<array>,
     *                  changed: list<array{key: array{0: string, 1: string, 2: string}, old: array{0: ?int, 1: ?int},
     *                                      new: array{0: ?int, 1: ?int}}>}
     * }
     */
    public static function between(?ZoneStateSnapshot $old, ZoneStateSnapshot $new): array
    {
        if ($old !== null && $old->contract() !== $new->contract()) {
            throw new InvalidArgumentException('Snapshots mit unterschiedlichem Vertrag sind nicht vergleichbar');
        }
        $o = $old?->data();
        $n = $new->data();

        $zone = [];
        foreach ($n['zone'] as $field => $value) {
            $before = $o === null ? null : $o['zone'][$field];
            if ($o === null || $before !== $value) {
                $zone[$field] = ['old' => $before, 'new' => $value];
            }
        }

        return [
            'zone'    => $zone,
            'servers' => self::diffServers($o === null ? [] : $o['servers'], $n['servers']),
            'records' => self::diffRecords($o === null ? [] : $o['records'], $n['records']),
        ];
    }

    /**
     * Kompakte Zusammenfassung für zone_revisions.diff_summary (nur Feldnamen und Zähler, keine Inhalte).
     *
     * @param array $diff Ergebnis von between()
     *
     * @return array{zone_fields: list<string>, servers: array{added: int, removed: int, changed: int},
     *               records: array{added: int, removed: int, changed: int}}
     */
    public static function summary(array $diff): array
    {
        return [
            'zone_fields' => array_keys($diff['zone']),
            'servers'     => [
                'added'   => count($diff['servers']['added']),
                'removed' => count($diff['servers']['removed']),
                'changed' => count($diff['servers']['changed']),
            ],
            'records'     => [
                'added'   => count($diff['records']['added']),
                'removed' => count($diff['records']['removed']),
                'changed' => count($diff['records']['changed']),
            ],
        ];
    }

    /** Kein inhaltlicher Unterschied? */
    public static function isEmpty(array $diff): bool
    {
        return $diff['zone'] === []
            && $diff['servers']['added'] === [] && $diff['servers']['removed'] === [] && $diff['servers']['changed'] === []
            && $diff['records']['added'] === [] && $diff['records']['removed'] === [] && $diff['records']['changed'] === [];
    }

    /**
     * @param list<array{0: int, 1: int}> $old
     * @param list<array{0: int, 1: int}> $new
     */
    private static function diffServers(array $old, array $new): array
    {
        $o = [];
        foreach ($old as [$id, $master]) {
            $o[$id] = $master;
        }
        $n = [];
        foreach ($new as [$id, $master]) {
            $n[$id] = $master;
        }

        $added = $removed = $changed = [];
        foreach ($n as $id => $master) {
            if (!array_key_exists($id, $o)) {
                $added[] = [$id, $master];
            } elseif ($o[$id] !== $master) {
                $changed[] = ['server_id' => $id, 'old' => $o[$id], 'new' => $master];
            }
        }
        foreach ($o as $id => $master) {
            if (!array_key_exists($id, $n)) {
                $removed[] = [$id, $master];
            }
        }
        $byId = static fn (array $a, array $b): int => $a[0] <=> $b[0];
        usort($added, $byId);
        usort($removed, $byId);
        usort($changed, static fn (array $a, array $b): int => $a['server_id'] <=> $b['server_id']);

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}> $old kanonisch sortiert
     * @param list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}> $new kanonisch sortiert
     */
    private static function diffRecords(array $old, array $new): array
    {
        $groupsOld = self::group($old);
        $groupsNew = self::group($new);

        $added = $removed = $changed = [];
        $keys = array_unique(array_merge(array_keys($groupsOld), array_keys($groupsNew)));
        foreach ($keys as $k) {
            $o = $groupsOld[$k] ?? [];
            $n = $groupsNew[$k] ?? [];
            // 1. exakt gleiche Einträge paarweise entfernen (beide Listen kanonisch sortiert)
            $restO = $restN = [];
            $i = $j = 0;
            while ($i < count($o) && $j < count($n)) {
                $c = ZoneStateSnapshot::compareRecords($o[$i], $n[$j]);
                if ($c === 0) {
                    $i++;
                    $j++;
                } elseif ($c < 0) {
                    $restO[] = $o[$i++];
                } else {
                    $restN[] = $n[$j++];
                }
            }
            for (; $i < count($o); $i++) {
                $restO[] = $o[$i];
            }
            for (; $j < count($n); $j++) {
                $restN[] = $n[$j];
            }
            // 2. paarweise Zuordnung als Änderung, Überschuss als entfernt/hinzugefügt
            $pairs = min(count($restO), count($restN));
            for ($p = 0; $p < $pairs; $p++) {
                $changed[] = [
                    'key' => [$restO[$p][0], $restO[$p][1], $restO[$p][2]],
                    'old' => [$restO[$p][3], $restO[$p][4]],
                    'new' => [$restN[$p][3], $restN[$p][4]],
                ];
            }
            for ($p = $pairs; $p < count($restO); $p++) {
                $removed[] = $restO[$p];
            }
            for ($p = $pairs; $p < count($restN); $p++) {
                $added[] = $restN[$p];
            }
        }

        usort($added, [ZoneStateSnapshot::class, 'compareRecords']);
        usort($removed, [ZoneStateSnapshot::class, 'compareRecords']);
        usort($changed, static function (array $a, array $b): int {
            $ka = [$a['key'][0], $a['key'][1], $a['key'][2], $a['old'][0], $a['old'][1]];
            $kb = [$b['key'][0], $b['key'][1], $b['key'][2], $b['old'][0], $b['old'][1]];
            return ZoneStateSnapshot::compareRecords($ka, $kb);
        });

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Gruppiert Records nach Inhaltsschlüssel; Reihenfolge innerhalb der Gruppe bleibt kanonisch.
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}>>
     */
    private static function group(array $records): array
    {
        $groups = [];
        foreach ($records as $r) {
            // Schlüssel byte-genau und kollisionsfrei (Längenpräfixe)
            $k = strlen($r[0]) . ':' . $r[0] . '|' . strlen($r[1]) . ':' . $r[1] . '|' . strlen($r[2]) . ':' . $r[2];
            $groups[$k][] = $r;
        }

        return $groups;
    }
}
