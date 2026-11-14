<?php
/**
 * Datei: inc/web/record_messages.php
 * Zweck: Web-Darstellung fachlicher Codes der Record-Bausteine.
 *
 * Die Texte zu RecordContentBuilder-Codes entsprechen wörtlich den bisherigen Fehlertexten von
 * buildRecordContent() (record_update.php zeigt sie als Toast, record_add.php schreibt sie ins Log).
 */

require_once __DIR__ . '/../app/records/RecordContentBuilder.php';

/**
 * Bisheriger Fehlertext zu einem RecordContentBuilder-Code. Ein unbekannter Code wird nicht verschluckt,
 * sondern unverändert ausgegeben.
 */
function recordBuildErrorText(string $code): string
{
    return match ($code) {
        RecordContentBuilder::MX_PRIORITY_INVALID   => 'MX-Priorität ist ungültig.',
        RecordContentBuilder::SRV_NUMBERS_INVALID   => 'SRV-Priorität, Gewicht und Port müssen numerisch sein.',
        RecordContentBuilder::SRV_TARGET_EMPTY      => 'SRV-Target darf nicht leer sein.',
        RecordContentBuilder::DKIM_FIELDS_EMPTY     => 'DKIM-Selector und Public Key dürfen nicht leer sein.',
        RecordContentBuilder::DKIM_KEY_TYPE_INVALID => 'DKIM-Schlüsseltyp (k=) muss rsa oder ed25519 sein.',
        RecordContentBuilder::DKIM_HASH_INVALID     => 'DKIM-Hash-Algorithmen (h=) sind ungültig (z. B. sha256 oder sha1:sha256).',
        RecordContentBuilder::DKIM_FLAGS_INVALID    => 'DKIM-Flags (t=) sind ungültig (z. B. y oder s).',
        RecordContentBuilder::DKIM_KEY_INVALID      => 'DKIM Public Key darf nur Base64-Zeichen enthalten.',
        RecordContentBuilder::DKIM_SELECTOR_INVALID => 'DKIM-Selector oder -Subdomain enthält unzulässige Zeichen.',
        RecordContentBuilder::DKIM_CONTENT_INVALID  => 'DKIM-Inhalt ist ungültig (nur v, k, h, t, p; Zeichenketten höchstens 255 Bytes).',
        RecordContentBuilder::NAPTR_NUMBERS_INVALID => 'NAPTR: Order und Preference müssen numerisch sein.',
        RecordContentBuilder::NAPTR_FIELDS_EMPTY    => 'NAPTR: Alle Felder (Flags, Service, Regexp, Replacement) müssen ausgefüllt sein.',
        default                                     => $code,
    };
}
