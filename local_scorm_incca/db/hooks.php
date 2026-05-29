<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hooks de local_scorm_incca.
 *
 * IMPORTANTE — Por qué este archivo está vacío:
 *
 * Moodle 4.5: cuando un plugin registra un callback para \core\hook\after_config
 * en db/hooks.php, el sistema de hooks marca la función legacy
 * {plugin}_after_config() de lib.php como "migrada" y la OMITE.
 *
 * Si el callback del sistema de hooks falla silenciosamente (ej: autoloading
 * falla y draftfile.php tiene NO_DEBUG_DISPLAY=true que suprime el debugging()),
 * NADA se ejecuta — ni el hook ni el legacy.
 *
 * SOLUCIÓN: usar SOLO el mecanismo legacy en lib.php:
 *   - local_scorm_incca_after_config()       → toda petición (draftfile/pluginfile)
 *   - local_scorm_incca_after_require_login() → respaldo vía require_login()
 *
 * Ambas funciones usan require_once() explícito para cargar sus dependencias,
 * eliminando cualquier dependencia de autoloading.
 */
$callbacks = [];
