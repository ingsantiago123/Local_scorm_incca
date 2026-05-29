<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Permite subir un SCORM y que quede marcado como protegido.
    'local/scorm_incca:cargar' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Permite descargar el .zip de un SCORM protegido.
    'local/scorm_incca:descargar' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Permite crear copias de seguridad de cursos con SCORMs protegidos.
    'local/scorm_incca:backup' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Acceso al panel de administracion del plugin.
    'local/scorm_incca:viewadminpanel' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
