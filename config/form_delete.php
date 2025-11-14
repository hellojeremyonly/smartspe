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
 * This page handles the deletion of a form.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;

require_once(__DIR__ . '/../../../config.php');

$cmid = required_param('id', PARAM_INT);
$formid = required_param('formid', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:manageforms', $context);
require_sesskey();

$PAGE->set_url('/mod/smartspe/config/form_delete.php', ['id' => $cmid, 'formid' => $formid]);
$PAGE->set_context($context);
$PAGE->set_heading(get_string('pluginname', 'mod_smartspe'));

global $DB;

// Load the form and make sure it belongs to this activity instance.
$form = $DB->get_record('smartspe_form', ['id' => $formid, 'smartspeid' => $cm->instance], '*', MUST_EXIST);

// Do not allow deleting a published form (status = 1).
if ((int)$form->status === 1) {
    $redirecturl = new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cmid]);
    redirect($redirecturl, get_string(
        'cannotdeletepublished',
        'mod_smartspe',
        null,
        true
    ) ?: 'Cannot delete a published form.', null, notification::NOTIFY_WARNING);
}

// Begin transaction.
$transaction = $DB->start_delegated_transaction();

// Collect all response ids for this form.
$responseids = $DB->get_fieldset_select('smartspe_response', 'id', 'formid = ?', [$formid]);

// If there are responses, delete related data first.
if (!empty($responseids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($responseids, SQL_PARAMS_QM);

    // Delete sessions.
    $DB->delete_records_select('smartspe_response_session', "responseid $insql", $inparams);

    // Delete answers.
    $DB->delete_records_select('smartspe_answer', "responseid $insql", $inparams);

    // Delete responses.
    $DB->delete_records('smartspe_response', ['formid' => $formid]);
}

// Delete questions.
$DB->delete_records('smartspe_question', ['formid' => $formid]);

// Delete form record.
$DB->delete_records('smartspe_form', ['id' => $formid]);

// Commit transaction.
$transaction->allow_commit();

// Redirect back to the configuration page.
$redirecturl = new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cmid]);
redirect($redirecturl, get_string('formdeletedsuccess', 'mod_smartspe'), null, notification::NOTIFY_SUCCESS);
