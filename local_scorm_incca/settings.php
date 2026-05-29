<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $category = new admin_category(
        'local_scorm_incca_category',
        get_string('pluginname', 'local_scorm_incca')
    );
    $ADMIN->add('localplugins', $category);

    $ADMIN->add('local_scorm_incca_category', new admin_externalpage(
        'local_scorm_incca_items',
        get_string('protected_list', 'local_scorm_incca'),
        new moodle_url('/local/scorm_incca/index.php'),
        'local/scorm_incca:viewadminpanel'
    ));

    $ADMIN->add('local_scorm_incca_category', new admin_externalpage(
        'local_scorm_incca_logs',
        get_string('logs', 'local_scorm_incca'),
        new moodle_url('/local/scorm_incca/logs.php'),
        'local/scorm_incca:viewadminpanel'
    ));

    $ADMIN->add('local_scorm_incca_category', new admin_externalpage(
        'local_scorm_incca_users',
        get_string('users_with_caps', 'local_scorm_incca'),
        new moodle_url('/local/scorm_incca/users.php'),
        'local/scorm_incca:viewadminpanel'
    ));


}
