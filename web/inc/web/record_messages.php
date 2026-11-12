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
        RecordContentBuilder::NAPTR_NUMBERS_INVALID => 'NAPTR: Order und Preference müssen numerisch sein.',
        RecordContentBuilder::NAPTR_FIELDS_EMPTY    => 'NAPTR: Alle Felder (Flags, Service, Regexp, Replacement) müssen ausgefüllt sein.',
        default                                     => $code,
    };
}
