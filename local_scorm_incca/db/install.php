<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Executed automatically when Moodle installs the plugin for the first time.
 *
 * Scans existing SCORMs on the platform and registers them:
 *  - If the creator has local/scorm_incca:upload → protected (isprotected=1)
 *  - If they don't have it or no creator is identified → public (isprotected=0)
 *
 * Only registers SCORMs that CURRENTLY exist in mdl_course_modules.
 * Deleted SCORMs are excluded because the JOIN with course_modules
 * filters only those with an active course module.
 *
 * @return bool
 */
function xmldb_local_scorm_incca_install(): bool {
    global $DB;

    // Retrieve only SCORMs that have an active course_module right now.
    // The JOIN ensures orphaned SCORMs (removed from Moodle but whose
    // mdl_scorm records might remain for any reason) are not registered.
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
        // Guard against duplicates if install() is called more than once.
        // This should not happen in a normal install, but protects against
        // partial failures of a previous installation attempt.
        if ($DB->record_exists('local_scorm_incca_items', ['cmid' => $scorm->cmid])) {
            continue;
        }

        // Attempt to identify the creator via Moodle's standard log store.
        // If logging is disabled or the entry does not exist, creatorid = 0.
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
                // Check whether the original creator had the upload capability at
                // install time. Uses has_capability() which respects role inheritance.
                $isprotected = has_capability('local/scorm_incca:upload', $context, $creatorid);
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
