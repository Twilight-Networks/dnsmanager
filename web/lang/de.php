<?php
return [
    // === Allgemein ===
    'actions' => 'Aktionen',
    'edit' => 'Bearbeiten',
    'delete' => 'Löschen',
    'save' => 'Speichern',
    'cancel' => 'Abbrechen',
    'username' => 'Benutzername',
    'password' => 'Passwort',
    'change_password_for' => 'Passwort ändern für %s',
    'new_password' => 'Neues Passwort',
    'users' => 'Benutzer',
    'zones' => 'Zonen',
    'name' => 'Name',
    'system_status' => 'Systemstatus',
    'dns_servers' => 'DNS-Server',
    'records' => 'Einträge',
    'description' => 'Beschreibung',
    'close' => 'Schließen',
    'inactive' => 'inaktiv',
    'for_example' => 'z. B.',
    'no_changes'             => 'Keine Änderungen vorgenommen.',
    'zone_rebuild_failed'    => 'Zonen-Rebuild fehlgeschlagen für Zone-ID %s.',
    'zone_rebuild_warning'   => 'Warnung beim Zonen-Rebuild für Zone-ID %s.',
    'error_server_not_found' => 'Server nicht gefunden.',

    'error_password_too_short'   => 'Das Passwort muss mindestens %d Zeichen lang sein.',
    'error_password_save'        => 'Fehler beim Speichern des Passworts.',
    'password_changed'           => 'Passwort erfolgreich geändert.',
    'password_changed_admin'       => 'Das Passwort wurde erfolgreich aktualisiert.',
    'error_password_unauthorized'  => 'Du darfst nur dein eigenes Passwort ändern.',
    'error_password_mismatch'      => 'Die Passwörter stimmen nicht überein.',
    'error_password_processing'    => 'Fehler beim Verarbeiten des Passworts.',

    // === login.php ===
    'login_title' => 'Anmeldung',
    'login_button' => 'Einloggen',
    'logout_success' => 'Du wurdest erfolgreich abgemeldet.',
    'login_failed' => 'Login fehlgeschlagen: Benutzername oder Passwort falsch.',
    'session_expired' => 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.',

    // === layout.php ===
    'menu_dashboard' => 'Dashboard',
    'menu_servers' => 'Server',
    'menu_dyndns' => 'DynDNS',
    'menu_logout' => 'Abmelden',
    'menu_check_updates' => 'Auf Updates prüfen',
    'publish' => 'Veröffentlichen',
    'logged_in_as' => 'Angemeldet als:',
    'warnings' => 'Warnungen',
    'errors' => 'Fehler',

    'modal_confirm_delete_title' => 'Löschen bestätigen',
    'modal_confirm_delete_text' => 'Möchtest Du diesen Eintrag wirklich löschen?',

    // === dashboard.php ===
    'welcome' => 'Willkommen',
    'system_error' => 'Es wurden Probleme erkannt. Details unter ',
    'system_warning' => 'Es wurden Warnungen erkannt. Details unter ',
    'system_ok' => 'Systemstatus fehlerfrei.',
    'my_zones' => 'Meine Zonen',

    // === dyndns.php ===
    'dyndns_accounts' => 'DynDNS-Accounts',
    'add_dyndns_account' => 'Neuer DynDNS-Account',
    'hostname' => 'Hostname',
    'zone' => 'Zone',
    'last_update' => 'Letztes Update',

    // form
    'dyndns_add_heading' => 'Neuen DynDNS-Account anlegen',
    'dyndns_hostname_label' => 'Hostname (z. B. home)',
    'dyndns_zone_label' => 'Zugehörige Zone',
    'dyndns_add_submit' => 'Account anlegen',
    'please_select' => '– Bitte wählen –',

    // log
    'dyndns_error_missing_fields' => 'Alle Felder sind erforderlich.',
    'dyndns_error_zone_not_allowed' => 'Zone ist nicht für DynDNS freigegeben.',
    'dyndns_success_account_created' => 'DynDNS-Account erfolgreich erstellt.',
    'dyndns_error_db' => 'Fehler beim Speichern',

    'dyndns_error_invalid_id' => 'Ungültige ID.',
    'dyndns_delete_success'   => 'DynDNS-Account und zugehörige A/AAAA-Records gelöscht.',
    'dyndns_error_delete'     => 'Fehler beim Löschen',

    'dyndns_error_invalid_input'    => 'Ungültige Eingaben.',
    'dyndns_error_zone_not_allowed' => 'Zone ist nicht für DynDNS freigegeben.',
    'dyndns_error_not_found'        => 'DynDNS-Account nicht gefunden.',
    'dyndns_no_ip_warning'          => 'Keine IP übernommen.',
    'dyndns_updated'                => 'DynDNS-Account aktualisiert.',
    'dyndns_error_update'           => 'Fehler beim Speichern',

    'dyndns_error_unauthorized'         => 'Zugriff verweigert.',

    // js
    'clipboard_success' => 'In der Zwischenablage',
    'clipboard_failed' => 'Kopieren fehlgeschlagen.',

    // === DynDNS Info Toggle ===
    'dyndns_info_toggle' => 'Informationen zur DynDNS-Integration anzeigen',
    'dyndns_info_title' => 'Hinweis zur DynDNS-Integration:',
    'dyndns_info_text_1' => 'Um DynDNS z. B. in einer <strong>FRITZ!Box</strong> oder einem anderen Router zu nutzen, verwenden Sie folgende Update-URL:',
    'dyndns_supported_parameters' => 'Unterstützte Parameter:',
    'ipv4_address' => 'IPv4-Adresse',
    'ipv6_address' => 'IPv6-Adresse',
    'important' => 'Wichtig:',
    'dyndns_info_auth' => 'Die Authentifizierung erfolgt per HTTP Basic Auth.<br>
    Benutzername und Kennwort müssen im Router eingetragen werden (z.&nbsp;B. unter <em>Dynamic DNS</em>).<br>
    Die Platzhalter <code>&lt;ipaddr&gt;</code> und <code>&lt;ip6addr&gt;</code> werden vom Gerät automatisch ersetzt.',
    'dyndns_copy_title' => 'In Zwischenablage kopieren',

    // === records.php ===
    'dns_records_for' => 'DNS-Einträge für %s',
    'add_record' => 'Neuer Eintrag',
    'delete_records' => 'Einträge löschen',
    'zone_diagnostic_for' => 'Zonenprüfung für <code>%s</code> auf allen zugewiesenen Servern:',
    'records_ns' => 'Name-Server Konfiguration (NS)',
    'records_mail' => 'Mail-Konfiguration (MX, SPF, DKIM)',
    'records_host' => 'Host-Einträge (A, AAAA, CNAME, PTR, TXT)',
    'records_host_reverse' => 'Host-Einträge (PTR, TXT)',
    'record_type' => 'Typ',
    'record_content' => 'Inhalt',
    'glue_protected' => 'Glue-Record – nicht löschbar',

    'ttl_auto' => 'Auto',
    'seconds' => 'Sekunden',
    'minute'  => 'Minute',
    'minutes' => 'Minuten',
    'hour'    => 'Stunde',
    'hours'   => 'Stunden',
    'day'     => 'Tag',

    // form
    'add_new_record' => 'Neuen DNS-Eintrag hinzufügen',
    'record_type_info_button' => 'Weitere Informationen zum Record-Typ',
    'record_name' => 'Name',
    'record_name_reverse' => 'IP-Anteil',
    'domain' => 'Domain',
    'ttl' => 'TTL',
    'priority' => 'Priorität',
    'weight' => 'Gewicht',
    'port' => 'Port',
    'target' => 'Ziel',
    'protocol' => 'Protokoll',

    // MX
    'mx_target' => 'Mailserver (FQDN)',

    // DKIM
    'dkim_selector' => 'DKIM Selector',
    'dkim_subdomain' => 'Subdomain (optional)',
    'dkim_key_type' => 'Key Type',
    'dkim_hash' => 'Hash',
    'dkim_flags' => 'Flags',
    'dkim_key' => 'Public Key',
    'dkim_upload_label' => 'openDKIM-Konfigurationsdatei hochladen',

    // URI
    'uri_service' => 'Dienst',
    'uri_target' => 'Ziel-URI',

    // NAPTR
    'order' => 'Order',
    'preference' => 'Preference',
    'flags' => 'Flags',
    'service' => 'Service',
    'regexp' => 'Regexp',
    'replacement' => 'Replacement',

    'auto_ptr' => 'PTR-Eintrag automatisch anlegen (für A/AAAA)',

    //log
    'record_error_auto_ptr_invalid_ip'   => 'PTR-Eintrag konnte nicht erzeugt werden – ungültige IP-Adresse oder Formatfehler.',
    'record_error_auto_ptr_no_zone'     => 'PTR-Eintrag nicht möglich – passende Reverse-Zone %s ist nicht vorhanden.',
    'record_error_auto_ptr_duplicate'   => 'PTR-Eintrag nicht möglich – in der Zone %s existiert bereits ein PTR für %s.',
    'record_error_auto_ptr_db'          => 'Fehler beim automatischen PTR-Eintrag.',

    'record_error_dkim_file_too_large'    => 'Die Datei ist zu groß. Die maximale Dateigröße beträgt 2 KB.',
    'record_error_dkim_invalid_extension' => 'Nur .txt-Dateien sind erlaubt.',
    'record_error_dkim_invalid_code'      => 'Die Datei enthält unerlaubten Code und konnte nicht verarbeitet werden.',
    'record_error_dkim_parse_failed'      => 'Die DKIM-Datei konnte nicht verarbeitet werden.',
    'record_error_build_failed'           => 'Fehler beim Verarbeiten des Record-Inhalts.',
    'record_error_duplicate'              => 'Ein identischer %2$s-Eintrag für <code>%1$s</code> existiert bereits.',
    'record_error_zonefile_invalid'       => 'Der Record konnte nicht gespeichert werden, da die Zonendatei ungültig wäre.',
    'record_warning_zonefile_check'       => 'Record gespeichert – Warnung beim Zonendatei-Check.',
    'record_error_db_save_failed'         => 'Beim Speichern des Records ist ein Fehler aufgetreten.',
    'record_created'                      => 'Record <strong>%1$s %2$s</strong> erfolgreich in <strong>%3$s</strong> hinzugefügt.',

    'record_error_build_failed'        => 'Der Record konnte nicht verarbeitet werden.',
    'record_error_glue_name_change'   => 'Namensänderung bei Glue-Records ist nicht erlaubt.',
    'record_error_glue_type_change'   => 'Typänderung bei Glue-Records ist nicht erlaubt.',
    'record_error_ns_name_change'     => 'Namensänderung bei geschütztem NS-Record ist nicht erlaubt.',
    'record_error_ns_content_change'  => 'Zieländerung bei geschütztem NS-Record ist nicht erlaubt.',
    'record_updated'                  => 'Record <strong>%1$s %2$s</strong> erfolgreich in <strong>%3$s</strong> aktualisiert.',

    'record_error_glue_protected'                => '%s darf nicht gelöscht werden (Glue-Record).',
    'record_error_ns_protected'                  => '%s ist durch Server-Zuweisung oder Glue geschützt.',
    'record_warning_zonefile_check_after_delete' => 'Record(s) gelöscht – Warnung beim Zonendatei-Check in %s.',
    'record_deleted_success'                     => 'Record(s) erfolgreich gelöscht.',
    'record_error_delete_failed'                 => 'Beim Löschen der Records ist ein Fehler aufgetreten.',

    // js
    'record_mx_invalid_priority' => 'Bitte eine gültige Priorität (Zahl) für den MX-Eintrag angeben.',
    'record_mx_missing_target' => 'Bitte einen gültigen Mailserver angeben.',
    'record_naptr_missing_fields' => 'Bitte alle NAPTR-Felder ausfüllen.',
    'record_naptr_missing_name' => 'Bitte einen Namen für den NAPTR-Eintrag angeben.',
    'record_srv_missing_fields' => 'Bitte Dienstname und Protokoll für den SRV-Eintrag angeben.',
    'record_srv_invalid_numbers' => 'SRV: Priorität, Weight und Port müssen numerisch sein.',
    'record_srv_missing_target' => 'Bitte ein gültiges Ziel für den SRV-Eintrag angeben.',
    'record_srv_name_invalid'      => 'Dienstname muss mit Unterstrich beginnen, z. B. _sip',
    'record_caa_missing_name' => 'Bitte einen Namen für den CAA-Eintrag angeben.',
    'record_dkim_missing_fields' => 'Bitte alle DKIM-Felder ausfüllen.',
    'record_uri_missing_fields'    => 'Bitte alle URI-Felder ausfüllen.',

    'record_info_forward_a' => '<strong>A-Record:</strong><br><br>Verweist auf eine IPv4-Adresse (z. B. 192.0.2.1).',
    'record_info_forward_aaaa' => '<strong>AAAA-Record:</strong><br><br>Verweist auf eine IPv6-Adresse (z. B. 2001:db8::1).',
    'record_info_forward_cname' => '<strong>CNAME-Record:</strong><br><br>Alias für einen anderen Hostnamen.<br>Wichtig: Nicht mit anderen Record-Typen kombinieren.',
    'record_info_forward_mx' => '<strong>MX-Record:</strong><br><br>Definiert Mailserver für die Domain.<br>Der Wert ist ein Hostname (FQDN), keine IP.',
    'record_info_forward_ns' => '<strong>NS-Record:</strong><br><br>Autoritativer Nameserver der Zone.<br>Wird meist automatisch gesetzt.',
    'record_info_forward_ptr' => '<strong>PTR-Record:</strong><br><br>Zeigt auf den Hostnamen einer IP-Adresse.<br>Erforderlich: vollständiger FQDN mit Punkt.',
    'record_info_forward_txt' => '<strong>TXT-Record:</strong><br><br>Freitext oder strukturierte Daten.<br>Beispiel: "v=verify123"',
    'record_info_forward_spf' => '<strong>SPF-Record:</strong><br><br>Definiert erlaubte Mailserver (Teil der TXT-Records).',
    'record_info_forward_dkim' => '<strong>DKIM:</strong><br><br>Erzeugt automatisch einen TXT-Record mit öffentlichem Schlüssel.',
    'record_info_forward_loc' => '<strong>LOC-Record:</strong><br><br>Speichert geographische Koordinaten.<br>Format: "52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m"',
    'record_info_forward_caa' => '<strong>CAA-Record:</strong><br><br>Legt fest, welche Zertifizierungsstellen Zertifikate ausstellen dürfen.<br>Beispiel: 0 issue "letsencrypt.org"',
    'record_info_forward_srv' => '<strong>SRV-Record:</strong><br><br>Definiert Services mit Priorität, Gewichtung, Port und Ziel.<br>Beispiel: 0 5 5060 sipserver.example.com',
    'record_info_forward_naptr' => '<strong>NAPTR-Record:</strong><br><br>Mapping-Mechanismus für Dienste (z. B. SIP, ENUM).<br>Format: Order Preference "Flags" "Service" "Regexp" Replacement<br>Beispiel: 100 10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .',
    'record_info_forward_uri' => '<strong>URI-Record:</strong><br><br>Definiert einen Dienst über Dienstname, Protokoll, Priorität, Gewichtung und Ziel-URI.<br>Format: &lt;Prio&gt; &lt;Weight&gt; "&lt;URI&gt;"<br>Beispiel: 10 1 "ftp://ftp1.example.com/public"',
    'record_info_reverse_ptr' => '<strong>PTR-Record:</strong><br><br>Zeigt auf den Hostnamen einer IP-Adresse.<br>Erforderlich: vollständiger FQDN mit Punkt.',
    'record_info_reverse_txt' => '<strong>TXT-Record:</strong><br><br>Freitext oder strukturierte Daten.<br>Beispiel: "Zertifikat gültig bis ..."',

    // === servers.php ===
    'add_server' => 'Neuer Server',
    'server_status' => 'Serverstatus',
    'server_error' => 'Fehlerhafte Server erkannt',
    'server_warning' => 'Warnungen bei Serverprüfungen',
    'server_ok' => 'Alle Server in Ordnung',
    'local' => 'Lokal',
    'active' => 'Aktiv',
    'yes' => 'Ja',
    'no' => 'Nein',
    'bind_reload' => 'BIND Reload',
    'bind_reload_title' => 'BIND neu laden',
    'bind_reload_confirm' => 'Bind auf diesem Server wirklich neu laden?',

    // form
    'add_new_server' => 'Neuen DNS-Server hinzufügen',
    'server_name' => 'Servername (FQDN)',
    'dns_ipv4' => 'DNS-IPv4-Adresse (IPv4)',
    'dns_ipv6' => 'DNS-IP-Adresse (IPv6)',
    'same_as_api_ip' => '= API-IP',
    'api_ip' => 'API-IP-Adresse (IPv4/IPv6)',
    'api_token' => 'API-Key',
    'api_token_placeholder' => 'z. B. 64-stelliger Hexwert',
    'generate' => 'Generieren',
    'server_active' => 'Server ist aktiv',
    'server_is_local' => 'Dieser Server ist der lokale (Webinterface-Host)',
    'add_server' => 'Server hinzufügen',

    // log
    'server_error_invalid_id'           => 'Ungültige ID.',
    'server_error_invalid_name'         => 'Ungültiger oder fehlender Servername',
    'server_error_invalid_dns_ip'       => 'Mindestens eine gültige DNS-IP-Adresse (IPv4 oder IPv6) ist erforderlich',
    'server_error_invalid_ipv4'         => 'Ungültige IPv4-Adresse',
    'server_error_invalid_ipv6'         => 'Ungültige IPv6-Adresse',
    'server_error_invalid_api_ip'       => 'Ungültige API-IP-Adresse',
    'server_error_api_ip_required'      => 'API-IP-Adresse ist erforderlich',
    'server_error_api_token_required'   => 'API-Key fehlt oder ist zu kurz',
    'server_error_local_exists'         => 'Es darf nur einen lokalen Server geben',
    'server_error_invalid_input'        => 'Fehlerhafte Eingabe',
    'server_created_success'            => 'Server <strong>%s</strong> erfolgreich hinzugefügt.',
    'server_error_db_save_failed'       => 'Beim Hinzufügen des Servers ist ein Fehler aufgetreten.',

    'server_error_master_deactivation_blocked' => 'Der Server ist als Master-Server in mindestens einer Zone eingetragen und kann daher nicht deaktiviert werden.',
    'server_updated_success'            => 'Server <strong>%s</strong> erfolgreich aktualisiert.',
    'server_error_db_update_failed'     => 'Beim Speichern des Servers ist ein Fehler aufgetreten.',

    'server_error_delete_assigned' => 'Der Server ist noch mindestens einer Zone zugewiesen und kann daher nicht gelöscht werden.',

    // === system_health.php ===
    'system_update_now' => 'Status jetzt aktualisieren',

    'toast_monitoring_failed' => 'Monitoring-Statusprüfung fehlgeschlagen.',
    'toast_monitoring_success' => 'Systemstatus erfolgreich aktualisiert.',
    'toast_monitoring_missing' => 'Monitoring-Skript nicht auffindbar.',

    'config_check' => 'Konfiguration (lokal)',
    'config_ok' => 'Alle Konfigurationen korrekt',
    'config_errors_found' => '%d Fehler gefunden',
    'config_hint' => 'Überprüfen Sie Ihre Konfigurationsdatei <code>ui_config.php</code>.',

    'php_version_local' => 'PHP-Version (lokal)',
    'php_version_outdated' => 'Veraltet',

    'php_modules_local' => 'PHP-Module (lokal)',
    'php_module_missing' => 'Fehlt',

    'file_permissions_local' => 'Dateiberechtigungen (lokal)',
    'file_permissions_ok' => 'Alle Berechtigungen korrekt',
    'file_permissions_errors' => '%d Fehler gefunden',
    'file_permissions_hint' => 'Führen Sie das Skript <code>fix_perms.sh</code> aus, um die Berechtigungen automatisch zu korrigieren.',

    'server_errors' => '%d Fehler erkannt',

    'named_checkconf' => 'named-checkconf',
    'named_checkconf_ok' => 'keine Fehler',
    'named_checkconf_issues' => '%d betroffene Server',

    'named_checkzone' => 'named-checkzone',
    'named_checkzone_ok' => 'keine Fehler',
    'named_checkzone_issues' => '%d betroffene Zonen',

    'status_ok' => 'OK',
    'status_error' => 'Fehler',
    'status_warning' => 'Warnungen',
    'status_missing' => 'Fehlt',
    'status_outdated' => 'Veraltet',
    'hint' => 'Hinweis',

    // === update.php ===
    'update_connection_failed' => 'Verbindung zum Update-Server fehlgeschlagen: %s',
    'update_invalid_response' => 'Ungültige Antwort vom Update-Server.',
    'unknown' => 'unbekannt',

    'update_check' => 'Update-Prüfung',
    'update_error' => 'Verbindung zum Update-Server fehlgeschlagen',
    'update_available' => 'Eine neue Version ist verfügbar:',
    'update_version' => 'Version %s',
    'update_released' => 'veröffentlicht am %s',
    'update_download' => 'Zum Download',
    'update_changelog' => 'Zum Changelog',
    'update_current' => 'Du verwendest die aktuellste Version (%s).',

    // === users.php ===
    'user_management' => 'Benutzerverwaltung',
    'add_user' => 'Neuer Benutzer',
    'edit_mode' => 'Bearbeitungsmodus',
    'all_zones' => 'Alle Zonen',
    'repeat_password' => 'Passwort wiederholen',

    // form
    'add_new_user' => 'Neuen Benutzer anlegen',
    'create_user' => 'Benutzer anlegen',
    'role' => 'Rolle',
    'role_admin' => 'Administrator',
    'role_zoneadmin' => 'Zonen-Administrator',
    'all_zones' => 'Alle Zonen',

    // log
    'user_error_invalid_username'   => 'Ungültiger Benutzername.',
    'user_error_username_exists'    => 'Benutzername existiert bereits.',
    'user_created'                  => 'Benutzer <strong>%s</strong> erfolgreich angelegt.',
    'user_error_create_failed'      => 'Fehler beim Anlegen des Benutzers.',

    'user_error_invalid_id'  => 'Ungültige Benutzer-ID.',
    'user_error_not_found'   => 'Benutzer existiert nicht.',
    'user_error_last_admin'  => 'Der letzte Admin darf nicht gelöscht werden.',
    'user_deleted'           => 'Benutzer erfolgreich gelöscht.',
    'user_error_delete'      => 'Fehler beim Löschen des Benutzers.',

    'user_error_load_failed' => 'Benutzer konnte nicht geladen werden.',
    'user_updated' => 'Benutzer <strong>%s</strong> erfolgreich aktualisiert.',
    'user_error_update_failed' => 'Fehler beim Aktualisieren des Benutzers.',

    // === zones.php ===
    'dns_zones' => 'DNS-Zonen',
    'add_zone' => 'Neue Zone',
    'zone_status' => 'Zonenstatus',
    'zone_errors' => 'Fehlerhafte Zonen vorhanden',
    'zone_warnings' => 'Warnungen bei der Zonenprüfung',
    'zone_ok' => 'Alle Zonen gültig',
    'forward_zones' => 'Forward Lookup Zonen',
    'reverse_zones' => 'Reverse Lookup Zonen',
    'zone_icon_error' => 'Fehlerhafte Zonendatei',
    'zone_icon_warning' => 'Warnung bei named-checkzone',
    'zone_icon_changed' => 'Änderung noch nicht veröffentlicht',
    'zone_icon_ok' => 'Zonendatei gültig',
    'server_inactive' => 'Dieser Server ist derzeit deaktiviert.',

    // form
    'add_new_zone' => 'Neue DNS-Zone anlegen',
    'zone_name' => 'Zonenname',
    'zone_type' => 'Typ',
    'zone_type_forward' => 'Forward',
    'zone_type_reverse4' => 'Reverse IPv4',
    'zone_type_reverse6' => 'Reverse IPv6',
    'prefix_length' => 'Prefix-Länge (nur Reverse)',
    'soa_settings' => 'SOA Einstellungen',
    'soa_ns' => 'SOA NS',
    'soa_domain' => 'SOA-Server-Domain (nur bei Reverse)',
    'soa_mail' => 'SOA-Mailadresse',
    'soa_refresh' => 'Refresh',
    'soa_retry' => 'Retry',
    'soa_expire' => 'Expire',
    'soa_minimum' => 'Minimum TTL',
    'assign_dns_servers' => 'DNS-Serverzuweisung',
    'assign' => 'Zuweisen',
    'assign_hint' => 'Bitte mindestens einen Server auswählen. Genau einer muss als Master definiert sein.',
    'allow_dyndns' => 'DynDNS erlaubt',
    'dyndns_zone_hint' => 'Zone darf über DynDNS aktualisiert werden',
    'description_optional' => 'Beschreibung (optional)',
    'create_zone' => 'Zone erstellen',
    'server_ignored_on_publish' => 'Dieser Server ist deaktiviert und wird bei Veröffentlichung ignoriert.',
    'auto_filled' => 'wird automatisch gesetzt',

    // log
    'zone_error_no_server_selected'    => 'Es muss mindestens ein Server ausgewählt werden.',
    'zone_error_master_not_included'  => 'Der Master-Server muss unter den gewählten Servern sein.',
    'zone_error_master_load_failed'   => 'Master-Server konnte nicht geladen werden.',
    'zone_error_zonefile_invalid'     => 'Die Zone konnte nicht gespeichert werden, da die Zonendatei ungültig wäre.',
    'zone_warning_zonefile_check'     => 'Zone wurde gespeichert – Warnung beim Zonendatei-Check.',
    'zone_created'       => 'Zone <strong>%s</strong> erfolgreich angelegt.',
    'zone_error_db_save_failed'       => 'Fehler beim Speichern der Zone.',

    'zone_error_not_found'             => 'Zone nicht gefunden.',
    'zone_error_invalid_prefix_length' => 'Ungültiger Prefix-Length.',
    'zone_error_no_servers'           => 'Mindestens ein DNS-Server muss gewählt sein.',
    'zone_error_master_not_in_list'   => 'Der Master-Server muss unter den gewählten Servern sein.',
    'zone_error_master_inactive'      => 'Master-Server ist inaktiv.',
    'zone_error_server_assignment_failed' => 'Fehler beim Aktualisieren der Server-Zuweisungen.',
    'zone_error_check_failed'         => 'Zone konnte nicht geprüft werden.',
    'zone_error_zonefile_invalid'     => 'Die Zonendatei ist ungültig – Änderungen wurden verworfen.',
    'zone_warning_zonefile_check'     => 'Zone wurde aktualisiert – Warnung beim Zonendatei-Check.',
    'zone_error_ns_glue_failed' => 'Zonenstruktur konnte nicht neu aufgebaut werden.',
    'zone_warning_ns_glue'      => 'Zone wurde aktualisiert – Warnung beim Zonen-Rebuild.',
    'zone_updated'              => 'Zone <strong>%s</strong> erfolgreich aktualisiert.',

    'zone_error_not_found'      => 'Zone nicht gefunden.',
    'zone_deleted'              => 'Zone <strong>%s</strong> wurde gelöscht.',
    'zone_error_delete_failed'  => 'Fehler beim Löschen der Zone.',

    // js
    'zone_form_no_server_selected' => 'Bitte mindestens einen DNS-Server auswählen.',
    'zone_form_no_master_selected' => 'Bitte einen Master-Server auswählen.',
    'zone_form_master_not_among_selected' => 'Der gewählte Master-Server muss auch bei den ausgewählten DNS-Servern markiert sein.',

    // === bind_reload.php ===
    'bind_reload_success' => 'BIND Reload auf <strong>%s</strong> erfolgreich:<br><code>%s</code>',
    'bind_reload_failed' => 'BIND Reload auf <strong>%s</strong> fehlgeschlagen:<br><code>%s</code>',

    // === publish_all.php ===
    'publish_all_success'   => 'Alle Zonen erfolgreich veröffentlicht.',
    'publish_all_warning'   => 'Warnung bei Veröffentlichung.',
    'publish_all_error'     => 'Fehler bei Veröffentlichung.',
    'publish_all_exception' => 'Veröffentlichung fehlgeschlagen.',

    // === validators.php ===
    'generic_validation_error' => 'Unbekannter Validierungsfehler.',

    // DNS-Record Validierungsfehler
    'ERR_INVALID_NAME_CHARS'      => 'Der Name enthält ungültige Zeichen (z. B. Leer- oder Steuerzeichen).',
    'ERR_TTL_NEGATIVE'            => 'TTL darf nicht negativ sein.',
    'ERR_INVALID_IPV4'            => 'Ungültige IPv4-Adresse.',
    'ERR_INVALID_IPV6'            => 'Ungültige IPv6-Adresse.',
    'ERR_MX_PARTS_INVALID'        => 'MX-Record muss zwei Teile enthalten: <priority> <target>.',
    'ERR_MX_PRIORITY_INVALID'     => 'Priority im MX-Record muss zwischen 0 und 65535 liegen.',
    'ERR_MX_TARGET_INVALID'       => 'Target im MX-Record ist kein gültiger FQDN.',
    'ERR_FQDN_REQUIRED'           => 'PTR-, NS- und CNAME-Records müssen einen gültigen FQDN enthalten.',
    'ERR_SPF_FORMAT'              => 'SPF-Inhalt muss mit "v=spf1 ..." beginnen.',
    'ERR_DKIM_QUOTES'             => 'DKIM-Eintrag muss in Anführungszeichen stehen.',
    'ERR_DKIM_VERSION'            => 'DKIM-Eintrag muss mit v=DKIM1 beginnen.',
    'ERR_DKIM_KEYTYPE'            => 'DKIM-Eintrag muss k=rsa oder k=ed25519 enthalten.',
    'ERR_DKIM_KEY_INVALID'        => 'DKIM Public Key (p=...) ist kein gültiger Base64-String.',
    'ERR_DKIM_KEY_MISSING'        => 'DKIM-Eintrag enthält keinen gültigen Public Key (p=...).',
    'ERR_TXT_TOO_LONG'            => 'TXT-Inhalt darf maximal 512 Zeichen lang sein.',
    'ERR_LOC_FORMAT'              => 'Ungültiges LOC-Format. Beispiel: 52 31 0.000 N 13 24 0.000 E 34.0m',
    'ERR_CAA_FORMAT'              => 'Ungültiger CAA-Record. Erwartet: 0 issue "letsencrypt.org"',
    'ERR_SRV_PARTS_INVALID'       => 'SRV-Record muss genau vier Teile enthalten: <priority> <weight> <port> <target>.',
    'ERR_SRV_NUMERIC_FIELDS'      => 'Priority, Weight und Port müssen Ganzzahlen zwischen 0 und 65535 sein.',
    'ERR_SRV_TARGET_INVALID'      => 'Target im SRV-Record ist kein gültiger FQDN.',
    'ERR_NAPTR_PARTS_INVALID'     => 'NAPTR-Record muss genau sechs Teile enthalten: <order> <preference> "<flags>" "<service>" "<regexp>" <replacement>.',
    'ERR_NAPTR_ORDER_PREF'        => 'Order und Preference müssen Ganzzahlen zwischen 0 und 65535 sein.',
    'ERR_NAPTR_FLAGS'             => 'Flags müssen in Anführungszeichen stehen (z. B. "U").',
    'ERR_NAPTR_SERVICE'           => 'Service muss in Anführungszeichen stehen (z. B. "E2U+sip").',
    'ERR_NAPTR_REGEXP'            => 'Regexp muss in Anführungszeichen stehen (z. B. "!^.*$!sip:info@example.com!").',
    'ERR_NAPTR_REPLACEMENT'       => 'Replacement muss ein gültiger FQDN oder ein einzelner Punkt sein.',
    'ERR_URI_PARTS_INVALID'       => 'URI-Record muss genau drei Teile enthalten: <priority> <weight> "<target>".',
    'ERR_URI_PRIORITY'            => 'Priority im URI-Record muss eine Ganzzahl zwischen 0 und 65535 sein.',
    'ERR_URI_WEIGHT'              => 'Weight im URI-Record muss eine Ganzzahl zwischen 0 und 65535 sein.',
    'ERR_URI_TARGET'              => 'Ziel im URI-Record muss in Anführungszeichen stehen (z. B. "https://example.com").',
    'ERR_UNKNOWN_RECORD_TYPE'     => 'Unbekannter oder nicht unterstützter Record-Typ.',

    // Zonen-Validierungsfehler
    'ERR_ZONE_EMPTY'              => 'Zonenname darf nicht leer sein.',
    'ERR_ZONE_NAME_INVALID'       => 'Ungültiger Zonenname.',
    'ERR_ZONE_PREFIX_LENGTH'      => 'Prefix-Length für Reverse-Zonen muss zwischen 8 und 128 liegen.',
    'ERR_ZONE_SOA_MAIL'           => 'Ungültige Administrator-Adresse (SOA Mail).',
    'ERR_ZONE_SOA_REFRESH'        => 'SOA-Refresh muss zwischen 1200 und 86400 Sekunden liegen.',
    'ERR_ZONE_SOA_RETRY'          => 'SOA-Retry muss zwischen 180 und 7200 Sekunden liegen.',
    'ERR_ZONE_SOA_EXPIRE'         => 'SOA-Expire muss zwischen 1209600 und 2419200 Sekunden liegen.',
    'ERR_ZONE_SOA_MINIMUM'        => 'SOA-Minimum TTL muss zwischen 300 und 86400 Sekunden liegen.',

    // === diagnostics.php ===
    'diag_error_base_url'       => 'BASE_URL ist nicht definiert.',
    'diag_error_named_checkzone'=> 'NAMED_CHECKZONE ist nicht vorhanden oder nicht ausführbar.',
    'diag_error_password_min'   => 'PASSWORD_MIN_LENGTH ist zu niedrig oder nicht gesetzt.',
    'diag_error_php_dev_mode'   => 'PHP_ERR_REPORT steht auf true (Entwicklungsmodus aktiv).',
    'diag_error_log_level'      => 'Ungültiger LOG_LEVEL: %s',
    'diag_error_log_target'     => 'Ungültiger LOG_TARGET: %s',
];
