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
 * This page handles publishing and unpublishing forms status.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');

$id = required_param('id', PARAM_INT);
$formid = required_param('formid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:manageforms', $context);
require_sesskey();

// Check if the form is archived; archived forms cannot be published again (Status 2 = Archived).
$form = $DB->get_record('smartspe_form', ['id' => $formid], '*', MUST_EXIST);
if ((int)$form->status === 2) {
    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $id]),
        get_string('formarchivednopublish', 'mod_smartspe'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

  // Handle publish action.
if ($action === 'publish') {
    // Unpublish currently published forms.
    $DB->set_field('smartspe_form', 'status', 0, [
        'smartspeid' => $cm->instance,
        'status' => 1,
    ]);

    // Publish the selected form.
    $DB->set_field('smartspe_form', 'status', 1, ['id' => $formid]);

    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $id]),
        get_string('formpublished', 'mod_smartspe')
    );
    // Handle unpublish action.
} else if ($action === 'unpublish') {
    // Set the form back to draft.
    $DB->set_field('smartspe_form', 'status', 0, ['id' => $formid]);
    // Redirect back with confirmation.
    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $id]),
        get_string('formunpublished', 'mod_smartspe')
    );
} else {
    // Invalid action.
    throw new moodle_exception('invalidaction', 'mod_smartspe');
}
