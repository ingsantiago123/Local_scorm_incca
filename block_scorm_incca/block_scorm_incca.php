<?php
// This file is part of block_scorm_incca.
//
// @package    block_scorm_incca
// @author     Kevin Garzon
// @copyright  2026 Universidad INCCA
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

/**
 * Bloque de acceso rápido al panel de administración de local_scorm_incca.
 *
 * Se oculta automáticamente para usuarios sin la capability viewadminpanel
 * (get_content() devuelve contenido vacío → is_empty() = true → Moodle no
 * renderiza el contenedor del bloque en modo no-edición).
 */
class block_scorm_incca extends block_base {

    public function init(): void {
        $this->title = get_string('pluginname', 'block_scorm_incca');
    }

    public function applicable_formats(): array {
        return ['all' => true];
    }

    public function has_config(): bool {
        return false;
    }

    public function get_content(): stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        // Sin capability → contenido vacío → is_empty() retorna true → bloque invisible.
        if (!has_capability('local/scorm_incca:viewadminpanel', context_system::instance())) {
            return $this->content;
        }

        $items = [];

        $items[] = html_writer::tag('li', html_writer::link(
            new moodle_url('/local/scorm_incca/index.php'),
            get_string('protected_list', 'local_scorm_incca')
        ));

        $items[] = html_writer::tag('li', html_writer::link(
            new moodle_url('/local/scorm_incca/logs.php'),
            get_string('logs', 'local_scorm_incca')
        ));

        // Usuarios con permisos: solo site admins.
        if (is_siteadmin()) {
            $items[] = html_writer::tag('li', html_writer::link(
                new moodle_url('/local/scorm_incca/users.php'),
                get_string('users_with_caps', 'local_scorm_incca')
            ));
        }

        $this->content->text = html_writer::tag('ul', implode('', $items),
            ['class' => 'list-unstyled mb-0']);

        return $this->content;
    }
}
