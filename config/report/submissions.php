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
 * Submissions report script.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

use mod_smartspe\local\report\submissions_service;

$cmid = required_param('id', PARAM_INT);
$formid = required_param('formid', PARAM_INT);
$teamid = optional_param('teamid', 0, PARAM_INT);
$targetid = optional_param('targetid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:manage', $context);

$PAGE->set_url(new moodle_url('/mod/smartspe/config/report/submissions.php', [
  'id' => $cmid, 'formid' => $formid, 'teamid' => $teamid, 'targetid' => $targetid,
]));
$PAGE->set_title(get_string('viewsubmissions', 'mod_smartspe'));
$PAGE->set_heading(format_string($course->fullname));

$backurl = new moodle_url('/mod/smartspe/config/configuration.php', [
  'id' => $cmid,
  'formid' => $formid,
  'teamid' => $teamid,
]);

// Initial payload.
$payload['backurl'] = $backurl->out(false);
/** @var \mod_smartspe\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_smartspe');

// Detail view.
if ($targetid) {
    $payload = submissions_service::detail($cmid, $formid, $targetid);
    $payload['backurl'] = $backurl->out(false);
    echo $OUTPUT->header();
    echo $renderer->render_from_template('mod_smartspe/report/submission_detail', $payload);
    echo $OUTPUT->footer();
    exit;
}

// List view.
$payload = submissions_service::list_targets($cmid, $formid, $teamid);
$payload['backurl'] = $backurl->out(false);
echo $OUTPUT->header();
echo $renderer->render_from_template('mod_smartspe/report/submissions', $payload);
echo $OUTPUT->footer();
