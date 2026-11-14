<?php
/**
 * Datei: templates/record_edit_form.php
 * Zweck: Formular zur Bearbeitung eines bestehenden DNS-Records in records.php
 *
 * Dieses dynamische Formular erscheint unterhalb eines bestehenden Records zur Bearbeitung.
 * Es basiert auf dem Neuanlageformular, nutzt jedoch vorausgefüllte Werte.
 *
 * Technische Merkmale:
 * - Felder sind per "edit_"-Präfix eindeutig identifizierbar.
 * - Typabhängige Felder (MX, SRV, etc.) werden per PHP und JavaScript sichtbar oder ausgeblendet.
 * - Formularinhalte werden serverseitig vorbereitet, clientseitig dynamisch angepasst.
 *
 * Anforderungen:
 * - Variablen aus pages/records.php: $r (Record inkl. is_glue, is_ns_glue, optional is_dkim), $zone (id, name, type), $zone_id
 * - Sicherheitskonstante IN_APP muss definiert sein.
 */

require_once __DIR__ . '/../inc/ttl_defaults.php';

// Zugriffsschutz bei direktem Aufruf
if (!defined('IN_APP')) {
    http_response_code(403);
    exit('Direkter Zugriff verboten.');
}

// Prüfen auf erforderliche Daten
if (!isset($r) || !isset($zone)) {
    echo "<div class='alert alert-danger'>Fehlende Daten für das Bearbeitungsformular.</div>";
    return;
}

require_once __DIR__ . '/../inc/app/records/RecordContentParser.php';

// Erlaubte Record-Typen je nach Zonentyp
$zone_type = $zone['type'];
$allowed_types = $zone_type === 'reverse'
    ? ['PTR', 'NAPTR', 'TXT']
    : ['A','AAAA','CNAME','MX','NS','TXT','SPF','DKIM','LOC','CAA','SRV','NAPTR'];

// Inhalt in die Formularfelder zerlegen (MX, SRV, DKIM, NAPTR, URI, CAA/NAPTR-Präfix) – RecordContentParser
$parsed = RecordContentParser::parse($r['type'], $r['name'], $r['content'], !empty($r['is_dkim']), $zone['name']);
$r['name']    = $parsed['name'];
$r['content'] = $parsed['content'];

$mx_priority = $parsed['mxPriority'];

$srv_priority = $parsed['srvPriority'];
$weight       = $parsed['srvWeight'];
$port         = $parsed['srvPort'];
$target       = $parsed['srvTarget'];
$srv_proto    = $parsed['srvProto'];

$dkim_key_type   = $parsed['dkimKeyType'];
$dkim_hash_algos = $parsed['dkimHashAlgos'];
$dkim_flags      = $parsed['dkimFlags'];
$dkim_selector   = $parsed['dkimSelector'];
$dkim_subdomain  = $parsed['dkimSubdomain'];
$dkim_key        = $parsed['dkimKey'];

$naptr_order   = $parsed['naptrOrder'];
$naptr_pref    = $parsed['naptrPref'];
$naptr_flags   = $parsed['naptrFlags'];
$naptr_service = $parsed['naptrService'];
$naptr_regexp  = $parsed['naptrRegexp'];
$naptr_replace = $parsed['naptrReplace'];

$uri_service  = $parsed['uriService'];
$uri_proto    = $parsed['uriProto'];
$uri_priority = $parsed['uriPriority'];
$uri_weight   = $parsed['uriWeight'];
$uri_target   = $parsed['uriTarget'];

// Glue-/NS-Schutz: Kennzeichen aus dem RecordReadService (Name nicht änderbar)
$is_glue    = !empty($r['is_glue']);
$is_ns_glue = !empty($r['is_ns_glue']);

// CAA/NAPTR: Präfix ohne Zonen-Suffix
$zone_suffix = rtrim($zone['name'], '.');
$caa_prefix  = $parsed['caaPrefix'];

// Auto TTL
$ttl_default = getAutoTTL($r['type']);
$is_auto_ttl = ((int)$r['ttl']) === $ttl_default;
?>


<tr class="table-warning table-edit-form">
    <td class="coltbl-select" aria-hidden="true">
        <div class="invisible">
            <input type="checkbox" class="form-check-input" disabled>
        </div>
    </td>
    <td colspan="5">
        <form method="post"
            action="actions/record_update.php"
            class="row g-3 d-flex flex-wrap align-items-start"
            id="editForm_<?= $r['id'] ?>"
            data-record-id="<?= $r['id'] ?>"
            data-zone-name="<?= htmlspecialchars(rtrim($zone['name'], '.')) ?>"
            data-zone-type="<?= $zone['type'] ?>">
            <?= csrf_input() ?>

            <!-- Interne ID des aktuellen Records, erforderlich für Updates oder Löschvorgänge -->
            <input type="hidden" name="id" value="<?= $r['id'] ?>">

            <!-- ID der zugehörigen DNS-Zone, zur eindeutigen Zuordnung des Eintrags im Backend -->
            <input type="hidden" name="zone_id" value="<?= $zone_id ?>">

            <!-- Namensfeld: Zeigt entweder 'Name' (forward) oder 'IP-Anteil' (reverse) -->
            <div class="col-md-4 colform-name d-flex flex-column">
                <label class="form-label"><?= $zone_type === 'reverse' ? $LANG['record_name_reverse'] : $LANG['record_name'] ?></label>

                <!-- Nur für CAA und NAPTR sichtbar: Präfix-Eingabe + feste Zonen-Suffix -->
                <div class="input-group <?= in_array($r['type'], ['CAA', 'NAPTR']) ? '' : 'd-none' ?>" id="edit_caa_name_wrapper">
                    <input type="text" id="edit_input_name_prefix" name="edit_name_prefix" class="form-control"
                           value="<?= htmlspecialchars($caa_prefix) ?>" placeholder="<?= $LANG['for_example'] ?> www oder sub" autocomplete="off">
                    <span class="input-group-text">.<?= htmlspecialchars($zone_suffix) ?></span>
                </div>

                <!-- Normales Namensfeld für alle Typen außer CAA, NAPTR, DKIM -->
                <input
                    name="name"
                    id="edit_input_name"
                    class="form-control
                        <?= in_array($r['type'], ['CAA', 'NAPTR']) ? 'd-none' : '' ?>
                        <?= in_array($r['type'], ['URI']) || !empty($r['is_dkim']) || $is_glue || $is_ns_glue ? 'bg-light text-muted' : '' ?>"
                    <?= in_array($r['type'], ['URI']) || !empty($r['is_dkim']) || $is_glue || $is_ns_glue ? 'readonly' : 'required' ?>
                    value="<?= htmlspecialchars($r['name']) ?>">
            </div>

            <!-- Typfeld: Immer als Hidden-Feld übergeben, auch in Reverse-Zonen -->
            <input type="hidden" name="type" value="<?= htmlspecialchars($r['type']) ?>">
            <input type="hidden" id="is_dkim_record" value="<?= !empty($r['is_dkim']) ? '1' : '0' ?>">
            <input type="hidden" id="edit_record_type" value="<?= htmlspecialchars($r['type']) ?>">

            <?php if ($zone_type !== 'reverse'): ?>
            <div class="d-flex flex-column colform-type">
                <label class="form-label">Typ</label>
                <span class="form-control bg-light text-muted"><?= htmlspecialchars($r['type']) ?></span>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-column colform-content <?= in_array($r['type'], ['SRV', 'NAPTR']) || !empty($r['is_dkim']) ? 'invisible' : '' ?>" id="edit_content_wrapper">
                <label class="form-label"><?= $LANG['record_content'] ?></label>
                <input
                    name="content"
                    id="edit_input_content"
                    class="form-control <?= ($is_glue || $is_ns_glue) ? 'bg-light text-muted' : '' ?>"
                    <?= ($is_glue || $is_ns_glue) ? 'readonly' : '' ?>
                    value="<?= htmlspecialchars($r['content']) ?>">
            </div>

            <!-- Gültigkeitsdauer (TTL) -->
            <div class="d-flex flex-column colform-ttl">
                <label class="form-label" for="edit_ttl_select">TTL</label>
                <select name="ttl" id="edit_ttl_select" class="form-select">
                    <option value="auto" <?= $is_auto_ttl ? 'selected' : '' ?>><?= $LANG['ttl_auto'] ?></option>
                    <?php
                    $ttlOptions = [
                        60   => '1 ' . $LANG['minute'],
                        120  => '2 ' . $LANG['minutes'],
                        300  => '5 ' . $LANG['minutes'],
                        600  => '10 ' . $LANG['minutes'],
                        900  => '15 ' . $LANG['minutes'],
                        1800 => '30 ' . $LANG['minutes'],
                        3600 => '1 ' . $LANG['hour'],
                        7200 => '2 ' . $LANG['hours'],
                        18000 => '5 ' . $LANG['hours'],
                        43200 => '12 ' . $LANG['hours'],
                        86400 => '1 ' . $LANG['day'],
                    ];
                    foreach ($ttlOptions as $val => $label):
                        // wenn TTL auf "auto", soll keine echte Zahl zusätzlich selected werden
                        $selected = (!$is_auto_ttl && (int)$r['ttl'] === $val) ? 'selected' : '';
                        echo "<option value=\"$val\" $selected>$label</option>";
                    endforeach;
                    ?>
                </select>
            </div>

            <!-- MX-spezifische Felder: Priorität und Ziel-Mailserver (FQDN) -->
            <div class="d-flex flex-column colform-priority <?= $r['type'] === 'MX' ? '' : 'd-none' ?>" id="edit_mx_fields">
                <label class="form-label"><?= $LANG['priority'] ?></label>
                <input name="mx_priority" id="edit_input_mx_priority" class="form-control" type="number" value="<?= htmlspecialchars($mx_priority) ?>">
            </div>

            <!-- SRV-spezifische Felder: Dienstname, Protokoll, Priorität, Gewichtung, Port, Ziel -->
            <div class="d-flex flex-column colform-srv <?= $r['type'] === 'SRV' ? '' : 'd-none' ?>" id="edit_srv_fields">
                <div class="d-flex gap-2 flex-wrap">
                    <div class="d-flex flex-column colform-protocol">
                        <label class="form-label"><?= $LANG['protocol'] ?></label>
                        <select name="srv_protocol" id="edit_srv_protocol" class="form-select">
                            <option value="_tcp" <?= $srv_proto === 'tcp' ? 'selected' : '' ?>>TCP</option>
                            <option value="_udp" <?= $srv_proto === 'udp' ? 'selected' : '' ?>>UDP</option>
                        </select>
                    </div>
                    <div class="d-flex flex-column colform-priority">
                        <label class="form-label"><?= $LANG['priority'] ?></label>
                        <input name="srv_priority" id="edit_input_srv_priority" class="form-control" type="number" value="<?= htmlspecialchars($srv_priority) ?>">
                    </div>
                    <div class="d-flex flex-column colform-weight">
                        <label class="form-label"><?= $LANG['weight'] ?></label>
                        <input name="srv_weight" class="form-control" type="number" value="<?= htmlspecialchars($weight) ?>">
                    </div>
                    <div class="d-flex flex-column colform-port">
                        <label class="form-label"><?= $LANG['port'] ?></label>
                        <input name="srv_port" class="form-control" type="number" value="<?= htmlspecialchars($port) ?>">
                    </div>
                    <div class="d-flex flex-column colform-target">
                        <label class="form-label"><?= $LANG['target'] ?></label>
                        <input name="srv_target" class="form-control" type="text" value="<?= htmlspecialchars($target) ?>">
                    </div>
                </div>
            </div>

            <!-- NAPTR-spezifische Felder: Vollständiger Satz für Mapping-Einträge -->
            <div class="d-flex flex-column colform-naptr <?= $r['type'] === 'NAPTR' ? '' : 'd-none' ?>" id="edit_naptr_fields">
                <div class="d-flex gap-2 flex-wrap">
                    <div class="d-flex flex-column colform-order">
                        <label class="form-label"><?= $LANG['order'] ?></label>
                        <input name="naptr_order" class="form-control" type="number" value="<?= htmlspecialchars($naptr_order) ?>">
                    </div>
                    <div class="d-flex flex-column colform-preference">
                        <label class="form-label"><?= $LANG['preference'] ?></label>
                        <input name="naptr_pref" class="form-control" type="number" value="<?= htmlspecialchars($naptr_pref) ?>">
                    </div>
                    <div class="d-flex flex-column colform-flags">
                        <label class="form-label"><?= $LANG['flags'] ?></label>
                        <input name="naptr_flags" class="form-control" value="<?= htmlspecialchars($naptr_flags) ?>">
                    </div>
                    <div class="d-flex flex-column colform-service">
                        <label class="form-label"><?= $LANG['service'] ?></label>
                        <input name="naptr_service" class="form-control" value="<?= htmlspecialchars($naptr_service) ?>">
                    </div>
                    <div class="d-flex flex-column colform-regex">
                        <label class="form-label"><?= $LANG['regexp'] ?></label>
                        <input name="naptr_regexp" class="form-control" value="<?= htmlspecialchars($naptr_regexp) ?>">
                    </div>
                    <div class="d-flex flex-column colform-replacement">
                        <label class="form-label"><?= $LANG['replacement'] ?></label>
                        <input name="naptr_replacement" class="form-control" value="<?= htmlspecialchars($naptr_replace) ?>">
                    </div>
                </div>
            </div>

            <!-- URI: Dienstname, Protokoll, Priorität, Gewicht, Ziel -->
            <div class="d-flex flex-column colform-uri <?= $r['type'] === 'URI' ? '' : 'd-none' ?>" id="edit_uri_fields">
                <div class="d-flex flex-row gap-2 flex-wrap">
                    <div class="d-flex flex-column colform-service">
                        <label class="form-label"><?= $LANG['service'] ?></label>
                        <input name="uri_service" id="edit_uri_service" class="form-control" type="text" value="<?= htmlspecialchars($uri_service) ?>">
                    </div>
                    <div class="d-flex flex-column colform-protocol">
                        <label class="form-label"><?= $LANG['protocol'] ?></label>
                        <select name="uri_protocol" id="edit_uri_protocol" class="form-select">
                            <option value="_tcp" <?= $uri_proto === '_tcp' ? 'selected' : '' ?>>TCP</option>
                            <option value="_udp" <?= $uri_proto === '_udp' ? 'selected' : '' ?>>TCP</option>
                        </select>
                    </div>
                    <div class="d-flex flex-column colform-priority">
                        <label class="form-label"><?= $LANG['priority'] ?></label>
                        <input name="uri_priority" id="edit_uri_priority" class="form-control" type="number" value="<?= htmlspecialchars($uri_priority) ?>">
                    </div>
                    <div class="d-flex flex-column colform-weight">
                        <label class="form-label"><?= $LANG['weight'] ?></label>
                        <input name="uri_weight" id="edit_uri_weight" class="form-control" type="number" value="<?= htmlspecialchars($uri_weight) ?>">
                    </div>
                    <div class="d-flex flex-column flex-grow-1">
                        <label class="form-label"><?= $LANG['uri_target'] ?></label>
                        <input name="uri_target" id="edit_uri_target" class="form-control" type="text" value="<?= htmlspecialchars($uri_target) ?>">
                    </div>
                </div>
            </div>

            <!-- DKIM: Spezialfall mit drei erforderlichen Werten -->
            <div class="col-12 <?= !empty($r['is_dkim']) ? '' : 'd-none' ?>" id="edit_dkim_fields">
                <div class="row">
                    <input type="hidden" name="is_dkim" value="1">
                    <div class="col-md-4 colform-dkim-selector">
                        <label class="form-label"><?= $LANG['dkim_selector'] ?></label>
                        <input type="text" name="dkim_selector" class="form-control" value="<?= htmlspecialchars($dkim_selector) ?>">
                    </div>
                        <div class="col-md-4 colform-dkim-domain">
                            <label class="form-label"><?= $LANG['dkim_subdomain'] ?></label>
                            <div class="input-group">
                                <input type="text"
                                       name="dkim_subdomain"
                                       class="form-control"
                                       value="<?= htmlspecialchars($dkim_subdomain) ?>"
                                       placeholder="z. B. sub"
                                       autocomplete="off">
                                <span class="input-group-text">.<?= htmlspecialchars($zone['name']) ?></span>
                            </div>
                        </div>
                        <!-- Key Type -->
                        <div class="col-md-4 colform-dkim-keytype">
                            <label class="form-label"><?= $LANG['dkim_key_type'] ?> (k=)</label>
                            <select name="dkim_key_type" class="form-select">
                                <option value="rsa" <?= $dkim_key_type === 'rsa' ? 'selected' : '' ?>>RSA</option>
                                <option value="ed25519" <?= $dkim_key_type === 'ed25519' ? 'selected' : '' ?>>Ed25519</option>
                            </select>
                        </div>

                        <!-- Hash Algorithmen -->
                        <div class="col-md-4 colform-dkim-hash">
                            <label class="form-label"><?= $LANG['dkim_hash'] ?> (h=)</label>
                            <input type="text" name="dkim_hash_algos" class="form-control"
                                placeholder="z. B. sha256" value="<?= htmlspecialchars($dkim_hash_algos) ?>">
                        </div>

                        <!-- Flags -->
                        <div class="col-md-4 colform-dkim-flags">
                            <label class="form-label"><?= $LANG['dkim_flags'] ?> (t=)</label>
                            <input type="text" name="dkim_flags" class="form-control"
                                placeholder="z. B. y" value="<?= htmlspecialchars($dkim_flags) ?>">
                        </div>
                        <div class="col-md-4 colform-dkim-keyblock">
                            <label class="form-label"><?= $LANG['dkim_key'] ?></label>
                            <!-- Setzen des Public Keys aus den vorhandenen Daten -->
                            <textarea name="dkim_key" class="form-control" rows="7" placeholder="Nur Base64-Inhalt"><?= htmlspecialchars($dkim_key) ?></textarea>
                        </div>
                    </div>
            </div>
        </form>
    </td>
</tr>
