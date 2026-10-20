<?php
/**
 * Datei: inc/app/publish/PublishService.php
 * Zweck: Application Service "Publish All" (Veröffentlichung geänderter Zonen bzw. Full-Rebuild).
 *
 * Der Service kennt keine Web-Schicht: keine Session, kein HTTP, keine Redirects, keine Toasts,
 * keine Sprachdateien, kein HTML, kein common.php und keine globale PDO-Verbindung. Alle
 * Abhängigkeiten werden explizit übergeben:
 * - PDO:            Verbindung für Advisory-Lock und Flag-Verwaltung (ERRMODE_EXCEPTION, Autocommit)
 * - PublishBackend: Zonengenerierung, Zonen-/Conf-Verteilung und Orphan-Cleanup
 * - Logger:         optionales callable(string $level, string $message) für Laufzeit-Logereignisse
 *
 * Ablauf (fachlich unverändert aus actions/publish_all.php übernommen):
 * 1. Autorisierung anhand des Actors (admin, zoneadmin) - ohne Lock und ohne Datenbankänderung.
 * 2. Globaler Advisory-Lock GET_LOCK('dnsmanager_publish_all', 0) auf der übergebenen Verbindung.
 *    Derselbe Lock dient zone_add/zone_update/zone_delete/server_update als Topologie-Lock.
 *    Ohne Lock werden keine Flags verändert und nichts veröffentlicht.
 * 3. bind_dirty 1|2 -> 2 übernehmen; bei übernommenem Full-Rebuild alle Zonen, sonst changed <> 0.
 * 4. Pro Zone: changed 1|2 -> 2 übernehmen, generieren/validieren (SOA-Serial), Zone und Conf verteilen.
 * 5. Ohne Fehler: Orphan-Cleanup (nur ohne Teilverteilung, auch bei leerer Zonenliste); Retry über
 *    bind_dirty = 1 nach Cleanup-Fehler bzw. Teilverteilung im Full-Rebuild; ausschließlich das
 *    übernommene 2 -> 0 zurücksetzen; teilweise verteilte Zonen auf changed = 1.
 * 6. Bei Fehler oder Exception: übernommene Flags 2 -> 1 wiederherstellen.
 * 7. Lock-Freigabe im finally. Bei hartem Prozessabbruch gibt MariaDB den Lock mit dem
 *    Verbindungsende frei; übernommene Flags (2) werden beim nächsten Lauf erneut verarbeitet.
 *
 * Flag-Semantik von zones.changed und system_status.bind_dirty (TINYINT; bewusst kein reiner Boolean):
 *   0 = sauber
 *   1 = Änderung wartet auf Publish (wird von allen ändernden Aktionen geschrieben)
 *   2 = von einem Publish-Lauf übernommen bzw. Publish nicht sicher abgeschlossen
 * 2 ist kein Lock und kein Besitzanspruch, sondern ein persistenter Pending-Zustand: Nach einem harten
 * Abbruch bleibt 2 stehen und wird beim nächsten Lauf wie 1 erneut verarbeitet. Eine neue Änderung
 * während eines Laufs schreibt wieder 1 und überschreibt damit die 2. Ein Lauf setzt ausschließlich
 * 2 -> 0 zurück, niemals 1 -> 0. Da Status 2 keinen Owner trägt, serialisiert der Advisory-Lock die Läufe.
 *
 * Es gibt bewusst keine äußere Transaktion: Jede Anweisung läuft im Autocommit, nur die
 * Zonengenerierung verwendet ihre eigene Transaktion. Ein Aufruf innerhalb einer offenen
 * Transaktion wird abgewiesen.
 *
 * Hinweis für langlebige Prozesse (z. B. MCP): Der Advisory-Lock ist an die Verbindung gebunden
 * und in MariaDB pro Verbindung reentrant. Pro Lauf eine eigene, nicht parallel genutzte
 * Verbindung verwenden.
 *
 * Ergebnisvertrag: siehe publishAll().
 */

require_once __DIR__ . '/../Actor.php';
require_once __DIR__ . '/../ServiceMessage.php';
require_once __DIR__ . '/../ServiceResult.php';
require_once __DIR__ . '/PublishBackend.php';

final class PublishService
{
    /** Globaler Publish-/Topologie-Lock (identisch in zone_add, zone_update, zone_delete, server_update) */
    public const LOCK_NAME = 'dnsmanager_publish_all';

    /** Rollen, die Publish All ausführen dürfen (unverändert: global, ohne Zonenbezug) */
    private const ALLOWED_ROLES = ['admin', 'zoneadmin'];

    private PDO $pdo;
    private PublishBackend $backend;
    private Closure $logger;

    /**
     * @param PDO            $pdo     Verbindung mit PDO::ERRMODE_EXCEPTION
     * @param PublishBackend $backend Infrastruktur (muss dieselbe Verbindung verwenden)
     * @param callable|null  $logger  callable(string $level, string $message): void
     */
    public function __construct(PDO $pdo, PublishBackend $backend, ?callable $logger = null)
    {
        if ((int)$pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PublishService benötigt eine PDO-Verbindung mit PDO::ERRMODE_EXCEPTION.');
        }

        $this->pdo = $pdo;
        $this->backend = $backend;
        $this->logger = $logger !== null
            ? Closure::fromCallable($logger)
            : static function (string $level, string $message): void {
            };
    }

    private function log(string $level, string $message): void
    {
        ($this->logger)($level, $message);
    }

    /**
     * Veröffentlicht alle ausstehenden Zonen (bzw. alle Zonen bei übernommenem bind_dirty).
     *
     * Ergebnisvertrag:
     * - ok = true:  Lauf ohne fatalen Publish-Fehler abgeschlossen (Status 'success' oder 'warning').
     *               Das bedeutet NICHT zwingend eine vollständige DNS-Synchronisation: Teilverteilungen
     *               und Cleanup-Probleme sind Warnungen und bleiben über die Flags zum Retry vorgemerkt.
     * - ok = false: Status 'error', 'exception', 'locked', 'lock_failed', 'denied' oder 'invalid_state'.
     * - messages:   Ereignisse in Ablaufreihenfolge (Codes publish.*), Kontext ausschließlich Rohdaten.
     *               Die letzte Meldung ist immer genau eine 'publish.result' mit dem Gesamtergebnis.
     * - Technische Detailtexte (z. B. aus named-checkzone oder den Agenten) werden unverändert als
     *   Rohdaten weitergereicht; der Service erzeugt keine übersetzten Texte und kein HTML.
     */
    public function publishAll(Actor $actor): ServiceResult
    {
        $messages = [];

        // 1. Autorisierung (ohne Lock, ohne Datenbankzugriff)
        if (!in_array($actor->role, self::ALLOWED_ROLES, true)) {
            $messages[] = new ServiceMessage('audit', 'publish.access_denied', [
                'userId' => $actor->userId,
                'role'   => $actor->role,
            ]);
            return $this->finish($messages, $this->emptyReport('denied'));
        }

        // Keine äußere Transaktion: Publish All arbeitet im Autocommit (Generator nutzt eigene Transaktionen)
        if ($this->pdo->inTransaction()) {
            $messages[] = new ServiceMessage('error', 'publish.invalid_state', [
                'detail' => 'Publish All darf nicht innerhalb einer offenen Transaktion ausgeführt werden.',
            ]);
            return $this->finish($messages, $this->emptyReport('invalid_state'));
        }

        // 2. Globalen Publish-Lock beziehen (Timeout 0, kein Warten)
        try {
            $stmtLock = $this->pdo->prepare("SELECT GET_LOCK(?, 0)");
            $stmtLock->execute([self::LOCK_NAME]);
            $lockResult = $stmtLock->fetchColumn(); // 1 = erhalten, 0 = belegt, NULL = Fehler
            $lockAcquired = $lockResult !== null && (int)$lockResult === 1;
        } catch (Throwable $e) {
            $messages[] = new ServiceMessage('error', 'publish.lock_failed', [
                'lockName' => self::LOCK_NAME,
                'detail'   => $e->getMessage(),
            ]);
            $report = $this->emptyReport('lock_failed');
            $report['lock'] = ['name' => self::LOCK_NAME, 'acquired' => false, 'released' => null, 'releaseResult' => null, 'releaseException' => false, 'releaseError' => null];
            return $this->finish($messages, $report);
        }

        if (!$lockAcquired) {
            $messages[] = new ServiceMessage('warning', 'publish.locked', [
                'lockName'   => self::LOCK_NAME,
                'lockResult' => $lockResult === null || $lockResult === false ? null : (int)$lockResult,
            ]);
            $report = $this->emptyReport('locked');
            $report['lock'] = ['name' => self::LOCK_NAME, 'acquired' => false, 'released' => null, 'releaseResult' => null, 'releaseException' => false, 'releaseError' => null];
            return $this->finish($messages, $report);
        }

        // Laufzustand
        $errors = [];             // fatale Fehler (Lauf scheitert, Flags werden wiederhergestellt)
        $warnings = [];           // Warnungen (Lauf erfolgreich, ggf. Teilverteilung/Retry)
        $publishedZoneIds = [];   // vollständig verteilt (Zone + Conf)
        $partialZoneIds = [];     // Teilfehler bei Zone- oder Conf-Verteilung
        $claimedZoneIds = [];     // Zonen, deren changed dieser Lauf auf 2 zu übernehmen versucht hat
        $pendingClaimedIds = [];  // Zonen, die nach der Übernahme tatsächlich changed = 2 hatten
        $zoneReports = [];
        $bindDirtyClaimed = false;
        $fullRebuild = null;
        $cleanup = ['ran' => false, 'status' => null, 'errors' => [], 'output' => ''];
        $flags = [
            'outcome'              => 'untouched', // committed | restored | restore_failed
            'bindDirtyClaimed'     => false,
            'bindDirty'            => 'not_claimed', // reset | kept_pending | retry_requested | restored | not_restored | not_claimed
            'bindDirtyRetryReason' => null,          // cleanup_failed | partial_full_rebuild
            'claimedZoneIds'       => [],
            'resetZoneIds'         => [],
            'keptPendingZoneIds'   => [],
            'markedPendingZoneIds' => [],
            'restoredZoneIds'      => [],
        ];
        $currentZoneReport = null;
        $status = null;
        $exceptionDetail = null;
        $rolledBackOpenTransaction = false;
        $pending = null;
        $lock = ['name' => self::LOCK_NAME, 'acquired' => true, 'released' => null, 'releaseResult' => null, 'releaseException' => false, 'releaseError' => null];

        // Übernommene Flags bei kontrolliertem Fehler wieder auf 1 setzen (2 -> 1), soweit die DB erreichbar ist.
        // Scheitert das, bleibt 2 als beim nächsten Lauf erneut verarbeiteter Zustand stehen.
        $restorePendingFlags = function () use (&$claimedZoneIds, &$bindDirtyClaimed, &$flags): void {
            try {
                $stmtRestore = $this->pdo->prepare("UPDATE zones SET changed = 1 WHERE id = ? AND changed = 2");
                foreach ($claimedZoneIds as $cid) {
                    $stmtRestore->execute([$cid]);
                    if ($stmtRestore->rowCount() > 0) {
                        $flags['restoredZoneIds'][] = $cid;
                    }
                }
                if ($bindDirtyClaimed) {
                    $restored = $this->pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1 AND bind_dirty = 2");
                    $flags['bindDirty'] = $restored > 0 ? 'restored' : 'not_restored';
                }
                $flags['outcome'] = 'restored';
            } catch (Throwable $e) {
                $flags['outcome'] = 'restore_failed';
                $this->log('error', "[publish] Publish-Flags konnten nicht wiederhergestellt werden (Status 2 bleibt erhalten): " . $e->getMessage());
            }
        };

        try {
            try {
                // 3. Ausstehenden Full-Rebuild übernehmen: 1 oder 2 -> 2 (Status wird gelesen, nicht per rowCount)
                $this->pdo->exec("UPDATE system_status SET bind_dirty = 2 WHERE id = 1 AND bind_dirty <> 0");
                $stmt = $this->pdo->prepare("SELECT bind_dirty FROM system_status WHERE id = :id");
                $stmt->execute(['id' => 1]);
                $bindDirtyClaimed = (int)$stmt->fetchColumn() !== 0;
                $fullRebuild = $bindDirtyClaimed;
                $flags['bindDirtyClaimed'] = $bindDirtyClaimed;

                // Betroffene Zonen
                if ($bindDirtyClaimed) {
                    $stmt = $this->pdo->prepare("SELECT id, name FROM zones");
                } else {
                    // changed 1 und 2 gelten als ausstehend
                    $stmt = $this->pdo->prepare("SELECT id, name FROM zones WHERE changed <> 0");
                }
                $stmt->execute();
                $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($zones as $zone) {
                    $zoneId = (int)$zone['id'];
                    $zoneName = (string)$zone['name'];
                    $zoneReport = ['id' => $zoneId, 'name' => $zoneName, 'outcome' => null];
                    $currentZoneReport = $zoneReport;

                    // 4. changed vor der Generierung übernehmen: 1 oder 2 -> 2
                    $claimedZoneIds[] = $zoneId;
                    $flags['claimedZoneIds'] = $claimedZoneIds;
                    try {
                        $this->pdo->prepare("UPDATE zones SET changed = 2 WHERE id = ? AND changed <> 0")->execute([$zoneId]);
                        $stmtChanged = $this->pdo->prepare("SELECT changed FROM zones WHERE id = ?");
                        $stmtChanged->execute([$zoneId]);
                        $zoneChanged = $stmtChanged->fetchColumn();
                    } catch (Throwable $e) {
                        $errors[] = "Zone $zoneName: Publish-Status konnte nicht übernommen werden: " . $e->getMessage();
                        $this->log('error', "[publish] Zone $zoneName: Publish-Status konnte nicht übernommen werden: " . $e->getMessage());
                        $messages[] = new ServiceMessage('error', 'publish.zone_claim_failed', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'detail'   => $e->getMessage(),
                        ]);
                        $zoneReport['outcome'] = 'claim_failed';
                        $zoneReports[] = $zoneReport;
                        $currentZoneReport = null;
                        continue;
                    }

                    if ($zoneChanged !== false && (int)$zoneChanged === 2) {
                        $pendingClaimedIds[] = $zoneId;
                    }

                    // Normaler changed-Lauf: Zone überspringen, wenn sie inzwischen veröffentlicht (0) oder gelöscht wurde
                    if (!$bindDirtyClaimed && ($zoneChanged === false || (int)$zoneChanged === 0)) {
                        $zoneReport['outcome'] = 'skipped';
                        $zoneReports[] = $zoneReport;
                        $currentZoneReport = null;
                        continue;
                    }

                    // Zonendatei generieren und validieren (SOA-Serial in eigener Transaktion des Generators)
                    $validation = $this->backend->generateZone($zoneId);

                    if (($validation['status'] ?? null) === 'error') {
                        $errors[] = "Zone $zoneName: " . ($validation['output'] ?? '');
                        $messages[] = new ServiceMessage('error', 'publish.zone_generation_failed', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'output'   => (string)($validation['output'] ?? ''),
                        ]);
                        $zoneReport['outcome'] = 'generation_failed';
                        $zoneReports[] = $zoneReport;
                        $currentZoneReport = null;
                        continue;
                    }

                    if (($validation['status'] ?? null) === 'warning') {
                        $warnings[] = "Zone $zoneName: " . ($validation['output'] ?? '');
                        $messages[] = new ServiceMessage('warning', 'publish.zone_generation_warning', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'output'   => (string)($validation['output'] ?? ''),
                        ]);
                    }

                    $path = $validation['path'] ?? null;

                    // Zonendatei verteilen
                    $distResult = $this->backend->distributeZone($zoneId, $path);
                    $distErrors = (array)($distResult['errors'] ?? []);
                    $zoneFailed = false;
                    if (($distResult['status'] ?? null) === 'error') {
                        foreach ($distErrors as $e) {
                            $errors[] = "Zone $zoneName: $e";
                            $this->log('error', "[publish] $e");
                        }
                        $zoneFailed = true;
                        $messages[] = new ServiceMessage('error', 'publish.zone_distribution_failed', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'errors'   => array_values($distErrors),
                        ]);
                    } elseif (($distResult['status'] ?? null) === 'warning') {
                        foreach ($distErrors as $e) {
                            $warnings[] = "Zone $zoneName: $e";
                            $this->log('warning', "[publish] $e");
                        }
                        $messages[] = new ServiceMessage('warning', 'publish.zone_distribution_warning', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'errors'   => array_values($distErrors),
                        ]);
                    }
                    if (!empty($distResult['output'])) {
                        $this->log('info', "[publish] {$distResult['output']}");
                    }

                    // Konfigurationsdatei (.conf) erzeugen und verteilen
                    $confResult = $this->backend->distributeZoneConf($zoneName);
                    $confErrors = (array)($confResult['errors'] ?? []);
                    $confStatus = $confResult['status'] ?? '';
                    $confFailed = false;
                    $confWarned = false;
                    if (!empty($confErrors)) {
                        foreach ($confErrors as $e) {
                            $warnings[] = "Zone $zoneName (conf): $e";
                            $this->log('warning', "[publish] $e");
                        }
                        $confWarned = true;
                    }
                    if (!empty($confResult['output'])) {
                        if ($confStatus === 'warning') {
                            $warnings[] = "Zone $zoneName (conf): " . $confResult['output'];
                            $this->log('warning', "[publish] " . $confResult['output']);
                            $confWarned = true;
                        } elseif ($confStatus === 'error') {
                            $errors[] = "Zone $zoneName (conf): " . $confResult['output'];
                            $this->log('error', "[publish] " . $confResult['output']);
                            $confFailed = true;
                        } else {
                            $this->log('info', "[publish] " . $confResult['output']);
                        }
                    }
                    if ($confFailed) {
                        $zoneFailed = true;
                        $messages[] = new ServiceMessage('error', 'publish.zone_conf_failed', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'errors'   => array_values($confErrors),
                            'output'   => (string)$confResult['output'],
                        ]);
                    } elseif ($confWarned) {
                        $messages[] = new ServiceMessage('warning', 'publish.zone_conf_warning', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                            'errors'   => array_values($confErrors),
                            'output'   => (string)($confResult['output'] ?? ''),
                        ]);
                    }

                    // Zone nur als veröffentlicht werten, wenn Zone- und Conf-Verteilung vollständig erfolgreich waren
                    $confOk = $confStatus === 'ok' && empty($confErrors);
                    if (($distResult['status'] ?? '') === 'ok' && $confOk) {
                        $publishedZoneIds[] = $zoneId;
                        $zoneReport['outcome'] = 'published';
                        $messages[] = new ServiceMessage('success', 'publish.zone_published', [
                            'zoneId'   => $zoneId,
                            'zoneName' => $zoneName,
                        ]);
                    } else {
                        $partialZoneIds[] = $zoneId;
                        $zoneReport['outcome'] = $zoneFailed ? 'failed' : 'partial';
                        if (!$zoneFailed) {
                            $messages[] = new ServiceMessage('warning', 'publish.zone_partial', [
                                'zoneId'   => $zoneId,
                                'zoneName' => $zoneName,
                            ]);
                        }
                    }
                    $zoneReports[] = $zoneReport;
                    $currentZoneReport = null;
                }

                if (empty($errors)) {
                    if (empty($partialZoneIds)) {
                        // 5. Orphan-Cleanup nur nach vollständig erfolgreicher Verteilung aller Zonen dieses Laufs
                        //    (auch bei leerer Zonenliste und ohne ausstehende Änderungen)
                        $cleanupResult = $this->backend->cleanupOrphans();
                        $cleanup['ran'] = true;
                        $cleanup['status'] = $cleanupResult['status'] ?? null;
                        $cleanup['output'] = (string)($cleanupResult['output'] ?? '');
                        if (($cleanupResult['status'] ?? '') !== 'ok') {
                            // Cleanup-Fehler = Warnung; bind_dirty = 1 erzwingt Retry (Full-Rebuild + Cleanup)
                            $cleanupErrors = $cleanupResult['errors'] ?? [];
                            if (empty($cleanupErrors)) {
                                $cleanupErrors = ['Zonen-Cleanup nicht vollständig erfolgreich'];
                            }
                            foreach ($cleanupErrors as $e) {
                                $warnings[] = "Cleanup: $e";
                                $this->log('warning', "[publish] Cleanup: $e");
                            }
                            $cleanup['errors'] = array_values((array)$cleanupErrors);
                            $messages[] = new ServiceMessage('warning', 'publish.cleanup_warning', [
                                'errors' => $cleanup['errors'],
                            ]);
                            $this->pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
                            $flags['bindDirtyRetryReason'] = 'cleanup_failed';
                        } elseif (!empty($cleanupResult['output'])) {
                            $this->log('info', "[publish] Cleanup: " . $cleanupResult['output']);
                        }
                    } elseif ($bindDirtyClaimed) {
                        // Übernommener Full-Rebuild mit Teilverteilung: Cleanup wurde nicht ausgeführt.
                        // bind_dirty = 1, damit Full-Rebuild + Cleanup beim nächsten Publish ausstehen.
                        $this->pdo->exec("UPDATE system_status SET bind_dirty = 1 WHERE id = 1");
                        $flags['bindDirtyRetryReason'] = 'partial_full_rebuild';
                    }

                    // Flags zurücksetzen: ausschließlich das übernommene 2 -> 0 (ein neu gesetztes 1 bleibt stehen)
                    if ($bindDirtyClaimed) {
                        $bindDirtyReset = $this->pdo->exec("UPDATE system_status SET bind_dirty = 0 WHERE id = 1 AND bind_dirty = 2");
                        $flags['bindDirty'] = $bindDirtyReset > 0 ? 'reset' : 'kept_pending';
                    }
                    // Vom Lauf angeforderter Retry (bind_dirty = 1) hat Vorrang - auch wenn bind_dirty vorher 0 war
                    if ($flags['bindDirtyRetryReason'] !== null) {
                        $flags['bindDirty'] = 'retry_requested';
                    }
                    $stmtReset = $this->pdo->prepare("UPDATE zones SET changed = 0 WHERE id = ? AND changed = 2");
                    foreach ($publishedZoneIds as $pid) {
                        $stmtReset->execute([$pid]);
                        if ($stmtReset->rowCount() > 0) {
                            $flags['resetZoneIds'][] = $pid;
                        } elseif (in_array($pid, $pendingClaimedIds, true)) {
                            // Während des Laufs erneut geändert (changed = 1): bleibt ausstehend
                            $flags['keptPendingZoneIds'][] = $pid;
                        }
                    }

                    // Teilweise verteilte Zonen für einen späteren Publish markiert lassen
                    $stmtMark = $this->pdo->prepare("UPDATE zones SET changed = 1 WHERE id = ?");
                    foreach ($partialZoneIds as $pid) {
                        $stmtMark->execute([$pid]);
                    }

                    $flags['outcome'] = 'committed';
                    $flags['markedPendingZoneIds'] = $partialZoneIds;

                    $status = empty($warnings) ? 'success' : 'warning';
                } else {
                    // 6. Fehler im Lauf: übernommene Flags wieder als ausstehend (1) markieren
                    $restorePendingFlags();
                    $status = 'error';
                }
            } catch (Throwable $e) {
                // Systemfehler: eine vom Backend offen gelassene Transaktion zurückrollen, damit die
                // Wiederherstellung nicht in einer fremden Transaktion verloren geht
                try {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                        $rolledBackOpenTransaction = true;
                    }
                } catch (Throwable $rollbackError) {
                    // Rollback nicht möglich: Wiederherstellung wird dennoch versucht
                }

                if ($currentZoneReport !== null) {
                    $currentZoneReport['outcome'] = 'aborted';
                    $zoneReports[] = $currentZoneReport;
                    $currentZoneReport = null;
                }

                $restorePendingFlags();
                $status = 'exception';
                $exceptionDetail = $e->getMessage();
                $messages[] = new ServiceMessage('error', 'publish.exception', [
                    'detail' => $exceptionDetail,
                ]);
            }

            // Tatsächlich ausstehende Änderungen nach dem Lauf (lesend, ohne Seiteneffekt)
            $pending = $this->readPending();
        } finally {
            // 7. Publish-Lock freigeben (bei hartem Abbruch: Verbindungsende)
            // Nicht hier protokolliert: Der Aufrufer meldet eine nicht bestätigte Freigabe nach dem
            // Ergebnis (Reihenfolge wie bisher: Ergebnis-Meldungen, danach Freigabe-Warnung).
            try {
                $stmtUnlock = $this->pdo->prepare("SELECT RELEASE_LOCK(?)");
                $stmtUnlock->execute([self::LOCK_NAME]);
                $releaseResult = $stmtUnlock->fetchColumn(); // 1 = freigegeben, 0 = von anderer Verbindung gehalten, NULL = Lock existiert nicht
                $lock['releaseResult'] = ($releaseResult === null || $releaseResult === false) ? null : (int)$releaseResult;
                if ($releaseResult !== null && $releaseResult !== false && (int)$releaseResult === 1) {
                    $lock['released'] = true;
                } else {
                    $lock['released'] = false;
                    $lock['releaseError'] = match (true) {
                        $releaseResult === false => 'RELEASE_LOCK lieferte kein Ergebnis',
                        $releaseResult === null  => 'RELEASE_LOCK = NULL: Lock existiert nicht (mehr)',
                        default                  => 'RELEASE_LOCK = ' . (int)$releaseResult . ': Lock wird nicht von dieser Verbindung gehalten',
                    };
                }
            } catch (Throwable $e) {
                $lock['released'] = false;
                $lock['releaseException'] = true;
                $lock['releaseError'] = $e->getMessage();
            }
        }

        $report = [
            'status'                    => $status,
            'fullRebuild'               => $fullRebuild,
            'zones'                     => $zoneReports,
            'publishedZoneIds'          => $publishedZoneIds,
            'partialZoneIds'            => $partialZoneIds,
            'cleanup'                   => $cleanup,
            'flags'                     => $flags,
            'pending'                   => $pending,
            'errors'                    => $errors,
            'warnings'                  => $warnings,
            'exception'                 => $exceptionDetail,
            'rolledBackOpenTransaction' => $rolledBackOpenTransaction,
            'lock'                      => $lock,
        ];

        return $this->finish($messages, $report);
    }

    /**
     * Liest den tatsächlichen Flag-Zustand nach dem Lauf.
     *
     * @return array{bindDirty: int, changedZoneIds: int[], requiresPublish: bool}|null null, wenn nicht lesbar
     */
    private function readPending(): ?array
    {
        try {
            $bindDirty = (int)$this->pdo->query("SELECT bind_dirty FROM system_status WHERE id = 1")->fetchColumn();
            $changed = array_map('intval', $this->pdo->query("SELECT id FROM zones WHERE changed <> 0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
            return [
                'bindDirty'       => $bindDirty,
                'changedZoneIds'  => $changed,
                'requiresPublish' => $bindDirty !== 0 || !empty($changed),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Bericht für Läufe ohne Lock (denied, invalid_state, locked, lock_failed).
     */
    private function emptyReport(string $status): array
    {
        return [
            'status'                    => $status,
            'fullRebuild'               => null,
            'zones'                     => [],
            'publishedZoneIds'          => [],
            'partialZoneIds'            => [],
            'cleanup'                   => ['ran' => false, 'status' => null, 'errors' => [], 'output' => ''],
            'flags'                     => [
                'outcome'              => 'untouched',
                'bindDirtyClaimed'     => false,
                'bindDirty'            => 'not_claimed',
                'bindDirtyRetryReason' => null,
                'claimedZoneIds'       => [],
                'resetZoneIds'         => [],
                'keptPendingZoneIds'   => [],
                'markedPendingZoneIds' => [],
                'restoredZoneIds'      => [],
            ],
            'pending'                   => null,
            'errors'                    => [],
            'warnings'                  => [],
            'exception'                 => null,
            'rolledBackOpenTransaction' => false,
            'lock'                      => ['name' => self::LOCK_NAME, 'acquired' => false, 'released' => null, 'releaseResult' => null, 'releaseException' => false, 'releaseError' => null],
        ];
    }

    /**
     * Hängt die abschließende publish.result-Meldung an und erzeugt das ServiceResult.
     *
     * @param ServiceMessage[] $messages
     */
    private function finish(array $messages, array $report): ServiceResult
    {
        $ok = in_array($report['status'], ['success', 'warning'], true);
        $kind = match ($report['status']) {
            'success'           => 'success',
            'warning', 'locked' => 'warning',
            'denied'            => 'audit',
            default             => 'error',
        };
        $messages[] = new ServiceMessage($kind, 'publish.result', $report);

        return new ServiceResult($ok, $messages, null);
    }
}
