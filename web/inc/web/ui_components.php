<?php
/**
 * Datei: inc/web/ui_components.php
 * Zweck: Wiederverwendbare UI-Bausteine der Weboberfläche (U3, Designvorlage A+).
 *
 * - Reine Darstellung: Funktionen geben escaptes HTML zurück. Keine Fachlogik, keine Datenbank, keine
 *   Berechtigungsprüfung, keine Session-Änderung; Texte übergibt der Aufrufer (Sprachschlüssel aus $LANG).
 * - Parameter mit dem Suffix "Html" sind bereits fertiges, vom Aufrufer verantwortetes Markup (z. B. Schaltflächen);
 *   alle übrigen Texte werden hier mit htmlspecialchars() kodiert.
 * - CSP-konform: keine Inline-Skripte, keine Inline-Handler, keine style-Attribute. Verhalten mit JavaScript
 *   liefern assets/js/components.js und assets/js/diff.js; ohne JavaScript bleibt alles bedienbar.
 * - Stile: assets/css/components.css (Klassenpräfix dm-).
 */

/** HTML-Kodierung (Kurzform für die Bausteine) */
function ui_e(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Zulässige Varianten für Chips und Hinweisbänder */
function ui_variant(string $variant): string
{
    return in_array($variant, ['ok', 'warn', 'err', 'info', 'accent', 'neutral'], true) ? $variant : 'neutral';
}

/**
 * Seitenkopf: Titel, optionale Unterzeile und Aktionen rechts.
 *
 * @param array{subtitle?: string, actionsHtml?: string, titleHtml?: string} $opts
 */
function ui_page_header(string $title, array $opts = []): string
{
    $titleHtml = $opts['titleHtml'] ?? ui_e($title);
    $html = '<div class="dm-page-head"><div class="dm-page-head-text"><h1 class="dm-page-title">' . $titleHtml . '</h1>';
    if (($opts['subtitle'] ?? '') !== '') {
        $html .= '<div class="dm-page-sub">' . ui_e($opts['subtitle']) . '</div>';
    }
    $html .= '</div>';
    if (($opts['actionsHtml'] ?? '') !== '') {
        $html .= '<div class="dm-page-actions">' . $opts['actionsHtml'] . '</div>';
    }
    return $html . '</div>';
}

/**
 * Kennzahl-Karte.
 *
 * @param array{icon?: string, unit?: string, foot?: string, variant?: string, href?: string} $opts
 */
function ui_kpi(string $label, string $value, array $opts = []): string
{
    $tag = isset($opts['href']) ? 'a' : 'div';
    $attr = isset($opts['href']) ? ' href="' . ui_e($opts['href']) . '"' : '';
    $variant = isset($opts['variant']) ? ' dm-kpi-' . ui_variant($opts['variant']) : '';
    $html = '<' . $tag . ' class="dm-kpi' . $variant . '"' . $attr . '><div class="dm-kpi-label">'
        . (isset($opts['icon']) ? ui_icon($opts['icon']) : '') . '<span>' . ui_e($label) . '</span></div>'
        . '<div class="dm-kpi-value">' . ui_e($value)
        . (($opts['unit'] ?? '') !== '' ? '<small>' . ui_e($opts['unit']) . '</small>' : '') . '</div>';
    if (($opts['foot'] ?? '') !== '') {
        $html .= '<div class="dm-kpi-foot">' . ui_e($opts['foot']) . '</div>';
    }
    return $html . '</' . $tag . '>';
}

/** Raster für Kennzahl-Karten */
function ui_kpi_grid(array $kpisHtml): string
{
    return '<div class="dm-kpis">' . implode('', $kpisHtml) . '</div>';
}

/**
 * Status-Chip.
 *
 * @param string $variant ok | warn | err | info | accent | neutral
 */
function ui_chip(string $text, string $variant = 'neutral', ?string $icon = null): string
{
    return '<span class="dm-chip dm-chip-' . ui_variant($variant) . '">' . ($icon !== null ? ui_icon($icon) : '')
        . ui_e($text) . '</span>';
}

/**
 * Hinweisband (z. B. ausstehende Veröffentlichung).
 *
 * @param string $variant ok | warn | err | info
 * @param array{text?: string, actionsHtml?: string, icon?: string, role?: string} $opts
 */
function ui_notice(string $variant, string $title, array $opts = []): string
{
    $variant = ui_variant($variant);
    $icons = ['ok' => 'check-circle', 'warn' => 'exclamation-triangle', 'err' => 'x-circle', 'info' => 'info-circle'];
    $icon = $opts['icon'] ?? ($icons[$variant] ?? 'info-circle');
    $role = in_array($opts['role'] ?? 'status', ['status', 'alert', 'note'], true) ? ($opts['role'] ?? 'status') : 'status';
    $html = '<div class="dm-notice dm-notice-' . $variant . '" role="' . $role . '">' . ui_icon($icon)
        . '<div class="dm-notice-text"><strong>' . ui_e($title) . '</strong>';
    if (($opts['text'] ?? '') !== '') {
        $html .= ' <span>' . ui_e($opts['text']) . '</span>';
    }
    $html .= '</div>';
    if (($opts['actionsHtml'] ?? '') !== '') {
        $html .= '<div class="dm-notice-actions">' . $opts['actionsHtml'] . '</div>';
    }
    return $html . '</div>';
}

/**
 * Datenkarte öffnen (Kopf mit Titel und optionalen Aktionen); schließen mit ui_card_close().
 *
 * @param array{actionsHtml?: string, flush?: bool, id?: string} $opts flush = Inhalt ohne Innenabstand (Tabellen)
 */
function ui_card_open(string $title, array $opts = []): string
{
    $id = isset($opts['id']) ? ' id="' . ui_e($opts['id']) . '"' : '';
    $html = '<section class="dm-card"' . $id . '>';
    if ($title !== '' || ($opts['actionsHtml'] ?? '') !== '') {
        $html .= '<header class="dm-card-head"><h2 class="dm-card-title">' . ui_e($title) . '</h2>'
            . (($opts['actionsHtml'] ?? '') !== '' ? '<div class="dm-card-actions">' . $opts['actionsHtml'] . '</div>' : '')
            . '</header>';
    }
    return $html . '<div class="dm-card-body' . (!empty($opts['flush']) ? ' dm-card-flush' : '') . '">';
}

function ui_card_close(): string
{
    return '</div></section>';
}

/**
 * Leerzustand.
 *
 * @param array{icon?: string, text?: string, actionHtml?: string} $opts
 */
function ui_empty_state(string $title, array $opts = []): string
{
    $html = '<div class="dm-empty">' . ui_icon($opts['icon'] ?? 'inbox') . '<div class="dm-empty-title">' . ui_e($title) . '</div>';
    if (($opts['text'] ?? '') !== '') {
        $html .= '<div class="dm-empty-text">' . ui_e($opts['text']) . '</div>';
    }
    if (($opts['actionHtml'] ?? '') !== '') {
        $html .= '<div class="dm-empty-action">' . $opts['actionHtml'] . '</div>';
    }
    return $html . '</div>';
}

/**
 * Massenaktionsleiste für eine auswählbare Tabelle (Gruppe $group). Sichtbar erst mit JavaScript und Auswahl;
 * components.js füllt die Anzahl und schreibt die ausgewählten IDs kommagetrennt in das Feld [data-dm-bulk-ids]
 * eines Formulars der Gruppe (bestehender Vertrag bulk_ids, D7).
 *
 * @param string $countFormat Text mit %d für die Anzahl (z. B. $LANG['bulk_selected'])
 * @param string $actionsHtml Schaltflächen bzw. Formulare der Massenaktion
 */
function ui_bulk_bar(string $group, string $countFormat, string $actionsHtml): string
{
    return '<div class="dm-bulkbar" data-dm-bulkbar="' . ui_e($group) . '" data-dm-count-format="' . ui_e($countFormat)
        . '" hidden><span class="dm-bulk-count" aria-live="polite"></span><div class="dm-bulk-actions">' . $actionsHtml . '</div></div>';
}

/** Auswahlkästchen „alle“ im Tabellenkopf einer Gruppe */
function ui_select_all(string $group, string $label): string
{
    return '<input type="checkbox" class="form-check-input dm-select-all" data-dm-select-all="' . ui_e($group)
        . '" aria-label="' . ui_e($label) . '">';
}

/** Auswahlkästchen einer Zeile (ohne name: wird nie mit einem Formular gesendet) */
function ui_select_row(string $group, string $value, string $label): string
{
    return '<input type="checkbox" class="form-check-input dm-select-row" data-dm-select="' . ui_e($group)
        . '" value="' . ui_e($value) . '" aria-label="' . ui_e($label) . '">';
}

/**
 * Lösch-Schaltfläche mit Bestätigung, auch ohne JavaScript (U0-B9).
 *
 * Ohne JavaScript (Rückfall): <details> – der erste Klick öffnet die Rückfrage, erst „Endgültig löschen“ sendet das Formular.
 * Mit JavaScript (Komfort): components.js öffnet beim Klick auf die Schaltfläche das bestehende Bestätigungs-Modal
 * (global.js, form.confirm-delete). Das umgebende Formular, seine Felder und sein Ziel bleiben unverändert.
 *
 * @param array{prompt?: string, confirm?: string, size?: string} $opts
 */
function ui_confirm_delete_button(string $label, array $opts = []): string
{
    $size = ($opts['size'] ?? 'sm') === 'sm' ? ' btn-sm' : '';
    return '<details class="dm-confirm"><summary class="btn' . $size . ' btn-outline-danger">' . ui_e($label) . '</summary>'
        . '<span class="dm-confirm-pop" role="group"><span class="dm-confirm-prompt">' . ui_e($opts['prompt'] ?? '') . '</span>'
        . '<button type="submit" class="btn' . $size . ' btn-danger dm-confirm-submit">' . ui_e($opts['confirm'] ?? $label) . '</button>'
        . '</span></details>';
}

/* ---------- Editor-Rahmen (rechtes Offcanvas, serverseitig geöffnet) ---------- */

/**
 * Editor-Rahmen öffnen. Die Seite rendert ihn nur, wenn die URL einen Editor anfordert (z. B. ?edit_id= oder
 * ?add_new=1, D2); er ist dann ohne JavaScript bereits geöffnet. Schließen/Abbrechen sind Links ($closeUrl).
 * Zwischen ui_editor_open() und ui_editor_close() steht das bestehende Formular der Seite unverändert.
 *
 * @param array{id?: string, subtitle?: string, chip?: string, chipVariant?: string, closeLabel?: string} $opts
 */
function ui_editor_open(string $title, string $closeUrl, array $opts = []): string
{
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $opts['id'] ?? 'dmEditor');
    $close = ui_e($opts['closeLabel'] ?? 'Schließen');
    $html = '<a class="dm-editor-backdrop" href="' . ui_e($closeUrl) . '" tabindex="-1" aria-hidden="true"></a>'
        . '<aside class="dm-editor" id="' . $id . '" role="dialog" aria-modal="true" aria-labelledby="' . $id . 'Title"'
        . ' data-dm-editor data-dm-close-url="' . ui_e($closeUrl) . '">'
        . '<header class="dm-editor-head"><div class="dm-editor-head-text">'
        . '<h2 class="dm-editor-title" id="' . $id . 'Title">' . ui_e($title)
        . (($opts['chip'] ?? '') !== '' ? ' ' . ui_chip($opts['chip'], $opts['chipVariant'] ?? 'accent') : '') . '</h2>';
    if (($opts['subtitle'] ?? '') !== '') {
        $html .= '<div class="dm-editor-sub">' . ui_e($opts['subtitle']) . '</div>';
    }
    return $html . '</div><a class="dm-icon-btn" href="' . ui_e($closeUrl) . '" aria-label="' . $close . '" title="' . $close . '">'
        . ui_icon('x-lg') . '</a></header><div class="dm-editor-body">';
}

/**
 * Editor-Rahmen schließen: Fuß mit optionalem linkem Bereich (z. B. Löschen) und rechts Abbrechen (Link) und
 * Speichern (Schaltfläche des Seitenformulars über das Attribut form=, damit Ziel und Felder unverändert bleiben).
 *
 * @param array{leftHtml?: string, cancelLabel?: string, saveLabel?: string, formId?: string, saveHtml?: string} $opts
 */
function ui_editor_close(string $closeUrl, array $opts = []): string
{
    $save = $opts['saveHtml'] ?? (isset($opts['formId'])
        ? '<button type="submit" class="btn btn-success" form="' . ui_e($opts['formId']) . '">' . ui_e($opts['saveLabel'] ?? 'Speichern') . '</button>'
        : '');
    return '</div><footer class="dm-editor-foot"><div class="dm-editor-foot-left">' . ($opts['leftHtml'] ?? '') . '</div>'
        . '<div class="dm-editor-foot-right"><a class="btn btn-outline-secondary" href="' . ui_e($closeUrl) . '">'
        . ui_e($opts['cancelLabel'] ?? 'Abbrechen') . '</a>' . $save . '</div></footer></aside>';
}

/** Abschnitt innerhalb des Editors (Überschrift in Kapitälchen) */
function ui_editor_section(string $label, string $bodyHtml): string
{
    return '<section class="dm-editor-section"><h3 class="dm-editor-section-label">' . ui_e($label) . '</h3>' . $bodyHtml . '</section>';
}

/* ---------- DIFF-Darstellung (allgemein, ohne Record-Logik) ---------- */

/** Text in Unicode-Zeichen zerlegen (gleiche Zählweise wie Array.from() in diff.js) */
function ui_diff_chars(string $text): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? $chars : str_split($text);
}

/**
 * Vergleich zweier Zeilen über gemeinsamen Anfang und gemeinsames Ende (zeichenweise).
 * Liefert die Länge des gemeinsamen Präfixes und Suffixes; der Rest dazwischen ist die Änderung.
 * Identische Regel in assets/js/diff.js (DmDiff.compare); die Gleichheit prüft tests/ui/diff_cases.php.
 *
 * @return array{prefix: int, suffixOld: int, suffixNew: int, lenOld: int, lenNew: int}
 */
function ui_diff_compare(string $old, string $new): array
{
    $a = ui_diff_chars($old);
    $b = ui_diff_chars($new);
    $la = count($a);
    $lb = count($b);
    $p = 0;
    while ($p < $la && $p < $lb && $a[$p] === $b[$p]) {
        $p++;
    }
    $s = 0;
    while ($s < $la - $p && $s < $lb - $p && $a[$la - 1 - $s] === $b[$lb - 1 - $s]) {
        $s++;
    }
    return ['prefix' => $p, 'suffixOld' => $s, 'suffixNew' => $s, 'lenOld' => $la, 'lenNew' => $lb];
}

/** Eine DIFF-Zeile mit hervorgehobenem Änderungsbereich [from, to) rendern */
function ui_diff_line(string $kind, string $text, int $from, int $to, string $charsFormat): string
{
    $chars = ui_diff_chars($text);
    $n = count($chars);
    $marks = ['del' => '−', 'add' => '+', 'same' => ' '];
    $body = ui_e(implode('', array_slice($chars, 0, $from)));
    if ($to > $from) {
        $body .= '<mark class="dm-diff-chg">' . ui_e(implode('', array_slice($chars, $from, $to - $from))) . '</mark>';
    }
    $body .= ui_e(implode('', array_slice($chars, $to)));
    return '<div class="dm-diff-line dm-diff-' . $kind . '"><span class="dm-diff-mark" aria-hidden="true">' . $marks[$kind] . '</span>'
        . '<span class="dm-diff-text">' . $body . '</span><span class="dm-diff-len">' . ui_e(sprintf($charsFormat, $n)) . '</span></div>';
}

/**
 * DIFF-Bereich für eine oder mehrere Zeilenpaare (gespeichert → neu).
 *
 * Je Paar: identisch → eine neutrale Zeile; nur neu → „+“; nur gespeichert → „−“; sonst „−“/„+“ mit hervorgehobenem
 * Änderungsbereich. Ohne JavaScript immer vollständig; diff.js rendert mit JavaScript dieselben Daten und klappt bei
 * langen Werten unveränderte Mittelteile ein („vollständig anzeigen“). Keine Live-Ansagen (aria-live="off"),
 * Zusammenfassung als Text.
 *
 * @param array<int, array{0: ?string, 1: ?string}> $pairs Liste [gespeichert|null, neu|null]
 * @param array{chars?: string, summaryChanged?: string, summaryNone?: string, showFull?: string, showLess?: string,
 *              label?: string} $texts Sprachtexte (%d bei chars und summaryChanged)
 */
function ui_diff(array $pairs, array $texts = []): string
{
    $charsFormat = $texts['chars'] ?? '%d';
    $lines = '';
    $changed = 0;
    $data = [];
    foreach ($pairs as $pair) {
        $old = $pair[0] ?? null;
        $new = $pair[1] ?? null;
        $data[] = [$old, $new];
        if ($old !== null && $new !== null && $old === $new) {
            $lines .= ui_diff_line('same', $old, 0, 0, $charsFormat);
            continue;
        }
        $changed++;
        if ($old !== null && $new !== null) {
            $c = ui_diff_compare($old, $new);
            $lines .= ui_diff_line('del', $old, $c['prefix'], $c['lenOld'] - $c['suffixOld'], $charsFormat);
            $lines .= ui_diff_line('add', $new, $c['prefix'], $c['lenNew'] - $c['suffixNew'], $charsFormat);
        } elseif ($old !== null) {
            $lines .= ui_diff_line('del', $old, 0, 0, $charsFormat);
        } elseif ($new !== null) {
            $lines .= ui_diff_line('add', $new, 0, 0, $charsFormat);
        }
    }
    $summary = $changed === 0
        ? ($texts['summaryNone'] ?? '')
        : sprintf($texts['summaryChanged'] ?? '%d', $changed);
    $json = json_encode(['pairs' => $data, 'texts' => [
        'chars' => $charsFormat, 'summaryChanged' => $texts['summaryChanged'] ?? '%d', 'summaryNone' => $texts['summaryNone'] ?? '',
        'showFull' => $texts['showFull'] ?? '', 'showLess' => $texts['showLess'] ?? '',
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<div class="dm-diff" aria-live="off"' . (isset($texts['label']) ? ' aria-label="' . ui_e($texts['label']) . '"' : '') . ' data-dm-diff>'
        . '<div class="dm-diff-summary">' . ui_e($summary) . '</div><div class="dm-diff-lines">' . $lines . '</div>'
        . '<script type="application/json" class="dm-diff-data">' . $json . '</script></div>';
}
