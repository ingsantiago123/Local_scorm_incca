<?php
namespace local_scorm_incca\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider para local_scorm_incca.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_scorm_incca_items',
            [
                'creatorid'    => 'privacy:metadata:items:creatorid',
                'cmid'         => 'privacy:metadata:items:cmid',
                'isprotected'  => 'privacy:metadata:items:isprotected',
                'timecreated'  => 'privacy:metadata:items:timecreated',
            ],
            'privacy:metadata:items'
        );

        $collection->add_database_table(
            'local_scorm_incca_logs',
            [
                'userid'      => 'privacy:metadata:logs:userid',
                'eventtype'   => 'privacy:metadata:logs:eventtype',
                'message'     => 'privacy:metadata:logs:message',
                'ipaddress'   => 'privacy:metadata:logs:ipaddress',
                'timecreated' => 'privacy:metadata:logs:timecreated',
            ],
            'privacy:metadata:logs'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
                  JOIN {local_scorm_incca_items} i ON i.cmid = cm.id
                 WHERE i.creatorid = :userid";
        $contextlist->add_from_sql($sql, ['level' => CONTEXT_MODULE, 'userid' => $userid]);
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT creatorid FROM {local_scorm_incca_items} WHERE cmid = :cmid";
        $userlist->add_from_sql('creatorid', $sql, ['cmid' => $context->instanceid]);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        // Implementacion minima - los datos no son sensibles, solo registran autoria.
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_MODULE) {
            $DB->delete_records('local_scorm_incca_items', ['cmid' => $context->instanceid]);
            $DB->delete_records('local_scorm_incca_logs',  ['cmid' => $context->instanceid]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_scorm_incca_logs', ['userid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_scorm_incca_logs', "userid $insql", $params);
    }
}
