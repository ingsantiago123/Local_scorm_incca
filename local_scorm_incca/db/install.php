<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Se ejecuta automáticamente cuando Moodle instala el plugin por primera vez.
 *
 * Escanea los SCORMs existentes en la plataforma y los registra:
 *  - Si el creador tiene local/scorm_incca:cargar → protegido (isprotected=1)
 *  - Si no la tiene o no se identifica creador → público (isprotected=0)
 *
 * Solo registra SCORMs que existan ACTUALMENTE en mdl_course_modules.
 * SCORMs eliminados de Moodle no aparecen porque el JOIN con course_modules
 * filtra solo los que tienen course module activo.
 *
 * @return bool
 */
function xmldb_local_scorm_incca_install(): bool {
    global $DB;

    // Obtener solo los SCORMs que tienen un course_module activo en este momento.
    // El JOIN garantiza que no se registren SCORMs huérfanos (eliminados de Moodle
    // pero cuyos registros en mdl_scorm pudieran haber quedado por alguna razón).
    $scorms = $DB->get_records_sql("
        SELECT s.id AS scormid,
               s.name,
               s.course AS courseid,
               cm.id AS cmid,
               cm.added AS timecreated
          FROM {scorm} s
          JOIN {course_modules} cm ON cm.instance = s.id
          JOIN {modules} m         ON m.id = cm.module AND m.name = 'scorm'
         WHERE cm.deletioninprogress = 0
    ");

    if (empty($scorms)) {
        return true;
    }

    $now = time();

    foreach ($scorms as $scorm) {
        // Defensive: evitar duplicados si install() se llama más de una vez.
        // Esto no debería ocurrir en una instalación normal, pero protege contra
        // fallos parciales de una instalación anterior.
        if ($DB->record_exists('local_scorm_incca_items', ['cmid' => $scorm->cmid])) {
            continue;
        }

        // Intentar identificar al creador via logs estándar de Moodle.
        // Si los logs están desactivados o la entrada no existe, creatorid = 0.
        $creatorid = (int)$DB->get_field_sql("
            SELECT userid
              FROM {logstore_standard_log}
             WHERE component = 'mod_scorm'
               AND action    = 'created'
               AND objectid  = :scormid
             ORDER BY timecreated ASC
             LIMIT 1
        ", ['scormid' => $scorm->scormid]);

        $isprotected = false;

        if ($creatorid) {
            $context = context_module::instance($scorm->cmid, IGNORE_MISSING);
            if ($context) {
                // Verificar si el creador original tenía permiso de carga al momento
                // de instalar. Usa has_capability() que respeta herencia de roles.
                $isprotected = has_capability('local/scorm_incca:cargar', $context, $creatorid);
            }
        }

        $DB->insert_record('local_scorm_incca_items', (object)[
            'cmid'         => (int)$scorm->cmid,
            'scormid'      => (int)$scorm->scormid,
            'courseid'     => (int)$scorm->courseid,
            'creatorid'    => $creatorid ?: 0,
            'isprotected'  => $isprotected ? 1 : 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    return true;
}
