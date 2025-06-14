<?php
return [
    // === Allgemein ===
    'actions' => 'Actions',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'username' => 'Username',
    'password' => 'Password',
    'change_password_for' => 'Change password for %s',
    'new_password' => 'New password',
    'users' => 'Users',
    'zones' => 'Zones',
    'name' => 'Name',
    'system_status' => 'System Status',
    'dns_servers' => 'DNS Servers',
    'records' => 'Records',
    'description' => 'Description',
    'close' => 'Close',
    'inactive' => 'inactive',
    'for_example' => 'e.g.',
    'no_changes'             => 'No changes made.',
    'zone_rebuild_failed'    => 'Zone rebuild failed for zone ID %s.',
    'zone_rebuild_warning'   => 'Warning during zone rebuild for zone ID %s.',
    'error_server_not_found' => 'Server not found.',

    'error_password_too_short'   => 'The password must be at least %d characters long.',
    'error_password_save'        => 'Error saving the password.',
    'password_changed'           => 'Password changed successfully.',
    'password_changed_admin'       => 'The password has been updated successfully.',
    'error_password_unauthorized'  => 'You may only change your own password.',
    'error_password_mismatch'      => 'The passwords do not match.',
    'error_password_processing'    => 'Error processing the password.',

    // === login.php ===
    'login_title' => 'Login',
    'login_button' => 'Log in',
    'logout_success' => 'You have been logged out successfully.',
    'login_failed' => 'Login failed: Incorrect username or password.',
    'session_expired' => 'Your session has expired. Please log in again.',

    // === layout.php ===
    'menu_dashboard' => 'Dashboard',
    'menu_servers' => 'Servers',
    'menu_dyndns' => 'DynDNS',
    'menu_logout' => 'Logout',
    'menu_check_updates' => 'Check for updates',
    'publish' => 'Publish',
    'logged_in_as' => 'Logged in as:',
    'warnings' => 'Warnings',
    'errors' => 'Errors',

    'modal_confirm_delete_title' => 'Confirm deletion',
    'modal_confirm_delete_text' => 'Do you really want to delete this entry?',

    // === dashboard.php ===
    'welcome' => 'Welcome',
    'system_error' => 'Problems were detected. Details in ',
    'system_warning' => 'Warnings were detected. Details in ',
    'system_ok' => 'System status OK.',
    'my_zones' => 'My Zones',

    // === dyndns.php ===
    'dyndns_accounts' => 'DynDNS Accounts',
    'add_dyndns_account' => 'New DynDNS Account',
    'hostname' => 'Hostname',
    'zone' => 'Zone',
    'last_update' => 'Last Update',

    // form
    'dyndns_add_heading' => 'Create new DynDNS account',
    'dyndns_hostname_label' => 'Hostname (e.g. home)',
    'dyndns_zone_label' => 'Assigned zone',
    'dyndns_add_submit' => 'Create account',
    'please_select' => '– Please select –',

    // log
    'dyndns_error_missing_fields' => 'All fields are required.',
    'dyndns_error_zone_not_allowed' => 'Zone is not enabled for DynDNS.',
    'dyndns_success_account_created' => 'DynDNS account created successfully.',
    'dyndns_error_db' => 'Error while saving',

    'dyndns_error_invalid_id' => 'Invalid ID.',
    'dyndns_delete_success'   => 'DynDNS account and related A/AAAA records deleted.',
    'dyndns_error_delete'     => 'Error while deleting',

    'dyndns_error_invalid_input'    => 'Invalid input.',
    'dyndns_error_zone_not_allowed' => 'Zone is not enabled for DynDNS.',
    'dyndns_error_not_found'        => 'DynDNS account not found.',
    'dyndns_no_ip_warning'          => 'No IP address applied.',
    'dyndns_updated'                => 'DynDNS account updated.',
    'dyndns_error_update'           => 'Error while saving',

    'dyndns_error_unauthorized'         => 'Access denied.',

    // js
    'clipboard_success' => 'Copied to clipboard',
    'clipboard_failed' => 'Copy failed.',

    // === DynDNS Info Toggle ===
    'dyndns_info_toggle' => 'Show information about DynDNS integration',
    'dyndns_info_title' => 'Information about DynDNS integration:',
    'dyndns_info_text_1' => 'To use DynDNS (e.g. in a <strong>FRITZ!Box</strong> or another router), use the following update URL:',
    'dyndns_supported_parameters' => 'Supported parameters:',
    'ipv4_address' => 'IPv4 address',
    'ipv6_address' => 'IPv6 address',
    'important' => 'Important:',
    'dyndns_info_auth' => 'Authentication is done via HTTP Basic Auth.<br>
    Username and password must be configured in the router (e.g. under <em>Dynamic DNS</em>).<br>
    The placeholders <code>&lt;ipaddr&gt;</code> and <code>&lt;ip6addr&gt;</code> are automatically replaced by the device.',
    'dyndns_copy_title' => 'Copy to clipboard',

    // === records.php ===
    'dns_records_for' => 'DNS Records for %s',
    'add_record' => 'New Record',
    'delete_records' => 'Delete Records',
    'zone_diagnostic_for' => 'Zone diagnostics for <code>%s</code> on all assigned servers:',
    'records_ns' => 'Name Server Configuration (NS)',
    'records_mail' => 'Mail Configuration (MX, SPF, DKIM)',
    'records_host' => 'Host Records (A, AAAA, CNAME, PTR, TXT)',
    'records_host_reverse' => 'Host Records (PTR, TXT)',
    'record_type' => 'Type',
    'record_content' => 'Content',
    'glue_protected' => 'Glue record – cannot be deleted',

    'ttl_auto' => 'Auto',
    'seconds' => 'seconds',
    'minute'  => 'minute',
    'minutes' => 'minutes',
    'hour'    => 'hour',
    'hours'   => 'hours',
    'day'     => 'day',

    // form
    'add_new_record' => 'Add new DNS record',
    'record_type_info_button' => 'More information about the record type',
    'record_name' => 'Name',
    'record_name_reverse' => 'IP part',
    'domain' => 'Domain',
    'ttl' => 'TTL',
    'priority' => 'Priority',
    'weight' => 'Weight',
    'port' => 'Port',
    'target' => 'Target',
    'protocol' => 'Protocol',

    // MX
    'mx_target' => 'Mail server (FQDN)',

    // DKIM
    'dkim_selector' => 'DKIM Selector',
    'dkim_subdomain' => 'Subdomain (optional)',
    'dkim_key_type' => 'Key type',
    'dkim_hash' => 'Hash',
    'dkim_flags' => 'Flags',
    'dkim_key' => 'Public Key',
    'dkim_upload_label' => 'Upload openDKIM configuration file',

    // URI
    'uri_service' => 'Service',
    'uri_target' => 'Target URI',

    // NAPTR
    'order' => 'Order',
    'preference' => 'Preference',
    'flags' => 'Flags',
    'service' => 'Service',
    'regexp' => 'Regexp',
    'replacement' => 'Replacement',

    'auto_ptr' => 'Automatically create PTR record (for A/AAAA)',

    // log
    'record_error_auto_ptr_invalid_ip'   => 'PTR record could not be created – invalid IP address or format error.',
    'record_error_auto_ptr_no_zone'     => 'PTR record not possible – corresponding reverse zone %s does not exist.',
    'record_error_auto_ptr_duplicate'   => 'PTR record not possible – PTR for %s already exists in zone %s.',
    'record_error_auto_ptr_db'          => 'Error while creating PTR record automatically.',

    'record_error_dkim_file_too_large'    => 'The file is too large. Maximum allowed size is 2 KB.',
    'record_error_dkim_invalid_extension' => 'Only .txt files are allowed.',
    'record_error_dkim_invalid_code'      => 'The file contains disallowed code and could not be processed.',
    'record_error_dkim_parse_failed'      => 'The DKIM file could not be processed.',
    'record_error_build_failed'           => 'Error while processing the record content.',
    'record_error_duplicate'              => 'A duplicate %2$s record for <code>%1$s</code> already exists.',
    'record_error_zonefile_invalid'       => 'The record could not be saved because the zone file would be invalid.',
    'record_warning_zonefile_check'       => 'Record saved – warning during zone file check.',
    'record_error_db_save_failed'         => 'An error occurred while saving the record.',
    'record_created'                      => 'Record <strong>%1$s %2$s</strong> successfully added to <strong>%3$s</strong>.',

    'record_error_build_failed'        => 'The record could not be processed.',
    'record_error_glue_name_change'   => 'Renaming glue records is not allowed.',
    'record_error_glue_type_change'   => 'Changing the type of glue records is not permitted.',
    'record_error_ns_name_change'     => 'Renaming protected NS records is not allowed.',
    'record_error_ns_content_change'  => 'Changing the target of protected NS records is not permitted.',
    'record_updated'                  => 'Record <strong>%1$s %2$s</strong> successfully updated in <strong>%3$s</strong>.',

    'record_error_glue_protected'                => '%s cannot be deleted (Glue record).',
    'record_error_ns_protected'                  => '%s is protected by server assignment or glue.',
    'record_warning_zonefile_check_after_delete' => 'Record(s) deleted – warning during zone file check in %s.',
    'record_deleted_success'                     => 'Record(s) deleted successfully.',
    'record_error_delete_failed'                 => 'An error occurred while deleting the record(s).',

    // js
    'record_mx_invalid_priority' => 'Please enter a valid priority (number) for the MX record.',
    'record_mx_missing_target' => 'Please provide a valid mail server.',
    'record_naptr_missing_fields' => 'Please fill in all NAPTR fields.',
    'record_naptr_missing_name' => 'Please provide a name for the NAPTR record.',
    'record_srv_missing_fields' => 'Please enter service name and protocol for the SRV record.',
    'record_srv_invalid_numbers' => 'SRV: Priority, weight and port must be numeric.',
    'record_srv_missing_target' => 'Please provide a valid target for the SRV record.',
    'record_srv_name_invalid'      => 'Service name must start with an underscore, e.g. _sip',
    'record_caa_missing_name' => 'Please provide a name for the CAA record.',
    'record_dkim_missing_fields' => 'Please fill in all DKIM fields.',
    'record_uri_missing_fields'    => 'Please fill in all URI fields.',

    'record_info_forward_a' => '<strong>A record:</strong><br><br>Points to an IPv4 address (e.g. 192.0.2.1).',
    'record_info_forward_aaaa' => '<strong>AAAA record:</strong><br><br>Points to an IPv6 address (e.g. 2001:db8::1).',
    'record_info_forward_cname' => '<strong>CNAME record:</strong><br><br>Alias for another hostname.<br>Important: must not be combined with other record types.',
    'record_info_forward_mx' => '<strong>MX record:</strong><br><br>Defines mail servers for the domain.<br>The value is a hostname (FQDN), not an IP.',
    'record_info_forward_ns' => '<strong>NS record:</strong><br><br>Authoritative name server of the zone.<br>Usually set automatically.',
    'record_info_forward_ptr' => '<strong>PTR record:</strong><br><br>Points to the hostname of an IP address.<br>Requires full FQDN with trailing dot.',
    'record_info_forward_txt' => '<strong>TXT record:</strong><br><br>Free text or structured data.<br>Example: "v=verify123"',
    'record_info_forward_spf' => '<strong>SPF record:</strong><br><br>Defines allowed mail servers (part of TXT records).',
    'record_info_forward_dkim' => '<strong>DKIM:</strong><br><br>Automatically creates a TXT record with a public key.',
    'record_info_forward_loc' => '<strong>LOC record:</strong><br><br>Stores geographical coordinates.<br>Format: "52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m"',
    'record_info_forward_caa' => '<strong>CAA record:</strong><br><br>Specifies which CAs may issue certificates.<br>Example: 0 issue "letsencrypt.org"',
    'record_info_forward_srv' => '<strong>SRV record:</strong><br><br>Defines services with priority, weight, port and target.<br>Example: 0 5 5060 sipserver.example.com',
    'record_info_forward_naptr' => '<strong>NAPTR record:</strong><br><br>Mapping mechanism for services (e.g. SIP, ENUM).<br>Format: Order Preference "Flags" "Service" "Regexp" Replacement<br>Example: 100 10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .',
    'record_info_forward_uri' => '<strong>URI record:</strong><br><br>Defines a service via name, protocol, priority, weight and URI target.<br>Format: &lt;Prio&gt; &lt;Weight&gt; "&lt;URI&gt;"<br>Example: 10 1 "ftp://ftp1.example.com/public"',
    'record_info_reverse_ptr' => '<strong>PTR record:</strong><br><br>Points to the hostname of an IP address.<br>Requires full FQDN with trailing dot.',
    'record_info_reverse_txt' => '<strong>TXT record:</strong><br><br>Free text or structured data.<br>Example: "Certificate valid until ..."',

    // === servers.php ===
    'add_server' => 'New Server',
    'server_status' => 'Server Status',
    'server_error' => 'Faulty servers detected',
    'server_warning' => 'Warnings during server checks',
    'server_ok' => 'All servers are OK',
    'local' => 'Local',
    'active' => 'Active',
    'yes' => 'Yes',
    'no' => 'No',
    'bind_reload' => 'BIND Reload',
    'bind_reload_title' => 'Reload BIND',
    'bind_reload_confirm' => 'Really reload BIND on this server?',

    // log
    'server_error_invalid_id'           => 'Invalid ID.',
    'server_error_invalid_name'         => 'Invalid or missing server name',
    'server_error_invalid_dns_ip'       => 'At least one valid DNS IP address (IPv4 or IPv6) is required',
    'server_error_invalid_ipv4'         => 'Invalid IPv4 address',
    'server_error_invalid_ipv6'         => 'Invalid IPv6 address',
    'server_error_invalid_api_ip'       => 'Invalid API IP address',
    'server_error_api_ip_required'      => 'API IP address is required',
    'server_error_api_token_required'   => 'API key is missing or too short',
    'server_error_local_exists'         => 'Only one local server is allowed',
    'server_error_invalid_input'        => 'Invalid input',
    'server_created_success'            => 'Server <strong>%s</strong> added successfully.',
    'server_error_db_save_failed'       => 'An error occurred while adding the server.',

    'server_error_master_deactivation_blocked' => 'This server is defined as master server in at least one zone and therefore cannot be deactivated.',
    'server_updated_success'            => 'Server <strong>%s</strong> successfully updated.',
    'server_error_db_update_failed'     => 'An error occurred while updating the server.',

    'server_error_delete_assigned' => 'The server is still assigned to at least one zone and cannot be deleted.',

    // form
    'add_new_server' => 'Add new DNS server',
    'server_name' => 'Server name (FQDN)',
    'dns_ipv4' => 'DNS IPv4 address',
    'dns_ipv6' => 'DNS IPv6 address',
    'same_as_api_ip' => '= API IP',
    'api_ip' => 'API IP address (IPv4/IPv6)',
    'api_token' => 'API key',
    'api_token_placeholder' => 'e.g. 64-character hex string',
    'generate' => 'Generate',
    'server_active' => 'Server is active',
    'server_is_local' => 'This server is the local one (Webinterface host)',
    'add_server' => 'Add server',

    // === system_health.php ===
    'system_update_now' => 'Update status now',

    'toast_monitoring_failed' => 'Monitoring status check failed.',
    'toast_monitoring_success' => 'System status updated successfully.',
    'toast_monitoring_missing' => 'Monitoring script not found.',

    'config_check' => 'Configuration (local)',
    'config_ok' => 'All configurations correct',
    'config_errors_found' => '%d error(s) found',
    'config_hint' => 'Please review your <code>ui_config.php</code> configuration file.',

    'php_version_local' => 'PHP version (local)',
    'php_version_outdated' => 'Outdated',

    'php_modules_local' => 'PHP modules (local)',
    'php_module_missing' => 'Missing',

    'file_permissions_local' => 'File permissions (local)',
    'file_permissions_ok' => 'All permissions correct',
    'file_permissions_errors' => '%d issue(s) found',
    'file_permissions_hint' => 'Run the script <code>fix_perms.sh</code> to automatically correct file permissions.',

    'server_errors' => '%d error(s) detected',

    'named_checkconf' => 'named-checkconf',
    'named_checkconf_ok' => 'no issues',
    'named_checkconf_issues' => '%d affected server(s)',

    'named_checkzone' => 'named-checkzone',
    'named_checkzone_ok' => 'no issues',
    'named_checkzone_issues' => '%d affected zone(s)',

    'status_ok' => 'OK',
    'status_error' => 'Error',
    'status_warning' => 'Warning',
    'status_missing' => 'Missing',
    'status_outdated' => 'Outdated',
    'hint' => 'Note',

    // === update.php ===
    'update_connection_failed' => 'Failed to connect to the update server: %s',
    'update_invalid_response' => 'Invalid response from the update server.',
    'unknown' => 'unknown',

    'update_check' => 'Update Check',
    'update_error' => 'Connection to the update server failed',
    'update_available' => 'A new version is available:',
    'update_version' => 'Version %s',
    'update_released' => 'released on %s',
    'update_download' => 'Download',
    'update_changelog' => 'View Changelog',
    'update_current' => 'You are using the latest version (%s).',

    // === users.php ===
    'user_management' => 'User Management',
    'add_user' => 'New User',
    'edit_mode' => 'Edit mode',
    'all_zones' => 'All zones',
    'repeat_password' => 'Repeat password',

    // form
    'add_new_user' => 'Add new user',
    'create_user' => 'Create user',
    'role' => 'Role',
    'role_admin' => 'Administrator',
    'role_zoneadmin' => 'Zone administrator',
    'all_zones' => 'All zones',

    // log
    'user_error_invalid_username'   => 'Invalid username.',
    'user_error_username_exists'    => 'Username already exists.',
    'user_created'                  => 'User <strong>%s</strong> created successfully.',
    'user_error_create_failed'      => 'Error creating user.',

    'user_error_invalid_id'  => 'Invalid user ID.',
    'user_error_not_found'   => 'User does not exist.',
    'user_error_last_admin'  => 'The last admin cannot be deleted.',
    'user_deleted'           => 'User deleted successfully.',
    'user_error_delete'      => 'Error deleting user.',

    'user_error_load_failed' => 'Failed to load user.',
    'user_updated' => 'User <strong>%s</strong> updated successfully.',
    'user_error_update_failed' => 'Error updating the user.',

    // === zones.php ===
    'dns_zones' => 'DNS Zones',
    'add_zone' => 'New Zone',
    'zone_status' => 'Zone Status',
    'zone_errors' => 'Faulty zones detected',
    'zone_warnings' => 'Warnings found during zone check',
    'zone_ok' => 'All zones are valid',
    'forward_zones' => 'Forward Lookup Zones',
    'reverse_zones' => 'Reverse Lookup Zones',
    'zone_icon_error' => 'Zone file contains errors',
    'zone_icon_warning' => 'Warning during named-checkzone',
    'zone_icon_changed' => 'Change not yet published',
    'zone_icon_ok' => 'Zone file is valid',
    'server_inactive' => 'This server is currently inactive.',

    // form
    'add_new_zone' => 'Add new DNS zone',
    'zone_name' => 'Zone name',
    'zone_type' => 'Type',
    'zone_type_forward' => 'Forward',
    'zone_type_reverse4' => 'Reverse IPv4',
    'zone_type_reverse6' => 'Reverse IPv6',
    'prefix_length' => 'Prefix length (reverse only)',
    'soa_settings' => 'SOA Settings',
    'soa_ns' => 'SOA NS',
    'soa_domain' => 'SOA server domain (reverse only)',
    'soa_mail' => 'SOA mail address',
    'soa_refresh' => 'Refresh',
    'soa_retry' => 'Retry',
    'soa_expire' => 'Expire',
    'soa_minimum' => 'Minimum TTL',
    'assign_dns_servers' => 'DNS Server Assignment',
    'assign' => 'Assign',
    'assign_hint' => 'Select at least one server. Exactly one must be defined as master.',
    'allow_dyndns' => 'DynDNS allowed',
    'dyndns_zone_hint' => 'Zone may be updated via DynDNS',
    'description_optional' => 'Description (optional)',
    'create_zone' => 'Create zone',
    'server_ignored_on_publish' => 'This server is inactive and will be ignored during publish.',
    'auto_filled' => 'auto-filled',

    // log
    'zone_error_no_server_selected'    => 'At least one server must be selected.',
    'zone_error_master_not_included'  => 'The master server must be among the selected servers.',
    'zone_error_master_load_failed'   => 'Master server could not be loaded.',
    'zone_error_zonefile_invalid'     => 'Zone could not be saved because the zone file would be invalid.',
    'zone_warning_zonefile_check'     => 'Zone was saved – warning during zone file check.',
    'zone_created'       => 'Zone <strong>%s</strong> created successfully.',
    'zone_error_db_save_failed'       => 'Error saving the zone.',

    'zone_error_not_found'             => 'Zone not found.',
    'zone_error_invalid_prefix_length' => 'Invalid prefix length.',
    'zone_error_no_servers'           => 'At least one DNS server must be selected.',
    'zone_error_master_not_in_list'   => 'The master server must be among the selected servers.',
    'zone_error_master_inactive'      => 'The master server is inactive.',
    'zone_error_server_assignment_failed' => 'Failed to update server assignments.',
    'zone_error_check_failed'         => 'Zone could not be verified.',
    'zone_error_zonefile_invalid'     => 'The zone file is invalid – changes have been discarded.',
    'zone_warning_zonefile_check'     => 'Zone updated – warning during zone file check.',
    'zone_error_ns_glue_failed' => 'Zone structure could not be rebuilt.',
    'zone_warning_ns_glue'      => 'Zone updated – warning during NS/Glue rebuild.',
    'zone_updated'              => 'Zone <strong>%s</strong> updated successfully.',

    'zone_error_invalid_id'     => 'Invalid zone ID.',
    'zone_deleted'              => 'Zone <strong>%s</strong> has been deleted.',
    'zone_error_delete_failed'  => 'Error deleting the zone.',

    // js
    'zone_form_no_server_selected' => 'Please select at least one DNS server.',
    'zone_form_no_master_selected' => 'Please select a master server.',
    'zone_form_master_not_among_selected' => 'The selected master server must also be checked among the selected DNS servers.',

    // === bind_reload.php ===
    'bind_reload_success' => 'BIND reload on <strong>%s</strong> succeeded:<br><code>%s</code>',
    'bind_reload_failed' => 'BIND reload on <strong>%s</strong> failed:<br><code>%s</code>',

    // === publish_all.php ===
    'publish_all_success'   => 'All zones published successfully.',
    'publish_all_warning'   => 'Warning during publication.',
    'publish_all_error'     => 'Error during publication.',
    'publish_all_exception' => 'Publishing failed.',

    // === validators.php ===
    'generic_validation_error' => 'Unknown validation error.',

    // DNS record validation errors
    'ERR_INVALID_NAME_CHARS'      => 'The name contains invalid characters (e.g. whitespace or control characters).',
    'ERR_TTL_NEGATIVE'            => 'TTL must not be negative.',
    'ERR_INVALID_IPV4'            => 'Invalid IPv4 address.',
    'ERR_INVALID_IPV6'            => 'Invalid IPv6 address.',
    'ERR_MX_PARTS_INVALID'        => 'MX record must contain two parts: <priority> <target>.',
    'ERR_MX_PRIORITY_INVALID'     => 'Priority in MX record must be between 0 and 65535.',
    'ERR_MX_TARGET_INVALID'       => 'Target in MX record is not a valid FQDN.',
    'ERR_FQDN_REQUIRED'           => 'PTR, NS and CNAME records must contain a valid FQDN.',
    'ERR_SPF_FORMAT'              => 'SPF content must start with "v=spf1 ..." including quotes.',
    'ERR_DKIM_QUOTES'             => 'DKIM record must be enclosed in quotation marks.',
    'ERR_DKIM_VERSION'            => 'DKIM record must start with v=DKIM1.',
    'ERR_DKIM_KEYTYPE'            => 'DKIM record must contain k=rsa or k=ed25519.',
    'ERR_DKIM_KEY_INVALID'        => 'DKIM public key (p=...) is not a valid Base64 string.',
    'ERR_DKIM_KEY_MISSING'        => 'DKIM record does not contain a valid public key (p=...).',
    'ERR_TXT_TOO_LONG'            => 'TXT content must not exceed 512 characters.',
    'ERR_LOC_FORMAT'              => 'Invalid LOC format. Example: 52 31 0.000 N 13 24 0.000 E 34.0m',
    'ERR_CAA_FORMAT'              => 'Invalid CAA record. Expected: 0 issue "letsencrypt.org"',
    'ERR_SRV_PARTS_INVALID'       => 'SRV record must contain exactly four parts: <priority> <weight> <port> <target>.',
    'ERR_SRV_NUMERIC_FIELDS'      => 'Priority, weight and port must be integers between 0 and 65535.',
    'ERR_SRV_TARGET_INVALID'      => 'Target in SRV record is not a valid FQDN.',
    'ERR_NAPTR_PARTS_INVALID'     => 'NAPTR record must contain exactly six parts: <order> <preference> "<flags>" "<service>" "<regexp>" <replacement>.',
    'ERR_NAPTR_ORDER_PREF'        => 'Order and preference must be integers between 0 and 65535.',
    'ERR_NAPTR_FLAGS'             => 'Flags must be enclosed in quotation marks (e.g. "U").',
    'ERR_NAPTR_SERVICE'           => 'Service must be enclosed in quotation marks (e.g. "E2U+sip").',
    'ERR_NAPTR_REGEXP'            => 'Regexp must be enclosed in quotation marks (e.g. "!^.*$!sip:info@example.com!").',
    'ERR_NAPTR_REPLACEMENT'       => 'Replacement must be a valid FQDN or a single dot.',
    'ERR_URI_PARTS_INVALID'       => 'URI record must contain exactly three parts: <priority> <weight> "<target>".',
    'ERR_URI_PRIORITY'            => 'Priority in URI record must be an integer between 0 and 65535.',
    'ERR_URI_WEIGHT'              => 'Weight in URI record must be an integer between 0 and 65535.',
    'ERR_URI_TARGET'              => 'Target in URI record must be enclosed in quotation marks (e.g. "https://example.com").',
    'ERR_UNKNOWN_RECORD_TYPE'     => 'Unknown or unsupported record type.',

    // Zone validation errors
    'ERR_ZONE_EMPTY'              => 'Zone name must not be empty.',
    'ERR_ZONE_NAME_INVALID'       => 'Invalid zone name.',
    'ERR_ZONE_PREFIX_LENGTH'      => 'Prefix length for reverse zones must be between 8 and 128.',
    'ERR_ZONE_SOA_MAIL'           => 'Invalid administrator address (SOA mail).',
    'ERR_ZONE_SOA_REFRESH'        => 'SOA refresh must be between 1200 and 86400 seconds.',
    'ERR_ZONE_SOA_RETRY'          => 'SOA retry must be between 180 and 7200 seconds.',
    'ERR_ZONE_SOA_EXPIRE'         => 'SOA expire must be between 1209600 and 2419200 seconds.',
    'ERR_ZONE_SOA_MINIMUM'        => 'SOA minimum TTL must be between 300 and 86400 seconds.',

    // === diagnostics.php ===
    'diag_error_base_url'       => 'BASE_URL is not defined.',
    'diag_error_named_checkzone'=> 'NAMED_CHECKZONE is missing or not executable.',
    'diag_error_password_min'   => 'PASSWORD_MIN_LENGTH is too low or not set.',
    'diag_error_php_dev_mode'   => 'PHP_ERR_REPORT is set to true (development mode active).',
    'diag_error_log_level'      => 'Invalid LOG_LEVEL: %s',
    'diag_error_log_target'     => 'Invalid LOG_TARGET: %s',
];
