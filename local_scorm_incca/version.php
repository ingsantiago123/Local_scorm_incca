<?php
// This file is part of local_scorm_incca.
//
// @package    local_scorm_incca
// @author     Kevin Garzon
// @copyright  2026 Universidad INCCA
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_scorm_incca';
$plugin->version   = 2026070900; // v1.1.0: install no longer scans the platform; on-demand course sync + bulk protect
$plugin->requires  = 2024100700; // Moodle 4.5
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.0';
