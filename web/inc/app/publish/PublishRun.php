<?php
/**
 * Datei: inc/app/publish/PublishRun.php
 * Zweck: Lauf-Token des Publish-Journals (AP1/H2d, E57). Wird ausschließlich von PublishLedger::registerRun() unter
 *        dem Publish-Lock ausgegeben; nur mit einem offenen Token desselben Ledgers dürfen Versuche registriert und
 *        Ergebnisse eingetragen werden. Fachservices erhalten nie ein Token.
 *
 * Die Lauf-ID ist die künftige Fencing-Kennung (F5, Meilenstein AS); sie wird in H2d nicht an Agenten gesendet.
 */

final class PublishRun
{
    /**
     * @param string $runLock Name der laufeigenen Advisory-Sperre (Identitätsnachweis für J5, E73)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly bool $fullRebuild,
        public readonly int $connectionId,
        public readonly string $runLock
    ) {
    }
}
