<?php
/**
 * Datei: inc/web/system_check_messages.php
 * Zweck: Web-Texte zu den Konfigurationscodes des SystemCheckService (bisher direkt in check_config_validity()).
 */

require_once __DIR__ . '/../app/status/SystemCheckService.php';

/**
 * Konfigurationsprobleme als bisherige Texte (Reihenfolge unverändert).
 *
 * @param array<string, string> $lang
 * @param array<int, array{code: string, value: ?string}> $issues
 * @return array<int, string>
 */
function systemConfigIssueTexts(array $lang, array $issues): array
{
    $texts = [];
    foreach ($issues as $issue) {
        $value = $issue['value'] ?? $lang['unknown'];
        $texts[] = match ($issue['code']) {
            SystemCheckService::BASE_URL_MISSING        => $lang['diag_error_base_url'],
            SystemCheckService::NAMED_CHECKZONE_MISSING => $lang['diag_error_named_checkzone'],
            SystemCheckService::PASSWORD_MIN_INVALID    => $lang['diag_error_password_min'],
            SystemCheckService::PHP_DEV_MODE            => $lang['diag_error_php_dev_mode'],
            SystemCheckService::LOG_LEVEL_INVALID       => sprintf($lang['diag_error_log_level'], $value),
            SystemCheckService::LOG_TARGET_INVALID      => sprintf($lang['diag_error_log_target'], $value),
            default                                     => $issue['code'],
        };
    }

    return $texts;
}
