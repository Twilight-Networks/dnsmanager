<?php
/**
 * Datei: inc/app/history/HistoryContext.php
 * Zweck: Aufrufkontext einer Änderungsmenge der Historie (AP1/H2b, E30): Akteur, Kanal, Operation und Korrelation.
 *
 * Unveränderliches Wertobjekt ohne Datenbankzugriff. Der Anzeigename eines Benutzerakteurs (actor_label) wird nicht
 * aus dem Kontext übernommen, sondern vom ZoneHistoryRecorder in der Fachtransaktion aus der bereits gesperrten
 * users-Zeile gelesen (Stand zum Zeitpunkt der Änderung, nicht der Session).
 *
 * - user:            Benutzer-ID aus dem Actor, Kanal aus Actor::$channel (Web: "web")
 * - dyndns_account:  nur die Konto-ID (keine DynDNS-Benutzernamen in der Historie, Abschnitt 16), Kanal "api"
 * - system:          kein Akteur (Drift, Lazy-Baseline, Abgleich)
 *
 * Die Korrelation (UUIDv4) ist je Service-Aufruf eindeutig und verbindet zusammengehörige Änderungsmengen (z. B. die
 * Systemrevision "drift" mit der Revision des Akteurs oder T1 und T2 einer AutoPTR-Anlage).
 */

require_once __DIR__ . '/../Actor.php';

final class HistoryContext
{
    public const ACTOR_USER = 'user';
    public const ACTOR_DYNDNS = 'dyndns_account';
    public const ACTOR_SYSTEM = 'system';

    private function __construct(
        public readonly string $actorType,
        public readonly ?int $userId,
        public readonly ?int $dyndnsAccountId,
        public readonly string $channel,
        public readonly string $operation,
        public readonly string $correlationId
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/D', $operation) !== 1 || strlen($operation) > 64) {
            throw new InvalidArgumentException('Ungültige History-Operation');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $channel) !== 1) {
            throw new InvalidArgumentException('Ungültiger History-Kanal');
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $correlationId) !== 1) {
            throw new InvalidArgumentException('Ungültige Korrelationskennung');
        }
        $valid = match ($actorType) {
            self::ACTOR_USER    => $userId !== null && $userId > 0 && $dyndnsAccountId === null,
            self::ACTOR_DYNDNS  => $dyndnsAccountId !== null && $dyndnsAccountId > 0 && $userId === null,
            self::ACTOR_SYSTEM  => $userId === null && $dyndnsAccountId === null,
            default             => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('Ungültige Akteurangaben');
        }
    }

    public static function forUser(Actor $actor, string $operation, ?string $correlationId = null): self
    {
        return new self(self::ACTOR_USER, $actor->userId, null, $actor->channel, $operation, $correlationId ?? self::uuid4());
    }

    public static function forDyndnsAccount(int $accountId, string $operation, string $channel = 'api'): self
    {
        return new self(self::ACTOR_DYNDNS, null, $accountId, $channel, $operation, self::uuid4());
    }

    public static function system(string $operation, string $channel, ?string $correlationId = null): self
    {
        return new self(self::ACTOR_SYSTEM, null, null, $channel, $operation, $correlationId ?? self::uuid4());
    }

    /** Gleicher Akteur, Kanal und gleiche Korrelation, andere Operation (z. B. T2 einer AutoPTR-Anlage) */
    public function withOperation(string $operation): self
    {
        return new self($this->actorType, $this->userId, $this->dyndnsAccountId, $this->channel, $operation, $this->correlationId);
    }

    /** Systemkontext mit Kanal und Korrelation dieses Aufrufs (Drift, Lazy-Baseline) */
    public function systemDerived(string $operation): self
    {
        return new self(self::ACTOR_SYSTEM, null, null, $this->channel, $operation, $this->correlationId);
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
