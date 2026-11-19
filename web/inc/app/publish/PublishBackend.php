<?php
/**
 * Datei: inc/app/publish/PublishBackend.php
 * Zweck: Infrastruktur-Schnittstelle des PublishService (Zonengenerierung, Verteilung, Cleanup).
 *
 * Der PublishService kennt weder BIND, named-checkzone noch die HTTP-API der DNS-Agenten.
 * Er arbeitet ausschließlich gegen diese Schnittstelle. Die Rückgabewerte entsprechen bewusst
 * unverändert den bestehenden Ergebnis-Arrays der Legacy-Funktionen, damit deren Status- und
 * Fehlersemantik (ok / warning / error, errors[], output) exakt erhalten bleibt.
 *
 * Implementierungen:
 * - LegacyBindPublishBackend (inc/deploy): ruft die bestehenden Generator-/Distributor-Funktionen auf
 * - Test-Backends (Fake) ohne BIND und Agenten
 *
 * AP1/H2d (E2 additiv, E59–E66): Publish-Journal. Der PublishService bindet nach der Laufregistrierung (J1) mit
 * bindJournal() die Journal-Anbindung des Laufs. Ist ein Journal gebunden,
 * - registriert generateZone() den Versuch in der Generator-Transaktion vor dem Serial-Commit (J2) und liefert
 *   zusätzlich 'journal' => [attemptId, runId, zoneId, zoneName, revisionNo, revisionBasis, contentHash, renderHash,
 *   fileHash, serial, targets]; scheitert die Registrierung, ist das Ergebnis 'error' ohne Serial-Änderung;
 * - verteilen distributeZone()/distributeZoneConf() ausschließlich an die registrierte Zielmenge und melden je
 *   Agentenantwort das Ergebnis (J4); distributeZone() sendet nur die Datei mit dem registrierten file_hash;
 * - registriert cleanupOrphans() vor dem ersten Agentaufruf alle Server und meldet je Antwort das Ergebnis; scheitert
 *   die Registrierung, wird kein Cleanup ausgeführt ('warning').
 * Alle drei Verteilmethoden liefern zusätzlich 'targets' => [serverId => Einordnung (PublishTargetClassifier)].
 * Status, errors und output bleiben unverändert (J8). Ohne gebundenes Journal verhalten sich alle Methoden wie bisher.
 */

require_once __DIR__ . '/PublishJournal.php';

interface PublishBackend
{
    /**
     * Bindet die Journal-Anbindung des laufenden Publish-Laufs (null = keine).
     */
    public function bindJournal(?PublishJournal $journal): void;

    /**
     * Erzeugt die Zonendatei einer Zone für die Veröffentlichung und validiert sie
     * (Legacy: generate_zone_file($zoneId, <Arbeitsverzeichnis>, 'publish', $pdo)).
     *
     * Erhöht im Erfolgsfall den SOA-Serial in einer eigenen, vollständig abgeschlossenen
     * Transaktion auf derselben PDO-Verbindung wie der PublishService.
     *
     * @return array{status: string, path?: string, output?: string, errors?: array}
     *               status: 'ok' | 'warning' | 'error'
     */
    public function generateZone(int $zoneId): array;

    /**
     * Verteilt eine erzeugte Zonendatei an alle aktiven, zugewiesenen Server
     * (Legacy: distribute_zone_file($pdo, $agent, $zoneId, $zonePath)).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'warning' (Agent-/HTTP-/Netzwerkfehler) | 'error' (interne Fehler)
     */
    public function distributeZone(int $zoneId, ?string $zonePath): array;

    /**
     * Erzeugt und verteilt die zonenspezifische BIND-Konfiguration
     * (Legacy: distribute_zone_conf_file($pdo, $agent, $work, $zoneName)).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'error'
     */
    public function distributeZoneConf(string $zoneName): array;

    /**
     * Entfernt verwaiste Zonen auf allen aktiven Servern
     * (Legacy: distribute_zone_cleanup($pdo, $agent)).
     *
     * @return array{status: string, errors: array, output: string}
     *               status: 'ok' | 'warning'
     */
    public function cleanupOrphans(): array;
}
