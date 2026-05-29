<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_scorm_incca_users');

require_capability('local/scorm_incca:viewadminpanel', context_system::instance());

$PAGE->set_title(get_string('users_with_caps', 'local_scorm_incca'));
$PAGE->set_heading(get_string('users_with_caps', 'local_scorm_incca'));

echo $OUTPUT->header();

$caps = [
    'local/scorm_incca:cargar'    => get_string('cap_cargar', 'local_scorm_incca'),
    'local/scorm_incca:descargar' => get_string('cap_descargar', 'local_scorm_incca'),
];

echo html_writer::tag('p', get_string('users_with_caps_help', 'local_scorm_incca'));

foreach ($caps as $cap => $label) {
    echo $OUTPUT->heading($label, 3);

    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, r.shortname AS rolename
              FROM {role_assignments} ra
              JOIN {role} r           ON r.id = ra.roleid
              JOIN {role_capabilities} rc ON rc.roleid = r.id AND rc.capability = :cap AND rc.permission = :allow
              JOIN {user} u           ON u.id = ra.userid
             ORDER BY u.lastname, u.firstname";

    $users = $DB->get_records_sql($sql, ['cap' => $cap, 'allow' => CAP_ALLOW]);

    if (empty($users)) {
        echo $OUTPUT->notification(
            get_string('no_users_with_cap', 'local_scorm_incca'),
            'warning'
        );
        continue;
    }

    $table = new html_table();
    $table->head = [
        get_string('th_user',  'local_scorm_incca'),
        get_string('th_email', 'local_scorm_incca'),
        get_string('th_role',  'local_scorm_incca'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($users as $u) {
        $name = fullname($u);
        $table->data[] = [
            html_writer::link(
                new moodle_url('/user/profile.php', ['id' => $u->id]),
                $name
            ),
            s($u->email),
            s($u->rolename),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
