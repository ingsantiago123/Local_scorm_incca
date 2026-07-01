<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Full cleanup when the plugin is uninstalled.
 *
 * IMPORTANT: This function runs BEFORE Moodle drops the plugin tables
 * (declared in install.xml). We explicitly clean everything here to ensure
 * no data remains even if the Moodle process fails partway through.
 *
 * What this function cleans up:
 *  ✅ All records in mdl_local_scorm_incca_items
 *  ✅ All records in mdl_local_scorm_incca_logs
 *  ✅ Debug log file in moodledata
 *
 * What Moodle cleans up automatically afterwards:
 *  - The tables themselves (DROP TABLE)
 *  - Capabilities declared in db/access.php
 *  - Plugin settings in mdl_config_plugins
 *  - Event records in mdl_events
 *  - Language strings
 *
 * What is intentionally left untouched:
 *  - SCORM packages (mdl_scorm, mdl_course_modules, mdl_files) — not owned by this plugin
 *  - Courses, users, roles, or any Moodle core data
 *
 * @return bool true always (errors are logged but do not interrupt uninstallation)
 */
function xmldb_local_scorm_incca_uninstall(): bool {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    // ── 1. Clear records from the items table ────────────────────────────────
    // Use table_exists() to avoid breaking if the table was already dropped
    // in a previous failed uninstall attempt.
    if ($dbman->table_exists('local_scorm_incca_items')) {
        $DB->delete_records('local_scorm_incca_items');
    }

    // ── 2. Clear records from the logs table ─────────────────────────────────
    if ($dbman->table_exists('local_scorm_incca_logs')) {
        $DB->delete_records('local_scorm_incca_logs');
    }

    // ── 3. Remove the debug log file from moodledata ─────────────────────────
    // This file is created by classes/debugger.php and is NOT managed by Moodle.
    $logfile = rtrim($CFG->dataroot, '/\\') . DIRECTORY_SEPARATOR . 'local_scorm_incca_debug.log';
    if (file_exists($logfile)) {
        @unlink($logfile);
    }

    return true;
}
