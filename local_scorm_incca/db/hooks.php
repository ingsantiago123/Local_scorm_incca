<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for local_scorm_incca.
 *
 * We register \core\hook\after_config here so Moodle does not show the
 * deprecation warning "Callback after_config should be migrated to new hook".
 *
 * The callback uses explicit require_once() for its dependencies instead of
 * relying on autoloading, so it works correctly even in draftfile.php where
 * NO_DEBUG_DISPLAY=true suppresses errors. Any exception thrown inside the
 * callback is caught by Moodle's hook dispatcher (try/catch around every
 * callback invocation) and silently discarded.
 */
$callbacks = [
    [
        'hook'     => \core\hook\after_config::class,
        'callback' => \local_scorm_incca\hook_callbacks::class . '::after_config',
        'priority' => 500,
    ],
];
