<?php
// This file is part of block_scorm_incca.
//
// @package    block_scorm_incca
// @author     Kevin Garzon
// @copyright  2026 Universidad INCCA
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Permite agregar el bloque a páginas normales (cursos, categorías, etc.)
    'block/scorm_incca:addinstance' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],
    // Permite agregar el bloque al Dashboard (Mi Moodle)
    'block/scorm_incca:myaddinstance' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'    => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks',
    ],
];
