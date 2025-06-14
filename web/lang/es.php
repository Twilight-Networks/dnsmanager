<?php
return [
    // === Allgemein ===
    'actions' => 'Acciones',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'save' => 'Guardar',
    'cancel' => 'Cancelar',
    'username' => 'Nombre de usuario',
    'password' => 'Contraseña',
    'change_password_for' => 'Cambiar la contraseña de %s',
    'new_password' => 'Nueva contraseña',
    'users' => 'Usuarios',
    'zones' => 'Zonas',
    'name' => 'Nombre',
    'system_status' => 'Estado del sistema',
    'dns_servers' => 'Servidores DNS',
    'records' => 'Registros',
    'description' => 'Descripción',
    'close' => 'Cerrar',
    'inactive' => 'inactivo',
    'for_example' => 'p. ej.',
    'no_changes'             => 'No se realizaron cambios.',
    'zone_rebuild_failed'    => 'Reconstrucción fallida para la zona ID %s.',
    'zone_rebuild_warning'   => 'Advertencia durante la reconstrucción para la zona ID %s.',
    'error_server_not_found' => 'Servidor no encontrado.',

    'error_password_too_short'   => 'La contraseña debe tener al menos %d caracteres.',
    'error_password_save'        => 'Error al guardar la contraseña.',
    'password_changed'           => 'Contraseña cambiada correctamente.',
    'password_changed_admin'     => 'La contraseña ha sido actualizada correctamente.',
    'error_password_unauthorized' => 'Solo puede cambiar su propia contraseña.',
    'error_password_mismatch'     => 'Las contraseñas no coinciden.',
    'error_password_processing'   => 'Error al procesar la contraseña.',

    // === login.php ===
    'login_title' => 'Iniciar sesión',
    'login_button' => 'Entrar',
    'logout_success' => 'Sesión cerrada correctamente.',
    'login_failed' => 'Error de inicio de sesión: Nombre de usuario o contraseña incorrectos.',
    'session_expired' => 'Su sesión ha expirado. Por favor, inicie sesión nuevamente.',

    // === layout.php ===
    'menu_dashboard' => 'Panel de control',
    'menu_servers' => 'Servidores',
    'menu_dyndns' => 'DynDNS',
    'menu_logout' => 'Cerrar sesión',
    'menu_check_updates' => 'Buscar actualizaciones',
    'publish' => 'Publicar',
    'logged_in_as' => 'Conectado como:',
    'warnings' => 'Advertencias',
    'errors' => 'Errores',

    'modal_confirm_delete_title' => 'Confirmar eliminación',
    'modal_confirm_delete_text' => '¿Realmente desea eliminar esta entrada?',

    // === dashboard.php ===
    'welcome' => 'Bienvenido',
    'system_error' => 'Se detectaron problemas. Detalles en ',
    'system_warning' => 'Se detectaron advertencias. Detalles en ',
    'system_ok' => 'Estado del sistema: OK.',
    'my_zones' => 'Mis zonas',

    // === dyndns.php ===
    'dyndns_accounts' => 'Cuentas DynDNS',
    'add_dyndns_account' => 'Nueva cuenta DynDNS',
    'hostname' => 'Nombre de host',
    'zone' => 'Zona',
    'last_update' => 'Última actualización',

    // form
    'dyndns_add_heading' => 'Crear nueva cuenta DynDNS',
    'dyndns_hostname_label' => 'Nombre de host (por ejemplo: home)',
    'dyndns_zone_label' => 'Zona asignada',
    'dyndns_add_submit' => 'Crear cuenta',
    'please_select' => '– Seleccione –',

    // log
    'dyndns_error_missing_fields' => 'Todos los campos son obligatorios.',
    'dyndns_error_zone_not_allowed' => 'La zona no está habilitada para DynDNS.',
    'dyndns_success_account_created' => 'Cuenta DynDNS creada correctamente.',
    'dyndns_error_db' => 'Error al guardar',

    'dyndns_error_invalid_id' => 'ID no válido.',
    'dyndns_delete_success' => 'Cuenta DynDNS y registros A/AAAA relacionados eliminados.',
    'dyndns_error_delete' => 'Error al eliminar',

    'dyndns_error_invalid_input' => 'Entrada no válida.',
    'dyndns_error_zone_not_allowed' => 'La zona no está habilitada para DynDNS.',
    'dyndns_error_not_found' => 'Cuenta DynDNS no encontrada.',
    'dyndns_no_ip_warning' => 'No se ha aplicado ninguna dirección IP.',
    'dyndns_updated' => 'Cuenta DynDNS actualizada.',
    'dyndns_error_update' => 'Error al guardar',

    'dyndns_error_unauthorized' => 'Acceso denegado.',

    // === DynDNS Info Toggle ===
    'dyndns_info_toggle' => 'Mostrar información sobre la integración DynDNS',
    'dyndns_info_title' => 'Información sobre la integración de DynDNS:',
    'dyndns_info_text_1' => 'Para usar DynDNS (por ejemplo, en un <strong>FRITZ!Box</strong> u otro router), utilice la siguiente URL de actualización:',
    'dyndns_supported_parameters' => 'Parámetros compatibles:',
    'ipv4_address' => 'Dirección IPv4',
    'ipv6_address' => 'Dirección IPv6',
    'important' => 'Importante:',
    'dyndns_info_auth' => 'La autenticación se realiza mediante HTTP Basic Auth.<br>
    El nombre de usuario y la contraseña deben configurarse en el router (por ejemplo, en <em>DNS dinámico</em>).<br>
    Los marcadores de posición <code>&lt;ipaddr&gt;</code> y <code>&lt;ip6addr&gt;</code> se reemplazan automáticamente por el dispositivo.',
    'dyndns_copy_title' => 'Copiar al portapapeles',

    // js
    'clipboard_success' => 'Copiado al portapapeles',
    'clipboard_failed' => 'Error al copiar.',

    // === records.php ===
    'dns_records_for' => 'Registros DNS para %s',
    'add_record' => 'Nuevo registro',
    'delete_records' => 'Eliminar registros',
    'zone_diagnostic_for' => 'Diagnóstico de zona para <code>%s</code> en todos los servidores asignados:',
    'records_ns' => 'Configuración del servidor de nombres (NS)',
    'records_mail' => 'Configuración de correo (MX, SPF, DKIM)',
    'records_host' => 'Registros de host (A, AAAA, CNAME, PTR, TXT)',
    'records_host_reverse' => 'Registros de host (PTR, TXT)',
    'record_type' => 'Tipo',
    'record_content' => 'Contenido',
    'glue_protected' => 'Registro Glue – no se puede eliminar',

    'ttl_auto' => 'Automático',
    'seconds' => 'segundos',
    'minute'  => 'minuto',
    'minutes' => 'minutos',
    'hour'    => 'hora',
    'hours'   => 'horas',
    'day'     => 'día',

    // form
    'add_new_record' => 'Añadir nuevo registro DNS',
    'record_type_info_button' => 'Más información sobre el tipo de registro',
    'record_name' => 'Nombre',
    'record_name_reverse' => 'Parte de IP',
    'domain' => 'Dominio',
    'ttl' => 'TTL',
    'priority' => 'Prioridad',
    'weight' => 'Peso',
    'port' => 'Puerto',
    'target' => 'Destino',
    'protocol' => 'Protocolo',

    // MX
    'mx_target' => 'Servidor de correo (FQDN)',

    // DKIM
    'dkim_selector' => 'Selector DKIM',
    'dkim_subdomain' => 'Subdominio (opcional)',
    'dkim_key_type' => 'Tipo de clave',
    'dkim_hash' => 'Hash',
    'dkim_flags' => 'Indicadores',
    'dkim_key' => 'Clave pública',
    'dkim_upload_label' => 'Subir archivo de configuración de openDKIM',

    // URI
    'uri_service' => 'Servicio',
    'uri_target' => 'URI de destino',

    // NAPTR
    'order' => 'Orden',
    'preference' => 'Preferencia',
    'flags' => 'Indicadores',
    'service' => 'Servicio',
    'regexp' => 'Expresión regular',
    'replacement' => 'Reemplazo',

    'auto_ptr' => 'Crear registro PTR automáticamente (para A/AAAA)',

    // log
    'record_error_auto_ptr_invalid_ip'   => 'No se pudo crear el registro PTR – dirección IP inválida o error de formato.',
    'record_error_auto_ptr_no_zone'     => 'Registro PTR no posible – la zona inversa correspondiente %s no existe.',
    'record_error_auto_ptr_duplicate'   => 'Registro PTR no posible – ya existe un PTR para %s en la zona %s.',
    'record_error_auto_ptr_db'          => 'Error al crear automáticamente el registro PTR.',

    'record_error_dkim_file_too_large'    => 'El archivo es demasiado grande. El tamaño máximo permitido es de 2 KB.',
    'record_error_dkim_invalid_extension' => 'Solo se permiten archivos con extensión .txt.',
    'record_error_dkim_invalid_code'      => 'El archivo contiene código no permitido y no pudo procesarse.',
    'record_error_dkim_parse_failed'      => 'No se pudo procesar el archivo DKIM.',
    'record_error_build_failed'           => 'Error al procesar el contenido del registro.',
    'record_error_duplicate'              => 'Ya existe un registro %2$s duplicado para <code>%1$s</code>.',
    'record_error_zonefile_invalid'       => 'El registro no pudo guardarse porque el archivo de zona sería inválido.',
    'record_warning_zonefile_check'       => 'Registro guardado – advertencia durante la verificación del archivo de zona.',
    'record_error_db_save_failed'         => 'Ocurrió un error al guardar el registro.',
    'record_created'                      => 'Registro <strong>%1$s %2$s</strong> añadido con éxito a <strong>%3$s</strong>.',

    'record_error_build_failed'        => 'No se pudo procesar el registro.',
    'record_error_glue_name_change'   => 'No está permitido renombrar registros glue.',
    'record_error_glue_type_change'   => 'No está permitido cambiar el tipo de registros glue.',
    'record_error_ns_name_change'     => 'No está permitido renombrar registros NS protegidos.',
    'record_error_ns_content_change'  => 'No está permitido cambiar el destino de registros NS protegidos.',
    'record_updated'                  => 'Registro <strong>%1$s %2$s</strong> actualizado con éxito en <strong>%3$s</strong>.',

    'record_error_glue_protected'                => '%s no puede eliminarse (registro glue).',
    'record_error_ns_protected'                  => '%s está protegido por asignación de servidor o glue.',
    'record_warning_zonefile_check_after_delete' => 'Registro(s) eliminado(s) – advertencia durante la verificación del archivo de zona en %s.',
    'record_deleted_success'                     => 'Registro(s) eliminado(s) con éxito.',
    'record_error_delete_failed'                 => 'Ocurrió un error al eliminar el(los) registro(s).',

    // js
    'record_mx_invalid_priority' => 'Por favor, introduce una prioridad válida (número) para el registro MX.',
    'record_mx_missing_target' => 'Por favor, proporciona un servidor de correo válido.',
    'record_naptr_missing_fields' => 'Por favor, rellena todos los campos de NAPTR.',
    'record_naptr_missing_name' => 'Por favor, proporciona un nombre para el registro NAPTR.',
    'record_srv_missing_fields' => 'Por favor, introduce el nombre del servicio y el protocolo para el registro SRV.',
    'record_srv_invalid_numbers' => 'SRV: la prioridad, el peso y el puerto deben ser numéricos.',
    'record_srv_missing_target' => 'Por favor, proporciona un destino válido para el registro SRV.',
    'record_srv_name_invalid'  => 'El nombre del servicio debe comenzar con un guion bajo, p. ej. _sip',
    'record_caa_missing_name' => 'Por favor, proporciona un nombre para el registro CAA.',
    'record_dkim_missing_fields' => 'Por favor, rellena todos los campos de DKIM.',
    'record_uri_missing_fields' => 'Por favor, rellena todos los campos de URI.',

    'record_info_forward_a' => '<strong>Registro A:</strong><br><br>Apunta a una dirección IPv4 (p. ej. 192.0.2.1).',
    'record_info_forward_aaaa' => '<strong>Registro AAAA:</strong><br><br>Apunta a una dirección IPv6 (p. ej. 2001:db8::1).',
    'record_info_forward_cname' => '<strong>Registro CNAME:</strong><br><br>Alias para otro nombre de host.<br>Importante: no debe combinarse con otros tipos de registros.',
    'record_info_forward_mx' => '<strong>Registro MX:</strong><br><br>Define los servidores de correo para el dominio.<br>El valor es un nombre de host (FQDN), no una IP.',
    'record_info_forward_ns' => '<strong>Registro NS:</strong><br><br>Servidor de nombres autorizado de la zona.<br>Normalmente se configura automáticamente.',
    'record_info_forward_ptr' => '<strong>Registro PTR:</strong><br><br>Apunta al nombre de host de una dirección IP.<br>Requiere un FQDN completo con punto final.',
    'record_info_forward_txt' => '<strong>Registro TXT:</strong><br><br>Texto libre o datos estructurados.<br>Ejemplo: "v=verify123"',
    'record_info_forward_spf' => '<strong>Registro SPF:</strong><br><br>Define los servidores de correo permitidos (parte de los registros TXT).',
    'record_info_forward_dkim' => '<strong>DKIM:</strong><br><br>Crea automáticamente un registro TXT con una clave pública.',
    'record_info_forward_loc' => '<strong>Registro LOC:</strong><br><br>Almacena coordenadas geográficas.<br>Formato: "52 31 0.000 N 13 24 0.000 E 34.0m 1m 10000m 10m"',
    'record_info_forward_caa' => '<strong>Registro CAA:</strong><br><br>Especifica qué autoridades de certificación pueden emitir certificados.<br>Ejemplo: 0 issue "letsencrypt.org"',
    'record_info_forward_srv' => '<strong>Registro SRV:</strong><br><br>Define servicios con prioridad, peso, puerto y destino.<br>Ejemplo: 0 5 5060 sipserver.example.com',
    'record_info_forward_naptr' => '<strong>Registro NAPTR:</strong><br><br>Mecanismo de mapeo para servicios (p. ej. SIP, ENUM).<br>Formato: Orden Preferencia "Banderas" "Servicio" "Expresión" Reemplazo<br>Ejemplo: 100 10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .',
    'record_info_forward_uri' => '<strong>Registro URI:</strong><br><br>Define un servicio mediante nombre, protocolo, prioridad, peso y URI de destino.<br>Formato: &lt;Prio&gt; &lt;Peso&gt; "&lt;URI&gt;"<br>Ejemplo: 10 1 "ftp://ftp1.example.com/public"',
    'record_info_reverse_ptr' => '<strong>Registro PTR:</strong><br><br>Apunta al nombre de host de una dirección IP.<br>Requiere un FQDN completo con punto final.',
    'record_info_reverse_txt' => '<strong>Registro TXT:</strong><br><br>Texto libre o datos estructurados.<br>Ejemplo: "Certificado válido hasta ..."',

    // === servers.php ===
    'add_server' => 'Nuevo servidor',
    'server_status' => 'Estado del servidor',
    'server_error' => 'Se detectaron servidores defectuosos',
    'server_warning' => 'Advertencias durante las comprobaciones del servidor',
    'server_ok' => 'Todos los servidores están correctos',
    'local' => 'Local',
    'active' => 'Activo',
    'yes' => 'Sí',
    'no' => 'No',
    'bind_reload' => 'Recargar BIND',
    'bind_reload_title' => 'Recargar BIND',
    'bind_reload_confirm' => '¿Realmente desea recargar BIND en este servidor?',

    // log
    'server_error_invalid_id'           => 'ID inválido.',
    'server_error_invalid_name'         => 'Nombre de servidor inválido o ausente',
    'server_error_invalid_dns_ip'       => 'Se requiere al menos una dirección IP DNS válida (IPv4 o IPv6)',
    'server_error_invalid_ipv4'         => 'Dirección IPv4 inválida',
    'server_error_invalid_ipv6'         => 'Dirección IPv6 inválida',
    'server_error_invalid_api_ip'       => 'Dirección IP de la API inválida',
    'server_error_api_ip_required'      => 'Se requiere la dirección IP de la API',
    'server_error_api_token_required'   => 'Falta la clave de API o es demasiado corta',
    'server_error_local_exists'         => 'Solo se permite un servidor local',
    'server_error_invalid_input'        => 'Entrada inválida',
    'server_created_success'            => 'Servidor <strong>%s</strong> añadido correctamente.',
    'server_error_db_save_failed'       => 'Ocurrió un error al añadir el servidor.',

    'server_error_master_deactivation_blocked' => 'Este servidor está definido como servidor maestro en al menos una zona y, por lo tanto, no puede desactivarse.',
    'server_updated_success'            => 'Servidor <strong>%s</strong> actualizado correctamente.',
    'server_error_db_update_failed'     => 'Ocurrió un error al actualizar el servidor.',

    'server_error_delete_assigned' => 'El servidor aún está asignado a al menos una zona y no puede eliminarse.',

    // form
    'add_new_server' => 'Añadir nuevo servidor DNS',
    'server_name' => 'Nombre del servidor (FQDN)',
    'dns_ipv4' => 'Dirección IPv4 DNS',
    'dns_ipv6' => 'Dirección IPv6 DNS',
    'same_as_api_ip' => '= IP de API',
    'api_ip' => 'Dirección IP de API (IPv4/IPv6)',
    'api_token' => 'Clave de API',
    'api_token_placeholder' => 'p. ej. cadena hex de 64 caracteres',
    'generate' => 'Generar',
    'server_active' => 'El servidor está activo',
    'server_is_local' => 'Este servidor es el local (host de la interfaz web)',
    'add_server' => 'Añadir servidor',

    // === system_health.php ===
    'system_update_now' => 'Actualizar estado ahora',

    'toast_monitoring_failed' => 'La comprobación del estado del sistema ha fallado.',
    'toast_monitoring_success' => 'Estado del sistema actualizado correctamente.',
    'toast_monitoring_missing' => 'Script de supervisión no encontrado.',

    'config_check' => 'Configuración (local)',
    'config_ok' => 'Todas las configuraciones son correctas',
    'config_errors_found' => 'Se encontraron %d error(es)',
    'config_hint' => 'Revise su archivo de configuración <code>ui_config.php</code>.',

    'php_version_local' => 'Versión de PHP (local)',
    'php_version_outdated' => 'Desactualizada',

    'php_modules_local' => 'Módulos PHP (local)',
    'php_module_missing' => 'Faltante',

    'file_permissions_local' => 'Permisos de archivo (local)',
    'file_permissions_ok' => 'Todos los permisos correctos',
    'file_permissions_errors' => '%d problema(s) encontrado(s)',
    'file_permissions_hint' => 'Ejecute el script <code>fix_perms.sh</code> para corregir automáticamente los permisos de archivo.',

    'server_errors' => '%d error(es) detectado(s)',

    'named_checkconf' => 'named-checkconf',
    'named_checkconf_ok' => 'sin problemas',
    'named_checkconf_issues' => '%d servidor(es) afectado(s)',

    'named_checkzone' => 'named-checkzone',
    'named_checkzone_ok' => 'sin problemas',
    'named_checkzone_issues' => '%d zona(s) afectada(s)',

    'status_ok' => 'Correcto',
    'status_error' => 'Error',
    'status_warning' => 'Advertencia',
    'status_missing' => 'Faltante',
    'status_outdated' => 'Desactualizado',
    'hint' => 'Nota',

    // === update.php ===
    'update_connection_failed' => 'No se pudo conectar con el servidor de actualizaciones: %s',
    'update_invalid_response' => 'Respuesta no válida del servidor de actualizaciones.',
    'unknown' => 'desconocido',

    'update_check' => 'Comprobación de actualizaciones',
    'update_error' => 'Fallo al conectar con el servidor de actualizaciones',
    'update_available' => 'Hay una nueva versión disponible:',
    'update_version' => 'Versión %s',
    'update_released' => 'publicado el %s',
    'update_download' => 'Descargar',
    'update_changelog' => 'Ver registro de cambios',
    'update_current' => 'Está utilizando la versión más reciente (%s).',

    // === users.php ===
    'user_management' => 'Gestión de usuarios',
    'add_user' => 'Nuevo usuario',
    'edit_mode' => 'Modo de edición',
    'all_zones' => 'Todas las zonas',
    'repeat_password' => 'Repetir contraseña',

    // form
    'add_new_user' => 'Añadir nuevo usuario',
    'create_user' => 'Crear usuario',
    'role' => 'Rol',
    'role_admin' => 'Administrador',
    'role_zoneadmin' => 'Administrador de zona',
    'all_zones' => 'Todas las zonas',

    // log
    'user_error_invalid_username'   => 'Nombre de usuario no válido.',
    'user_error_username_exists'    => 'El nombre de usuario ya existe.',
    'user_created'                  => 'Usuario <strong>%s</strong> creado correctamente.',
    'user_error_create_failed'      => 'Error al crear el usuario.',

    'user_error_invalid_id'  => 'ID de usuario no válido.',
    'user_error_not_found'   => 'El usuario no existe.',
    'user_error_last_admin'  => 'No se puede eliminar al último administrador.',
    'user_deleted'           => 'Usuario eliminado correctamente.',
    'user_error_delete'      => 'Error al eliminar el usuario.',

    'user_error_load_failed' => 'Error al cargar el usuario.',
    'user_updated' => 'Usuario <strong>%s</strong> actualizado correctamente.',
    'user_error_update_failed' => 'Error al actualizar el usuario.',

    // === zones.php ===
    'dns_zones' => 'Zonas DNS',
    'add_zone' => 'Nueva zona',
    'zone_status' => 'Estado de la zona',
    'zone_errors' => 'Se detectaron zonas con errores',
    'zone_warnings' => 'Se encontraron advertencias al verificar zonas',
    'zone_ok' => 'Todas las zonas son válidas',
    'forward_zones' => 'Zonas de búsqueda directa',
    'reverse_zones' => 'Zonas de búsqueda inversa',
    'zone_icon_error' => 'El archivo de zona contiene errores',
    'zone_icon_warning' => 'Advertencia de named-checkzone',
    'zone_icon_changed' => 'Cambio no publicado aún',
    'zone_icon_ok' => 'El archivo de zona es válido',
    'server_inactive' => 'Este servidor está actualmente inactivo.',

    // form
    'add_new_zone' => 'Añadir nueva zona DNS',
    'zone_name' => 'Nombre de la zona',
    'zone_type' => 'Tipo',
    'zone_type_forward' => 'Directa',
    'zone_type_reverse4' => 'Inversa IPv4',
    'zone_type_reverse6' => 'Inversa IPv6',
    'prefix_length' => 'Longitud del prefijo (solo inversa)',
    'soa_settings' => 'Configuraciones SOA',
    'soa_ns' => 'SOA NS',
    'soa_domain' => 'Dominio del servidor SOA (solo inversa)',
    'soa_mail' => 'Dirección de correo SOA',
    'soa_refresh' => 'Refrescar',
    'soa_retry' => 'Reintento',
    'soa_expire' => 'Expiración',
    'soa_minimum' => 'TTL mínimo',
    'assign_dns_servers' => 'Asignación de servidores DNS',
    'assign' => 'Asignar',
    'assign_hint' => 'Seleccione al menos un servidor. Exactamente uno debe estar definido como master.',
    'allow_dyndns' => 'Permitir DynDNS',
    'dyndns_zone_hint' => 'La zona puede actualizarse vía DynDNS',
    'description_optional' => 'Descripción (opcional)',
    'create_zone' => 'Crear zona',
    'server_ignored_on_publish' => 'Este servidor está inactivo y se ignorará durante la publicación.',
    'auto_filled' => 'rellenado automáticamente',

    // log
    'zone_error_no_server_selected'    => 'Debe seleccionarse al menos un servidor.',
    'zone_error_master_not_included'  => 'El servidor master debe estar entre los seleccionados.',
    'zone_error_master_load_failed'   => 'No se pudo cargar el servidor master.',
    'zone_error_zonefile_invalid'     => 'No se pudo guardar la zona porque el archivo de zona sería inválido.',
    'zone_warning_zonefile_check'     => 'Zona guardada – advertencia durante la verificación del archivo de zona.',
    'zone_created'       => 'Zona <strong>%s</strong> creada correctamente.',
    'zone_error_db_save_failed'       => 'Error al guardar la zona.',

    'zone_error_not_found'             => 'Zona no encontrada.',
    'zone_error_invalid_prefix_length' => 'Longitud de prefijo no válida.',
    'zone_error_no_servers'           => 'Debe seleccionarse al menos un servidor DNS.',
    'zone_error_master_not_in_list'   => 'El servidor master debe estar entre los seleccionados.',
    'zone_error_master_inactive'      => 'El servidor master está inactivo.',
    'zone_error_server_assignment_failed' => 'Error al actualizar las asignaciones de servidor.',
    'zone_error_check_failed'         => 'No se pudo verificar la zona.',
    'zone_error_zonefile_invalid'     => 'El archivo de zona es inválido – los cambios han sido descartados.',
    'zone_warning_zonefile_check'     => 'Zona actualizada – advertencia durante la verificación del archivo de zona.',
    'zone_error_ns_glue_failed' => 'No se pudo reconstruir la estructura de la zona.',
    'zone_warning_ns_glue'      => 'Zona actualizada – advertencia durante la reconstrucción de NS/Glue.',
    'zone_updated'              => 'Zona <strong>%s</strong> actualizada correctamente.',

    'zone_error_invalid_id'     => 'ID de zona no válido.',
    'zone_deleted'              => 'Zona <strong>%s</strong> ha sido eliminada.',
    'zone_error_delete_failed'  => 'Error al eliminar la zona.',

    // js
    'zone_form_no_server_selected' => 'Por favor seleccione al menos un servidor DNS.',
    'zone_form_no_master_selected' => 'Por favor seleccione un servidor master.',
    'zone_form_master_not_among_selected' => 'El servidor master seleccionado debe estar también marcado entre los servidores DNS seleccionados.',

    // === bind_reload.php ===
    'bind_reload_success' => 'Recarga de BIND en <strong>%s</strong> realizada con éxito:<br><code>%s</code>',
    'bind_reload_failed' => 'Error al recargar BIND en <strong>%s</strong>:<br><code>%s</code>',

    // === publish_all.php ===
    'publish_all_success'   => 'Todas las zonas se han publicado correctamente.',
    'publish_all_warning'   => 'Advertencia durante la publicación.',
    'publish_all_error'     => 'Error durante la publicación.',
    'publish_all_exception' => 'La publicación ha fallado.',

    // === validators.php ===
    'generic_validation_error' => 'Error de validación desconocido.',

    // DNS record validation errors
    'ERR_INVALID_NAME_CHARS'      => 'El nombre contiene caracteres no válidos (por ejemplo, espacios o caracteres de control).',
    'ERR_TTL_NEGATIVE'            => 'El TTL no debe ser negativo.',
    'ERR_INVALID_IPV4'            => 'Dirección IPv4 no válida.',
    'ERR_INVALID_IPV6'            => 'Dirección IPv6 no válida.',
    'ERR_MX_PARTS_INVALID'        => 'El registro MX debe contener dos partes: <prioridad> <destino>.',
    'ERR_MX_PRIORITY_INVALID'     => 'La prioridad en el registro MX debe estar entre 0 y 65535.',
    'ERR_MX_TARGET_INVALID'       => 'El destino en el registro MX no es un FQDN válido.',
    'ERR_FQDN_REQUIRED'           => 'Los registros PTR, NS y CNAME deben contener un FQDN válido.',
    'ERR_SPF_FORMAT'              => 'El contenido SPF debe comenzar con "v=spf1 ..." e incluir comillas.',
    'ERR_DKIM_QUOTES'             => 'El registro DKIM debe estar entre comillas.',
    'ERR_DKIM_VERSION'            => 'El registro DKIM debe comenzar con v=DKIM1.',
    'ERR_DKIM_KEYTYPE'            => 'El registro DKIM debe contener k=rsa o k=ed25519.',
    'ERR_DKIM_KEY_INVALID'        => 'La clave pública DKIM (p=...) no es una cadena Base64 válida.',
    'ERR_DKIM_KEY_MISSING'        => 'El registro DKIM no contiene una clave pública válida (p=...).',
    'ERR_TXT_TOO_LONG'            => 'El contenido TXT no debe superar los 512 caracteres.',
    'ERR_LOC_FORMAT'              => 'Formato LOC no válido. Ejemplo: 52 31 0.000 N 13 24 0.000 E 34.0m',
    'ERR_CAA_FORMAT'              => 'Registro CAA no válido. Se espera: 0 issue "letsencrypt.org"',
    'ERR_SRV_PARTS_INVALID'       => 'El registro SRV debe contener exactamente cuatro partes: <prioridad> <peso> <puerto> <destino>.',
    'ERR_SRV_NUMERIC_FIELDS'      => 'Prioridad, peso y puerto deben ser enteros entre 0 y 65535.',
    'ERR_SRV_TARGET_INVALID'      => 'El destino en el registro SRV no es un FQDN válido.',
    'ERR_NAPTR_PARTS_INVALID'     => 'El registro NAPTR debe contener exactamente seis partes: <orden> <preferencia> "<flags>" "<servicio>" "<regexp>" <reemplazo>.',
    'ERR_NAPTR_ORDER_PREF'        => 'El orden y la preferencia deben ser enteros entre 0 y 65535.',
    'ERR_NAPTR_FLAGS'             => 'Las flags deben estar entre comillas (por ejemplo, "U").',
    'ERR_NAPTR_SERVICE'           => 'El servicio debe estar entre comillas (por ejemplo, "E2U+sip").',
    'ERR_NAPTR_REGEXP'            => 'La expresión regular debe estar entre comillas (por ejemplo, "!^.*$!sip:info@example.com!").',
    'ERR_NAPTR_REPLACEMENT'       => 'El reemplazo debe ser un FQDN válido o un punto.',
    'ERR_URI_PARTS_INVALID'       => 'El registro URI debe contener exactamente tres partes: <prioridad> <peso> "<destino>".',
    'ERR_URI_PRIORITY'            => 'La prioridad en el registro URI debe ser un entero entre 0 y 65535.',
    'ERR_URI_WEIGHT'              => 'El peso en el registro URI debe ser un entero entre 0 y 65535.',
    'ERR_URI_TARGET'              => 'El destino en el registro URI debe estar entre comillas (por ejemplo, "https://example.com").',
    'ERR_UNKNOWN_RECORD_TYPE'     => 'Tipo de registro desconocido o no compatible.',

    // Zone validation errors
    'ERR_ZONE_EMPTY'              => 'El nombre de la zona no debe estar vacío.',
    'ERR_ZONE_NAME_INVALID'       => 'Nombre de zona no válido.',
    'ERR_ZONE_PREFIX_LENGTH'      => 'La longitud del prefijo para zonas reversas debe estar entre 8 y 128.',
    'ERR_ZONE_SOA_MAIL'           => 'Dirección de administrador no válida (correo SOA).',
    'ERR_ZONE_SOA_REFRESH'        => 'El valor de SOA refresh debe estar entre 1200 y 86400 segundos.',
    'ERR_ZONE_SOA_RETRY'          => 'El valor de SOA retry debe estar entre 180 y 7200 segundos.',
    'ERR_ZONE_SOA_EXPIRE'         => 'El valor de SOA expire debe estar entre 1209600 y 2419200 segundos.',
    'ERR_ZONE_SOA_MINIMUM'        => 'El valor mínimo de TTL de SOA debe estar entre 300 y 86400 segundos.',

    // === diagnostics.php ===
    'diag_error_base_url'       => 'BASE_URL no está definida.',
    'diag_error_named_checkzone'=> 'NAMED_CHECKZONE no existe o no es ejecutable.',
    'diag_error_password_min'   => 'PASSWORD_MIN_LENGTH es demasiado bajo o no está definido.',
    'diag_error_php_dev_mode'   => 'PHP_ERR_REPORT está activado (modo desarrollo activo).',
    'diag_error_log_level'      => 'LOG_LEVEL inválido: %s',
    'diag_error_log_target'     => 'LOG_TARGET inválido: %s',
];
