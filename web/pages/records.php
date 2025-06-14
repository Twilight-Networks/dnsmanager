<?php
/**
 * Datei: records.php
 * Zweck: Anzeige und Bearbeitung von DNS-Records einer spezifischen Zone.
 * Details:
 * - Admins dürfen alle Zonen und deren Records verwalten.
 * - Zone-Admins dürfen nur Records in ihnen zugewiesenen Zonen verwalten.
 * - Unterstützung für A, AAAA, CNAME, MX, NS, PTR, TXT, SPF, DKIM Records.
 * - Glue-Records (A/AAAA-Records, die autoritative NS-Records referenzieren) sind
 *   visuell als nicht löschbar gekennzeichnet.
 * - Bei Glue-Records ist im Bearbeitungsmodus nur die Änderung von TTL und IP-Adresse zulässig.
 * Zugriff: Geschützt über requireRole(['admin', 'zoneadmin']) und Zonenbesitzprüfung.
 * Aufruf: GET /pages/records.php?zone_id={id}
 */

define('IN_APP', true);
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../inc/glue_check.php';
require_once __DIR__ . '/../inc/ttl_defaults.php';
include __DIR__ . '/../templates/layout.php';

// Zugriffskontrolle: Nur Admins oder Zone-Admins erlaubt
requireRole(['admin', 'zoneadmin']);

// Eingangsparameter lesen
$zone_id = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
$edit_record_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

// Zonenberechtigung prüfen
if ($_SESSION['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
    $stmt->execute([$zone_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT z.* FROM zones z
        JOIN user_zones uz ON uz.zone_id = z.id
        WHERE z.id = ? AND uz.user_id = ?
    ");
    $stmt->execute([$zone_id, $_SESSION['user_id']]);
}

$zone = $stmt->fetch();

if (!$zone) {
    logAccessDenied('Benutzer-ID ' . ($_SESSION['user_id'] ?? 'unbekannt') . ' versucht unbefugten Zugriff auf Zone-ID ' . $zone_id);
    http_response_code(403);
    exit('Zugriff verweigert: Keine Berechtigung für diese Zone.');
}

// Records laden (nach Typ alphabetisch sortieren)
$stmt = $pdo->prepare("SELECT * FROM records WHERE zone_id = ? ORDER BY type ASC, name ASC");
$stmt->execute([$zone_id]);
$all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Records gruppieren
$groups = ['NS' => [], 'MAIL' => [], 'OTHER' => []];
foreach ($all_records as $r) {
    // DKIM heuristisch erkennen
    $is_dkim = $r['type'] === 'TXT'
        && str_contains($r['content'], 'v=DKIM1')
        && str_contains($r['name'], '._domainkey');

    if ($is_dkim) {
        $r['is_dkim'] = true;
    }

    // Gruppierung
    if ($r['type'] === 'NS') {
        $groups['NS'][] = $r;
    } elseif ($r['type'] === 'MX' || $is_dkim || ($r['type'] === 'TXT' && preg_match('/spf|dmarc/i', $r['content']))) {
        $groups['MAIL'][] = $r;
    } else {
        $groups['OTHER'][] = $r;
    }
}

/**
 * Helper-Funktion zum Anzeigen einer Tabelle von Records.
 */
function render_records_table(array $records, string $title, int $zone_id, ?int $edit_record_id, PDO $pdo): void
{
    global $all_records, $zone, $LANG;

    if (empty($records)) {
        return;
    }

    echo "<h4 class='mt-4'>" . htmlspecialchars($title) . "</h4>";
    echo "<div class='table-responsive'>
            <table class='table table-striped table-bordered align-middle' style='table-layout: fixed; width: 100%;'>";

    echo "<thead class='table-light'><tr>";
    echo "<th class='coltbl-select'><input type='checkbox' class='form-check-input select-all' data-table-id='" . md5($title) . "'></th>";
    echo "<th class='coltbl-name'>" . $LANG['name'] . "</th>";
    echo "<th class='coltbl-type'>" . $LANG['record_type'] . "</th>";
    echo "<th class='coltbl-content'>" . $LANG['record_content'] . "</th>";
    echo "<th class='coltbl-ttl'>TTL</th>";
    echo "<th class='coltbl-actions'>" . $LANG['actions'] . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($records as $r) {
        $is_glue = isGlueRecord($r, $all_records, $zone['name']);
        $is_ns_glue = ($r['type'] === 'NS') ? isProtectedNsRecord($r, $all_records, $zone['name'], $pdo, $zone_id) : false;
        $is_editing = ($edit_record_id === (int)$r['id']);
        $row_class = $is_editing ? 'table-warning' : '';

        echo "<tr class='$row_class'>";
        echo "<td class='coltbl-select'>";
        if (!$is_editing) {
            if ($is_glue) {
                echo "<input type='checkbox' class='form-check-input' disabled style='opacity:0.5; cursor:not-allowed;' title='" . $LANG['glue_protected'] . "'>";
            } else {
                echo "<input type='checkbox' class='form-check-input select-row' data-id='" . $r['id'] . "' data-table-id='" . md5($title) . "'>";
            }
        }
        echo "</td>";

        echo "<td>" . htmlspecialchars($r['name']) . "</td>";
        echo "<td>" . htmlspecialchars($r['type']) . "</td>";
        echo "<td class='coltbl-content' title='" . htmlspecialchars($r['content']) . "'>" . htmlspecialchars($r['content']) . "</td>";

        // TTL-Anzeige
        $ttl_value = (int)$r['ttl'];
        $ttl_auto = getAutoTTL($r['type']);
        if ($ttl_value === $ttl_auto) {
            $ttl_display = $LANG['ttl_auto'];
        } else {
            $ttl_map = [
                60 => '1 ' . $LANG['minute'],
                120 => '2 ' . $LANG['minutes'],
                300 => '5 ' . $LANG['minutes'],
                600 => '10 ' . $LANG['minutes'],
                900 => '15 ' . $LANG['minutes'],
                1800 => '30 ' . $LANG['minutes'],
                3600 => '1 ' . $LANG['hour'],
                7200 => '2 ' . $LANG['hours'],
                18000 => '5 ' . $LANG['hours'],
                43200 => '12 ' . $LANG['hours'],
                86400 => '1 ' . $LANG['day'],
            ];
            $ttl_display = $ttl_map[$ttl_value] ?? $ttl_value . ' ' . $LANG['seconds'];
        }

        echo "<td>" . htmlspecialchars($ttl_display) . "</td>";

        // Aktionsspalte
        echo "<td><div class='d-flex gap-1 flex-wrap'>";
        if ($is_editing) {
            echo "<button type='submit' form='editForm_" . $r['id'] . "' class='btn btn-sm btn-success'>" . $LANG['save'] . "</button>";
            echo "<a href='pages/records.php?zone_id=" . $zone_id . "' class='btn btn-sm btn-secondary'>" . $LANG['cancel'] . "</a>";
        } else {
            echo "<a href='pages/records.php?zone_id=" . $zone_id . "&edit_id=" . $r['id'] . "' class='btn btn-sm btn-outline-warning'>" . $LANG['edit'] . "</a>";

            if (!$is_glue && !$is_ns_glue) {
                echo "<form method='post' action='actions/record_delete.php' class='d-inline confirm-delete'>";
                echo csrf_input();
                echo "<input type='hidden' name='id' value='" . $r['id'] . "'>";
                echo "<input type='hidden' name='zone_id' value='" . $zone_id . "'>";
                echo "<button type='submit' class='btn btn-sm btn-outline-danger'>" . $LANG['delete'] . "</button>";
                echo "</form>";
            }
        }
        echo "</div></td>";

        // Bearbeitungsformular (wenn aktiv)
        if ($is_editing) {
            include __DIR__ . '/../templates/record_edit_form.php';
        }

        echo "</tr>";
    }

    echo "</tbody></table></div>";
}
?>

<br>
<br>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h2><?= sprintf($LANG['dns_records_for'], htmlspecialchars($zone['name'])) ?></h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <a href="pages/records.php?zone_id=<?= $zone['id'] ?>&add_new=1" class="btn btn-success">+ <?= $LANG['add_record'] ?></a>

        <form id="bulkDeleteForm" method="post" action="actions/record_delete.php" class="d-none confirm-delete">
            <?= csrf_input() ?>

            <input type="hidden" name="bulk_ids" value="">
            <button type="submit" class="btn btn-danger fw-bold" id="bulkDeleteBtn"><?= $LANG['delete_records'] ?></button>
        </form>
    </div>
</div>

<?php

// Zonendiagnose aus der Datenbank laden
require_once __DIR__ . '/../inc/diagnostics.php';
$diagnostics = getDiagnosticResults($pdo);
$zone_diag = $diagnostics['zone'];

$results = [];
foreach ($zone_diag as $entry) {
    if (($entry['zone'] ?? '') === $zone['name']) {
        $results[] = [
            'server' => htmlspecialchars($entry['server'] ?? '(unbekannt)'),
            'status' => $entry['zone_status']['status'],
            'message' => $entry['zone_status']['message']
        ];
    }
}

// Ausgabe gesammelt darstellen
echo "<div class='alert alert-secondary'><strong>📦 " . sprintf($LANG['zone_diagnostic_for'], htmlspecialchars($zone['name'])) . "</strong><br>";
echo "<br>";

foreach ($results as $r) {
    $color = match ($r['status']) {
        'ok' => '✅',
        'error' => '❌',
        'not_found' => '❌',
        default => '❓'
    };

    echo "<div class='mt-2'><strong>{$color} {$r['server']}</strong><br>";
    echo "<pre style='white-space: pre-wrap; font-family: monospace'>" . htmlspecialchars($r['message']) . "</pre></div>";
}

echo "</div>";

// === Formular für neuen Eintrag hier einfügen ===
$show_add_form = isset($_GET['add_new']) && $_GET['add_new'] == '1';
if ($show_add_form): ?>
    <?php include __DIR__ . '/../templates/record_add_form.php'; ?>
<?php endif; ?>

<?php
$record_title_ns   = $LANG['records_ns'];
$record_title_mail = $LANG['records_mail'];
$record_title_other = $zone['type'] === 'reverse'
    ? $LANG['records_host_reverse']
    : $LANG['records_host'];

render_records_table($groups['NS'], $record_title_ns, $zone['id'], $edit_record_id, $pdo);
render_records_table($groups['MAIL'], $record_title_mail, $zone['id'], $edit_record_id, $pdo);
render_records_table($groups['OTHER'], $record_title_other, $zone['id'], $edit_record_id, $pdo);
?>

<!-- JavaScript auslagern -->
<?php
AssetRegistry::enqueueScript('js/records.js');
AssetRegistry::enqueueScript('js/record_add_form.js');
AssetRegistry::enqueueScript('js/record_edit_form.js');
AssetRegistry::enqueueScript('js/dkim_helpers.js');
?>

<?php include __DIR__ . '/../templates/layout_footer.php'; ?>
