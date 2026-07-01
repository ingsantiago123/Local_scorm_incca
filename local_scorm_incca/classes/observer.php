<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

use core\event\course_module_created;
use core\event\course_module_updated;
use core\event\course_module_deleted;
use core\event\course_restored;

/**
 * Observes activity creation and deletion to register SCORMs.
 */
class observer {

    /**
     * When a SCORM activity is created, register it in the plugin.
     * If the creator has local/scorm_incca:upload → protected.
     * If not → public (still registered for visibility in the admin panel).
     */
    public static function course_module_created(course_module_created $event): void {
        self::handle_create_or_update($event);
    }

    /**
     * Re-evaluates protection status when the course module is updated.
     * Useful if the user's role changes or the activity is reassigned.
     */
    public static function course_module_updated(course_module_updated $event): void {
        self::handle_create_or_update($event);
    }

    /**
     * Cleans up the registration when a SCORM activity is deleted.
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
                "Record removed for cmid={$cmid}");
        } catch (\Throwable $e) {
            // Silence to avoid interrupting Moodle's deletion process.
        }
    }

    /**
     * Registers SCORMs that arrived via import/restore between courses.
     *
     * course_module_created does NOT fire during restore (Moodle creates cmids
     * directly via DB in restore_stepslib.php::process_module). course_restored
     * does fire at the end of restore_plan::execute(), with originalcourseid
     * available for same-site imports.
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
     * Shared logic for create / update events.
     *
     * @param course_module_created|course_module_updated $event
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
            $userHasCargar = has_capability('local/scorm_incca:upload', $context, $userid);

            if ($isUpdate) {
                if ($existing) {
                    // UPDATE on already-registered SCORM: the edit form NEVER changes
                    // isprotected, regardless of who saves it or what capability they have.
                    // The only source of truth for isprotected is the admin panel.
                    $isprotected = (bool) $existing->isprotected;
                } else {
                    // UPDATE on a SCORM that pre-dates the plugin installation (not in table).
                    // Register as public — no information about who uploaded it or with
                    // what permissions, so we apply the most permissive state.
                    $isprotected = false;
                }
            } else {
                // CREATE: initial state based on the creator's capability.
                // Has cargar → automatically protected.
                // Does not have cargar → public.
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
                // true = the form preserved the status without changing it (correct behaviour on update).
                'status_preserved' => $isUpdate,
            ]);

            helper::register_scorm($cmid, $scormid, $courseid, $userid, $isprotected);

            $type = $isprotected ? helper::LOG_UPLOAD_PROTECTED : helper::LOG_UPLOAD_PUBLIC;

            if ($isUpdate) {
                $stateLabel = $isprotected ? 'PROTECTED' : 'PUBLIC';
                $msg = "SCORM cmid={$cmid} edited — status {$stateLabel} preserved unchanged (editor userid={$userid})";
            } else {
                $msg = $isprotected
                    ? "SCORM cmid={$cmid} created as PROTECTED (creator userid={$userid})"
                    : "SCORM cmid={$cmid} created as PUBLIC (creator userid={$userid})";
            }

            helper::log($type, $userid, $cmid, $msg);

        } catch (\Throwable $e) {
            helper::log(helper::LOG_ERROR, (int)$event->userid, $event->contextinstanceid ?? null,
                "Observer error: " . $e->getMessage());
        }
    }
}
