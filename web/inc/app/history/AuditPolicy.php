<?php
/**
 * Datei: inc/app/history/AuditPolicy.php
 * Zweck: Verbindliche Feld-Whitelist, Typprüfung und Schwärzung der Audit-Ereignisse (AP1/H2c, E48;
 *        docs/ARCHITECTURE_HISTORY.md Abschnitt 5c).
 *
 * Rein (ohne Datenbank). Erzeugt aus Vorher-/Nachher-Werten, Geheimnis-Kennzeichen und Fakten das kanonische
 * "changes"-JSON eines Ereignisses. Jedes Feld gehört je Entitätstyp genau einer Klasse an; alles andere ist ein
 * Programmfehler (LogicException ⇒ Rollback der Fachtransaktion, fail-closed):
 *
 *   name     Bezeichner (Server-, Benutzer-, Hostname): 1–255 Zeichen, gültiges UTF-8, ohne Leerraum,
 *            Steuerzeichen, "$", Anführungszeichen und Backslash (schließt Passwort-Hashes aus)
 *   ip       NULL oder gültige IPv4-/IPv6-Adresse (nur Infrastrukturadressen der Server, Q1; nie Client-IPs)
 *   bool     bool, 0/1 oder "0"/"1"
 *   id       Ganzzahl ≥ 1
 *   role     admin | zoneadmin
 *   text     Freitext (Zonenbeschreibung): NULL oder Zeichenkette; gekürzt auf MAX_TEXT_CHARS Zeichen
 *            ("…_truncated"), bei Geheimnismuster vollständig geschwärzt (Wert NULL, "…_redacted")
 *   zones    Zonenzuweisung eines Benutzers: Liste von Zonen-IDs oder ['mode' => 'all', 'count' => n] (Q4);
 *            Ausgabe als hinzugefügte/entfernte IDs, ab MAX_ID_LIST nur als Anzahl
 *   secret   nur über $secrets und nur als Kennzeichen (set, changed, rotated, cleared) – nie ein Wert
 *   count    Fakt: Ganzzahl ≥ 0 (z. B. Kaskadenzähler der Zonenlöschung, E53)
 *   result   Fakt: ok | failed (BIND-Reload, E51); kein Antworttext des Agenten
 *
 * Ausgabe: JSON-Objekt mit Feldern in der Reihenfolge der Whitelist; Wertfelder als {"old":…,"new":…} (Anlage nur
 * "new", Löschung nur "old"), Geheimnisse als Kennzeichen-Zeichenkette, Fakten als Skalar. Deterministisch
 * (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, ohne Leerraum), höchstens MAX_JSON_BYTES Bytes (= CHECK der
 * Tabelle). Ein Ereignis ohne Änderung ergibt null (kein Eintrag).
 */

final class AuditPolicy
{
    public const MAX_JSON_BYTES = 16384;
    public const MAX_TEXT_CHARS = 500;
    public const MAX_NAME_CHARS = 255;
    public const MAX_ID_LIST = 200;

    public const ENTITY_TYPES = ['zone', 'server', 'dyndns_account', 'user'];
    public const ACTIONS = ['create', 'update', 'delete', 'reload'];
    public const SECRET_MARKERS = ['set', 'changed', 'rotated', 'cleared'];

    /** Feld-Whitelist je Entitätstyp (Reihenfolge = Ausgabereihenfolge) */
    public const FIELDS = [
        'zone' => [
            'description'              => 'text',
            'allow_dyndns'             => 'bool',
            'dyndns_accounts_removed'  => 'count',
            'user_assignments_removed' => 'count',
        ],
        'server' => [
            'name'      => 'name',
            'dns_ip4'   => 'ip',
            'dns_ip6'   => 'ip',
            'api_ip'    => 'ip',
            'is_local'  => 'bool',
            'active'    => 'bool',
            'api_token' => 'secret',
            'result'    => 'result',
        ],
        'dyndns_account' => [
            'hostname' => 'name',
            'zone_id'  => 'id',
            'username' => 'secret',
            'password' => 'secret',
        ],
        'user' => [
            'username' => 'name',
            'role'     => 'role',
            'active'   => 'bool',
            'zones'    => 'zones',
            'password' => 'secret',
        ],
    ];

    /** Fakten (keine Vorher-/Nachher-Werte) */
    private const FACT_CLASSES = ['count', 'result'];

    /**
     * Muster, die in Freitext als Geheimnis gelten (Schwärzung). Passwort-Hashes (bcrypt, argon2, crypt), private
     * Schlüssel und lange Token-ähnliche Zeichenfolgen ohne Leerraum (≥ 32 Zeichen aus Hex-/Base64-Alphabet).
     */
    public const SECRET_PATTERNS = [
        '/\$2[abxy]\$\d{2}\$/',
        '/\$argon2(?:id|i|d)\$/',
        '/\$[1356]\$[^\s$]{1,16}\$/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/[A-Za-z0-9+\/_=-]{32,}/',
    ];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Kanonisches changes-JSON eines Ereignisses oder null, wenn sich nichts geändert hat.
     *
     * @param array<string, mixed>  $before  Vorher-Werte (nur Wertfelder; Anlage: [])
     * @param array<string, mixed>  $after   Nachher-Werte (nur Wertfelder; Löschung: [])
     * @param array<string, string> $secrets Geheimnis-Kennzeichen (Feld => set|changed|rotated|cleared)
     * @param array<string, mixed>  $facts   Fakten (count/result)
     *
     * @throws LogicException bei unbekanntem Typ, unzulässiger Aktion, Feld oder Wert
     */
    public static function changes(
        string $entityType,
        string $action,
        array $before,
        array $after,
        array $secrets = [],
        array $facts = []
    ): ?string {
        if (!isset(self::FIELDS[$entityType])) {
            throw new LogicException("Audit: unbekannter Entitätstyp '$entityType'");
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new LogicException("Audit: unzulässige Aktion '$action'");
        }
        if ($action === 'reload' && $entityType !== 'server') {
            throw new LogicException('Audit: reload nur für Server');
        }
        $fields = self::FIELDS[$entityType];

        foreach ([$before, $after] as $values) {
            foreach (array_keys($values) as $field) {
                $class = self::classOf($fields, $entityType, $field);
                if ($class === 'secret' || in_array($class, self::FACT_CLASSES, true)) {
                    throw new LogicException("Audit: Feld '$entityType.$field' darf nicht als Wert übergeben werden");
                }
            }
        }
        foreach ($secrets as $field => $marker) {
            if (self::classOf($fields, $entityType, (string)$field) !== 'secret') {
                throw new LogicException("Audit: Feld '$entityType.$field' ist kein Geheimnisfeld");
            }
            if (!is_string($marker) || !in_array($marker, self::SECRET_MARKERS, true)) {
                throw new LogicException("Audit: unzulässiges Kennzeichen für '$entityType.$field'");
            }
        }
        foreach (array_keys($facts) as $field) {
            if (!in_array(self::classOf($fields, $entityType, (string)$field), self::FACT_CLASSES, true)) {
                throw new LogicException("Audit: Feld '$entityType.$field' ist kein Fakt");
            }
        }

        switch ($action) {
            case 'create':
                if ($before !== []) {
                    throw new LogicException('Audit: Anlage ohne Vorher-Werte');
                }
                break;
            case 'delete':
                if ($after !== []) {
                    throw new LogicException('Audit: Löschung ohne Nachher-Werte');
                }
                break;
            case 'update':
                $b = array_keys($before);
                $a = array_keys($after);
                sort($b);
                sort($a);
                if ($a !== $b) {
                    throw new LogicException('Audit: Änderung mit abweichenden Vorher-/Nachher-Feldern');
                }
                break;
            case 'reload':
                if ($before !== [] || $after !== [] || $secrets !== [] || array_keys($facts) !== ['result']) {
                    throw new LogicException('Audit: reload nur mit dem Fakt result');
                }
                break;
        }

        $out = [];
        foreach ($fields as $field => $class) {
            if ($class === 'secret') {
                if (isset($secrets[$field])) {
                    $out[$field] = $secrets[$field];
                }
                continue;
            }
            if (in_array($class, self::FACT_CLASSES, true)) {
                if (array_key_exists($field, $facts)) {
                    $out[$field] = self::fact($class, $field, $facts[$field]);
                }
                continue;
            }
            $hasBefore = array_key_exists($field, $before);
            $hasAfter = array_key_exists($field, $after);
            if (!$hasBefore && !$hasAfter) {
                continue;
            }
            $entry = $class === 'zones'
                ? self::zonesEntry($field, $hasBefore ? $before[$field] : null, $hasAfter ? $after[$field] : null, $hasBefore, $hasAfter)
                : self::valueEntry($class, $field, $hasBefore ? $before[$field] : null, $hasAfter ? $after[$field] : null, $hasBefore, $hasAfter);
            if ($entry !== null) {
                $out[$field] = $entry;
            }
        }

        if ($out === []) {
            return null;
        }
        $json = json_encode($out, self::JSON_FLAGS);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new LogicException('Audit: Ereignis überschreitet ' . self::MAX_JSON_BYTES . ' Bytes');
        }

        return $json;
    }

    /**
     * Prüft ein Ereignis-Label (Zonen-, Server-, Benutzer- oder Hostname; nie ein DynDNS-Kontoname).
     *
     * @throws LogicException bei ungültigem Label
     */
    public static function label(string $label): string
    {
        return self::name('label', $label);
    }

    /** true, wenn der Text einem Geheimnismuster entspricht (für Freitext und Suchtests) */
    public static function looksSecret(string $text): bool
    {
        foreach (self::SECRET_PATTERNS as $re) {
            if (preg_match($re, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /* ======================================================================================== */
    /* Intern                                                                                   */
    /* ======================================================================================== */

    /** @param array<string, string> $fields */
    private static function classOf(array $fields, string $entityType, string $field): string
    {
        if (!isset($fields[$field])) {
            throw new LogicException("Audit: Feld '$entityType.$field' ist nicht freigegeben");
        }

        return $fields[$field];
    }

    /** @return array<string, mixed>|null */
    private static function valueEntry(string $class, string $field, mixed $old, mixed $new, bool $hasOld, bool $hasNew): ?array
    {
        $o = $hasOld ? self::normalize($class, $field, $old) : null;
        $n = $hasNew ? self::normalize($class, $field, $new) : null;

        if ($hasOld && $hasNew) {
            if ($o === $n) {
                return null;
            }
        } elseif ($hasNew && $n === null) {
            return null;   // Anlage: nicht gesetzte Felder entfallen
        } elseif ($hasOld && $o === null) {
            return null;   // Löschung: nicht gesetzte Felder entfallen
        }

        $entry = [];
        if ($hasOld) {
            self::put($entry, 'old', $class, $o);
        }
        if ($hasNew) {
            self::put($entry, 'new', $class, $n);
        }

        return $entry;
    }

    /** @param array<string, mixed> $entry */
    private static function put(array &$entry, string $key, string $class, mixed $value): void
    {
        if ($class !== 'text' || $value === null) {
            $entry[$key] = $value;
            return;
        }
        if (self::looksSecret($value)) {
            $entry[$key] = null;
            $entry[$key . '_redacted'] = true;
            return;
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_TEXT_CHARS) {
            $entry[$key] = mb_substr($value, 0, self::MAX_TEXT_CHARS, 'UTF-8');
            $entry[$key . '_truncated'] = true;
            return;
        }
        $entry[$key] = $value;
    }

    private static function normalize(string $class, string $field, mixed $v): mixed
    {
        switch ($class) {
            case 'name':
                if (!is_string($v)) {
                    throw new LogicException("Audit: '$field' ist keine Zeichenkette");
                }
                return self::name($field, $v);
            case 'ip':
                if ($v === null || $v === '') {
                    return null;
                }
                if (!is_string($v) || filter_var($v, FILTER_VALIDATE_IP) === false) {
                    throw new LogicException("Audit: '$field' ist keine IP-Adresse");
                }
                return $v;
            case 'bool':
                if ($v === true || $v === 1 || $v === '1') {
                    return true;
                }
                if ($v === false || $v === 0 || $v === '0') {
                    return false;
                }
                throw new LogicException("Audit: '$field' ist kein Wahrheitswert");
            case 'id':
                if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1) {
                    $v = (int)$v;
                }
                if (!is_int($v) || $v < 1) {
                    throw new LogicException("Audit: '$field' ist keine ID");
                }
                return $v;
            case 'role':
                if (!in_array($v, ['admin', 'zoneadmin'], true)) {
                    throw new LogicException("Audit: '$field' ist keine Rolle");
                }
                return $v;
            case 'text':
                if ($v === null) {
                    return null;
                }
                if (!is_string($v) || preg_match('//u', $v) !== 1) {
                    throw new LogicException("Audit: '$field' ist kein gültiger Text");
                }
                return $v;
        }

        throw new LogicException("Audit: unbekannte Feldklasse '$class'");
    }

    private static function name(string $field, string $v): string
    {
        if ($v === '' || preg_match('//u', $v) !== 1 || mb_strlen($v, 'UTF-8') > self::MAX_NAME_CHARS
            || preg_match('/[\s\x00-\x1F\x7F$"\'\\\\]/u', $v) === 1) {
            throw new LogicException("Audit: '$field' ist kein zulässiger Bezeichner");
        }

        return $v;
    }

    private static function fact(string $class, string $field, mixed $v): int|string
    {
        if ($class === 'count') {
            if (!is_int($v) || $v < 0) {
                throw new LogicException("Audit: '$field' ist keine Anzahl");
            }
            return $v;
        }
        if (!in_array($v, ['ok', 'failed'], true)) {
            throw new LogicException("Audit: '$field' ist kein Ergebnis");
        }

        return $v;
    }

    /**
     * Zonenzuweisung: Liste von IDs oder ['mode' => 'all', 'count' => n] (nur bei Anlage).
     *
     * @return array<string, mixed>|null
     */
    private static function zonesEntry(string $field, mixed $old, mixed $new, bool $hasOld, bool $hasNew): ?array
    {
        if ($hasNew && is_array($new) && array_key_exists('mode', $new)) {
            if ($hasOld || array_keys($new) !== ['mode', 'count'] || $new['mode'] !== 'all'
                || !is_int($new['count']) || $new['count'] < 0) {
                throw new LogicException("Audit: '$field' mit mode=all nur bei Anlage und mit Anzahl");
            }
            return ['mode' => 'all', 'count' => $new['count']];
        }
        $o = $hasOld ? self::idList($field, $old) : [];
        $n = $hasNew ? self::idList($field, $new) : [];
        $added = array_values(array_diff($n, $o));
        $removed = array_values(array_diff($o, $n));
        if ($added === [] && $removed === []) {
            return null;
        }
        $entry = [];
        foreach (['added' => $added, 'removed' => $removed] as $k => $ids) {
            if ($ids === []) {
                continue;
            }
            if (count($ids) > self::MAX_ID_LIST) {
                $entry[$k . '_count'] = count($ids);
            } else {
                $entry[$k] = $ids;
            }
        }

        return $entry;
    }

    /** @return int[] aufsteigend, ohne Duplikate */
    private static function idList(string $field, mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new LogicException("Audit: '$field' ist keine ID-Liste");
        }
        $ids = [];
        foreach ($v as $id) {
            if (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/D', $id) === 1) {
                $id = (int)$id;
            }
            if (!is_int($id) || $id < 1) {
                throw new LogicException("Audit: '$field' enthält eine ungültige ID");
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }
}
