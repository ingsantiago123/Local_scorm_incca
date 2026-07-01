<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Debug logger for diagnosing the SCORM download flow.
 *
 * Writes to {moodledata}/local_scorm_incca_debug.log.
 * The file is NOT accessible via the web (it is outside the docroot).
 *
 * Usage: view the log on the admin page
 *        Administration > Plugins > SCORM INCCA > Debug Log
 *
 * NOTE: Disable in production by setting ENABLED = false.
 */
class debugger {

    /** @var bool Enable/disable debug logging without touching production code. */
    public const ENABLED = false;

    public static function log(string $section, array $data = []): void {
        if (!self::ENABLED) {
            return;
        }
        global $CFG;
        if (empty($CFG->dataroot)) {
            return;
        }
        $logfile = self::get_path();
        // Milliseconds to order entries within the same second.
        $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $ts = date('Y-m-d H:i:s') . '.' . $ms;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = "[{$ts}] [{$section}] {$json}\n";
        @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Logs ALL relevant $_SERVER values to diagnose the request.
     * Call at the beginning of the hook to capture the full context.
     */
    public static function log_request(int $userid): void {
        self::log('REQUEST', [
            'userid'         => $userid,
            'SCRIPT_NAME'    => $_SERVER['SCRIPT_NAME']    ?? '(empty)',
            'PATH_INFO'      => $_SERVER['PATH_INFO']      ?? '(empty)',
            'REQUEST_URI'    => $_SERVER['REQUEST_URI']    ?? '(empty)',
            'QUERY_STRING'   => $_SERVER['QUERY_STRING']   ?? '(empty)',
            'HTTP_REFERER'   => $_SERVER['HTTP_REFERER']   ?? '(empty)',
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '(empty)',
            'file_GET_param' => $_GET['file']              ?? '(empty)',
        ]);
    }

    public static function read(): string {
        $logfile = self::get_path();
        if (!file_exists($logfile)) {
            return '';
        }
        return file_get_contents($logfile);
    }

    public static function clear(): void {
        $logfile = self::get_path();
        if (file_exists($logfile)) {
            @unlink($logfile);
        }
    }

    public static function get_path(): string {
        global $CFG;
        return $CFG->dataroot . '/local_scorm_incca_debug.log';
    }

    /**
     * Always writes to the general diagnostic log (ignores ENABLED).
     * File: {moodledata}/local_scorm_incca_diag.log
     * Use to trace specific flows. Remove calls before stable production release.
     */
    public static function logDiag(string $section, array $data = []): void {
        global $CFG;
        if (empty($CFG->dataroot)) {
            return;
        }
        $logfile = $CFG->dataroot . '/local_scorm_incca_diag.log';
        $ms    = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $ts    = date('Y-m-d H:i:s') . '.' . $ms;
        $json  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = "[{$ts}] [{$section}] {$json}\n";
        @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Always writes to the import log (ignores ENABLED).
     * File: {moodledata}/local_scorm_incca_import.log
     * Remove these calls before stable production release.
     */
    public static function logImport(string $section, array $data = []): void {
        global $CFG;
        if (empty($CFG->dataroot)) {
            return;
        }
        $logfile = $CFG->dataroot . '/local_scorm_incca_import.log';
        $ms    = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $ts    = date('Y-m-d H:i:s') . '.' . $ms;
        $json  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = "[{$ts}] [{$section}] {$json}\n";
        @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }

    public static function file_size(): string {
        $logfile = self::get_path();
        if (!file_exists($logfile)) {
            return '0 B';
        }
        $bytes = filesize($logfile);
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
