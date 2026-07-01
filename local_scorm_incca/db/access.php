<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Allows uploading a SCORM package and marking it as protected.
    'local/scorm_incca:upload' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Allows downloading the .zip file of a protected SCORM package.
    'local/scorm_incca:download' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Allows creating backups of courses containing protected SCORM packages.
    'local/scorm_incca:backup' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Access to the plugin admin panel.
    'local/scorm_incca:viewadminpanel' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
