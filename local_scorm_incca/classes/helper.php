<?php
namespace local_scorm_incca;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper centralizado con la logica de negocio del plugin.
 */
class helper {

    /** Tipos de evento de log */
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
     * Registra un SCORM en la tabla del plugin con su estado de proteccion.
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
     * Registra todos los SCORMs del curso destino que no están en la tabla,
     * heredando el estado isprotected del curso origen via sha1hash del paquete.
     *
     * Llamado desde observer::course_restored() después de un import/restore.
     * Matching origen→destino: mdl_scorm.sha1hash (mismo contenido de paquete).
     *
     * Política cuando no hay match (AICC, externo, sha1hash null, restore .mbz sin
     * originalcourseid): isprotected = false (el admin puede ajustar desde el panel).
     *
     * @param int $destcourseid   ID del curso destino (recién restaurado).
     * @param int $importerid     ID del usuario que ejecutó el import.
     * @param int $sourcecourse   ID del curso origen (0 si no disponible).
     */
    public static function register_imported_scorms(
        int $destcourseid,
        int $importerid,
        int $sourcecourse
    ): void {
        global $DB;

        // SCORMs en el curso destino que todavía no tienen registro en el plugin.
        // Seleccionamos s.name: es el identificador de instancia exacto que Moodle preserva
        // durante el restore. A diferencia del sha1hash (hash del paquete), el name distingue
        // cada actividad SCORM individualmente aunque compartan el mismo archivo ZIP.
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
                // Buscar el SCORM origen por nombre de actividad.
                //
                // ¿Por qué nombre y no sha1hash?
                // El sha1hash identifica el CONTENIDO del paquete ZIP, no la instancia SCORM.
                // Si el mismo ZIP se sube varias veces (paquete público Y protegido), todos comparten
                // el mismo hash → el MAX() por hash daría falso positivo para los públicos.
                // El nombre de la actividad identifica la instancia específica (Moodle lo preserva
                // exactamente durante el restore) y distingue entre dos SCORMs del mismo paquete
                // que tienen distinto estado de protección.
                //
                // Si hay nombre duplicado en el origen (improbable pero posible), MAX() devuelve
                // el estado más restrictivo entre ellos — mismo comportamiento conservador del bug
                // anterior pero acotado a duplicados reales de nombre, no de contenido.
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

                // null → ningún SCORM con ese nombre en el origen tiene registro → público.
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
                'SCORM importado registrado. isprotected=' . ($isprotected ? '1' : '0')
                    . ' sourcecourse=' . $sourcecourse
            );
        }
    }

    /**
     * Devuelve los cmids de SCORMs protegidos en un curso.
     * Usado por hook_callbacks::handle_backup() para detectar si el curso
     * tiene contenido protegido antes de permitir la creación del backup.
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
     * Elimina el registro de un SCORM (cuando se borra la actividad).
     */
    public static function unregister_scorm(int $cmid): void {
        global $DB;
        $DB->delete_records('local_scorm_incca_items', ['cmid' => $cmid]);
    }

    /**
     * Indica si un course module corresponde a un SCORM protegido.
     */
    public static function is_protected(int $cmid): bool {
        global $DB;
        return (bool)$DB->record_exists('local_scorm_incca_items', [
            'cmid'        => $cmid,
            'isprotected' => 1,
        ]);
    }

    /**
     * Inserta una entrada en el log del plugin.
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
     * Parsea la URL de pluginfile.php / draftfile.php y devuelve sus componentes.
     *
     * Formato: /{contextid}/{component}/{filearea}/{itemid}/{filename}
     *
     * Soporta tres modos de entrega de la ruta:
     *  1. PATH_INFO  (slasharguments = 1, modo normal)
     *  2. REQUEST_URI menos SCRIPT_NAME  (algunos servidores/proxies)
     *  3. Parametro GET 'file'  (slasharguments = 0)
     *
     * @return array|null ['contextid','component','filearea','itemid','filename'] o null
     */
    public static function parse_pluginfile_path(): ?array {

        // Intento 1: PATH_INFO estandar.
        $pathinfo = $_SERVER['PATH_INFO'] ?? '';

        // Intento 2: derivar de REQUEST_URI quitando el nombre del script.
        if (empty($pathinfo)) {
            $scriptname = $_SERVER['SCRIPT_NAME'] ?? '';
            $requesturi = strtok($_SERVER['REQUEST_URI'] ?? '', '?'); // sin query string
            if ($scriptname && strpos($requesturi, $scriptname) === 0) {
                $pathinfo = substr($requesturi, strlen($scriptname));
            }
        }

        // Intento 3: parametro GET 'file' (Moodle con slasharguments = 0).
        if (empty($pathinfo)) {
            $pathinfo = $_GET['file'] ?? '';
        }

        if (empty($pathinfo)) {
            return null;
        }

        // Remover slash inicial y dividir.
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
     * Indica si la peticion actual es a pluginfile.php.
     */
    public static function is_pluginfile_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/pluginfile.php') !== false);
    }

    /**
     * Indica si la peticion actual es a draftfile.php.
     *
     * draftfile.php sirve archivos del area de borrador del usuario (file-manager
     * en modo edicion). Tambien llama require_login(), por lo que el hook se
     * dispara, pero el endpoint diferente no estaba cubierto por la proteccion.
     */
    public static function is_draftfile_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/draftfile.php') !== false);
    }

    /**
     * Indica si la peticion actual es al endpoint AJAX de servicios web (lib/ajax/service.php).
     *
     * Este endpoint procesa acciones del editor de cursos de Moodle 4.x, incluidas las
     * eliminaciones de módulos vía core_courseformat_update_course (action=cm_delete) y
     * core_course_edit_module (action=delete). Ambas llaman course_delete_module($id, true)
     * que usa eliminación asíncrona y por tanto NO dispara pre_course_module_delete en el
     * contexto web. Por eso la intercepción debe hacerse aquí, antes de que el servicio corra.
     */
    public static function is_ajax_service_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/lib/ajax/service.php') !== false);
    }

    /**
     * Indica si la peticion actual es al endpoint AJAX de archivos borrador.
     *
     * draftfiles_ajax.php es llamado por el file-manager cuando el usuario hace
     * clic en "Descargar todos como ZIP". Llama require_login(), por lo que
     * nuestro callback se dispara. Interceptamos AQUI para filtrar los archivos
     * protegidos ANTES de que se genere el ZIP, ya que el ZIP resultante tiene
     * un contenthash nuevo que no coincide con ningun SCORM registrado.
     *
     * Flujo:
     *   POST /repository/draftfiles_ajax.php?action=downloadselected
     *   -> require_login() -> nuestro callback -> filtrar $_POST['selected']
     *   -> genera ZIP solo con archivos permitidos
     *   -> devuelve URL a GET /draftfile.php/.../files.zip
     *   -> nuestro callback en draftfile.php ya no necesita actuar sobre el ZIP
     */
    public static function is_draftfiles_ajax_request(): bool {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($script, '/draftfiles_ajax.php') !== false);
    }

    /**
     * Dado un itemid de borrador y un nombre de archivo, determina si ese
     * borrador corresponde a un SCORM protegido.
     *
     * ESTRATEGIA (en orden de prioridad):
     *
     * 1. CAMPO 'source' del borrador (más preciso):
     *    Moodle guarda en mdl_files.source un objeto PHP serializado con:
     *      - source->original = "{contextid}/mod_scorm/package/{itemid}/{filepath}/{filename}"
     *      - source->source   = URL del archivo original (puede estar vacia)
     *    Extrayendo el contextid del campo 'original' identificamos exactamente
     *    qué SCORM se estaba editando → decision exacta sin falsos positivos.
     *
     * 2. CONTENTHASH como fallback (postura segura):
     *    Si el 'source' no identifica el SCORM exacto, bloqueamos si AL MENOS
     *    UN SCORM con ese hash de contenido es protegido.
     *    Motivo: no podemos saber qué SCORM editaba el usuario, y la postura
     *    de seguridad es no permitir si el contenido pertenece a algo protegido.
     *
     * @param int    $draftitemid ID del area de borrador
     * @param string $filename    Nombre del archivo (p. ej. "curso.zip")
     * @return int|null cmid del SCORM protegido, o null si se permite la descarga
     */
    public static function find_protected_scorm_by_draft(int $draftitemid, string $filename): ?int {
        global $DB;

        // Obtener el registro del archivo borrador.
        $draftfile = $DB->get_record('files', [
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filename'  => $filename,
        ], '*', IGNORE_MULTIPLE);

        if (!$draftfile || empty($draftfile->contenthash)) {
            return null;
        }

        // ── Intento 1: campo 'source' del borrador ───────────────────────────
        //
        // Moodle (lib/filelib.php file_prepare_draft_area) guarda en source un
        // objeto PHP serializado: serialize((object)['source'=>..., 'original'=>...])
        // El campo 'original' tiene formato: "{contextid}/{component}/{filearea}/..."
        //
        // Ejemplo real:
        //   original = "55/mod_scorm/package/0//paquetev2.zip"
        //   → contextid=55 → context_module → cmid=27
        //
        // También intentamos con JSON y URL directa por compatibilidad.
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

        // ── Intento 2: fallback por contenthash ──────────────────────────────
        //
        // Buscamos todos los SCORMs registrados con este contenthash.
        // Si alguno es protegido → bloquear (postura de seguridad).
        // Si todos son publicos → permitir.
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
            return null; // No es un paquete controlado.
        }

        // Postura de seguridad: si CUALQUIER SCORM con este contenido es protegido,
        // bloqueamos. No podemos determinar qué SCORM exacto se editaba (source falló),
        // por lo que elegimos la opción más segura.
        foreach ($records as $record) {
            if ((int)$record->isprotected) {
                return (int)$record->cmid;
            }
        }

        // Todos públicos → permitir.
        return null;
    }

    /**
     * Extrae el contextid del campo 'source' de un archivo borrador de Moodle.
     *
     * Moodle (lib/filelib.php, file_prepare_draft_area) almacena source así:
     *
     *   serialize((object)[
     *       'source'   => <URL pluginfile o vacío>,
     *       'original' => file_storage::pack_reference($original),
     *   ])
     *
     * donde pack_reference = base64_encode(serialize((object){contextid, component, filearea, itemid, filename, filepath}))
     *
     * Por tanto el campo 'original' NO es un path plano — es un blob base64+serializado.
     * Hay que desempacarlo con unserialize(base64_decode(...)).
     *
     * Formatos soportados (en orden de prevalencia):
     *  1. PHP serializado (Moodle 4.x estándar): serialize((object){source, original})
     *  2. JSON: {"source":"...","original":"..."}
     *  3. URL directa: http://.../pluginfile.php/CTX/mod_scorm/package/...
     *
     * @param  string   $source  Valor raw de mdl_files.source
     * @return int|null contextid si se pudo extraer, null en otro caso
     */
    private static function extract_source_contextid(string $source): ?int {

        // ── Formato 1: PHP serializado (estándar Moodle 4.x) ─────────────────
        // Detectar por marcadores del serialize de stdClass
        if (strpos($source, 'O:8:"stdClass"') !== false || strpos($source, 's:8:"original"') !== false) {
            $obj = @unserialize($source);
            if ($obj && isset($obj->original)) {
                // original = file_storage::pack_reference($orig)
                //          = base64_encode(serialize((object){contextid, component, filearea, itemid, filename, filepath}))
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
                // Fallback dentro del mismo formato: path plano (versiones antiguas/personalizadas)
                if (preg_match('#^(\d+)/mod_scorm/package/#', $obj->original, $m)) {
                    return (int)$m[1];
                }
            }
            // Intentar también source->source como URL pluginfile directa
            if ($obj && !empty($obj->source)) {
                if (preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $obj->source, $m)) {
                    return (int)$m[1];
                }
            }
        }

        // ── Formato 2: JSON ───────────────────────────────────────────────────
        $json = json_decode($source, true);
        if (is_array($json)) {
            $original = $json['original'] ?? '';
            if ($original) {
                // Intentar desempacar como pack_reference
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
                // Fallback: path plano
                if (preg_match('#^(\d+)/mod_scorm/package/#', $original, $m)) {
                    return (int)$m[1];
                }
            }
            $srcurl = $json['source'] ?? '';
            if ($srcurl && preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $srcurl, $m)) {
                return (int)$m[1];
            }
        }

        // ── Formato 3: URL directa ────────────────────────────────────────────
        if (preg_match('#pluginfile\.php/(\d+)/mod_scorm/package#', $source, $m)) {
            return (int)$m[1];
        }

        return null;
    }
}
