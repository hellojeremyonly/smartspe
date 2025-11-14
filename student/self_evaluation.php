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
 * Self evaluation page
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use mod_smartspe\local\student\evaluation_service;
use mod_smartspe\local\session\logger;
use mod_smartspe\output\student\self_evaluation_page;

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:submit', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]));
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('selfevaluation', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));

// Services.
$svc = new evaluation_service();
$logger = new logger();

// Get published form for this activity instance.
try {
    $form = $svc->get_published_form($cm->instance);
} catch (dml_missing_record_exception $e) {
    redirect(
        course_get_url($course),
        get_string('nopublishedform', 'mod_smartspe'),
        0,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Ensure response record for this student.
$response = $svc->ensure_response($form->id, $USER->id);

// Require that student details step is completed first.
if (!$svc->details_completed($response)) {
    redirect(
        new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]),
        get_string('pleasecompletestudentdetails', 'mod_smartspe'),
        0,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Start/rollover a session segment for this page.
$logger->rollover($response->id);

// Pull only SELF or BOTH questions for this form.
$questions = $svc->get_questions_for_self($form->id);
if (empty($questions)) {
    // Nothing to answer; bounce back with a friendly message.
    $logger->close_open($response->id);
    redirect(
        course_get_url($course),
        get_string('noquestionsconfigured', 'mod_smartspe'),
        0,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Existing answers (to prefill the form).
$existing = $svc->get_existing_answers($response->id, $USER->id);

// Handle POST actions (Back / Draft / Save).
$action = optional_param('action', '', PARAM_ALPHA);
if (!empty($action) && confirm_sesskey()) {
    if ($action === 'back') {
        $logger->close_open($response->id);
        redirect(new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]));
    }
    $answers = $svc->build_answers_payload(
        $questions,
        optional_param_array('scale', [], PARAM_INT),
        optional_param_array('text', [], PARAM_RAW_TRIMMED)
    );

    // Persist answers (draft and save behave the same for storage).
    $svc->save_answers_self($form->id, $response->id, $USER->id, $answers);

    // Close the current segment and redirect accordingly.
    $logger->close_open($response->id);

    if ($action === 'draft') {
        redirect(
            new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]),
            get_string('draftsaved', 'mod_smartspe'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id]),
            get_string('selfevaluationsaved', 'mod_smartspe'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Build instruction accordion content.
$instructionhtml = format_text(
    (string)($form->instruction ?? ''),
    FORMAT_HTML,
    ['context' => $context, 'filter' => true, 'para' => true]
);

// Build question viewmodels.
$qvm = $svc->build_question_viewmodels($questions, $existing, $cm->id);

// Build renderable for template.
$posturl = (new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]))->out(false);
$backurl = (new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]))->out(false);

$renderable = new self_evaluation_page(
    get_string('selfevaluation', 'mod_smartspe'),
    $instructionhtml,
    $posturl,
    $qvm,
    sesskey(),
    'instr-' . $cm->id,
    false,
    $backurl,
    true,
    true,
    'q' . $cm->id
);

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render_self_evaluation_page($renderable);

echo $OUTPUT->footer();
