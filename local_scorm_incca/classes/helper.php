<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Centralised helper with the plugin's business logic.
 */
class helper {

    /** Log event type constants */
    public const LOG_UPLOAD_PROTECTED  = 'upload_protected';
    public const LOG_UPLOAD_PUBLIC     = 'upload_public';
    public const LOG_DOWNLOAD_ALLOWED  = 'download_allowed';
    public const LOG_DOWNLOAD_BLOCKED  = 'download_blocked';
    public const LOG_DELETED           = 'deleted';
    public const LOG_ERROR             = 'error';
    public const LOG_DELETE_BLOCKED    = 'delete_blocked';
    public const LOG_UNZIP_BLOCKED     = 'unzip_blocked';
    public const LOG_IMPORT_REGISTERED = 'import_registered';

    /**
     * Registers a SCORM in the plugin table with its protection status.
     *
     * @param int  $cmid
     * @param int  $scormid
     * @param int  $courseid
     * @param int  $creatorid
     * @param bool $isprotected
     */
    public static function register_scorm(int $cmid, int $scormid, int $courseid, int $creatorid, bool $isprotected): void {
        global $DB;

        $now = time();
        $existing = $DB->get_record('local_scorm_incca_items', ['cmid' => $cmid]);

        if ($existing) {
            $existing->scormid      = $scormid;
            $existing->courseid     = $courseid;
            $existing->isprotected  = $isprotected ? 1 : 0;
            $existing->timemodified = $now;
            $DB->update_record('local_scorm_incca_items', $existing);
            return;
        }

        $DB->insert_record('local_scorm_incca_items', (object)[
            'cmid'         => $cmid,
            'scormid'      => $scormid,
            'courseid'     => $courseid,
            'creatorid'    => $creatorid,
            'isprotected'  => $isprotected ? 1 : 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Registers all SCORMs in the destination course that are not yet in the table,
     * inheriting the isprotected status from the source course via the package sha1hash.
     *
     * Called from observer::course_restored() after an import/restore.
     * Source→destination matching: mdl_scorm.sha1hash (same package content).
     *
     * Policy when no match is found (AICC, external, null sha1hash, .mbz restore
     * without originalcourseid): isprotected = false (admin can adjust from the panel).
     *
     * @param int $destcourseid   ID of the destination course (just restored).
     * @param int $importerid     ID of the user who ran the import.
     * @param int $sourcecourse   ID of the source course (0 if not available).
     */
    public static function register_imported_scorms(
        int $destcourseid,
        int $importerid,
        int $sourcecourse
    ): void {
        global $DB;

        // SCORMs in the destination course not yet registered in the plugin.
        // We select s.name: it is the exact instance identifier that Moodle preserves
        // during restore. Unlike sha1hash (package content hash), the name uniquely
        // identifies each SCORM activity even if they share the same ZIP file.
        $sql = "SELECT cm.id AS cmid, cm.instance AS scormid, s.name AS scorm_name
                  FROM {course_modules} cm
                  JOIN {modules} m  ON m.id = cm.module AND m.name = 'scorm'
                  JOIN {scorm} s    ON s.id = cm.instance
                 WHERE cm.course = :course
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_scorm_incca_items} si WHERE si.cmid = cm.id
                   )";

        $unregistered = $DB->get_records_sql($sql, ['course' => $destcourseid]);

        if (empty($unregistered)) {
            return;
        }

        foreach ($unregistered as $row) {
            $isprotected = false;

            if ($sourcecourse > 0) {
                // Look up the source SCORM by activity name.
                //
                // Why name and not sha1hash?
                // sha1hash identifies the CONTENT of the ZIP package, not the SCORM instance.
                // If the same ZIP is uploaded multiple times (one public, one protected), they
                // all share the same hash → MAX() by hash would give false positives for the
                // public ones. The activity name identifies the specific instance (Moodle
                // preserves it exactly during restore) and distinguishes between two SCORMs
                // of the same package that have different protection states.
                //
                // If there are duplicate names in the source (unlikely but possible), MAX()
                // returns the most restrictive state among them — same conservative behaviour
                // as the previous bug but limited to actual name duplicates, not content duplicates.
                $maxProtected = $DB->get_field_sql(
                    "SELECT MAX(si.isprotected)
                       FROM {scorm} s_dest
                       JOIN {scorm} s_src         ON s_src.name = s_dest.name
                       JOIN {course_modules} cm   ON cm.instance = s_src.id
                       JOIN {modules} m           ON m.id = cm.module AND m.name = 'scorm'
                       JOIN {local_scorm_incca_items} si ON si.cmid = cm.id
                      WHERE s_dest.id  = :destscormid
                        AND cm.course  = :sourcecourse",
                    ['destscormid' => (int) $row->scormid, 'sourcecourse' => $sourcecourse]
                );

                // null → no SCORM with that name in the source has a record → public.
                $isprotected = (bool) $maxProtected;
            }

            self::register_scorm(
                (int) $row->cmid,
                (int) $row->scormid,
                $destcourseid,
                $importerid,
                $isprotected
            );

            self::log(
                self::LOG_IMPORT_REGISTERED,
                $importerid,
                (int) $row->cmid,
                'Imported SCORM registered. isprotected=' . ($isprotected ? '1' : '0')
                    . ' sourcecourse=' . $sourcecourse
            );
        }
    }

    /**
     * Returns the cmids of protected SCORMs in a course.
     * Used by hook_callbacks::handle_backup() to detect whether the course
     * has protected content before allowing backup creation.
     */
    public static function get_protected_cmids_in_course(int $courseid): array {
        global $DB;
        $records = $DB->get_records('local_scorm_incca_items', [
            'courseid'    => $courseid,
            'isprotected' => 1,
        ], '', 'cmid');
        return array_map(fn($r) => (int)$r->cmid, $records);
    }

    /**
     * Removes the registration of a SCORM (when the activity is deleted).
     */
    public static function unregister_scorm(int $cmid): void {
        global $DB;
        $DB->delete_records('local_scorm_incca_items', ['cmid' => $cmid]);
    }

    /**
     * Returns whether a course module corresponds to a protected SCORM.
     */
    public static function is_protected(int $cmid): bool {
        global $DB;
        return (bool)$DB->record_exists('local_scorm_incca_items', [
            'cmid'        => $cmid,
            'isprotected' => 1,
        ]);
    }

    /**
     * Inserts an entry in the plugin log.
     */
    public static function log(string $eventtype, int $userid, ?int $cmid, string $message): void {
        global $DB;

        $DB->insert_record('local_scorm_incca_logs', (object)[
            'eventtype'   => $eventtype,
            'userid'      => $userid,
            'cmid'        => $cmid,
            'message'     => $message,
            'ipaddress'   => getremoteaddr(),
            'timecreated' => time(),
        ]);
    }

    /**
     * Parses the URL of pluginfile.php / draftfile.php and returns its components.
     *
     * Format: /{contextid}/{component}/{filearea}/{itemid}/{filename}
     *
     * Supports three path delivery modes:
     *  1. PATH_INFO  (slasharguments = 1, normal mode)
     *  2. REQUEST_URI minus SCRIPT_NAME  (some servers/proxies)
     *  3. GET parameter 'file'  (slasharguments = 0)
     *
     * @return array|null ['contextid','component','filearea','itemid','filename'] or null
     */
    public static function parse_pluginfile_path(): ?array {

        // Attempt 1: standard PATH_INFO.
        $pathinfo = $_SERVER['PATH_INFO'] ?? '';

        // Attempt 2: derive from REQUEST_URI by removing the script name.
        if (empty($pathinfo)) {
            $scriptname = $_SERVER['SCRIPT_NAME'] ?? '';
            $requesturi = strtok($_SERVER['REQUEST_URI'] ?? '', '?'); // strip query string
            if ($scriptname && strpos($requesturi, $scriptname) === 0) {
                $pathinfo = substr($requesturi, strlen($scriptname));
            }
        }

        // Attempt 3: GET parameter 'file' (Moodle with slasharguments = 0).
        if (empty($pathinfo)) {
            $pathinfo = $_GET['file'] ?? '';
        }

        if (empty($pathinfo)) {
            return null;
        }

        // Strip leading slash and split.
        $parts = explode('/', ltrim($pathinfo, '/'));
        if (count($parts) < 4) {
            return null;
        }

        return [
            'contextid' => (int)$parts[0],
            'component' => $parts[1],
            'filearea'  => $parts[2],
            'itemid'    => isset($parts[3]) ? (int)$parts[3] : 0,
            'filename'  => isset($parts[4]) ? $parts[4] : '',
        ];
    }

    /**
     * Returns whether the current request is to pluginfile.php.
     */
    public static function is_pluginfile_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/pluginfile.php') !== false);
    }

    /**
     * Returns whether the current request is to draftfile.php.
     *
     * draftfile.php serves files from the user's draft area (file manager in edit
     * mode). It also calls require_login(), so our hook fires, but this different
     * endpoint was not previously covered by the protection.
     */
    public static function is_draftfile_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/draftfile.php') !== false);
    }

    /**
     * Returns whether the current request is to the web service AJAX endpoint (lib/ajax/service.php).
     *
     * This endpoint processes actions from the Moodle 4.x course editor, including
     * module deletions via core_courseformat_update_course (action=cm_delete) and
     * core_course_edit_module (action=delete). Both call course_delete_module($id, true)
     * which uses async deletion and therefore does NOT fire pre_course_module_delete in
     * the web context. The interception must happen here, before the service runs.
     */
    public static function is_ajax_service_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/lib/ajax/service.php') !== false);
    }

    /**
     * Returns whether the current request is to the draft files AJAX endpoint.
     *
     * draftfiles_ajax.php is called by the file manager when the user clicks
     * "Download all as ZIP". It calls require_login(), so our callback fires.
     * We intercept HERE to filter protected files BEFORE the ZIP is generated,
     * because the resulting ZIP has a new contenthash that does not match any
     * registered SCORM.
     *
     * Flow:
     *   POST /repository/draftfiles_ajax.php?action=downloadselected
     *   -> require_login() -> our callback -> filter $_POST['selected']
     *   -> generates ZIP with permitted files only
     *   -> returns URL to GET /draftfile.php/.../files.zip
     *   -> our callback in draftfile.php no longer needs to act on the ZIP
     */
    public static function is_draftfiles_ajax_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/draftfiles_ajax.php') !== false);
    }

    /**
     * Given a draft itemid and filename, determines whether that draft corresponds
     * to a protected SCORM.
     *
     * STRATEGY (in order of priority):
     *
     * 1. 'source' field of the draft (most precise):
     *    Moodle stores in mdl_files.source a PHP-serialised object with:
     *      - source->original = pack_reference (base64+serialised object with contextid, etc.)
     *      - source->source   = URL of the original file (may be empty)
     *    Extracting the contextid from 'original' identifies exactly which SCORM
     *    was being edited → exact decision with no false positives.
     *
     * 2. CONTENTHASH fallback (safe posture):
     *    If 'source' does not identify the exact SCORM, block if AT LEAST ONE SCORM
     *    with that content hash is protected.
     *    Reason: we cannot determine which SCORM the user was editing, and the
     *    secure posture is to deny if the content belongs to something protected.
     *
     * @param int    $draftitemid Draft area ID
     * @param string $filename    File name (e.g. "course.zip")
     * @return int|null cmid of the protected SCORM, or null if download is allowed
     */
    public static function find_protected_scorm_by_draft(int $draftitemid, string $filename): ?int {
        global $DB;

        // Retrieve the draft file record.
        $draftfile = $DB->get_record('files', [
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filename'  => $filename,
        ], '*', IGNORE_MULTIPLE);

        if (!$draftfile || empty($draftfile->contenthash)) {
            return null;
        }

        // ── Attempt 1: 'source' field of the draft ───────────────────────────
        //
        // Moodle (lib/filelib.php file_prepare_draft_area) stores in source a
        // PHP-serialised object: serialize((object)['source'=>..., 'original'=>...])
        // The 'original' field contains a pack_reference:
        //   base64_encode(serialize((object){contextid, component, filearea, itemid, filename, filepath}))
        //
        // Example:
        //   original = "55/mod_scorm/package/0//packagev2.zip" (legacy flat path)
        //   or pack_reference blob → contextid=55 → context_module → cmid=27
        //
        // We also try JSON and direct URL for compatibility with older or custom installs.
        if (!empty($draftfile->source)) {
            $contextid = self::extract_source_contextid($draftfile->source);

            if ($contextid) {
                $srcctx = \context::instance_by_id($contextid, IGNORE_MISSING);
                if ($srcctx && $srcctx->contextlevel === CONTEXT_MODULE) {
                    $directcmid  = (int)$srcctx->instanceid;
                    $isprotected = self::is_protected($directcmid);
                    return $isprotected ? $directcmid : null;
                }
            }
        }

        // ── Attempt 2: contenthash fallback ──────────────────────────────────
        //
        // Find all registered SCORMs with this content hash.
        // If any is protected → block (secure posture).
        // If all are public → allow.
        $sql = "SELECT s.cmid, s.isprotected
                  FROM {local_scorm_incca_items} s
                  JOIN {context} ctx ON ctx.instanceid = s.cmid
                                     AND ctx.contextlevel = :ctxlevel
                  JOIN {files} f    ON f.contextid     = ctx.id
                                     AND f.component   = 'mod_scorm'
                                     AND f.filearea    = 'package'
                                     AND f.contenthash = :contenthash
                                     AND f.filename   != '.'";

        $records = $DB->get_records_sql($sql, [
            'ctxlevel'    => CONTEXT_MODULE,
            'contenthash' => $draftfile->contenthash,
        ]);

        if (empty($records)) {
            return null; // Not a controlled package.
        }

        // Secure posture: if ANY SCORM with this content is protected, block.
        // We cannot determine which exact SCORM was being edited (source failed),
        // so we choose the safer option.
        foreach ($records as $record) {
            if ((int)$record->isprotected) {
                return (int)$record->cmid;
            }
        }

        // All public → allow.
        return null;
    }

    /**
     * Extracts the contextid from the 'source' field of a Moodle draft file.
     *
     * Moodle (lib/filelib.php, file_prepare_draft_area) stores source as:
     *
     *   serialize((object)[
     *       'source'   => <pluginfile URL or empty>,
     *       'original' => file_storage::pack_reference($original),
     *   ])
     *
     * where pack_reference = base64_encode(serialize((object){contextid, component,
     * filearea, itemid, filename, filepath}))
     *
     * So 'original' is NOT a plain path — it is a base64+serialised blob.
     * It must be unpacked with unserialize(base64_decode(...)).
     *
     * Supported formats (in order of prevalence):
     *  1. PHP serialised (standard Moodle 4.x): serialize((object){source, original})
     *  2. JSON: {"source":"...","original":"..."}
     *  3. Direct URL: http://.../pluginfile.php/CTX/mod_scorm/package/...
     *
     * @param  string   $source  Raw value of mdl_files.source
     * @return int|null contextid if successfully extracted, null otherwise
     */
    private static function extract_source_contextid(string $source): ?int {

        // ── Format 1: PHP serialised (standard Moodle 4.x) ───────────────────
        if (strpos($source, 'O:8:"stdClass"') !== false || strpos($source, 's:8:"original"') !== false) {
            $obj = @unserialize($source);
            if ($obj && isset($obj->original)) {
                // original = file_storage::pack_reference($orig)
                //          = base64_encode(serialize((object){contextid, component, filearea, ...}))
                $unpacked = @unserialize(@base64_decode($obj->original));

                if (is_array($unpacked)) {
                    $unpacked = (object) $unpacked;
                }

                if (is_object($unpacked)
                    && isset($unpacked->contextid)
                    && isset($unpacked->component)
                    && $unpacked->component === 'mod_scorm') {
                    return (int)$unpacked->contextid;
                }
                // Fallback within the same format: flat path (older/custom installs).
                if (preg_match('#^(\d+)/mod_scorm/package/#', $obj->original, $m)) {
                    return (int)$m[1];
                }
            }
            // Also try source->source as a direct pluginfile URL.
            if ($obj && !empty($obj->source)) {
                if (preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $obj->source, $m)) {
                    return (int)$m[1];
                }
            }
        }

        // ── Format 2: JSON ────────────────────────────────────────────────────
        $json = json_decode($source, true);
        if (is_array($json)) {
            $original = $json['original'] ?? '';
            if ($original) {
                // Try to unpack as a pack_reference.
                $unpacked = @unserialize(@base64_decode($original));

                if (is_array($unpacked)) {
                    $unpacked = (object) $unpacked;
                }

                if (is_object($unpacked)
                    && isset($unpacked->contextid)
                    && isset($unpacked->component)
                    && $unpacked->component === 'mod_scorm') {
                    return (int)$unpacked->contextid;
                }
                // Fallback: flat path.
                if (preg_match('#^(\d+)/mod_scorm/package/#', $original, $m)) {
                    return (int)$m[1];
                }
            }
            $srcurl = $json['source'] ?? '';
            if ($srcurl && preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $srcurl, $m)) {
                return (int)$m[1];
            }
        }

        // ── Format 3: direct URL ──────────────────────────────────────────────
        if (preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $source, $m)) {
            return (int)$m[1];
        }

        return null;
    }
}
