<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

use core\event\course_module_created;
use core\event\course_module_updated;
use core\event\course_module_deleted;
use core\event\course_restored;

/**
 * Observa la creacion y eliminacion de actividades para registrar SCORMs.
 */
class observer {

    /**
     * Cuando se crea una actividad SCORM, se registra en el plugin.
     * Si el creador tiene local/scorm_incca:cargar -> protegido.
     * Si no la tiene -> publico (registrado igual para visibilidad en el panel).
     */
    public static function course_module_created(course_module_created $event): void {
        self::handle_create_or_update($event);
    }

    /**
     * Re-evalua la proteccion si se actualiza el course module.
     * Util si cambia el rol del usuario o se reasigna la actividad.
     */
    public static function course_module_updated(course_module_updated $event): void {
        self::handle_create_or_update($event);
    }

    /**
     * Limpia el registro cuando se elimina la actividad SCORM.
     */
    public static function course_module_deleted(course_module_deleted $event): void {
        $modulename = $event->other['modulename'] ?? '';
        if ($modulename !== 'scorm') {
            return;
        }

        try {
            $cmid = (int)$event->contextinstanceid;
            helper::unregister_scorm($cmid);
            helper::log(helper::LOG_DELETED, (int)$event->userid, $cmid,
                "Registro eliminado para cmid {$cmid}");
        } catch (\Throwable $e) {
            // Silenciar para no interrumpir el proceso de eliminación de Moodle.
        }
    }

    /**
     * Registra SCORMs que llegaron via import/restore entre cursos.
     *
     * course_module_created NO se dispara durante restore (Moodle crea los cmid via
     * DB directa en restore_stepslib.php::process_module). course_restored sí se
     * dispara al final de restore_plan::execute(), con originalcourseid disponible
     * en imports same-site.
     */
    public static function course_restored(course_restored $event): void {
        $destcourseid = (int) $event->objectid;
        $importerid   = (int) $event->userid;
        $sourcecourse = (int) ($event->other['originalcourseid'] ?? 0);

        try {
            helper::register_imported_scorms($destcourseid, $importerid, $sourcecourse);
        } catch (\Throwable $e) {
            helper::log(helper::LOG_ERROR, $importerid, 0,
                'course_restored handler: ' . $e->getMessage());
        }
    }

    /**
     * Logica compartida para create / update.
     */
    private static function handle_create_or_update($event): void {
        $modulename = $event->other['modulename'] ?? '';
        if ($modulename !== 'scorm') {
            return;
        }

        $isUpdate = ($event instanceof course_module_updated);

        try {
            global $DB;

            $cmid     = (int) $event->contextinstanceid;
            $scormid  = (int) ($event->other['instanceid'] ?? 0);
            $courseid = (int) $event->courseid;
            $userid   = (int) $event->userid;

            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if (!$context) {
                return;
            }

            $existing      = $DB->get_record('local_scorm_incca_items', ['cmid' => $cmid]);
            $userHasCargar = has_capability('local/scorm_incca:cargar', $context, $userid);

            if ($isUpdate) {
                if ($existing) {
                    // UPDATE de SCORM ya registrado: el formulario de edición NUNCA cambia
                    // isprotected, sin importar quién guarde ni qué capability tenga.
                    // La única fuente de verdad para isprotected es el panel de administración.
                    $isprotected = (bool) $existing->isprotected;
                } else {
                    // UPDATE de SCORM anterior a la instalación del plugin (no está en la tabla).
                    // Registrar como público — no tenemos información sobre quién lo subió
                    // ni con qué permisos, así que aplicamos el estado más permisivo.
                    $isprotected = false;
                }
            } else {
                // CREATE: estado inicial según la capability del creador.
                // Si tiene cargar → protegido automáticamente.
                // Si no tiene cargar → público.
                $isprotected = $userHasCargar;
            }

            debugger::logDiag('SCORM_SAVE', [
                'event'           => $isUpdate ? 'update' : 'create',
                'cmid'            => $cmid,
                'userid'          => $userid,
                'script'          => $_SERVER['SCRIPT_NAME'] ?? '(cli)',
                'db_isprotected'  => $existing ? (int) $existing->isprotected : null,
                'user_has_cargar' => $userHasCargar,
                'final_protected' => $isprotected,
                // true = el formulario preservó el estado sin tocarlo (comportamiento correcto en update).
                'status_preserved' => $isUpdate,
            ]);

            helper::register_scorm($cmid, $scormid, $courseid, $userid, $isprotected);

            $type = $isprotected ? helper::LOG_UPLOAD_PROTECTED : helper::LOG_UPLOAD_PUBLIC;

            if ($isUpdate) {
                $stateLabel = $isprotected ? 'PROTEGIDO' : 'PÚBLICO';
                $msg = "SCORM cmid={$cmid} editado — estado {$stateLabel} preservado sin cambios (editor userid={$userid})";
            } else {
                $msg = $isprotected
                    ? "SCORM cmid={$cmid} creado como PROTEGIDO (creador userid={$userid})"
                    : "SCORM cmid={$cmid} creado como PÚBLICO (creador userid={$userid})";
            }

            helper::log($type, $userid, $cmid, $msg);

        } catch (\Throwable $e) {
            helper::log(helper::LOG_ERROR, (int)$event->userid, $event->contextinstanceid ?? null,
                "Error en observer: " . $e->getMessage());
        }
    }
}
