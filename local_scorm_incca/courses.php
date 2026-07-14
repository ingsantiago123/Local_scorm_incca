<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/scorm_incca:viewadminpanel', context_system::instance());

if (is_siteadmin()) {
    admin_externalpage_setup('local_scorm_incca_courses');
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/scorm_incca/courses.php'));
    $PAGE->set_pagelayout('standard');
}

$shortname = optional_param('shortname', '', PARAM_TEXT);
$sync      = optional_param('sync',      0,  PARAM_INT);

// ── Sync action: register (as PUBLIC) any SCORM in this course not yet tracked.
// Bounded to a single course; never touches {logstore_standard_log}. ───────────
if ($sync && confirm_sesskey()) {
    $course = $DB->get_record('course', ['id' => $sync], 'id, shortname', IGNORE_MISSING);
    if ($course) {
        $registered = \local_scorm_incca\helper::sync_course_scorms((int)$course->id, (int)$USER->id);
        $msgkey = $registered > 0 ? 'sync_result' : 'sync_zero_result';
        redirect(
            new moodle_url('/local/scorm_incca/index.php', ['courseid' => $course->id]),
            get_string($msgkey, 'local_scorm_incca', (object)['count' => $registered, 'shortname' => $course->shortname]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(new moodle_url('/local/scorm_incca/courses.php'));
}

$PAGE->set_title(get_string('search_by_course', 'local_scorm_incca'));
$PAGE->set_heading(get_string('search_by_course', 'local_scorm_incca'));
echo $OUTPUT->header();

echo html_writer::tag('p', get_string('search_by_course_help', 'local_scorm_incca'), ['class' => 'text-muted']);

// ── Search form ──────────────────────────────────────────────────────────────
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'name'         => 'shortname',
    'value'        => s($shortname),
    'placeholder'  => get_string('shortname_placeholder', 'local_scorm_incca'),
    'class'        => 'form-control',
    'autocomplete' => 'off',
]);
echo html_writer::start_tag('div', ['class' => 'input-group-append']);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('search', 'local_scorm_incca'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('div');
echo html_writer::end_div();
echo html_writer::end_tag('form');

$shortnameclean = trim($shortname);

if ($shortnameclean === '') {
    echo $OUTPUT->footer();
    die();
}

// ── Exact match on shortname, not LIKE '%...%'. course.shortname is UNIQUE
// and indexed in Moodle core, so this is a single indexed lookup regardless
// of how many courses the site has — the same "stay bounded, don't scan"
// principle as sync_course_scorms(). A contains-match was tried first but
// discarded: with this university's internal shortname scheme, a partial
// match pulled in unrelated courses that merely share a substring. Staff
// managing these packages already know the exact shortname, so there is no
// UX reason to support partial matches here. ────────────────────────────────
$course = $DB->get_record('course', ['shortname' => $shortnameclean], 'id, shortname, fullname', IGNORE_MISSING);

if (!$course || (int)$course->id === (int)SITEID) {
    echo $OUTPUT->notification(get_string('no_courses_found', 'local_scorm_incca'), 'info');
    echo $OUTPUT->footer();
    die();
}

$cid         = (int)$course->id;
$syncedcounts = \local_scorm_incca\helper::get_synced_course_counts([$cid]);
$scormcounts  = \local_scorm_incca\helper::get_scorm_counts_for_courses([$cid]);
$foundcount   = $scormcounts[$cid]  ?? 0;
$syncedcount  = $syncedcounts[$cid] ?? 0;

$viewurl = new moodle_url('/local/scorm_incca/index.php', ['courseid' => $cid]);
$syncurl = new moodle_url('/local/scorm_incca/courses.php', [
    'shortname' => $shortnameclean, 'sync' => $cid, 'sesskey' => sesskey(),
]);

if ($syncedcount > 0) {
    $status = html_writer::tag('span',
        get_string('sync_status_done', 'local_scorm_incca', $syncedcount),
        ['class' => 'badge badge-success']);
} else if ($foundcount > 0) {
    $status = html_writer::tag('span',
        get_string('sync_status_pending', 'local_scorm_incca'),
        ['class' => 'badge badge-warning']);
} else {
    $status = html_writer::tag('span',
        get_string('sync_status_none', 'local_scorm_incca'),
        ['class' => 'badge badge-secondary']);
}

$actions = '';
if ($foundcount > $syncedcount) {
    $actions .= html_writer::link($syncurl,
        get_string('sync_now', 'local_scorm_incca'),
        ['class' => 'btn btn-sm btn-primary mr-1']);
}
if ($syncedcount > 0) {
    $actions .= html_writer::link($viewurl,
        get_string('view_items', 'local_scorm_incca'),
        ['class' => 'btn btn-sm btn-secondary']);
}

$table = new html_table();
$table->head = [
    get_string('th_shortname',   'local_scorm_incca'),
    get_string('th_course',      'local_scorm_incca'),
    get_string('th_scorm_count', 'local_scorm_incca'),
    get_string('th_sync_status', 'local_scorm_incca'),
    get_string('th_actions',     'local_scorm_incca'),
];
$table->attributes['class'] = 'generaltable table-sm';
$table->data[] = [
    html_writer::link($viewurl, s($course->shortname)),
    format_string($course->fullname),
    $foundcount,
    $status,
    $actions,
];

echo html_writer::table($table);

echo $OUTPUT->footer();
