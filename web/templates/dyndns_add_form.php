<?php
/**
 * Datei: dyndns_add_form.php
 * Zweck: Formular zur Erstellung eines neuen DynDNS-Accounts
 *
 * Beschreibung:
 * Dieses Formular wird eingeblendet, wenn in `dyndns.php` die Option "Neuer DynDNS-Account" ausgewählt ist.
 * Es erlaubt Administratoren, einen neuen DynDNS-Benutzer für eine bestimmte Zone anzulegen.
 *
 * Felder:
 * - Benutzername
 * - Passwort (wird gehasht gespeichert)
 * - Hostname (Subdomain innerhalb der Zone)
 * - Zugehörige Zone (Dropdown-Auswahl)
 *
 * Anforderungen:
 * - Der Benutzername muss eindeutig sein.
 * - Die gewählte Zone muss DynDNS erlauben (`allow_dyndns = 1`).
 *
 * Ziel:
 * - POST an `actions/dyndns_add.php`
 */

if (!defined('IN_APP')) {
    http_response_code(403);
    exit;
}

// Zonen laden, die DynDNS erlauben
$stmt = $pdo->query("SELECT id, name FROM zones WHERE allow_dyndns = 1 ORDER BY name");
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<hr class="my-4">
<h4 class="mt-4">Neuen DynDNS-Account anlegen</h4>

<form method="post" action="actions/dyndns_add.php" class="row g-3">
    <?= csrf_input() ?>

    <div class="col-md-3">
        <label for="username" class="form-label">Benutzername</label>
        <input type="text" name="username" id="username" class="form-control" required maxlength="64">
    </div>

    <div class="col-md-3">
        <label for="password" class="form-label">Passwort</label>
        <input type="password" name="password" id="password" class="form-control" required minlength="6">
    </div>

    <div class="col-md-3">
        <label for="hostname" class="form-label">Hostname (z. B. home)</label>
        <input type="text" name="hostname" id="hostname" class="form-control" required maxlength="255">
    </div>

    <div class="col-md-3">
        <label for="zone_id" class="form-label">Zugehörige Zone</label>
        <select name="zone_id" id="zone_id" class="form-select" required>
            <option value="">– Bitte wählen –</option>
            <?php foreach ($zones as $zone): ?>
                <option value="<?= $zone['id'] ?>"><?= htmlspecialchars($zone['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-success">Account anlegen</button>
        <a href="pages/dyndns.php" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>

<hr class="my-4">
<br>
