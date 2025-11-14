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
 * Peer evaluation page
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use mod_smartspe\local\student\evaluation_service;
use mod_smartspe\local\session\logger;
use mod_smartspe\output\student\peer_evaluation_page;

$id = required_param('id', PARAM_INT);
$tid = optional_param('tid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:submit', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $tid]));
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('peerevaluation', 'mod_smartspe'));
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

// Build list of peer targets for this student.
$targets = $svc->get_peer_targets($course->id, $USER->id);
if (!$targets) {
    redirect(
        course_get_url($course),
        get_string('nopeerstoEvaluate', 'mod_smartspe'),
        0,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Find and validate target id ($tid).
if (!$tid || !$svc->is_valid_peer_target($course->id, $USER->id, $tid)) {
    $tid = $targets[0]['id'];
}

// Start/rollover a session segment for the peer page.
$logger->rollover($response->id);

// Pull only PEER or BOTH questions for this form.
$questions = $svc->get_questions_for_peer($form->id);
if (empty($questions)) {
    $logger->close_open($response->id);
    redirect(
        course_get_url($course),
        get_string('noquestionsconfigured', 'mod_smartspe'),
        0,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Existing answers to prefill the form.
$existing = $svc->get_existing_answers($response->id, $tid);

// Handle POST actions.
$action = optional_param('action', '', PARAM_ALPHA);
if (!empty($action) && confirm_sesskey()) {
    if ($action === 'back') {
        $logger->close_open($response->id);
        $prev = $svc->get_prev_peer_target($course->id, $USER->id, $tid);
        if ($prev !== null) {
            redirect(new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $prev]));
        } else {
            redirect(new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]));
        }
    }

    // Build an answers payload from posted arrays.
    $answers = $svc->build_answers_payload(
        $questions,
        optional_param_array('scale', [], PARAM_INT),
        optional_param_array('text', [], PARAM_RAW_TRIMMED)
    );

    // Persist answers for this teammate ($tid).
    $svc->save_answers_peer($course->id, $form->id, $response->id, $USER->id, $tid, $answers);

    // Close the current segment and then decide where to go.
    $logger->close_open($response->id);

    if ($action === 'draft') {
        // Stay on the same teammate.
        redirect(
            new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $tid]),
            get_string('draftsaved', 'mod_smartspe'),
            0,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'save') {
        $next = $svc->get_next_peer_target($course->id, $USER->id, $tid);
        if ($next !== null) {
            redirect(
                new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $next]),
                get_string('peerevaluationsaved', 'mod_smartspe'),
                0,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            redirect(
                new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $tid]),
                get_string('submitsuccess', 'mod_smartspe'),
                0,
                \core\output\notification::NOTIFY_INFO
            );
        }
    }

    // Final submission.
    if ($action === 'submit') {
        $svc->mark_submitted($response->id);
        redirect(
            course_get_url($course),
            get_string(
                'submitsuccess',
                'mod_smartspe'
            ),
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

// Display name of the peer being evaluated.
$membername = '';
foreach ($targets as $t) {
    if ((int)$t['id'] === (int)$tid) {
        $membername = $t['fullname'];
        break;
    }
}

// Build question viewmodels.
$qvm = $svc->build_question_viewmodels($questions, $existing, $cm->id);

// Check if this is the last peer to evaluate.
$islast = $svc->is_last_peer_target($course->id, $USER->id, $tid);

$posturl = (new moodle_url('/mod/smartspe/student/peer_evaluation.php', ['id' => $cm->id, 'tid' => $tid]))->out(false);
$backurl = (new moodle_url('/mod/smartspe/student/self_evaluation.php', ['id' => $cm->id]))->out(false);

$renderable = new peer_evaluation_page(
    get_string('peerevaluation', 'mod_smartspe'),
    $instructionhtml,
    $posturl,
    $qvm,
    sesskey(),
    $membername,
    $islast,
    'instr-' . $cm->id,
    false,
    $backurl,
    true,
    true,
    'q' . $cm->id
);

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render_peer_evaluation_page($renderable);

echo $OUTPUT->footer();
