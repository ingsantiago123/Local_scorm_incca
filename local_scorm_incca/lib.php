<?php
defined('MOODLE_INTERNAL') || die();

/**
 * lib.php — Legacy callbacks for local_scorm_incca.
 *
 * after_config has been migrated to the hook system (db/hooks.php).
 * This file retains two callbacks that have no hook equivalent:
 *
 *  - after_require_login: fallback fired inside require_login(). Acts when
 *    the hook fires before the user is authenticated (e.g. cached sessions).
 *    check_access() has a per-request static guard to prevent double execution.
 *
 *  - pre_course_module_delete: blocks deletion of protected SCORMs from
 *    course/lib.php (synchronous delete path only). AJAX deletions are
 *    intercepted earlier by hook_callbacks::check_access() via service.php.
 */


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACK: after_require_login — fallback via require_login()
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Legacy callback: fired after require_login() authenticates the user.
 *
 * @param int|stdClass $courseorid
 * @param bool $autologinguest
 * @param stdClass|null $cm
 * @param bool $setwantsurltome
 * @param bool $preventredirect
 */
function local_scorm_incca_after_require_login(
    $courseorid,
    $autologinguest,
    $cm,
    $setwantsurltome,
    $preventredirect
): void {
    // Load dependencies explicitly (avoids any autoloading issues).
    $plugindir = __DIR__;
    @include_once($plugindir . '/classes/debugger.php');
    @include_once($plugindir . '/classes/helper.php');
    @include_once($plugindir . '/classes/hook_callbacks.php');

    // check_access() has a static guard. If after_config hook already acted,
    // it returns immediately without double execution.
    try {
        if (class_exists('\\local_scorm_incca\\hook_callbacks', false)) {
            \local_scorm_incca\hook_callbacks::check_access();
        }
    } catch (\Throwable $e) {
        // Silence: do not propagate exceptions to avoid interrupting the request.
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACK: pre_course_module_delete — protect deletion of protected SCORMs
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Legacy hook callback: pre_course_module_delete.
 * Prevents users without permission from deleting a protected SCORM.
 *
 * Detected by Moodle via get_plugins_with_function('pre_course_module_delete').
 * Called from course_delete_module() in course/lib.php BEFORE deleting data.
 * Throwing moodle_exception here cancels the deletion entirely.
 *
 * @param stdClass $cm  course_modules record: id, course, module (FK) — does NOT have modname.
 */
function local_scorm_incca_pre_course_module_delete(stdClass $cm): void {
    global $USER;

    // CLI/cron context → do not block (async or automated deletion).
    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        return;
    }

    // Requires an authenticated user to evaluate permissions.
    if (empty($USER->id)) {
        return;
    }

    try {
        $cmid = (int)$cm->id;

        // NOTE: $cm here comes from $DB->get_record('course_modules', ...)
        // which does NOT include the 'modname' property. Therefore $cm->modname
        // cannot be used. Instead, helper::is_protected() only returns true for
        // SCORMs registered in our table — no need to filter by module type.

        // No restriction if the SCORM is not marked as protected.
        if (!\local_scorm_incca\helper::is_protected($cmid)) {
            return;
        }

        // Site admins can always delete.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // Check capability in the module context.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            $canUpload   = has_capability('local/scorm_incca:upload',   $context, $USER->id, false);
            $canDownload = has_capability('local/scorm_incca:download', $context, $USER->id, false);
            if ($canUpload || $canDownload) {
                return; // Has permission: allow deletion.
            }
        }

        // BLOCK: log the attempt and throw exception.
        \local_scorm_incca\helper::log(
            \local_scorm_incca\helper::LOG_DELETE_BLOCKED,
            (int)$USER->id,
            $cmid,
            "Deletion BLOCKED | userid={$USER->id} cmid={$cmid}"
        );

        // moodle_exception generates the standard Moodle error page.
        // Third parameter = "Continue" button URL → returns to the course.
        throw new \moodle_exception(
            'deletedenied',
            'local_scorm_incca',
            new \moodle_url('/course/view.php', ['id' => (int)$cm->course])
        );

    } catch (\moodle_exception $e) {
        throw $e; // Re-throw to cancel the deletion.
    } catch (\Throwable $e) {
        // Other errors from the check: fail-open (don't block if the helper fails).
    }
}
