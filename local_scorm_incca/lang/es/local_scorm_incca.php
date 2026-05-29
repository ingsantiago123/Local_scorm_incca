<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']          = 'SCORM INCCA - Control de descarga';

// Capabilities.
$string['scorm_incca:cargar']         = 'Cargar paquetes SCORM como protegidos';
$string['scorm_incca:descargar']      = 'Descargar paquetes SCORM protegidos';
$string['scorm_incca:viewadminpanel'] = 'Ver el panel de administracion del plugin';

$string['cap_cargar']    = 'Usuarios autorizados a SUBIR SCORMs como protegidos';
$string['cap_descargar'] = 'Usuarios autorizados a DESCARGAR SCORMs protegidos';
$string['cap_backup']    = 'Usuarios autorizados a crear COPIAS DE SEGURIDAD de cursos con SCORMs protegidos';

$string['scorm_incca:backup']     = 'Crear copias de seguridad de cursos con SCORMs protegidos';
$string['backup_denied_activity'] = 'No tiene permisos para crear copias de seguridad de paquetes SCORM protegidos.';
$string['backup_denied_course']   = 'Este curso contiene paquetes SCORM protegidos. Se requiere el permiso de backup para crear copias de seguridad de este curso.';

// Mensajes.
$string['downloaddenied'] = 'Acceso denegado. No tiene permisos para descargar este paquete SCORM protegido.';
$string['deletedenied']   = 'No tiene permiso para eliminar este paquete SCORM protegido. Solo administradores o usuarios con permisos de carga/descarga pueden eliminarlo.';
$string['unzipdenied']    = 'No tiene permisos para descomprimir este paquete SCORM protegido.';

// Navegacion.
$string['protected_list']   = 'Paquetes SCORM registrados';
$string['logs']             = 'Logs de depuracion';
$string['users_with_caps']  = 'Usuarios con permisos personalizados';
$string['users_with_caps_help'] = 'Listado de usuarios que tienen asignados los roles con los permisos personalizados del plugin.';

// Tabla items.
$string['th_status']  = 'Estado';
$string['th_scorm']   = 'SCORM';
$string['th_course']  = 'Curso';
$string['th_creator'] = 'Creador';
$string['th_created'] = 'Creado';
$string['th_actions'] = 'Acciones';

$string['protected_badge'] = 'Protegido';
$string['public_badge']    = 'Publico';

$string['filter_protected'] = 'Solo protegidos';
$string['filter_public']    = 'Solo publicos';
$string['filter_all']       = 'Todos';
$string['filter']           = 'Filtrar';
$string['filter_by_cmid']   = 'Filtrar por cmid';

$string['no_records'] = 'No hay paquetes SCORM registrados para el filtro seleccionado.';
$string['view_logs']  = 'Ver logs';

// Tabla logs.
$string['th_when']      = 'Fecha';
$string['th_eventtype'] = 'Evento';
$string['th_user']      = 'Usuario';
$string['th_cmid']      = 'cmid';
$string['th_ip']        = 'IP';
$string['th_message']   = 'Mensaje';
$string['th_email']     = 'Email';
$string['th_role']      = 'Rol';

$string['no_logs']           = 'No hay registros de log para los filtros seleccionados.';
$string['no_users_with_cap'] = 'No hay usuarios asignados con esta capacidad.';

$string['log_upload_protected']  = 'Subida (protegido)';
$string['log_upload_public']     = 'Subida (publico)';
$string['log_download_allowed']  = 'Descarga permitida';
$string['log_download_blocked']  = 'Descarga bloqueada';
$string['log_deleted']           = 'Eliminado';
$string['log_error']             = 'Error';
$string['log_delete_blocked']    = 'Eliminacion bloqueada';
$string['log_unzip_blocked']     = 'Descompresion bloqueada';
$string['log_import_registered'] = 'Importado';

$string['search_placeholder'] = 'Buscar por nombre de SCORM o curso...';
$string['search']             = 'Buscar';
$string['showing_x_of_y']    = 'Mostrando {$a->from}–{$a->to} de {$a->total} paquetes';

// Privacy.
$string['privacy:metadata:items']             = 'Registro de paquetes SCORM y su estado de proteccion.';
$string['privacy:metadata:items:creatorid']   = 'Usuario creador del SCORM.';
$string['privacy:metadata:items:cmid']        = 'ID del course module.';
$string['privacy:metadata:items:isprotected'] = 'Indica si el paquete esta protegido.';
$string['privacy:metadata:items:timecreated'] = 'Fecha de creacion del registro.';

$string['privacy:metadata:logs']             = 'Registros de auditoria del plugin.';
$string['privacy:metadata:logs:userid']      = 'Usuario que realizo la accion.';
$string['privacy:metadata:logs:eventtype']   = 'Tipo de evento.';
$string['privacy:metadata:logs:message']     = 'Detalle del evento.';
$string['privacy:metadata:logs:ipaddress']   = 'Direccion IP.';
$string['privacy:metadata:logs:timecreated'] = 'Fecha del evento.';
