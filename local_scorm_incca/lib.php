<?php
defined('MOODLE_INTERNAL') || die();

/**
 * lib.php — Callbacks legacy de local_scorm_incca.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ SE USA EL MECANISMO LEGACY (Y NO db/hooks.php PARA after_config)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * draftfile.php define NO_DEBUG_DISPLAY=true ANTES de cargar config.php.
 * Cuando process_legacy_callbacks() (en after_config.php de Moodle) llama
 * nuestra función, lo hace dentro de try/catch. Si la función lanza una
 * excepción, el catch llama debugging() — que está SUPRIMIDO por
 * NO_DEBUG_DISPLAY=true → silencio total.
 *
 * SOLUCIÓN:
 *  1. Usar require_once() explícito con rutas absolutas (evita autoloading).
 *  2. Capturar excepciones dentro de la función y silenciarlas.
 *
 * NOTA: db/hooks.php tiene $callbacks = [] (vacío) para que Moodle NO marque
 * la función legacy como "migrada" y la omita.
 */


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACK 1: after_config — TODA petición HTTP
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Callback legacy: fired for EVERY HTTP request at end of setup.php.
 *
 * NOTA: para que esta función sea llamada, db/hooks.php NO debe tener registrado
 * el hook \core\hook\after_config. Si lo tiene, Moodle considera que el plugin
 * "migró" al nuevo sistema y omite esta función legacy.
 */
function local_scorm_incca_after_config(): void {
    global $CFG, $USER;

    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    // ── Filtrar: solo endpoints relevantes ───────────────────────────────────
    $isDraft   = strpos($script, '/draftfile.php') !== false;
    $isPlugin  = strpos($script, '/pluginfile.php') !== false;
    $isAjax    = strpos($script, '/draftfiles_ajax.php') !== false;
    $isService = strpos($script, '/lib/ajax/service.php') !== false;
    $isBackup  = strpos($script, '/backup/backup.php') !== false;

    if (!$isDraft && !$isPlugin && !$isAjax && !$isService && !$isBackup) {
        return;
    }

    // ── Verificar usuario autenticado ────────────────────────────────────────
    if (empty($USER) || empty($USER->id) || isguestuser($USER)) {
        return;
    }

    // ── Cargar dependencias explícitamente ───────────────────────────────────
    if (!empty($CFG->dirroot)) {
        $plugindir = $CFG->dirroot . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'scorm_incca';
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'debugger.php');
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'helper.php');
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'hook_callbacks.php');
    }

    // ── Ejecutar verificación central ────────────────────────────────────────
    // Capturamos excepciones aquí porque process_legacy_callbacks las silencia.
    try {
        if (!class_exists('\\local_scorm_incca\\hook_callbacks', false)) {
            return;
        }
        if ($isBackup) {
            // Backup tiene flujo propio — no pasa por check_access().
            \local_scorm_incca\hook_callbacks::handle_backup($USER);
            return;
        }
        \local_scorm_incca\hook_callbacks::check_access();
    } catch (\Throwable $e) {
        // Silenciar: no propagar excepciones para no interrumpir la petición.
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACK 2: after_require_login — respaldo vía require_login()
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Callback legacy: fired after require_login() authenticates the user.
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
    global $CFG;

    // ── Cargar dependencias explícitamente ───────────────────────────────────
    if (!empty($CFG->dirroot)) {
        $plugindir = $CFG->dirroot . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'scorm_incca';
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'debugger.php');
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'helper.php');
        @include_once($plugindir . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'hook_callbacks.php');
    }

    // ── Ejecutar verificación central ────────────────────────────────────────
    // check_access() tiene guard estático. Si after_config ya actuó, retorna sin
    // doble ejecución. Capturamos aquí para no propagar excepciones.
    try {
        if (class_exists('\\local_scorm_incca\\hook_callbacks', false)) {
            \local_scorm_incca\hook_callbacks::check_access();
        }
    } catch (\Throwable $e) {
        // Silenciar: no propagar excepciones para no interrumpir la petición.
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACK 3: pre_course_module_delete — proteger eliminación de SCORMs protegidos
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Callback pre_course_module_delete (Moodle hook legacy).
 * Impide que usuarios sin permisos eliminen un SCORM protegido.
 *
 * Detectado por Moodle vía get_plugins_with_function('pre_course_module_delete').
 * Se llama desde course_delete_module() en course/lib.php ANTES de borrar datos.
 * Lanzar moodle_exception aquí cancela la eliminación completamente.
 *
 * @param stdClass $cm  Registro de course_modules: id, course, module (FK) — NO tiene modname
 */
function local_scorm_incca_pre_course_module_delete(stdClass $cm): void {
    global $USER;

    // En contexto CLI/cron → no bloquear (eliminación asíncrona o automatizada).
    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        return;
    }

    // Necesita usuario autenticado para evaluar permisos.
    if (empty($USER->id)) {
        return;
    }

    try {
        $cmid = (int)$cm->id;

        // NOTA IMPORTANTE: $cm aquí viene de $DB->get_record('course_modules', ...)
        // que NO incluye la propiedad 'modname'. Por eso NO se puede usar $cm->modname.
        // En cambio, helper::is_protected() solo retorna true para SCORMs registrados
        // en nuestra tabla — no hay necesidad de filtrar por tipo de módulo.

        // Sin restricción si el SCORM no está marcado como protegido.
        if (!\local_scorm_incca\helper::is_protected($cmid)) {
            return;
        }

        // Site admins pueden eliminar siempre.
        if (is_siteadmin($USER->id)) {
            return;
        }

        // Verificar capability en el contexto del módulo.
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($context) {
            $canUpload   = has_capability('local/scorm_incca:cargar',    $context, $USER->id, false);
            $canDownload = has_capability('local/scorm_incca:descargar', $context, $USER->id, false);
            if ($canUpload || $canDownload) {
                return; // Tiene permiso: permitir eliminación.
            }
        }

        // BLOQUEAR: registrar intento y lanzar excepción.
        \local_scorm_incca\helper::log(
            \local_scorm_incca\helper::LOG_DELETE_BLOCKED,
            (int)$USER->id,
            $cmid,
            "Eliminacion BLOQUEADA | userid={$USER->id} cmid={$cmid}"
        );

        // moodle_exception genera la página de error estándar de Moodle.
        // Tercer parámetro = URL del botón "Continuar" → vuelve al curso.
        throw new \moodle_exception(
            'deletedenied',
            'local_scorm_incca',
            new \moodle_url('/course/view.php', ['id' => (int)$cm->course])
        );

    } catch (\moodle_exception $e) {
        throw $e; // Re-propagar para cancelar la eliminación.
    } catch (\Throwable $e) {
        // Otros errores del chequeo: fail-open (no bloquear si el helper falla).
    }
}
