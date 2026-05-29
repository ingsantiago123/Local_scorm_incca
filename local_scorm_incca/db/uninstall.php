<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Limpieza completa al desinstalar el plugin.
 *
 * IMPORTANTE: Esta función se ejecuta ANTES de que Moodle elimine las tablas
 * del plugin (declaradas en install.xml). Limpiamos explícitamente todo para
 * garantizar que no queden remanentes aunque el proceso de Moodle falle.
 *
 * Lo que limpia esta función:
 *  ✅ Todos los registros de mdl_local_scorm_incca_items
 *  ✅ Todos los registros de mdl_local_scorm_incca_logs
 *  ✅ Archivo de debug log en moodledata
 *
 * Lo que Moodle limpia automáticamente después:
 *  - Las tablas en sí (DROP TABLE)
 *  - Las capabilities declaradas en db/access.php
 *  - Las configuraciones del plugin en mdl_config_plugins
 *  - Los registros de eventos en mdl_events
 *  - Los strings de idioma
 *
 * Lo que NO se toca (a propósito):
 *  - Los paquetes SCORM (mdl_scorm, mdl_course_modules, mdl_files) — no son del plugin
 *  - Los cursos, usuarios, roles, ni nada del núcleo de Moodle
 *
 * @return bool true siempre (errores se loguean pero no interrumpen la desinstalación)
 */
function xmldb_local_scorm_incca_uninstall(): bool {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    // ── 1. Limpiar registros de la tabla de items ────────────────────────────
    // Usamos table_exists() para no romper si la tabla ya fue eliminada en un
    // intento previo fallido de desinstalación.
    if ($dbman->table_exists('local_scorm_incca_items')) {
        $DB->delete_records('local_scorm_incca_items');
    }

    // ── 2. Limpiar registros de la tabla de logs ─────────────────────────────
    if ($dbman->table_exists('local_scorm_incca_logs')) {
        $DB->delete_records('local_scorm_incca_logs');
    }

    // ── 3. Eliminar el archivo de debug log en moodledata ───────────────────
    // Este archivo lo crea classes/debugger.php y NO está gestionado por Moodle.
    $logfile = rtrim($CFG->dataroot, '/\\') . DIRECTORY_SEPARATOR . 'local_scorm_incca_debug.log';
    if (file_exists($logfile)) {
        @unlink($logfile);
    }

    return true;
}
