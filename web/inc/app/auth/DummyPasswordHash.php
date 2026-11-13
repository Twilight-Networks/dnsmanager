<?php
/**
 * Datei: inc/app/auth/DummyPasswordHash.php
 * Zweck: Verringerung der zeitlichen Unterscheidbarkeit bekannter und unbekannter Benutzernamen (SP1, S31).
 *
 * Bei einem unbekannten Benutzernamen führen AuthService (Web-Login) und DyndnsUpdateService (DynDNS-API) eine
 * Passwortprüfung gegen diesen festen, vorab erzeugten Hash aus, damit die Antwortzeit ungefähr der einer Prüfung
 * gegen einen gespeicherten Hash entspricht.
 *
 * - Verfahren und Kosten wie die gespeicherten Hashes (bcrypt, Kosten 12 = PASSWORD_DEFAULT ab PHP 8.4); der Hash
 *   wird nicht je Anfrage erzeugt.
 * - Der Hash stammt aus einem zufälligen, verworfenen Wert. Das Ergebnis der Prüfung wird nie ausgewertet: Über
 *   diesen Hash ist keine Anmeldung möglich.
 * - Eine vollständige Timing-Gleichheit ist nicht zugesagt (z. B. abweichende Kosten älterer Hashes,
 *   Datenbankzugriffe).
 */

final class DummyPasswordHash
{
    /** bcrypt, Kosten 12; Klartext unbekannt (zufällig erzeugt und verworfen) */
    public const HASH = '$2y$12$mx1MeaWeuml0CZK7qsOs9O1z0xS5EDSulZozSHHgiESrsAyH/uu.u';

    /**
     * Führt eine Passwortprüfung mit dem Aufwand einer echten Prüfung aus; das Ergebnis wird verworfen.
     */
    public static function consumeVerification(string $password): void
    {
        password_verify($password, self::HASH);
    }
}
