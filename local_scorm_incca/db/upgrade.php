<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_scorm_incca.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_scorm_incca_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070900) {

        // Course-scoped lookups (courses.php / index.php courseid filter) rely on
        // this index instead of scanning the whole items table.
        $table = new xmldb_table('local_scorm_incca_items');
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026070900, 'local', 'scorm_incca');
    }

    return true;
}
