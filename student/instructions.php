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
 * Student instructions page
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_smartspe\output\student\instructions_page;

require_once(__DIR__ . '/../../../config.php');

$id = required_param('id', PARAM_INT); // Course module id.

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:submit', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/student/instructions.php', ['id' => $cm->id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('instructions', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));

// Get currently published form for this activity (status = 1).
$form = $DB->get_record('smartspe_form', ['smartspeid' => $cm->instance, 'status' => 1], '*', MUST_EXIST);

// Turn the stored instruction into safe HTML output.
$instructionhtml = format_text(
    (string)($form->instruction ?? ''),
    FORMAT_HTML,
    ['context' => $context, 'filter' => true, 'newlines' => true, 'para' => true]
);

$instructionsid = 'instr-' . $cm->id;

$renderable = new instructions_page($instructionhtml, $instructionsid, true);

echo $OUTPUT->header();

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render_instructions_page($renderable);

echo $OUTPUT->footer();
