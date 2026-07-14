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
    public const LOG_COURSE_SYNCED     = 'course_synced';

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
     * Registers every SCORM in the destination course that is not yet in the table.
     * Two steps, in order:
     *
     *  1. Sync the SOURCE course (sync_course_scorms) so a source SCORM nobody has
     *     visited in the admin panel yet still has a row to match against below,
     *     instead of silently defaulting to public purely for lack of one.
     *  2. For every untracked destination SCORM, try to match it against the source
     *     course requiring BOTH the same activity name AND the same package content
     *     (files.contenthash) before inheriting isprotected. Anything that doesn't
     *     clear that bar — including content that has nothing to do with this import
     *     but happens to be untracked in the destination for an unrelated reason
     *     (e.g. the plugin's table was cleared by an uninstall/reinstall and nobody
     *     has synced that course since) — is registered as PUBLIC, same default
     *     sync_course_scorms() uses everywhere else.
     *
     * An earlier version of this function tried to tell "just imported by this
     * restore" apart from "unrelated untracked leftovers" using a cm.added recency
     * window, matching on name alone once inside that window. That assumed Moodle
     * stamps cm.added with the restore time. It does not: restore PRESERVES the
     * original activity's added time from the backup, so genuinely old content
     * (weeks old) can and does show up with an old cm.added after being restored
     * today, and unrelated content created minutes before an unrelated import can
     * fall inside any window that's actually tight enough to be useful. Requiring
     * content identity (not just a name string, which this plugin's own test data
     * shows gets reused across unrelated packages — "uno", "dos", generic names)
     * is the part that's actually reliable, so scope is now enforced there instead
     * of via timing.
     *
     * Called from observer::course_restored() after an import/restore.
     *
     * None of this retroactively discovers whether an untracked SCORM was "meant" to
     * be protected (that intent only exists once an admin sets it from the panel);
     * it only guarantees content inherits whatever is ACTUALLY on record, same as if
     * someone had clicked "Sync" on the relevant course a minute earlier.
     *
     * Policy when no confident source match is found (AICC, external, no
     * originalcourseid, name-only or content-only coincidence, or genuinely
     * unrelated content): isprotected = false (admin can adjust from the panel).
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

        // Backfill the source course first (bounded to that one course — never the
        // destination, which is handled below by the loop itself; syncing it here
        // too would register it as public before the match below runs and defeat
        // the inheritance entirely).
        if ($sourcecourse > 0) {
            self::sync_course_scorms($sourcecourse, $importerid);
        }

        // Every SCORM in the destination course not yet registered in the plugin —
        // whether genuinely brought in by this restore or unrelated untracked
        // leftovers (see docblock above for why cm.added can't tell those apart).
        $sql = "SELECT cm.id AS cmid, cm.course AS cm_course, cm.instance AS scormid,
                       s.name AS scorm_name
                  FROM {course_modules} cm
                  JOIN {modules} m  ON m.id = cm.module AND m.name = 'scorm'
                  JOIN {scorm} s    ON s.id = cm.instance
                 WHERE cm.course = :course
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_scorm_incca_items} si WHERE si.cmid = cm.id
                   )";

        $unregistered = $DB->get_records_sql($sql, ['course' => $destcourseid]);

        // TEMPORARY diagnostic — see observer::course_restored(). Remove alongside it.
        // cm_course is included so a query/scope bug (candidate cmid belonging to the
        // wrong course) would be immediately visible instead of assumed.
        debugger::logDiag('REGISTER_IMPORTED_SCORMS_CANDIDATES', [
            'destcourseid'       => $destcourseid,
            'sourcecourse'       => $sourcecourse,
            'unregistered_count' => count($unregistered),
            'unregistered'       => array_map(fn($r) => [
                'cmid' => (int)$r->cmid, 'cm_course' => (int)$r->cm_course,
                'scormid' => (int)$r->scormid, 'name' => $r->scorm_name,
            ], array_values($unregistered)),
        ]);

        foreach ($unregistered as $row) {
            $isprotected  = false;
            $maxProtected = null;
            $desthash     = null;

            if ($sourcecourse > 0) {
                // Content identity of THIS destination SCORM's package. Required
                // alongside the name match below — a name match alone is not
                // reliable evidence this is "the same activity" as something in
                // the source (see docblock: generic reused names in test/demo data).
                $desthash = $DB->get_field_sql(
                    "SELECT f.contenthash
                       FROM {files} f
                       JOIN {context} ctx ON ctx.instanceid = :cmid AND ctx.contextlevel = :ctxlevel
                      WHERE f.contextid = ctx.id
                        AND f.component = 'mod_scorm' AND f.filearea = 'package'
                        AND f.filename != '.'",
                    ['cmid' => (int) $row->cmid, 'ctxlevel' => CONTEXT_MODULE],
                    IGNORE_MULTIPLE
                );

                if ($desthash) {
                    // Look up a source SCORM with the SAME name AND the SAME package
                    // content hash. If there are duplicate name+hash pairs in the
                    // source (unlikely but possible), MAX() returns the most
                    // restrictive state among them — conservative by design.
                    $maxProtected = $DB->get_field_sql(
                        "SELECT MAX(si.isprotected)
                           FROM {scorm} s_dest
                           JOIN {scorm} s_src       ON s_src.name = s_dest.name
                           JOIN {course_modules} cm ON cm.instance = s_src.id
                           JOIN {modules} m         ON m.id = cm.module AND m.name = 'scorm'
                           JOIN {local_scorm_incca_items} si ON si.cmid = cm.id
                           JOIN {context} ctx        ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel
                           JOIN {files} f            ON f.contextid = ctx.id
                                                     AND f.component = 'mod_scorm' AND f.filearea = 'package'
                                                     AND f.filename != '.' AND f.contenthash = :desthash
                          WHERE s_dest.id = :destscormid
                            AND cm.course  = :sourcecourse",
                        [
                            'ctxlevel'    => CONTEXT_MODULE,
                            'desthash'    => $desthash,
                            'destscormid' => (int) $row->scormid,
                            'sourcecourse' => $sourcecourse,
                        ],
                        IGNORE_MULTIPLE
                    );

                    // null → no source SCORM shares both name and content → public.
                    $isprotected = (bool) $maxProtected;
                }
            }

            self::register_scorm(
                (int) $row->cmid,
                (int) $row->scormid,
                $destcourseid,
                $importerid,
                $isprotected
            );

            // TEMPORARY diagnostic — confirms not just what was DECIDED but what
            // actually landed in the table right after the write, per cmid.
            $written = $DB->get_record('local_scorm_incca_items', ['cmid' => (int)$row->cmid], 'cmid, courseid, isprotected');
            debugger::logDiag('IMPORT_MATCH', [
                'cmid'                 => (int) $row->cmid,
                'scorm_name'           => $row->scorm_name,
                'sourcecourse'         => $sourcecourse,
                'dest_contenthash'     => $desthash,
                'raw_maxprotected'     => $maxProtected,
                'computed_isprotected' => $isprotected,
                'written_row'          => $written ? [
                    'courseid' => (int)$written->courseid, 'isprotected' => (int)$written->isprotected,
                ] : null,
            ]);

            self::log(
                self::LOG_IMPORT_REGISTERED,
                $importerid,
                (int) $row->cmid,
                'Imported SCORM registered. isprotected=' . ($isprotected ? '1' : '0')
                    . ' sourcecourse=' . $sourcecourse
            );
        }

        // TEMPORARY diagnostic — full, authoritative dump of everything the plugin has
        // on record for the destination course right after this whole operation. This
        // is the one entry that answers "what actually ended up in the table" without
        // reconstructing it from the steps above.
        $finalstate = $DB->get_records('local_scorm_incca_items', ['courseid' => $destcourseid], 'cmid', 'cmid, isprotected, timemodified');
        debugger::logDiag('REGISTER_IMPORTED_SCORMS_FINAL_STATE', [
            'destcourseid' => $destcourseid,
            'rows'         => array_map(fn($r) => [
                'cmid' => (int)$r->cmid, 'isprotected' => (int)$r->isprotected,
            ], array_values($finalstate)),
        ]);
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
     * Registers, as PUBLIC, every SCORM currently in $courseid that is not yet
     * tracked in local_scorm_incca_items. Bounded to a single course (cm.course
     * is an indexed FK) and does NOT query {logstore_standard_log}.
     *
     * This replaces the old install-time platform-wide scan (see problema.md):
     * that scan ran one query per SCORM against the log table to guess the
     * original creator/protection state, which could hang for many minutes on
     * a site with a large log table. Discovery is now explicit, on demand, one
     * course at a time (triggered from courses.php), and newly discovered
     * SCORMs simply start public — the admin protects the right ones from the
     * panel afterwards, individually or in bulk via bulk_set_protection().
     *
     * @return int number of SCORMs newly registered.
     */
    public static function sync_course_scorms(int $courseid, int $actorid): int {
        global $DB;

        $sql = "SELECT cm.id AS cmid, s.id AS scormid, s.name AS scorm_name, cm.added AS cm_added
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
                  JOIN {scorm} s   ON s.id = cm.instance
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_scorm_incca_items} si WHERE si.cmid = cm.id
                   )";
        $unregistered = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        // TEMPORARY diagnostic — unconditional, so a "found nothing" run is just as
        // visible as one that finds something. Remove alongside the other diag calls
        // in observer::course_restored() / register_imported_scorms().
        debugger::logDiag('SYNC_COURSE_SCORMS', [
            'courseid' => $courseid,
            'actorid'  => $actorid,
            'found'    => array_map(fn($r) => [
                'cmid' => (int)$r->cmid, 'scormid' => (int)$r->scormid,
                'name' => $r->scorm_name, 'added' => (int)$r->cm_added,
            ], array_values($unregistered)),
        ]);

        if (empty($unregistered)) {
            return 0;
        }

        $now = time();
        foreach ($unregistered as $row) {
            $DB->insert_record('local_scorm_incca_items', (object)[
                'cmid'         => (int)$row->cmid,
                'scormid'      => (int)$row->scormid,
                'courseid'     => $courseid,
                'creatorid'    => 0,
                'isprotected'  => 0,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        $count = count($unregistered);

        self::log(
            self::LOG_COURSE_SYNCED,
            $actorid,
            null,
            "Course sync | courseid={$courseid} registered={$count} (all as PUBLIC, pending manual review) by userid={$actorid}"
        );

        return $count;
    }

    /**
     * Sets isprotected for a batch of cmids in a single UPDATE statement,
     * instead of one query per row, and writes ONE summary log entry
     * regardless of how many rows are affected (avoids flooding the log
     * table on large "select all" bulk actions from the admin panel).
     *
     * @return int number of rows actually affected.
     */
    public static function bulk_set_protection(array $cmids, bool $protected, int $actorid): int {
        global $DB;

        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        if (empty($cmids)) {
            return 0;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);

        $affected = $DB->count_records_select('local_scorm_incca_items', "cmid {$insql}", $inparams);
        if ($affected === 0) {
            return 0;
        }

        $updateparams = array_merge($inparams, [
            'newprotected' => $protected ? 1 : 0,
            'newmodified'  => time(),
        ]);
        $DB->execute(
            "UPDATE {local_scorm_incca_items}
                SET isprotected = :newprotected, timemodified = :newmodified
              WHERE cmid {$insql}",
            $updateparams
        );

        $sample = implode(',', array_slice($cmids, 0, 20)) . (count($cmids) > 20 ? ',...' : '');
        self::log(
            $protected ? self::LOG_UPLOAD_PROTECTED : self::LOG_UPLOAD_PUBLIC,
            $actorid,
            null,
            'Bulk status change to ' . ($protected ? 'PROTECTED' : 'PUBLIC')
                . " | count={$affected} cmids={$sample} by userid={$actorid}"
        );

        return $affected;
    }

    /**
     * Batch version of counting already-synced items per course, used by
     * courses.php to render sync status for a page of search results in one
     * query instead of one COUNT per course row.
     *
     * @param int[] $courseids
     * @return array<int,int> courseid => number of registered items
     */
    public static function get_synced_course_counts(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (empty($courseids)) {
            return [];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT courseid, COUNT(*) AS total
               FROM {local_scorm_incca_items}
              WHERE courseid {$insql}
              GROUP BY courseid",
            $inparams
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->courseid] = (int)$row->total;
        }
        return $result;
    }

    /**
     * Batch version of counting existing SCORM course_modules per course
     * (regardless of whether they are registered in the plugin yet), used by
     * courses.php to show how many packages exist to sync. One query for the
     * whole page of results instead of one per course row.
     *
     * @param int[] $courseids
     * @return array<int,int> courseid => number of SCORM activities found
     */
    public static function get_scorm_counts_for_courses(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (empty($courseids)) {
            return [];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT cm.course AS courseid, COUNT(*) AS total
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
              WHERE cm.course {$insql}
                AND cm.deletioninprogress = 0
              GROUP BY cm.course",
            $inparams
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->courseid] = (int)$row->total;
        }
        return $result;
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
