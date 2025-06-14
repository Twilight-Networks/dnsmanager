<?php
/**
 * Datei: templates/record_add_form.php
 * Zweck: Formular zur Erstellung eines neuen DNS-Records
 *
 * Dieses Formular wird zur Anlage neuer DNS-Einträge in einer Zone verwendet.
 * Es ist auf verschiedene Record-Typen vorbereitet und zeigt je nach Auswahl
 * dynamisch die passenden Eingabefelder an.
 *
 * Technische Merkmale:
 * - Clientseitige Steuerung der Sichtbarkeit per record_add_form.js
 * - Serverseitige Vorbelegung per PHP
 * - Eingabefelder folgen der Struktur der Datenbankeinträge
 *
 * Voraussetzungen:
 * - IN_APP muss definiert sein
 * - $zone muss ein gültiges Zonendaten-Array enthalten
 */
require_once __DIR__ . '/../inc/dkim_helpers.php';

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

// Zonenspezifische Initialisierung
$zoneType = $zone['type']; // forward oder reverse
$zoneId = (int)$zone['id'];
$zoneName = htmlspecialchars(rtrim($zone['name'], '.')); // für HTML-Ausgabe
$zoneRaw = rtrim($zone['name'], '.'); // für interne Logik

// Erlaubte Record-Typen je nach Zonenausrichtung
$allowedTypes = $zoneType === 'reverse'
    ? ['PTR', 'NAPTR', 'TXT']
    : ['A','AAAA','CNAME','MX','NS','TXT','SPF','DKIM','LOC','CAA','SRV','NAPTR', 'URI'];
?>

<hr class="my-4">
<h4><?= $LANG['add_new_record'] ?></h4>

<!-- Ausklappbare Info-Box zu Record-Typ (Inhalt wird per JS aus record_add_form.js befüllt) -->
<div class="mb-3">
    <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#typeInfoBox">
        ℹ️ <?= $LANG['record_type_info_button'] ?>
    </button>
    <div class="collapse mt-2" id="typeInfoBox">
        <div class="alert alert-info mb-0" id="typeInfoContent" style="white-space: pre-line;"></div>
    </div>
</div>

<form method="post" action="actions/record_add.php"
    class="row g-3 d-flex flex-wrap align-items-start"
    id="recordForm"
    data-zone-type="<?= $zoneType ?>"
    data-zone-name="<?= $zoneName ?>"
    enctype="multipart/form-data">
    <?= csrf_input() ?>

    <!-- Eindeutige ID der aktuellen DNS-Zone -->
    <input type="hidden" name="zone_id" value="<?= $zoneId ?>">

    <!-- Namensfeld: Zeigt entweder 'Name' (forward) oder 'IP-Anteil' (reverse) -->
    <div class="colform-name d-flex flex-column">
        <label class="form-label"><?= $zoneType === 'reverse' ? $LANG['record_name_reverse'] : $LANG['record_name'] ?></label>

        <!-- Normales Namensfeld (z. B. www) -->
        <input name="name" id="input_name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

        <!-- Präfix-Eingabe für zusammengesetzten Namen (z. B. bei CAA/NAPTR) -->
        <div class="input-group d-none" id="caa_name_wrapper">
            <input type="text" id="input_name_prefix" class="form-control" placeholder="<?= $LANG['for_example'] ?> @ oder www" autocomplete="off">
            <span class="input-group-text">.<?= $zoneName ?></span>
        </div>
    </div>

    <!-- Auswahl des gewünschten Record-Typs -->
    <div class="d-flex flex-column colform-type">
        <label class="form-label"><?= $LANG['record_type'] ?></label>
        <select name="type" class="form-select" id="record_type" onchange="toggleDKIM(this.value)">
            <?php foreach ($allowedTypes as $type): ?>
                <option value="<?= $type ?>"><?= $type ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Content-Feld für alle Record-Typen, bei denen keine Spezialfelder nötig sind -->
    <div class="d-flex flex-column colform-content" id="content_wrapper">
        <label class="form-label"><?= $LANG['record_content'] ?></label>
        <input id="input_content" name="content" class="form-control" required>
    </div>

    <!-- Auswahloption, ob der Inhalt als FQDN mit Punkt oder Zonenname ergänzt werden soll -->
    <div class="d-flex flex-column colform-domain <?= $zoneType === 'reverse' ? 'd-none' : '' ?>" id="fqdn_wrapper">
        <label class="form-label"><?= $LANG['domain'] ?></label>
        <select name="fqdn_mode" class="form-select">
            <option value="dot">.</option>
            <option value="<?= $zoneName ?>."><?= $zoneName ?></option>
        </select>
    </div>

    <!-- Gültigkeitsdauer (TTL) -->
    <div class="d-flex flex-column colform-ttl">
        <label class="form-label" for="ttl_select"><?= $LANG['ttl'] ?></label>
        <select name="ttl" id="ttl_select" class="form-select">
            <option value="auto"><?= $LANG['ttl_auto'] ?></option>
            <option value="60">1 <?= $LANG['minute'] ?></option>
            <option value="120">2 <?= $LANG['minutes'] ?></option>
            <option value="300">5 <?= $LANG['minutes'] ?></option>
            <option value="600">10 <?= $LANG['minutes'] ?></option>
            <option value="900">15 <?= $LANG['minutes'] ?></option>
            <option value="1800">30 <?= $LANG['minutes'] ?></option>
            <option value="3600">1 <?= $LANG['hour'] ?></option>
            <option value="7200">2 <?= $LANG['hours'] ?></option>
            <option value="18000">5 <?= $LANG['hours'] ?></option>
            <option value="43200">12 <?= $LANG['hours'] ?></option>
            <option value="86400">1 <?= $LANG['day'] ?></option>
        </select>
    </div>

    <!-- MX-spezifische Felder: Priorität und Ziel-Mailserver (FQDN) -->
    <div class="d-flex flex-wrap gap-2 d-none" id="mx_fields_inline">
        <div class="d-flex flex-column colform-priority">
            <label class="form-label" for="input_mx_priority"><?= $LANG['priority'] ?></label>
            <input name="mx_priority" id="input_mx_priority" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 10">
        </div>
        <div class="d-flex flex-column colform-content">
            <label class="form-label" for="input_mx_target"><?= $LANG['mx_target'] ?></label>
            <input id="input_mx_target" class="form-control" type="text" placeholder="<?= $LANG['for_example'] ?> mail.example.com">
        </div>
        <div class="d-flex flex-column colform-domain">
            <label class="form-label"><?= $LANG['domain'] ?></label>
            <select name="mx_target_mode" id="mx_target_mode" class="form-select">
                <option value="dot">.</option>
                <option value="<?= $zoneName ?>."><?= $zoneName ?></option>
            </select>
        </div>
    </div>

    <!-- SRV-spezifische Felder: Dienstname, Protokoll, Priorität, Gewichtung, Port, Ziel -->
    <div class="d-flex flex-column colform-srv d-none" id="srv_fields">
        <div class="d-flex flex-row gap-2 flex-wrap">
            <div class="d-flex flex-column colform-protocol">
                <label class="form-label" for="srv_protocol"><?= $LANG['protocol'] ?></label>
                <select name="srv_protocol" id="srv_protocol" class="form-select">
                    <option value="_tcp">TCP</option>
                    <option value="_udp">UDP</option>
                </select>
            </div>
            <div class="d-flex flex-column colform-priority">
                <label class="form-label" for="input_srv_priority"><?= $LANG['priority'] ?></label>
                <input name="srv_priority" id="input_srv_priority" class="form-control" type="number" />
            </div>
            <div class="d-flex flex-column colform-weight">
                <label class="form-label"><?= $LANG['weight'] ?></label>
                <input name="srv_weight" id="srv_weight" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 10">
            </div>
            <div class="d-flex flex-column colform-port">
                <label class="form-label"><?= $LANG['port'] ?></label>
                <input name="srv_port" id="srv_port" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 5060">
            </div>
            <div class="d-flex flex-column colform-target">
                <label class="form-label"><?= $LANG['target'] ?></label>
                <input name="srv_target" id="srv_target" class="form-control" type="text" placeholder="<?= $LANG['for_example'] ?> sip.example.com">
            </div>
            <div class="d-flex flex-column colform-domain">
                <label class="form-label"><?= $LANG['domain'] ?></label>
                <select name="srv_target_mode" id="srv_target_mode" class="form-select">
                    <option value="dot">.</option>
                    <option value="<?= $zoneName ?>."><?= $zoneName ?></option>
                </select>
            </div>
        </div>
    </div>

    <!-- NAPTR-spezifische Felder: Vollständiger Satz für Mapping-Einträge -->
    <div class="d-flex flex-column colform-naptr d-none" id="naptr_fields">
        <div class="d-flex gap-2 flex-wrap">
            <div class="d-flex flex-column colform-order">
                <label class="form-label"><?= $LANG['order'] ?></label>
                <input name="naptr_order" id="naptr_order" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 100">
            </div>
            <div class="d-flex flex-column colform-preference">
                <label class="form-label"><?= $LANG['preference'] ?></label>
                <input name="naptr_pref" id="naptr_pref" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 10">
            </div>
            <div class="d-flex flex-column colform-flags">
                <label class="form-label"><?= $LANG['flags'] ?></label>
                <input name="naptr_flags" id="naptr_flags" class="form-control" placeholder="<?= $LANG['for_example'] ?> U">
            </div>
            <div class="d-flex flex-column colform-service">
                <label class="form-label"><?= $LANG['service'] ?></label>
                <input name="naptr_service" id="naptr_service" class="form-control" placeholder="<?= $LANG['for_example'] ?> E2U+sip">
            </div>
            <div class="d-flex flex-column colform-regex">
                <label class="form-label"><?= $LANG['regexp'] ?></label>
                <input name="naptr_regexp" id="naptr_regexp" class="form-control" placeholder="<?= $LANG['for_example'] ?> !^.*$!sip:info@example.com!">
            </div>
            <div class="d-flex flex-column colform-replacement">
                <label class="form-label"><?= $LANG['replacement'] ?></label>
                <input name="naptr_replacement" id="naptr_replacement" class="form-control" placeholder="<?= $LANG['for_example'] ?> .">
            </div>
        </div>
    </div>

    <!-- DKIM: Spezialfall mit drei erforderlichen Werten -->
    <div class="col-12" id="dkim_fields" style="display: none;">
        <div class="row">
            <input type="hidden" name="is_dkim" value="1">
            <div class="col-md-4 colform-dkim-selector">
                <label class="form-label"><?= $LANG['dkim_selector'] ?></label>
                <input type="text" id="dkim_selector" class="form-control" placeholder="<?= $LANG['for_example'] ?> default">
            </div>
            <div class="col-md-4 colform-dkim-domain">
                <label class="form-label"><?= $LANG['dkim_subdomain'] ?></label>
                <div class="input-group">
                    <input type="text"
                           id="dkim_subdomain"
                           name="dkim_subdomain"
                           class="form-control"
                           placeholder="<?= $LANG['for_example'] ?> sub"
                           autocomplete="off">
                    <span class="input-group-text">.<?= htmlspecialchars($zone['name']) ?></span>
                </div>
            </div>
            <div class="col-md-4 colform-dkim-keytype">
                <label class="form-label"><?= $LANG['dkim_key_type'] ?> (k=)</label>
                <select id="dkim_key_type" class="form-select">
                    <option value="rsa" selected>RSA</option>
                    <option value="ed25519">Ed25519</option>
                </select>
            </div>

            <div class="col-md-4 colform-dkim-hash">
                <label class="form-label"><?= $LANG['dkim_hash'] ?> (h=)</label>
                <input type="text" id="dkim_hash_algos" class="form-control" placeholder="<?= $LANG['for_example'] ?> sha256">
            </div>

            <div class="col-md-4 colform-dkim-flags">
                <label class="form-label"><?= $LANG['dkim_flags'] ?> (t=)</label>
                <input type="text" id="dkim_flags" class="form-control" placeholder="<?= $LANG['for_example'] ?> y">
            </div>
            <div class="col-md-4 colform-dkim-keyblock">
                <label class="form-label"><?= $LANG['dkim_key'] ?></label>
                <textarea id="dkim_key" class="form-control" rows="7" placeholder="Nur Base64-Inhalt"><?= htmlspecialchars($_POST['dkim_key'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label" for="dkim_file"><?= $LANG['dkim_upload_label'] ?></label>
                <input type="file" name="dkim_file" id="dkim_file" class="form-control dkim-file-upload" accept=".txt" onchange="handleDKIMFileUpload(this)">
                <button type="button" class="btn btn-link" data-bs-toggle="popover" data-bs-content="Laden Sie hier Ihre DKIM-Konfigurationsdatei hoch. Sie müssen sicherstellen, dass die Datei im richtigen Format vorliegt." data-bs-trigger="focus">
                    <i class="bi bi-question-circle"></i> <!-- Bootstrap-Icons für das Fragezeichen -->
                </button>
            </div>
        </div>
    </div>

    <!-- URI-spezifische Felder: Dienstname, Protokoll, Priorität, Gewicht, Ziel-URI -->
    <div class="d-flex flex-column colform-uri d-none" id="uri_fields">
        <div class="d-flex flex-row gap-2 flex-wrap">
            <div class="d-flex flex-column colform-service">
                <label class="form-label" for="uri_service"><?= $LANG['service'] ?></label>
                <input name="uri_service" id="uri_service" class="form-control" type="text" placeholder="<?= $LANG['for_example'] ?> _ftp">
            </div>
            <div class="d-flex flex-column colform-protocol">
                <label class="form-label" for="uri_protocol"><?= $LANG['protocol'] ?></label>
                <select name="uri_protocol" id="uri_protocol" class="form-select">
                    <option value="_tcp">TCP</option>
                    <option value="_udp">UDP</option>
                </select>
            </div>
            <div class="d-flex flex-column colform-priority">
                <label class="form-label" for="uri_priority"><?= $LANG['priority'] ?></label>
                <input name="uri_priority" id="uri_priority" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 10">
            </div>
            <div class="d-flex flex-column colform-weight">
                <label class="form-label" for="uri_weight"><?= $LANG['weight'] ?></label>
                <input name="uri_weight" id="uri_weight" class="form-control" type="number" placeholder="<?= $LANG['for_example'] ?> 1">
            </div>
            <div class="d-flex flex-column flex-grow-1">
                <label class="form-label" for="uri_target"><?= $LANG['uri_target'] ?></label>
                <input name="uri_target" id="uri_target" class="form-control" type="text" placeholder="<?= $LANG['for_example'] ?> ftp://ftp.example.com/public">
            </div>
        </div>
    </div>

    <!-- Optional: Automatische Erstellung eines PTR-Eintrags bei A/AAAA, sofern aktiviert -->
    <?php if ($zoneType === 'forward'): ?>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="auto_ptr" id="auto_ptr">
                <label class="form-check-label" for="auto_ptr"><?= $LANG['auto_ptr'] ?></label>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formularaktionen: Speichern des neuen Eintrags oder Abbruch -->
    <div class="col-12 d-flex gap-2">
        <button class="btn btn-success"><?= $LANG['save'] ?></button>
        <a href="pages/records.php?zone_id=<?= $zoneId ?>" class="btn btn-secondary"><?= $LANG['cancel'] ?></a>
    </div>
</form>

<hr class="my-4">
<br>
