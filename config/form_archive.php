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
 * This page handles the archiving of a form.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_smartspe\local\report\student_table_service;

require('../../../config.php');

$id = required_param('id', PARAM_INT);
$formid = required_param('formid', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:manageforms', $context);
require_sesskey();

$form = $DB->get_record('smartspe_form', ['id' => $formid, 'smartspeid' => $cm->instance], '*', MUST_EXIST);

// Only published forms can be archived.
if ((int)$form->status !== 1) {
    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]),
        get_string('formnotpublished', 'mod_smartspe'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Archive the form.
$update = (object)[
    'id' => $form->id,
    'status' => 2,
    'timemodified' => time(),
];
$DB->update_record('smartspe_form', $update);

// Take a snapshot of the current responses for reporting.
try {
    $stats = student_table_service::snapshot_form($cm->id, $form->id);
} catch (\Throwable $e) {
    debugging('student_table_service::snapshot_form failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

redirect(
    new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]),
    get_string('formarchived', 'mod_smartspe'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
