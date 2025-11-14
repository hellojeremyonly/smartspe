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
 * Student details page
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use mod_smartspe\local\session\logger;
use mod_smartspe\local\student\student_details_service;
use mod_smartspe\output\student\student_details_page;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:submit', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]));
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('studentdetails', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));

// Services and data retrieval.
$svc = new student_details_service();
$form = $svc->get_published_form($cm->instance, $course->id, true);
$response = $svc->ensure_response($form->id, $USER->id);

// Start or rollover session log.
$logger = new logger();
$logger->rollover($response->id);

// Build ordered labels for inputs and heading.
$labels  = $svc->build_detail_labels($form);
$heading = get_string('studentdetailsheading', 'mod_smartspe');

// Prepare instruction accordion content for this page.
$instructionhtml = '';
if (!empty($form->instruction)) {
    $instructionhtml = format_text((string)$form->instruction, FORMAT_HTML, [
      'context' => $context,
      'filter' => true,
      'para' => true,
    ]);
}
$instructionsid = 'instr-' . $cm->id;

// Form include and instance.
require_once($CFG->dirroot . '/mod/smartspe/form/student/student_details_form.php');

$formurl = new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]);
$customdata = ['labels' => $labels];
$mform = new \mod_smartspe_student_details_form($formurl, $customdata);

// Prefill from existing response data.
$prefillmap = $svc->prefill_details($response, $labels);
$mform->set_initial_details_bylabel($prefillmap, $cm->id, $form->id);

// Handle form cancel.
$backtocourseurl = course_get_url($course);
if ($mform->is_cancelled()) {
    $logger->close_open($response->id); // Close any open log on cancel.
    redirect($backtocourseurl);
}

// Process and save submitted data.
if ($data = $mform->get_data()) {
    $submittedlabels = (isset($data->labels) && is_array($data->labels)) ? array_values($data->labels) : [];
    $submittedvalues = (isset($data->details) && is_array($data->details)) ? array_values($data->details) : [];

    $svc->save_details_map($response, $submittedlabels, $submittedvalues);
    $logger->close_open($response->id); // Close any open log on submission.

    $next = new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]);
    redirect($next, get_string('detailssavedsuccess', 'mod_smartspe'), 0, \core\output\notification::NOTIFY_SUCCESS);
}

$renderable = new student_details_page($heading, $instructionhtml, $instructionsid);

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render_student_details_page($renderable);
$mform->display();

echo $OUTPUT->footer();
