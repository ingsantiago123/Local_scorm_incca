<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'SCORM INCCA - Control de descarga';

// Capabilities.
$string['scorm_incca:upload']         = 'Cargar paquetes SCORM como protegidos';
$string['scorm_incca:download']       = 'Descargar paquetes SCORM protegidos';
$string['scorm_incca:backup']         = 'Crear copias de seguridad de cursos con SCORMs protegidos';
$string['scorm_incca:viewadminpanel'] = 'Ver el panel de administración del plugin';

$string['cap_cargar']    = 'Usuarios autorizados a SUBIR SCORMs como protegidos';
$string['cap_descargar'] = 'Usuarios autorizados a DESCARGAR SCORMs protegidos';
$string['cap_backup']    = 'Usuarios autorizados a crear COPIAS DE SEGURIDAD de cursos con SCORMs protegidos';

// Access denied messages.
$string['backup_denied_activity'] = 'No tiene permisos para crear copias de seguridad de paquetes SCORM protegidos.';
$string['backup_denied_course']   = 'Este curso contiene paquetes SCORM protegidos. Se requiere el permiso de backup para crear copias de seguridad de este curso.';
$string['downloaddenied'] = 'Acceso denegado. No tiene permisos para descargar este paquete SCORM protegido.';
$string['deletedenied']   = 'No tiene permiso para eliminar este paquete SCORM protegido. Solo administradores o usuarios con permisos de carga/descarga pueden eliminarlo.';
$string['unzipdenied']    = 'No tiene permisos para descomprimir este paquete SCORM protegido.';

// Navigation.
$string['protected_list']       = 'Paquetes SCORM registrados';
$string['logs']                 = 'Registros de depuración';
$string['users_with_caps']      = 'Usuarios con permisos personalizados';
$string['users_with_caps_help'] = 'Listado de usuarios que tienen asignados los roles con los permisos personalizados del plugin.';

// Items table headers.
$string['th_status']  = 'Estado';
$string['th_scorm']   = 'SCORM';
$string['th_course']  = 'Curso';
$string['th_creator'] = 'Creador';
$string['th_created'] = 'Creado';
$string['th_actions'] = 'Acciones';

$string['protected_badge'] = 'Protegido';
$string['public_badge']    = 'Público';

$string['filter_protected'] = 'Solo protegidos';
$string['filter_public']    = 'Solo públicos';
$string['filter_all']       = 'Todos';
$string['filter']           = 'Filtrar';
$string['filter_by_cmid']   = 'Filtrar por cmid';

$string['no_records'] = 'No hay paquetes SCORM registrados para el filtro seleccionado.';
$string['view_logs']  = 'Ver registros';

// Logs table headers.
$string['th_when']      = 'Fecha';
$string['th_eventtype'] = 'Evento';
$string['th_user']      = 'Usuario';
$string['th_cmid']      = 'cmid';
$string['th_ip']        = 'IP';
$string['th_message']   = 'Mensaje';
$string['th_email']     = 'Correo electrónico';
$string['th_role']      = 'Rol';

$string['no_logs']           = 'No hay registros para los filtros seleccionados.';
$string['no_users_with_cap'] = 'No hay usuarios asignados con esta capacidad.';

// Log event type labels.
$string['log_upload_protected']  = 'Carga (protegido)';
$string['log_upload_public']     = 'Carga (público)';
$string['log_download_allowed']  = 'Descarga permitida';
$string['log_download_blocked']  = 'Descarga bloqueada';
$string['log_deleted']           = 'Eliminado';
$string['log_error']             = 'Error';
$string['log_delete_blocked']    = 'Eliminación bloqueada';
$string['log_unzip_blocked']     = 'Descompresión bloqueada';
$string['log_import_registered'] = 'Importado';

// Search.
$string['search_placeholder'] = 'Buscar por nombre de SCORM o curso...';
$string['search']             = 'Buscar';
$string['showing_x_of_y']    = 'Mostrando {$a->from}–{$a->to} de {$a->total} paquetes';

// Admin panel UI strings.
$string['make_public']             = 'Hacer Público';
$string['make_protected']          = 'Proteger';
$string['make_public_confirm']     = '¿Hacer PÚBLICO este SCORM? Cualquier usuario podrá descargarlo.';
$string['make_protected_confirm']  = '¿Marcar este SCORM como PROTEGIDO? Solo usuarios con permiso podrán descargarlo.';
$string['cleanup_orphans']         = 'Limpiar huérfanos';
$string['cleanup_orphans_title']   = 'Elimina registros de SCORMs que han sido borrados de Moodle';
$string['cleanup_orphans_confirm'] = '¿Eliminar registros de SCORMs que ya no existen en Moodle?';
$string['clear_search']            = 'Limpiar búsqueda';
$string['clear_filters']           = 'Limpiar';
$string['changed_to_protected']    = 'SCORM cmid={$a->cmid} ahora es PROTEGIDO.';
$string['changed_to_public']       = 'SCORM cmid={$a->cmid} ahora es PÚBLICO.';
$string['cleanup_result']          = 'Limpieza: {$a} registro(s) huérfano(s) eliminado(s).';
$string['cleanup_error']           = 'Error en limpieza: {$a}';
$string['showing_x_of_y_records']  = 'Mostrando {$a->from}–{$a->to} de {$a->total} registros';

// Privacy metadata.
$string['privacy:metadata:items']             = 'Registro de paquetes SCORM y su estado de protección.';
$string['privacy:metadata:items:creatorid']   = 'Usuario creador del SCORM.';
$string['privacy:metadata:items:cmid']        = 'ID del módulo de curso.';
$string['privacy:metadata:items:isprotected'] = 'Indica si el paquete está protegido.';
$string['privacy:metadata:items:timecreated'] = 'Fecha de creación del registro.';

$string['privacy:metadata:logs']             = 'Registros de auditoría del plugin.';
$string['privacy:metadata:logs:userid']      = 'Usuario que realizó la acción.';
$string['privacy:metadata:logs:eventtype']   = 'Tipo de evento.';
$string['privacy:metadata:logs:message']     = 'Detalle del evento.';
$string['privacy:metadata:logs:ipaddress']   = 'Dirección IP.';
$string['privacy:metadata:logs:timecreated'] = 'Fecha del evento.';
