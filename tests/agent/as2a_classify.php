<?php
/**
 * Datei: tests/agent/as2a_classify.php
 * Zweck: Hilfsprogramm der AS2a-Suite – ordnet eine tatsächliche Agentenantwort mit dem Core-Klassifizierer ein (E113).
 * Aufruf: php as2a_classify.php <Checkout> <zone_sync|conf_sync|zone_cleanup> <HTTP-Status> <Body>
 */
require $argv[1] . '/web/inc/app/publish/PublishTargetClassifier.php';
$r = PublishTargetClassifier::classify($argv[2], ['body' => $argv[4], 'httpCode' => (int)$argv[3], 'curlError' => '', 'curlErrno' => 0]);
echo $r['status'], "\n";
