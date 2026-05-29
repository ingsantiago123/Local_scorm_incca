<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/scorm_incca:viewadminpanel', context_system::instance());

if (is_siteadmin()) {
    admin_externalpage_setup('local_scorm_incca_logs');
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/scorm_incca/logs.php'));
    $PAGE->set_pagelayout('standard');
}

$cmid      = optional_param('cmid',      0,   PARAM_INT);
$eventtype = optional_param('eventtype', '',  PARAM_ALPHANUMEXT);
$page      = optional_param('page',      0,   PARAM_INT);
$perpage   = 50;

$PAGE->set_title(get_string('logs', 'local_scorm_incca'));
$PAGE->set_heading(get_string('logs', 'local_scorm_incca'));
echo $OUTPUT->header();

// ── Formulario de filtros ──────────────────────────────────────────────────────
$eventtypes = [
    ''                                               => get_string('filter_all',             'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_UPLOAD_PROTECTED  => get_string('log_upload_protected',   'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_UPLOAD_PUBLIC     => get_string('log_upload_public',      'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_DOWNLOAD_ALLOWED  => get_string('log_download_allowed',   'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_DOWNLOAD_BLOCKED  => get_string('log_download_blocked',   'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_DELETED           => get_string('log_deleted',            'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_ERROR             => get_string('log_error',              'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_DELETE_BLOCKED    => get_string('log_delete_blocked',     'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_UNZIP_BLOCKED     => get_string('log_unzip_blocked',      'local_scorm_incca'),
    \local_scorm_incca\helper::LOG_IMPORT_REGISTERED => get_string('log_import_registered',  'local_scorm_incca'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::start_div('row g-2 align-items-end');

echo html_writer::start_div('col-12 col-sm-auto');
echo html_writer::select($eventtypes, 'eventtype', $eventtype, false,
    ['class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-sm-auto');
echo html_writer::empty_tag('input', [
    'type'        => 'number',
    'name'        => 'cmid',
    'placeholder' => get_string('filter_by_cmid', 'local_scorm_incca'),
    'value'       => $cmid ?: '',
    'class'       => 'form-control',
    'min'         => '1',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-sm-auto');
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('filter', 'local_scorm_incca'),
    'class' => 'btn btn-primary',
]);
if ($eventtype !== '' || $cmid) {
    echo '&nbsp;';
    echo html_writer::link(
        new moodle_url('/local/scorm_incca/logs.php'),
        'Limpiar',
        ['class' => 'btn btn-outline-secondary']
    );
}
echo html_writer::end_div();

echo html_writer::end_div(); // row
echo html_writer::end_tag('form');

// ── Query ──────────────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if (!empty($eventtype)) {
    $where[]              = 'l.eventtype = :eventtype';
    $params['eventtype']  = $eventtype;
}
if (!empty($cmid)) {
    $where[]       = 'l.cmid = :cmid';
    $params['cmid'] = $cmid;
}

$wheresql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $DB->count_records_sql(
    "SELECT COUNT(1) FROM {local_scorm_incca_logs} l {$wheresql}", $params
);

$sql = "SELECT l.id, l.eventtype, l.userid, l.cmid, l.message, l.ipaddress, l.timecreated,
               u.firstname, u.lastname
          FROM {local_scorm_incca_logs} l
          LEFT JOIN {user} u ON u.id = l.userid
          {$wheresql}
         ORDER BY l.timecreated DESC";

$records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// ── Contador ───────────────────────────────────────────────────────────────────
if ($total > 0) {
    $from = $page * $perpage + 1;
    $to   = min($from + $perpage - 1, $total);
    echo html_writer::tag('p',
        "Mostrando {$from}–{$to} de {$total} registros",
        ['class' => 'text-muted small mb-2']
    );
}

if (empty($records)) {
    echo $OUTPUT->notification(get_string('no_logs', 'local_scorm_incca'), 'info');
} else {

    // Badge colors.
    $badgeclass = [
        'upload_protected'   => 'badge-info',
        'upload_public'      => 'badge-secondary',
        'download_allowed'   => 'badge-success',
        'download_blocked'   => 'badge-danger',
        'deleted'            => 'badge-warning',
        'error'              => 'badge-dark',
        'delete_blocked'     => 'badge-danger',
        'unzip_blocked'      => 'badge-warning',
        'import_registered'  => 'badge-primary',
    ];

    // ── Vista tabla: md y superior ─────────────────────────────────────────
    echo html_writer::start_div('d-none d-md-block');
    echo html_writer::start_div('table-responsive');

    $table = new html_table();
    $table->head = [
        get_string('th_when',      'local_scorm_incca'),
        get_string('th_eventtype', 'local_scorm_incca'),
        get_string('th_user',      'local_scorm_incca'),
        get_string('th_cmid',      'local_scorm_incca'),
        get_string('th_ip',        'local_scorm_incca'),
        get_string('th_message',   'local_scorm_incca'),
    ];
    $table->attributes['class'] = 'generaltable table-sm';

    foreach ($records as $r) {
        $table->data[] = logs_build_row($r, $badgeclass);
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // ── Vista cards: menos de md ───────────────────────────────────────────
    echo html_writer::start_div('d-md-none');
    foreach ($records as $r) {
        echo logs_build_card($r, $badgeclass);
    }
    echo html_writer::end_div();

    // ── Paginación inferior ────────────────────────────────────────────────
    echo html_writer::start_div('mt-3');
    echo $OUTPUT->paging_bar($total, $page, $perpage,
        new moodle_url('/local/scorm_incca/logs.php',
            ['eventtype' => $eventtype, 'cmid' => $cmid]));
    echo html_writer::end_div();
}

echo $OUTPUT->footer();

// ── Helpers ────────────────────────────────────────────────────────────────────

function logs_user_link(object $r): string {
    $name = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? ''));
    if ($r->userid) {
        return html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $r->userid]),
            $name ?: '#' . $r->userid
        );
    }
    return '-';
}

function logs_build_row(object $r, array $badgeclass): array {
    $cls   = $badgeclass[$r->eventtype] ?? 'badge-secondary';
    $badge = html_writer::tag('span', s($r->eventtype), ['class' => "badge {$cls}"]);
    return [
        userdate($r->timecreated, '%Y-%m-%d %H:%M:%S'),
        $badge,
        logs_user_link($r),
        $r->cmid ? s($r->cmid) : '-',
        $r->ipaddress ? s($r->ipaddress) : '-',
        s($r->message),
    ];
}

function logs_build_card(object $r, array $badgeclass): string {
    $cls   = $badgeclass[$r->eventtype] ?? 'badge-secondary';
    $badge = html_writer::tag('span', s($r->eventtype), ['class' => "badge {$cls} mb-1"]);
    $border = in_array($cls, ['badge-danger', 'badge-dark']) ? 'border-danger' : 'border-secondary';

    return html_writer::div(
        html_writer::div(
            $badge .
            html_writer::tag('p',
                html_writer::tag('small', '🕐 ' . userdate($r->timecreated, '%Y-%m-%d %H:%M:%S'), ['class' => 'text-muted']),
                ['class' => 'mb-1']) .
            html_writer::tag('p',
                html_writer::tag('small', '👤 ' . logs_user_link($r), ['class' => 'text-muted']),
                ['class' => 'mb-1']) .
            ($r->cmid ? html_writer::tag('p',
                html_writer::tag('small', 'cmid: ' . s($r->cmid), ['class' => 'text-muted']),
                ['class' => 'mb-1']) : '') .
            ($r->ipaddress ? html_writer::tag('p',
                html_writer::tag('small', 'IP: ' . s($r->ipaddress), ['class' => 'text-muted']),
                ['class' => 'mb-1']) : '') .
            html_writer::tag('p', s($r->message), ['class' => 'mb-0 small']),
        'card-body py-2')
    , 'card mb-2 ' . $border);
}
