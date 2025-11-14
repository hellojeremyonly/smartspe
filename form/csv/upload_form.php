<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * * Define CSV upload form.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * CSV upload form.
 */
class upload_form extends moodleform {
    /**
     * Define the form structure.
     *
     * @return void
     * @throws coding_exception If there is an error in the form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'uploadheader', get_string('uploadcsv', 'mod_smartspe'));

        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('selectcsvfile', 'mod_smartspe'),
            null,
            ['accepted_types' => ['.csv'],
              'maxfiles' => 1]
        );
        $mform->addRule('csvfile', null, 'required', null, 'client');

        $mform->addElement('hidden', 'id', $this->_customdata['id'] ?? 0);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('upload', 'mod_smartspe'));
    }
}
