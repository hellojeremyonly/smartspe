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
 * CSV upload script.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_smartspe\local\csv\upload_service;
use mod_smartspe\output\csv\upload_page;

require_once(__DIR__ . '/../../../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:manage', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/config/csv/upload.php', ['id' => $id]));
$PAGE->set_title(get_string('upload', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('uploadcsvheading', 'mod_smartspe'));

require_once($CFG->dirroot . '/mod/smartspe/form/csv/upload_form.php');

$mform = new upload_form($PAGE->url, ['id' => $id]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/smartspe/view.php', ['id' => $cm->id]));
}

if ($data = $mform->get_data()) {
    $service = new upload_service($data, $course->id, $cm->id, $context);
    $result = $service->process();
    $renderable = new upload_page($result);
    $renderer = $PAGE->get_renderer('mod_smartspe');

    echo $OUTPUT->header();
    echo $OUTPUT->heading_with_help(
        get_string('uploadcsvheading', 'mod_smartspe'),
        'uploadcsvheading',
        'mod_smartspe'
    );
    echo $renderer->render($renderable);
    echo $OUTPUT->footer();
    return;
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading_with_help(
    get_string('uploadcsvheading', 'mod_smartspe'),
    'uploadcsvheading',
    'mod_smartspe'
);

ob_start();
$mform->display();
$formhtml = ob_get_clean();

$renderable = new upload_page(['formhtml' => $formhtml]);
$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render($renderable);

echo $OUTPUT->footer();
