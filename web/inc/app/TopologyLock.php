<?php
/**
 * Datei: inc/app/TopologyLock.php
 * Zweck: Topologie-Lock für strukturelle Zonenänderungen (Anlegen, Löschen, Serverzuweisung).
 *
 * Es handelt sich um denselben MariaDB-Advisory-Lock wie bei "Publish All" ('dnsmanager_publish_all').
 * Er wird ohne Warten bezogen (GET_LOCK mit Timeout 0, Try-Lock) und kann deshalb nie Teil eines
 * Wartezyklus (Deadlock) sein. Er ist an die übergebene Verbindung gebunden; bei hartem Prozessabbruch
 * gibt MariaDB ihn mit dem Verbindungsende frei.
 *
 * Keine Session, kein HTTP, kein Logging: Ergebnisse und Fehler werden als Rückgabewerte geliefert;
 * der Aufrufer entscheidet über Meldungen und Protokollierung.
 */

final class TopologyLock
{
    public const NAME = 'dnsmanager_publish_all';

    private PDO $pdo;
    private bool $held = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Versucht, den Lock ohne Warten zu beziehen.
     *
     * @return array{acquired: bool, error: ?string} error = Exception-Meldung beim Bezug (sonst null)
     */
    public function acquire(): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute([self::NAME]);
            $result = $stmt->fetchColumn(); // 1 = erhalten, 0 = belegt, NULL = Fehler
            $this->held = $result !== null && $result !== false && (int)$result === 1;

            return ['acquired' => $this->held, 'error' => null];
        } catch (Throwable $e) {
            $this->held = false;

            return ['acquired' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gibt den Lock frei, sofern er gehalten wird. Ein Freigabeproblem macht den fachlichen Vorgang
     * nicht ungültig (der Lock endet spätestens mit der Verbindung) und wird nur zurückgemeldet.
     *
     * @return array{confirmed: bool, result: mixed, error: ?string}|null null = Lock war nicht gehalten
     */
    public function release(): ?array
    {
        if (!$this->held) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::NAME]);
            $result = $stmt->fetchColumn(); // 1 = freigegeben, 0 = nicht gehalten, NULL = existiert nicht

            return [
                'confirmed' => !($result === null || $result === false || (int)$result !== 1),
                'result'    => $result,
                'error'     => null,
            ];
        } catch (Throwable $e) {
            return ['confirmed' => false, 'result' => null, 'error' => $e->getMessage()];
        } finally {
            // Freigabeversuch abgeschlossen: keine erneuten Versuche
            $this->held = false;
        }
    }

    public function isHeld(): bool
    {
        return $this->held;
    }
}
