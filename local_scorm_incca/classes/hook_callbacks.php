<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Access control logic for local_scorm_incca.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INTERCEPTION ARCHITECTURE (Moodle 4.5)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * MECHANISM 1 — after_config hook (db/hooks.php)
 *   • Fires in lib/setup.php for EVERY HTTP request.
 *   • Covers draftfile.php and pluginfile.php before they serve the file.
 *   • The hook dispatcher wraps every callback in try/catch, so exceptions
 *     are caught silently — safe even when NO_DEBUG_DISPLAY=true.
 *
 * MECHANISM 2 — after_require_login (lib.php, LEGACY callback)
 *   • Fires inside require_login() as a fallback.
 *   • Acts if the hook fired before the user was authenticated.
 *
 * SINGLE-EXECUTION GUARD
 *   Both mechanisms call check_access(). To avoid double DB queries,
 *   check_access() uses REQUEST_TIME_FLOAT as a per-request guard key.
 *
 * SITE ADMIN BYPASS
 *   Site admins can always download.
 */
class hook_callbacks {

    // ═══════════════════════════════════════════════════════════════════════════
    // HOOK ENTRY POINT: after_config
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Hook callback: fires when config.php finishes loading (every HTTP request).
     *
     * Registered in db/hooks.php for \core\hook\after_config.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $USER;

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $isDraft   = strpos($script, '/draftfile.php')       !== false;
        $isPlugin  = strpos($script, '/pluginfile.php')      !== false;
        $isAjax    = strpos($script, '/draftfiles_ajax.php') !== false;
        $isService = strpos($script, '/lib/ajax/service.php') !== false;
        $isBackup  = strpos($script, '/backup/backup.php')   !== false;

        if (!$isDraft && !$isPlugin && !$isAjax && !$isService && !$isBackup) {
            return;
        }

        if (empty($USER) || empty($USER->id) || isguestuser($USER)) {
            return;
        }

        if ($isBackup) {
            self::handle_backup($USER);
            return;
        }

        self::check_access();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CENTRAL LOGIC: check_access()
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Central access control logic.
     * Called from after_config hook and from after_require_login (lib.php).
     *
     * Includes a single-execution guard to avoid double DB queries when
     * both mechanisms fire in the same request.
     */
    public static function check_access(): void {
        global $USER;

        // ── Guard: prevent double execution within the same request ──────────
        // Uses REQUEST_TIME_FLOAT so the guard is per-request even if the PHP
        // process persists between requests (PHP-FPM, OPcache, etc.).
        static $checkedRequestTime = null;
        $currentRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        if ($checkedRequestTime === $currentRequestTime) {
            return;
        }
        $checkedRequestTime = $currentRequestTime;

        // ── Identify endpoint ────────────────────────────────────────────────
        $isPluginfile     = helper::is_pluginfile_request();
        $isDraftfile      = helper::is_draftfile_request();
        $isDraftfilesAjax = helper::is_draftfiles_ajax_request();
        $isService        = helper::is_ajax_service_request();

        if (!$isPluginfile && !$isDraftfile && !$isDraftfilesAjax && !$isService) {
            return;
        }

        // ── Flow: lib/ajax/service.php — SCORM module deletion ───────────────
        // The new course editor (Moodle 4.x) deletes modules via AJAX to this
        // endpoint using core_courseformat_update_course (action=cm_delete) or
        // core_course_edit_module (action=delete). Both use $async=true, so
        // pre_course_module_delete does NOT fire in the web context.
        if ($isService) {
            self::handle_ajax_service_delete($USER);
            return;
        }

        // ── Flow: draftfiles_ajax.php ────────────────────────────────────────
        if ($isDraftfilesAjax) {
            $action = $_REQUEST['action'] ?? '';
            if ($action === 'unzip') {
                self::handle_draftfiles_ajax_unzip($USER);
                return;
            }
            if ($action === 'delete') {
                self::handle_draftfiles_ajax_delete_file($USER);
                return;
            }
            if ($action === 'deleteselected') {
                self::handle_draftfiles_ajax_delete_selected($USER);
                return;
            }
            self::handle_draftfiles_ajax_download($USER);
            return;
        }

        // ── Flow: pluginfile.php / draftfile.php ─────────────────────────────
        $info = helper::parse_pluginfile_path();

        if (!$info) {
            return;
        }

        $cmid    = null;
        $context = null;

        if ($isPluginfile) {
            // ── pluginfile.php ───────────────────────────────────────────────
            if ($info['component'] !== 'mod_scorm' || $info['filearea'] !== 'package') {
                return;
            }

            $ctx = \context::instance_by_id($info['contextid'], IGNORE_MISSING);

            if (!$ctx || $ctx->contextlevel !== CONTEXT_MODULE) {
                return;
            }
            $cmid = (int)$ctx->instanceid;

            if (!helper::is_protected($cmid)) {
                return;
            }
            $context = $ctx;

        } else {
            // ── draftfile.php ────────────────────────────────────────────────
            if ($info['component'] !== 'user' || $info['filearea'] !== 'draft') {
                return;
            }

            $ext = strtolower(pathinfo($info['filename'], PATHINFO_EXTENSION));

            if ($ext !== 'zip') {
                return;
            }

            $cmid = helper::find_protected_scorm_by_draft($info['itemid'], $info['filename']);

            if (!$cmid) {
                return;
            }

            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if (!$context) {
                return;
            }
        }

        // ── Bypass: site admins can always download ──────────────────────────
        if (is_siteadmin($USER->id)) {
            return;
        }

        // ── Evaluate capability ──────────────────────────────────────────────
        $hasCap = has_capability('local/scorm_incca:download', $context, $USER);

        if ($hasCap) {
            helper::log(helper::LOG_DOWNLOAD_ALLOWED, (int)$USER->id, $cmid,
                "Download allowed | userid={$USER->id} cmid={$cmid}");
            return;
        }

        // ── BLOCK ────────────────────────────────────────────────────────────
        helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, $cmid,
            "Download BLOCKED | userid={$USER->id} cmid={$cmid}");

        // die() is irrecoverable — cannot be caught by Moodle's try/catch.
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<title>403 - Access Denied</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;text-align:center}';
        echo 'h1{color:#c0392b}p{color:#555}a{color:#2980b9}</style></head><body>';
        echo '<h1>&#x1F512; Access Denied</h1>';
        echo '<p>' . get_string('downloaddenied', 'local_scorm_incca') . '</p>';
        echo '<p><a href="javascript:history.back()">&#8592; Back</a></p>';
        echo '</body></html>';
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ZIP: draftfiles_ajax.php — "Download all"
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts bulk ZIP generation and filters out protected SCORMs.
     */
    private static function handle_draftfiles_ajax_download($USER): void {
        $action = $_REQUEST['action'] ?? '';

        if ($action !== 'downloadselected') {
            return;
        }

        $draftid = (int)($_POST['itemid'] ?? $_GET['itemid'] ?? 0);
        if (!$draftid) {
            return;
        }

        $selectedJson = $_POST['selected'] ?? '[]';
        $selected = json_decode($selectedJson, true);
        if (!is_array($selected)) {
            $selected = [];
        }

        if (empty($selected)) {
            $filepath = $_POST['filepath'] ?? '/';
            $selected = self::enumerate_draft_files($USER->id, $draftid, $filepath);
        }

        // Site admins: full bypass.
        if (is_siteadmin($USER->id)) {
            return;
        }

        $allowed      = [];
        $blockedcmids = [];

        foreach ($selected as $fileinfo) {
            $filename = $fileinfo['filename'] ?? '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($ext === 'zip' && $filename !== '') {
                $cmid = helper::find_protected_scorm_by_draft($draftid, $filename);
                if ($cmid) {
                    $modctx = \context_module::instance($cmid, IGNORE_MISSING);
                    if ($modctx && !has_capability('local/scorm_incca:download', $modctx, $USER)) {
                        $blockedcmids[] = $cmid;
                        helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, $cmid,
                            "Bulk download blocked | userid={$USER->id} cmid={$cmid} file={$filename}");
                        continue;
                    }
                }
            }
            $allowed[] = $fileinfo;
        }

        if (empty($allowed) && !empty($blockedcmids)) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => get_string('downloaddenied', 'local_scorm_incca')]);
            die();
        }

        if (!empty($blockedcmids)) {
            $_POST['selected']    = json_encode($allowed);
            $_REQUEST['selected'] = json_encode($allowed);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SERVICE: lib/ajax/service.php — deletion of protected SCORM modules
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts module deletion requests via lib/ajax/service.php.
     *
     * Detects two web service methods that delete modules in Moodle 4.x:
     *  1. core_courseformat_update_course  (action=cm_delete)  — new course editor
     *  2. core_course_edit_module          (action=delete)     — legacy
     *
     * In PHP 8+ (required by Moodle 4.5), php://input is re-readable: reading it
     * here in after_config does NOT prevent service.php from reading it afterwards.
     */
    private static function handle_ajax_service_delete($USER): void {
        // Only applies to POST requests (reads use GET in service.php).
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $rawbody = @file_get_contents('php://input');
        if (empty($rawbody)) {
            return;
        }

        $requests = @json_decode($rawbody, true);
        if (!is_array($requests)) {
            return;
        }

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $methodname = $request['methodname'] ?? '';
            $args       = $request['args'] ?? [];
            $index      = (int)($request['index'] ?? 0);

            $blockedcmid = null;

            // ── core_courseformat_update_course — new Moodle 4.x editor ──────
            if ($methodname === 'core_courseformat_update_course'
                && ($args['action'] ?? '') === 'cm_delete') {
                $ids = (array)($args['ids'] ?? []);
                foreach ($ids as $id) {
                    if (self::is_delete_blocked((int)$id, $USER)) {
                        $blockedcmid = (int)$id;
                        break;
                    }
                }
            }

            // ── core_course_edit_module — legacy path ─────────────────────────
            if ($methodname === 'core_course_edit_module'
                && ($args['action'] ?? '') === 'delete') {
                $id = (int)($args['id'] ?? 0);
                if (self::is_delete_blocked($id, $USER)) {
                    $blockedcmid = $id;
                }
            }

            if ($blockedcmid !== null) {
                helper::log(
                    helper::LOG_DELETE_BLOCKED,
                    (int)$USER->id,
                    $blockedcmid,
                    "Deletion BLOCKED via AJAX service | userid={$USER->id} cmid={$blockedcmid}"
                );

                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }

                // Error format expected by the lib/ajax/service.php JS handler.
                echo json_encode([
                    $index => [
                        'error'     => true,
                        'exception' => [
                            'errorcode' => 'deletedenied',
                            'module'    => 'local_scorm_incca',
                            'message'   => get_string('deletedenied', 'local_scorm_incca'),
                        ],
                    ],
                ]);
                die();
            }
        }
    }

    /**
     * Checks whether the current user does NOT have permission to delete a cmid.
     * Returns true if the action should be BLOCKED, false if allowed.
     */
    private static function is_delete_blocked(int $cmid, $USER): bool {
        if (!$cmid) {
            return false;
        }
        // Only applies to protected SCORMs in our table.
        if (!helper::is_protected($cmid)) {
            return false;
        }
        // Site admins can always delete.
        if (is_siteadmin($USER->id)) {
            return false;
        }
        // With cargar or descargar capability → allowed.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            if (has_capability('local/scorm_incca:upload',    $context, $USER->id, false) ||
                has_capability('local/scorm_incca:download', $context, $USER->id, false)) {
                return false;
            }
        }
        return true; // Block.
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UNZIP: draftfiles_ajax.php — "Extract"
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts the unzip action in the file manager.
     * POST /repository/draftfiles_ajax.php?action=unzip
     *
     * If the ZIP is a protected SCORM and the user lacks descargar → rejects with JSON.
     * The JS client (filemanager.js ~line 1040) shows the error message to the user.
     */
    private static function handle_draftfiles_ajax_unzip($USER): void {
        $itemid   = (int)($_POST['itemid'] ?? 0);
        $filename = trim($_POST['filename'] ?? '');

        if (!$itemid || $filename === '') {
            return; // Insufficient data: fail-open.
        }

        // Check whether the draft ZIP corresponds to a protected SCORM.
        $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
        if (!$cmid) {
            return; // Not a protected SCORM: allow.
        }

        // Site admins can always unzip.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // Check local/scorm_incca:download capability.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context && has_capability('local/scorm_incca:download', $context, $USER)) {
            helper::log(helper::LOG_DOWNLOAD_ALLOWED, (int)$USER->id, $cmid,
                "Unzip allowed | userid={$USER->id} cmid={$cmid} file={$filename}");
            return;
        }

        // BLOCK: respond with JSON error (JS client expects JSON from draftfiles_ajax.php).
        helper::log(helper::LOG_UNZIP_BLOCKED, (int)$USER->id, $cmid,
            "Unzip BLOCKED | userid={$USER->id} cmid={$cmid} file={$filename}");

        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => get_string('unzipdenied', 'local_scorm_incca')]);
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DELETE FILE: draftfiles_ajax.php — "Delete file" in Edit Settings
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts ZIP file deletion inside the file manager in SCORM "Edit settings".
     *
     * POST /repository/draftfiles_ajax.php?action=delete
     * Relevant parameters: itemid (draft area ID), filename (ZIP name).
     *
     * If the ZIP corresponds to a protected SCORM and the user lacks both
     * cargar and descargar → responds with JSON error and terminates.
     */
    private static function handle_draftfiles_ajax_delete_file($USER): void {
        $itemid   = (int)($_POST['itemid'] ?? 0);
        $filename = trim($_POST['filename'] ?? '');

        if (!$itemid || $filename === '') {
            return; // Insufficient data: fail-open.
        }

        // Only applies to ZIP files (SCORM packages).
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return;
        }

        // Check whether the draft ZIP corresponds to a protected SCORM.
        $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
        if (!$cmid) {
            return; // Not a protected SCORM: allow.
        }

        // Site admins can always delete.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // With cargar OR descargar capability → allowed.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            if (has_capability('local/scorm_incca:upload',    $context, $USER->id, false) ||
                has_capability('local/scorm_incca:download', $context, $USER->id, false)) {
                return;
            }
        }

        // BLOCK: log and respond with JSON error.
        helper::log(
            helper::LOG_DELETE_BLOCKED,
            (int)$USER->id,
            $cmid,
            "File deletion blocked (Edit Settings) | userid={$USER->id} cmid={$cmid} file={$filename}"
        );

        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => get_string('deletedenied', 'local_scorm_incca')]);
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DELETE SELECTED: draftfiles_ajax.php — toolbar "Delete" in list mode
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts bulk deletion from the file manager toolbar (list mode).
     * POST /repository/draftfiles_ajax.php?action=deleteselected
     *
     * Parameters: itemid (draft area ID), selected (JSON: [{filepath, filename}, ...])
     *
     * If any selected file is a protected SCORM without permission,
     * blocks the entire operation by responding with a JSON error.
     */
    private static function handle_draftfiles_ajax_delete_selected($USER): void {
        $itemid      = (int)($_POST['itemid'] ?? 0);
        $selectedraw = trim($_POST['selected'] ?? '');

        if (!$itemid || $selectedraw === '') {
            return; // Insufficient data: fail-open.
        }

        $selectedfiles = @json_decode($selectedraw);
        if (!is_array($selectedfiles) || empty($selectedfiles)) {
            return;
        }

        // Site admins: full bypass.
        if (is_siteadmin($USER->id)) {
            return;
        }

        foreach ($selectedfiles as $fileinfo) {
            $filename = trim($fileinfo->filename ?? '');
            if ($filename === '' || $filename === '.') {
                continue; // Directories: skip.
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                continue; // Only applies to ZIPs (SCORM packages).
            }

            $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
            if (!$cmid) {
                continue; // Not a protected SCORM: allow.
            }

            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if ($context &&
                (has_capability('local/scorm_incca:upload',    $context, $USER->id, false) ||
                 has_capability('local/scorm_incca:download', $context, $USER->id, false))) {
                continue; // Has permission: allow.
            }

            // BLOCK on first protected file without permission.
            helper::log(
                helper::LOG_DELETE_BLOCKED,
                (int)$USER->id,
                $cmid,
                "Bulk deletion BLOCKED (deleteselected) | userid={$USER->id} cmid={$cmid} file={$filename}"
            );

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => get_string('deletedenied', 'local_scorm_incca')]);
            die();
        }
    }

    /**
     * Enumerates all files in a draft file area.
     */
    private static function enumerate_draft_files(int $userid, int $draftid, string $filepath): array {
        $userctx     = \context_user::instance($userid);
        $fs          = get_file_storage();
        $storedfiles = $fs->get_area_files($userctx->id, 'user', 'draft', $draftid, 'filename', false);

        $result = [];
        foreach ($storedfiles as $file) {
            if ($file->is_directory()) {
                continue;
            }
            if ($filepath === '/' || strpos($file->get_filepath(), $filepath) === 0) {
                $result[] = [
                    'filepath' => $file->get_filepath(),
                    'filename' => $file->get_filename(),
                ];
            }
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BACKUP: backup/backup.php — block download of protected SCORMs via .mbz
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts backup/backup.php for both course and activity backup.
     *
     * Flow:
     *  - If cm is in GET and is a protected SCORM → check backup permission.
     *  - If no cm → check whether the course has protected SCORMs → check permission.
     *  - Users with backup|cargar|descargar on the course → allow.
     *  - Siteadmin → always allow.
     */
    public static function handle_backup(\stdClass $USER): void {
        // Site admins always allowed.
        if (is_siteadmin($USER->id)) {
            debugger::logDiag('BACKUP_ALLOW', [
                'reason'   => 'siteadmin',
                'userid'   => $USER->id,
                'courseid' => (int)($_GET['id'] ?? 0),
                'cmid'     => (int)($_GET['cm'] ?? 0),
            ]);
            return;
        }

        $courseid = (int)($_GET['id'] ?? 0);
        $cmid     = (int)($_GET['cm'] ?? 0);

        if ($courseid <= 0) {
            debugger::logDiag('BACKUP_SKIP', ['reason' => 'no courseid in GET']);
            return;
        }

        if ($cmid > 0) {
            // ── Single activity backup ────────────────────────────────────────
            $isProtected = helper::is_protected($cmid);

            debugger::logDiag('BACKUP_ACTIVITY_CHECK', [
                'userid'       => $USER->id,
                'courseid'     => $courseid,
                'cmid'         => $cmid,
                'is_protected' => $isProtected,
            ]);

            if (!$isProtected) {
                return; // Not a protected SCORM — no restriction.
            }

            $ctx = \context_module::instance($cmid, IGNORE_MISSING);
            if (!$ctx) {
                return;
            }
            $coursecontext = $ctx->get_parent_context();
            $canBackup = self::user_can_backup_protected($USER, $coursecontext);

            debugger::logDiag('BACKUP_ACTIVITY_RESULT', [
                'userid'     => $USER->id,
                'cmid'       => $cmid,
                'can_backup' => $canBackup,
            ]);

            if (!$canBackup) {
                helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, $cmid,
                    "Activity backup BLOCKED | cmid={$cmid} userid={$USER->id}");
                self::deny_backup_response(true);
            }
            return;
        }

        // ── Full course backup ────────────────────────────────────────────────
        $protectedCmids = helper::get_protected_cmids_in_course($courseid);

        debugger::logDiag('BACKUP_COURSE_CHECK', [
            'userid'          => $USER->id,
            'courseid'        => $courseid,
            'protected_count' => count($protectedCmids),
            'protected_cmids' => $protectedCmids,
        ]);

        if (empty($protectedCmids)) {
            return; // No protected SCORMs — normal backup without restriction.
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return;
        }

        $canBackup = self::user_can_backup_protected($USER, $coursecontext);

        debugger::logDiag('BACKUP_COURSE_RESULT', [
            'userid'     => $USER->id,
            'courseid'   => $courseid,
            'can_backup' => $canBackup,
        ]);

        if (!$canBackup) {
            helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, 0,
                "Course backup BLOCKED | courseid={$courseid} userid={$USER->id} protected=" . count($protectedCmids));
            self::deny_backup_response(false);
        }
    }

    /**
     * Checks whether the user can back up a course containing protected SCORMs.
     * Accepts any of the three plugin capabilities at the course level.
     */
    private static function user_can_backup_protected(\stdClass $USER, \context $ctx): bool {
        return has_capability('local/scorm_incca:backup',    $ctx, $USER)
            || has_capability('local/scorm_incca:upload',    $ctx, $USER)
            || has_capability('local/scorm_incca:download', $ctx, $USER);
    }

    /**
     * Renders a 403 page and stops execution (same pattern as check_access).
     *
     * @param bool $isActivity true = activity backup, false = course backup.
     */
    private static function deny_backup_response(bool $isActivity): void {
        $msg = $isActivity
            ? get_string('backup_denied_activity', 'local_scorm_incca')
            : get_string('backup_denied_course',   'local_scorm_incca');
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
        echo '<title>403 - Backup Not Allowed</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;text-align:center}';
        echo 'h1{color:#c0392b}p{color:#555}a{color:#2980b9}code{background:#f4f4f4;padding:2px 4px}</style></head><body>';
        echo '<h1>&#x1F512; Backup Not Allowed</h1>';
        echo "<p>{$msg}</p>";
        echo '<p><small>Contact the administrator to assign the <code>local/scorm_incca:backup</code> capability in this course.</small></p>';
        echo '<p><a href="javascript:history.back()">&#8592; Back</a></p>';
        echo '</body></html>';
        die();
    }
}
