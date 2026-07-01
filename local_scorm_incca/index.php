<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/scorm_incca:viewadminpanel', context_system::instance());

if (is_siteadmin()) {
    admin_externalpage_setup('local_scorm_incca_items');
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/scorm_incca/index.php'));
    $PAGE->set_pagelayout('standard');
}

// ── Internal cleanup helper ────────────────────────────────────────────────────
function scorm_incca_delete_orphans(): int {
    global $DB;
    $items   = $DB->get_records('local_scorm_incca_items', [], '', 'id, cmid');
    $deleted = 0;
    foreach ($items as $item) {
        $valid = $DB->record_exists_sql(
            "SELECT 1
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
              WHERE cm.id = :cmid
                AND (cm.deletioninprogress = 0 OR cm.deletioninprogress IS NULL)",
            ['cmid' => (int)$item->cmid]
        );
        if (!$valid) {
            $DB->delete_records('local_scorm_incca_items', ['cmid' => (int)$item->cmid]);
            $deleted++;
        }
    }
    return $deleted;
}

try {
    scorm_incca_delete_orphans();
} catch (\Throwable $e) {
    //Do not break the panel if cleanup fails.
}

// ── GET parameters ─────────────────────────────────────────────────────────────
$filter  = optional_param('filter',  'all', PARAM_ALPHA);
$search  = optional_param('search',  '',    PARAM_TEXT);
$page    = optional_param('page',    0,     PARAM_INT);
$perpage = 10;
$action  = optional_param('action',  '',    PARAM_ALPHA);
$cmid    = optional_param('cmid',    0,     PARAM_INT);

// ── POST actions ───────────────────────────────────────────────────────────────
if ($action === 'toggle' && $cmid && confirm_sesskey()) {
    $rec    = $DB->get_record('local_scorm_incca_items', ['cmid' => $cmid], '*', MUST_EXIST);
    $newval = $rec->isprotected ? 0 : 1;
    $DB->set_field('local_scorm_incca_items', 'isprotected', $newval, ['cmid' => $cmid]);
    $DB->set_field('local_scorm_incca_items', 'timemodified', time(), ['cmid' => $cmid]);
    $msgkey = $newval ? 'changed_to_protected' : 'changed_to_public';
    \local_scorm_incca\helper::log(
        \local_scorm_incca\helper::LOG_UPLOAD_PROTECTED,
        (int)$USER->id,
        $cmid,
        "Status changed manually to " . ($newval ? 'PROTECTED' : 'PUBLIC') . " | cmid={$cmid} by userid={$USER->id}"
    );
    redirect(
        new moodle_url('/local/scorm_incca/index.php', [
            'filter' => $filter, 'search' => $search, 'page' => $page,
        ]),
        get_string($msgkey, 'local_scorm_incca', (object)['cmid' => $cmid]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'cleanup' && confirm_sesskey()) {
    try {
        $deleted = scorm_incca_delete_orphans();
        redirect(
            new moodle_url('/local/scorm_incca/index.php'),
            get_string('cleanup_result', 'local_scorm_incca', $deleted),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        redirect(
            new moodle_url('/local/scorm_incca/index.php'),
            get_string('cleanup_error', 'local_scorm_incca', $e->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// ── SQL: filter + search condition ────────────────────────────────────────────
$params = [];
$where  = '1=1';
if ($filter === 'protected') $where = 'i.isprotected = 1';
if ($filter === 'public')    $where = 'i.isprotected = 0';

$searchsql    = '';
$searchparams = [];
$searchclean  = trim($search);
if ($searchclean !== '') {
    $searchsql =
        ' AND (' . $DB->sql_like('s.name',     ':searchname',   false) .
        ' OR '  . $DB->sql_like('c.fullname',  ':searchcourse', false) .
        ' OR '  . $DB->sql_like('c.shortname', ':searchshort',  false) . ')';
    $esc = '%' . $DB->sql_like_escape($searchclean) . '%';
    $searchparams = ['searchname' => $esc, 'searchcourse' => $esc, 'searchshort' => $esc];
}

$allparams = array_merge($params, $searchparams);

$basesql = "FROM {local_scorm_incca_items} i
            LEFT JOIN {user}   u ON u.id = i.creatorid
            LEFT JOIN {course} c ON c.id = i.courseid
            LEFT JOIN {scorm}  s ON s.id = i.scormid
            WHERE {$where}{$searchsql}";

$total = $DB->count_records_sql("SELECT COUNT(1) {$basesql}", $allparams);

$selectsql = "SELECT i.id, i.cmid, i.scormid, i.courseid, i.isprotected,
                     i.timecreated, i.timemodified,
                     u.firstname, u.lastname, u.email,
                     c.fullname AS coursename, c.shortname,
                     s.name AS scormname
              {$basesql}
              ORDER BY i.timemodified DESC";

$records = $DB->get_records_sql($selectsql, $allparams, $page * $perpage, $perpage);

// ── Page header ───────────────────────────────────────────────────────────────
$PAGE->set_title(get_string('protected_list', 'local_scorm_incca'));
$PAGE->set_heading(get_string('protected_list', 'local_scorm_incca'));
echo $OUTPUT->header();

// ── Search bar ────────────────────────────────────────────────────────────────
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter]);
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'name'         => 'search',
    'value'        => s($searchclean),
    'placeholder'  => get_string('search_placeholder', 'local_scorm_incca'),
    'class'        => 'form-control',
    'autocomplete' => 'off',
]);
echo html_writer::start_tag('div', ['class' => 'input-group-append']);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('search', 'local_scorm_incca'),
    'class' => 'btn btn-primary',
]);
if ($searchclean !== '') {
    echo html_writer::link(
        new moodle_url('/local/scorm_incca/index.php', ['filter' => $filter]),
        '✕',
        ['class' => 'btn btn-outline-secondary', 'title' => get_string('clear_search', 'local_scorm_incca')]
    );
}
echo html_writer::end_tag('div');
echo html_writer::end_div();
echo html_writer::end_tag('form');

// ── Filter row + actions ──────────────────────────────────────────────────────
echo html_writer::start_div('mb-3 d-flex gap-2 flex-wrap align-items-center');

$filtersMap = [
    'all'       => get_string('filter_all',       'local_scorm_incca'),
    'protected' => get_string('filter_protected',  'local_scorm_incca'),
    'public'    => get_string('filter_public',     'local_scorm_incca'),
];
foreach ($filtersMap as $key => $label) {
    $url = new moodle_url('/local/scorm_incca/index.php', [
        'filter' => $key, 'search' => $searchclean,
    ]);
    $cls = ($filter === $key) ? 'btn btn-primary mr-1' : 'btn btn-outline-primary mr-1';
    echo html_writer::link($url, $label, ['class' => $cls]);
}

echo '&nbsp;&nbsp;';

$cleanupurl = new moodle_url('/local/scorm_incca/index.php', [
    'action' => 'cleanup', 'sesskey' => sesskey(),
]);
echo html_writer::link($cleanupurl,
    $OUTPUT->pix_icon('t/delete', '') . ' ' . get_string('cleanup_orphans', 'local_scorm_incca'),
    [
        'class'   => 'btn btn-outline-danger mr-1',
        'title'   => get_string('cleanup_orphans_title', 'local_scorm_incca'),
        'onclick' => 'return confirm(' . json_encode(get_string('cleanup_orphans_confirm', 'local_scorm_incca')) . ')',
    ]
);

echo html_writer::end_div();

// ── Counter and top pagination ────────────────────────────────────────────────
if ($total > 0) {
    $from  = $page * $perpage + 1;
    $to    = min($from + $perpage - 1, $total);
    $info  = (object)['from' => $from, 'to' => $to, 'total' => $total];
    echo html_writer::tag('p',
        get_string('showing_x_of_y', 'local_scorm_incca', $info),
        ['class' => 'text-muted small mb-2']
    );
}

// ── Table (desktop) / Cards (mobile) ─────────────────────────────────────────
if (empty($records)) {
    echo $OUTPUT->notification(get_string('no_records', 'local_scorm_incca'), 'info');
} else {

    // ── Table view: md and above ────────────────────────────────────────────
    echo html_writer::start_div('d-none d-md-block');
    echo html_writer::start_div('table-responsive');

    $table = new html_table();
    $table->head = [
        get_string('th_status',  'local_scorm_incca'),
        get_string('th_scorm',   'local_scorm_incca'),
        get_string('th_course',  'local_scorm_incca'),
        get_string('th_creator', 'local_scorm_incca'),
        get_string('th_created', 'local_scorm_incca'),
        get_string('th_actions', 'local_scorm_incca'),
    ];
    $table->attributes['class'] = 'generaltable table-sm';

    foreach ($records as $r) {
        $table->data[] = scorm_incca_build_row($r, $filter, $searchclean, $page, $OUTPUT);
    }
    echo html_writer::table($table);
    echo html_writer::end_div(); // table-responsive
    echo html_writer::end_div(); // d-none d-md-block

    // ── Card view: below md ─────────────────────────────────────────────────
    echo html_writer::start_div('d-md-none');
    foreach ($records as $r) {
        echo scorm_incca_build_card($r, $filter, $searchclean, $page, $OUTPUT);
    }
    echo html_writer::end_div();

    // ── Bottom pagination ───────────────────────────────────────────────────
    $pageurl = new moodle_url('/local/scorm_incca/index.php', [
        'filter' => $filter, 'search' => $searchclean,
    ]);
    echo html_writer::start_div('mt-3');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    echo html_writer::end_div();
}

echo $OUTPUT->footer();

// ── Rendering helpers ──────────────────────────────────────────────────────────

function scorm_incca_status_badge(bool $isprotected, $OUTPUT): string {
    return $isprotected
        ? html_writer::tag('span',
            $OUTPUT->pix_icon('t/locked', '') . ' ' . get_string('protected_badge', 'local_scorm_incca'),
            ['class' => 'badge badge-danger', 'style' => 'font-size:11px'])
        : html_writer::tag('span',
            $OUTPUT->pix_icon('t/unlocked', '') . ' ' . get_string('public_badge', 'local_scorm_incca'),
            ['class' => 'badge badge-secondary', 'style' => 'font-size:11px']);
}

function scorm_incca_toggle_btn(object $r, string $filter, string $search, int $page, $OUTPUT): string {
    $togglelabel = $r->isprotected
        ? $OUTPUT->pix_icon('t/unlocked', '') . ' ' . get_string('make_public',    'local_scorm_incca')
        : $OUTPUT->pix_icon('t/locked',   '') . ' ' . get_string('make_protected', 'local_scorm_incca');
    $toggleclass = $r->isprotected
        ? 'btn btn-sm btn-outline-secondary'
        : 'btn btn-sm btn-outline-danger';
    $toggleurl = new moodle_url('/local/scorm_incca/index.php', [
        'action'  => 'toggle',
        'cmid'    => $r->cmid,
        'sesskey' => sesskey(),
        'filter'  => $filter,
        'search'  => $search,
        'page'    => $page,
    ]);
    $confirmkey = $r->isprotected ? 'make_public_confirm' : 'make_protected_confirm';
    return html_writer::link($toggleurl, $togglelabel, [
        'class'   => $toggleclass . ' mr-1',
        'onclick' => 'return confirm(' . json_encode(get_string($confirmkey, 'local_scorm_incca')) . ')',
    ]);
}

function scorm_incca_build_row(object $r, string $filter, string $search, int $page, $OUTPUT): array {
    $statusbadge = scorm_incca_status_badge((bool)$r->isprotected, $OUTPUT);

    $scormlink = ($r->cmid && $r->scormname)
        ? html_writer::link(
            new moodle_url('/mod/scorm/view.php', ['id' => $r->cmid]),
            format_string($r->scormname))
        : ($r->scormname ? format_string($r->scormname) : '-');

    $courselink = ($r->courseid && $r->coursename)
        ? html_writer::link(
            new moodle_url('/course/view.php', ['id' => $r->courseid]),
            format_string($r->coursename))
        : '-';

    $creator = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? ''));
    if ($r->email) {
        $creator .= html_writer::empty_tag('br') .
            html_writer::tag('small', s($r->email), ['class' => 'text-muted']);
    }

    $actions  = scorm_incca_toggle_btn($r, $filter, $search, $page, $OUTPUT);
    $actions .= html_writer::link(
        new moodle_url('/local/scorm_incca/logs.php', ['cmid' => $r->cmid]),
        get_string('view_logs', 'local_scorm_incca'),
        ['class' => 'btn btn-sm btn-secondary']
    );

    return [$statusbadge, $scormlink, $courselink, $creator ?: '-', userdate($r->timecreated), $actions];
}

function scorm_incca_build_card(object $r, string $filter, string $search, int $page, $OUTPUT): string {
    $borderclass = $r->isprotected ? 'border-danger' : 'border-secondary';
    $badge  = scorm_incca_status_badge((bool)$r->isprotected, $OUTPUT);
    $toggle = scorm_incca_toggle_btn($r, $filter, $search, $page, $OUTPUT);

    $scormname = $r->scormname
        ? ($r->cmid
            ? html_writer::link(new moodle_url('/mod/scorm/view.php', ['id' => $r->cmid]),
                format_string($r->scormname))
            : format_string($r->scormname))
        : '-';

    $coursename = ($r->courseid && $r->coursename)
        ? html_writer::link(new moodle_url('/course/view.php', ['id' => $r->courseid]),
            format_string($r->coursename))
        : '-';

    $creator = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')) ?: '-';

    $toggle = scorm_incca_toggle_btn($r, $filter, $search, $page, $OUTPUT);
    $logs   = html_writer::link(
        new moodle_url('/local/scorm_incca/logs.php', ['cmid' => $r->cmid]),
        get_string('view_logs', 'local_scorm_incca'),
        ['class' => 'btn btn-sm btn-secondary']
    );

    return html_writer::div(
        html_writer::div(
            html_writer::div(
                html_writer::tag('h6', $scormname, ['class' => 'card-title mb-1']) .
                html_writer::tag('p', $badge, ['class' => 'mb-1']) .
                html_writer::tag('p',
                    html_writer::tag('small', '📚 ' . $coursename, ['class' => 'text-muted']),
                    ['class' => 'mb-1']) .
                html_writer::tag('p',
                    html_writer::tag('small', '👤 ' . s($creator), ['class' => 'text-muted']),
                    ['class' => 'mb-1']) .
                html_writer::tag('p',
                    html_writer::tag('small', '🕐 ' . userdate($r->timecreated), ['class' => 'text-muted']),
                    ['class' => 'mb-2']) .
                html_writer::div($toggle . $logs, 'd-flex flex-wrap gap-1'),
            'card-body py-2')
        , 'card mb-2 ' . $borderclass)
    , '');
}
