<?php
defined('MOODLE_INTERNAL') || die();

$observers = [

    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_scorm_incca\observer::course_module_created',
        'priority'  => 1000,
    ],

    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_scorm_incca\observer::course_module_updated',
        'priority'  => 1000,
    ],

    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_scorm_incca\observer::course_module_deleted',
        'priority'  => 1000,
    ],

    // Registra SCORMs que llegaron por import/restore (course_module_created no se dispara en restore).
    [
        'eventname' => '\core\event\course_restored',
        'callback'  => '\local_scorm_incca\observer::course_restored',
        'priority'  => 500,
        'internal'  => false,
    ],
];
