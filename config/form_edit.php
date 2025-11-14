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
 * Form edit page.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../form/config/form_edit_form.php');

use mod_smartspe\local\config\form_edit_service;
use mod_smartspe\output\config\form_edit_page;

$cmid = required_param('id', PARAM_INT);
$formid = optional_param('formid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = \context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:configure', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/config/form_edit.php', ['id' => $cmid, 'formid' => $formid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('formedit', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));

// Initialize service and form.
$svc = new form_edit_service();
$addinrequest = (bool)optional_param('add_question', 0, PARAM_BOOL);
$customdata = $svc->get_customdata_for_form($formid, $addinrequest);

// Create the form.
$mform = new \mod_smartspe_form_edit_form(
    new moodle_url('/mod/smartspe/config/form_edit.php', ['id' => $cmid, 'formid' => $formid]),
    $customdata
);

// Handle archived form case.
if ($formid && $svc->is_archived($formid)) {
    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]),
        get_string('formarchived', 'mod_smartspe'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// If editing an existing form, load its data.
if ($formid && !$mform->is_submitted()) {
    $existing = $svc->prepare_existing_for_edit($formid);
    $mform->set_data($existing);
}

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cmid]));
}

// Process and save data.
if ($data = $mform->get_data()) {
    $savedid = $svc->save_from_mform($data, $cm->instance, $formid ?: null);
    redirect(
        new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]),
        get_string($formid ? 'formupdated' : 'formcreated', 'mod_smartspe')
    );
}

$renderable = new form_edit_page($course->id, $cm->id, $formid ?: null);

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render($renderable);
$mform->display();

echo $OUTPUT->footer();
