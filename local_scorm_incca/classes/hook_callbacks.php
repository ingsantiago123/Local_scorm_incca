<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks para local_scorm_incca.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ARQUITECTURA DE INTERCEPCIÓN (Moodle 4.5)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * MECANISMO 1 — after_config (lib.php, callback LEGACY)
 *   • Se dispara en lib/setup.php para TODA petición HTTP.
 *   • Cubre draftfile.php y pluginfile.php antes de que sirvan el archivo.
 *
 * MECANISMO 2 — after_require_login (lib.php, callback LEGACY)
 *   • Se dispara dentro de require_login() como respaldo.
 *   • Actúa si after_config no pudo actuar (usuario no autenticado aún).
 *
 * GUARD DE EJECUCIÓN ÚNICA
 *   Ambos mecanismos llaman a check_access(). Para evitar doble consulta BD,
 *   check_access() usa REQUEST_TIME_FLOAT como clave de guard por-petición.
 *
 * BYPASS DE SITE ADMIN
 *   Los site admins siempre pueden descargar.
 */
class hook_callbacks {

    // ═══════════════════════════════════════════════════════════════════════════
    // MECANISMO 1: after_config — dispara para TODA petición HTTP
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Dispara cuando config.php termina de cargarse (toda petición HTTP).
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $USER;

        $isPluginfile     = helper::is_pluginfile_request();
        $isDraftfile      = helper::is_draftfile_request();
        $isDraftfilesAjax = helper::is_draftfiles_ajax_request();

        if (!$isPluginfile && !$isDraftfile && !$isDraftfilesAjax) {
            return;
        }

        if (empty($USER->id) || isguestuser($USER)) {
            return;
        }

        self::check_access();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // LÓGICA CENTRAL: check_access()
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Lógica central de control de acceso.
     * Llamado desde after_config y desde after_require_login (lib.php).
     *
     * Incluye guard de ejecución única para evitar doble consulta BD cuando
     * ambos mecanismos disparan en la misma petición.
     */
    public static function check_access(): void {
        global $USER;

        // ── Guard: evitar ejecución doble en la misma petición ──────────────
        // Usa REQUEST_TIME_FLOAT para que el guard sea por-petición aunque el
        // proceso PHP persista entre requests (PHP-FPM, OPcache, etc.).
        static $checkedRequestTime = null;
        $currentRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        if ($checkedRequestTime === $currentRequestTime) {
            return;
        }
        $checkedRequestTime = $currentRequestTime;

        // ── Identificar endpoint ─────────────────────────────────────────────
        $isPluginfile     = helper::is_pluginfile_request();
        $isDraftfile      = helper::is_draftfile_request();
        $isDraftfilesAjax = helper::is_draftfiles_ajax_request();
        $isService        = helper::is_ajax_service_request();

        if (!$isPluginfile && !$isDraftfile && !$isDraftfilesAjax && !$isService) {
            return;
        }

        // ── Flujo lib/ajax/service.php — eliminación de módulos SCORM ────────
        // El nuevo editor de cursos (Moodle 4.x) borra módulos via AJAX a este
        // endpoint con core_courseformat_update_course (action=cm_delete) o
        // core_course_edit_module (action=delete). Ambas usan $async=true, por lo
        // que pre_course_module_delete NO dispara en el contexto web.
        if ($isService) {
            self::handle_ajax_service_delete($USER);
            return;
        }

        // ── Flujo draftfiles_ajax.php ────────────────────────────────────────
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

        // ── Flujo pluginfile.php / draftfile.php ─────────────────────────────
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

        // ── Bypass: site admins siempre pueden descargar ─────────────────────
        if (is_siteadmin($USER->id)) {
            return;
        }

        // ── Evaluar capability ───────────────────────────────────────────────
        $hasCap = has_capability('local/scorm_incca:descargar', $context, $USER);

        if ($hasCap) {
            helper::log(helper::LOG_DOWNLOAD_ALLOWED, (int)$USER->id, $cmid,
                "Descarga permitida | userid={$USER->id} cmid={$cmid}");
            return;
        }

        // ── BLOQUEAR ─────────────────────────────────────────────────────────
        helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, $cmid,
            "Descarga BLOQUEADA | userid={$USER->id} cmid={$cmid}");

        // die() es irrecuperable — no puede ser capturado por try/catch de Moodle.
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
        echo '<title>403 - Acceso Denegado</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;text-align:center}';
        echo 'h1{color:#c0392b}p{color:#555}a{color:#2980b9}</style></head><body>';
        echo '<h1>&#x1F512; Acceso Denegado</h1>';
        echo '<p>No tiene permisos para descargar este paquete SCORM protegido.</p>';
        echo '<p><a href="javascript:history.back()">&#8592; Volver</a></p>';
        echo '</body></html>';
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ZIP: draftfiles_ajax.php — "Descargar todos"
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepta la generación del ZIP masivo y filtra los SCORM protegidos.
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

        // Site admins: bypass completo.
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
                    if ($modctx && !has_capability('local/scorm_incca:descargar', $modctx, $USER)) {
                        $blockedcmids[] = $cmid;
                        helper::log(helper::LOG_DOWNLOAD_BLOCKED, (int)$USER->id, $cmid,
                            "ZIP masivo bloqueado | userid={$USER->id} cmid={$cmid} file={$filename}");
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
            echo json_encode(['error' => 'Acceso denegado. No tiene permisos para descargar este paquete SCORM protegido.']);
            die();
        }

        if (!empty($blockedcmids)) {
            $_POST['selected']    = json_encode($allowed);
            $_REQUEST['selected'] = json_encode($allowed);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SERVICE: lib/ajax/service.php — eliminación de módulos SCORM protegidos
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepta peticiones de eliminación de módulos via lib/ajax/service.php.
     *
     * Detecta dos métodos de webservice que eliminan módulos en Moodle 4.x:
     *  1. core_courseformat_update_course  (action=cm_delete)  — nuevo editor de cursos
     *  2. core_course_edit_module          (action=delete)     — legacy
     *
     * En PHP 8+ (requerido por Moodle 4.5), php://input es re-legible: leerlo
     * aquí en after_config NO impide que service.php lo lea después.
     */
    private static function handle_ajax_service_delete($USER): void {
        // Solo aplica a peticiones POST (las lecturas usan GET en service.php).
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

            // ── core_courseformat_update_course — nuevo editor Moodle 4.x ────
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

            // ── core_course_edit_module — path legacy ─────────────────────────
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
                    "Eliminacion BLOQUEADA via AJAX service | userid={$USER->id} cmid={$blockedcmid}"
                );

                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }

                // Formato de error que espera lib/ajax/service.php JS handler.
                echo json_encode([
                    $index => [
                        'error'     => true,
                        'exception' => [
                            'errorcode' => 'deletedenied',
                            'module'    => 'local_scorm_incca',
                            'message'   => 'No tiene permiso para eliminar este paquete SCORM protegido. Solo administradores o usuarios con permisos de carga/descarga pueden eliminarlo.',
                        ],
                    ],
                ]);
                die();
            }
        }
    }

    /**
     * Comprueba si el usuario actual NO tiene permiso para eliminar un cmid.
     * Retorna true si se debe BLOQUEAR, false si se permite.
     */
    private static function is_delete_blocked(int $cmid, $USER): bool {
        if (!$cmid) {
            return false;
        }
        // Solo aplica a SCORMs protegidos en nuestra tabla.
        if (!helper::is_protected($cmid)) {
            return false;
        }
        // Site admins pueden eliminar siempre.
        if (is_siteadmin($USER->id)) {
            return false;
        }
        // Con capability cargar o descargar → permitido.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            if (has_capability('local/scorm_incca:cargar',    $context, $USER->id, false) ||
                has_capability('local/scorm_incca:descargar', $context, $USER->id, false)) {
                return false;
            }
        }
        return true; // Bloquear.
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UNZIP: draftfiles_ajax.php — "Descomprimir"
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepta la acción de descompresión en el file-manager.
     * POST /repository/draftfiles_ajax.php?action=unzip
     *
     * Si el ZIP es un SCORM protegido y el usuario no tiene descargar → rechaza con JSON.
     * El client JS (filemanager.js línea ~1040) muestra el mensaje de error al usuario.
     */
    private static function handle_draftfiles_ajax_unzip($USER): void {
        $itemid   = (int)($_POST['itemid'] ?? 0);
        $filename = trim($_POST['filename'] ?? '');

        if (!$itemid || $filename === '') {
            return; // Sin datos suficientes: fail-open.
        }

        // Verificar si el draft ZIP corresponde a un SCORM protegido.
        $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
        if (!$cmid) {
            return; // No es SCORM protegido: permitir.
        }

        // Site admins siempre pueden descomprimir.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // Verificar capability local/scorm_incca:descargar.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context && has_capability('local/scorm_incca:descargar', $context, $USER)) {
            helper::log(helper::LOG_DOWNLOAD_ALLOWED, (int)$USER->id, $cmid,
                "Descompresion permitida | userid={$USER->id} cmid={$cmid} file={$filename}");
            return;
        }

        // BLOQUEAR: responder con JSON error (el client JS espera JSON de draftfiles_ajax.php).
        helper::log(helper::LOG_UNZIP_BLOCKED, (int)$USER->id, $cmid,
            "Descompresion BLOQUEADA | userid={$USER->id} cmid={$cmid} file={$filename}");

        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'No tiene permisos para descomprimir este paquete SCORM protegido.']);
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DELETE FILE: draftfiles_ajax.php — "Eliminar archivo" en Edit Settings
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepta la eliminación del archivo ZIP dentro del file-manager de
     * "Editar ajustes" del SCORM.
     *
     * POST /repository/draftfiles_ajax.php?action=delete
     * Parámetros relevantes: itemid (draft area ID), filename (nombre del ZIP).
     *
     * Si el ZIP corresponde a un SCORM protegido y el usuario no tiene
     * cargar NI descargar → responde JSON error y termina.
     */
    private static function handle_draftfiles_ajax_delete_file($USER): void {
        $itemid   = (int)($_POST['itemid'] ?? 0);
        $filename = trim($_POST['filename'] ?? '');

        if (!$itemid || $filename === '') {
            return; // Sin datos suficientes: fail-open.
        }

        // Solo aplica a archivos ZIP (paquetes SCORM).
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return;
        }

        // Verificar si el draft ZIP corresponde a un SCORM protegido.
        $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
        if (!$cmid) {
            return; // No es SCORM protegido: permitir.
        }

        // Site admins siempre pueden eliminar.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // Con capability cargar O descargar → permitido.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            if (has_capability('local/scorm_incca:cargar',    $context, $USER->id, false) ||
                has_capability('local/scorm_incca:descargar', $context, $USER->id, false)) {
                return;
            }
        }

        // BLOQUEAR: registrar y responder JSON error.
        helper::log(
            helper::LOG_DELETE_BLOCKED,
            (int)$USER->id,
            $cmid,
            "Eliminacion archivo bloqueada (Edit Settings) | userid={$USER->id} cmid={$cmid} file={$filename}"
        );

        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'No tiene permisos para eliminar el archivo de este paquete SCORM protegido.']);
        die();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DELETE SELECTED: draftfiles_ajax.php — toolbar "Delete" en modo lista
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepta la eliminacion masiva desde el toolbar del file manager (modo lista).
     * POST /repository/draftfiles_ajax.php?action=deleteselected
     *
     * Parametros: itemid (draft area ID), selected (JSON: [{filepath, filename}, ...])
     *
     * Si alguno de los archivos seleccionados es un SCORM protegido sin permiso,
     * bloquea toda la operacion respondiendo con JSON error.
     */
    private static function handle_draftfiles_ajax_delete_selected($USER): void {
        $itemid      = (int)($_POST['itemid'] ?? 0);
        $selectedraw = trim($_POST['selected'] ?? '');

        if (!$itemid || $selectedraw === '') {
            return; // Sin datos suficientes: fail-open.
        }

        $selectedfiles = @json_decode($selectedraw);
        if (!is_array($selectedfiles) || empty($selectedfiles)) {
            return;
        }

        // Site admins: bypass completo.
        if (is_siteadmin($USER->id)) {
            return;
        }

        foreach ($selectedfiles as $fileinfo) {
            $filename = trim($fileinfo->filename ?? '');
            if ($filename === '' || $filename === '.') {
                continue; // Directorios: ignorar.
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                continue; // Solo aplica a ZIPs (paquetes SCORM).
            }

            $cmid = helper::find_protected_scorm_by_draft($itemid, $filename);
            if (!$cmid) {
                continue; // No es SCORM protegido: permitir.
            }

            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if ($context &&
                (has_capability('local/scorm_incca:cargar',    $context, $USER->id, false) ||
                 has_capability('local/scorm_incca:descargar', $context, $USER->id, false))) {
                continue; // Tiene permiso: permitir.
            }

            // BLOQUEAR al encontrar el primer archivo protegido sin permiso.
            helper::log(
                helper::LOG_DELETE_BLOCKED,
                (int)$USER->id,
                $cmid,
                "Eliminacion masiva BLOQUEADA (deleteselected) | userid={$USER->id} cmid={$cmid} file={$filename}"
            );

            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'No tiene permisos para eliminar el archivo de este paquete SCORM protegido.']);
            die();
        }
    }

    /**
     * Enumera todos los archivos de un área de borrador.
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
    // BACKUP: backup/backup.php — bloquear descarga de SCORMs protegidos vía .mbz
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Intercepts backup/backup.php for both course and activity backup.
     *
     * Flujo:
     *  - Si cm está en GET y es un SCORM protegido → verificar permiso backup.
     *  - Si no hay cm → verificar si el curso tiene SCORMs protegidos → verificar permiso.
     *  - Quien tenga backup|cargar|descargar en el curso → permitir.
     *  - Siteadmin → siempre permitir.
     */
    public static function handle_backup(\stdClass $USER): void {
        // Siteadmin siempre puede.
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
            // ── Backup de actividad específica ────────────────────────────
            $isProtected = helper::is_protected($cmid);

            debugger::logDiag('BACKUP_ACTIVITY_CHECK', [
                'userid'       => $USER->id,
                'courseid'     => $courseid,
                'cmid'         => $cmid,
                'is_protected' => $isProtected,
            ]);

            if (!$isProtected) {
                return; // No es SCORM protegido — sin restricción.
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
                    "Backup actividad BLOQUEADO | cmid={$cmid} userid={$USER->id}");
                self::deny_backup_response(true);
            }
            return;
        }

        // ── Backup de curso completo ───────────────────────────────────────
        $protectedCmids = helper::get_protected_cmids_in_course($courseid);

        debugger::logDiag('BACKUP_COURSE_CHECK', [
            'userid'          => $USER->id,
            'courseid'        => $courseid,
            'protected_count' => count($protectedCmids),
            'protected_cmids' => $protectedCmids,
        ]);

        if (empty($protectedCmids)) {
            return; // Sin SCORMs protegidos — backup normal sin restricción.
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
                "Backup curso BLOQUEADO | courseid={$courseid} userid={$USER->id} protected=" . count($protectedCmids));
            self::deny_backup_response(false);
        }
    }

    /**
     * Verifica si el usuario puede hacer backup de un curso con SCORMs protegidos.
     * Acepta cualquiera de las tres capabilities del plugin a nivel de curso.
     */
    private static function user_can_backup_protected(\stdClass $USER, \context $ctx): bool {
        return has_capability('local/scorm_incca:backup',    $ctx, $USER)
            || has_capability('local/scorm_incca:cargar',    $ctx, $USER)
            || has_capability('local/scorm_incca:descargar', $ctx, $USER);
    }

    /**
     * Muestra página 403 y detiene la ejecución (mismo patrón que check_access).
     *
     * @param bool $isActivity true = backup de actividad, false = backup de curso.
     */
    private static function deny_backup_response(bool $isActivity): void {
        $msg = $isActivity
            ? 'No tiene permisos para crear copias de seguridad de paquetes SCORM protegidos.'
            : 'Este curso contiene paquetes SCORM protegidos. Se requiere el permiso de backup para crear copias de seguridad de este curso.';
        if (!headers_sent()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
        echo '<title>403 - Acceso Denegado</title>';
        echo '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;text-align:center}';
        echo 'h1{color:#c0392b}p{color:#555}a{color:#2980b9}</style></head><body>';
        echo '<h1>&#x1F512; Copia de seguridad no permitida</h1>';
        echo "<p>{$msg}</p>";
        echo '<p><small>Contacte al administrador para que le asigne el permiso <code>local/scorm_incca:backup</code> en este curso.</small></p>';
        echo '<p><a href="javascript:history.back()">&#8592; Volver</a></p>';
        echo '</body></html>';
        die();
    }


}
