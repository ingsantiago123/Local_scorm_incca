<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Logger de depuracion para diagnosticar el flujo de descarga SCORM.
 *
 * Escribe en {moodledata}/local_scorm_incca_debug.log.
 * El archivo NO es accesible via web (esta fuera del docroot).
 *
 * Uso: ver el log en la pagina de administracion
 *      Administracion > Extensiones > SCORM INCCA > Debug Log
 *
 * NOTA: Deshabilitar en produccion eliminando las llamadas a debugger::log()
 *       o usando la constante SCORM_INCCA_DEBUG = false.
 */
class debugger {

    /** @var bool Activar/desactivar el debug sin tocar el codigo */
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
        // Microsegundos para ordenar entradas del mismo segundo.
        $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
        $ts = date('Y-m-d H:i:s') . '.' . $ms;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = "[{$ts}] [{$section}] {$json}\n";
        @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Registra TODOS los valores $_SERVER relevantes para diagnosticar la peticion.
     * Llamar al inicio del hook para capturar el contexto completo.
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
     * Escribe SIEMPRE en el log de diagnóstico general (ignora ENABLED).
     * Archivo: {moodledata}/local_scorm_incca_diag.log
     * Usar para rastrear flujos específicos. Eliminar llamadas antes de producción estable.
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
     * Escribe SIEMPRE en el log de importación (ignora ENABLED).
     * Archivo: {moodledata}/local_scorm_incca_import.log
     * Eliminar estas llamadas antes de pasar a producción estable.
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
