<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Executed automatically when Moodle installs the plugin for the first time.
 *
 * Does NOT query the database. Tables (install.xml), capabilities
 * (db/access.php), event observers (db/events.php) and admin panel pages
 * (settings.php) are all registered by Moodle core around this call —
 * nothing else needs to happen here.
 *
 * Earlier versions scanned every SCORM on the platform at this point and,
 * for each one, ran a separate query against {logstore_standard_log} to
 * find its creator (see problema.md for the incident report). On a site
 * with a large log table and many SCORMs, that N+1 pattern could hang for
 * the entire install request without a covering index — and because
 * Moodle core saves the target version in mdl_config_plugins BEFORE
 * calling this function, a timeout here left the plugin "installed" at
 * the target version while capabilities/observers were never registered.
 *
 * The plugin now starts with an EMPTY local_scorm_incca_items table.
 * Pre-existing SCORM packages are discovered on demand, one course at a
 * time, from the "Search by course" admin page (courses.php), which calls
 * \local_scorm_incca\helper::sync_course_scorms() for a single courseid.
 * New SCORMs created after install are still registered instantly by
 * classes/observer.php (course_module_created) — no logstore query there
 * either.
 *
 * @return bool
 */
function xmldb_local_scorm_incca_install(): bool {
    return true;
}
