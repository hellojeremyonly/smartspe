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
 * Configuration page.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');

use mod_smartspe\local\config\configuration_service;
use mod_smartspe\local\report\analysis_service;
use mod_smartspe\output\config\configuration_page;

$id = required_param('id', PARAM_INT);
$formid = optional_param('formid', 0, PARAM_INT);
$teamid = optional_param('teamid', 0, PARAM_INT);
$run = optional_param('run', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/smartspe:configure', $context);

$PAGE->set_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id, 'formid' => $formid, 'teamid' => $teamid]);
$PAGE->set_title(get_string('configurationtitle', 'mod_smartspe'));
$PAGE->set_heading(get_string('configurationtitle', 'mod_smartspe'));

// Forms management table.
$forms = configuration_service::build_forms_for_display($cm, $context, $OUTPUT);

// Report dropdowns.
$formsdropdown = configuration_service::archived_forms_dropdown($cm->instance, $formid);

// Build teams dropdown.
require_once($CFG->dirroot . '/group/lib.php');
$teams = [];
if ($formid > 0) {
    $groups = groups_get_all_groups($course->id, 0, 0, 'g.id, g.name', false) ?: [];
    foreach ($groups as $g) {
        $teams[] = [
          'id' => (int)$g->id,
          'name' => format_string($g->name),
          'selected' => ((int)$g->id === (int)$teamid),
        ];
    }
}

// Build matrix automatically when a form and team are selected.
$matrix = null;
if ($formid > 0 && $teamid > 0 && class_exists(analysis_service::class)) {
    $matrix = analysis_service::build_team_matrix($cm->id, $formid, $teamid);
}

// Export URL (only when both form & team selected).
$exporturl = ($formid > 0 && $teamid > 0)
  ? (new moodle_url('/mod/smartspe/config/csv/export.php', [
    'id' => $cm->id,
    'formid' => $formid,
    'teamid' => $teamid,
  ]))->out(false)
  : '#';

// View submissions URL (only when form selected).
$viewsubmissionsurl = $formid
  ? (new moodle_url(
      '/mod/smartspe/config/report/submissions.php',
      ['id' => $cm->id, 'formid' => $formid] + ($teamid ? ['teamid' => $teamid] : [])
  ))->out(false)
  : '#';

// Construct data to render report section.
$report = [
  'reporturl' => (new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]))->out(false),
  'formid' => (int)$formid,
  'formsdropdown' => $formsdropdown,
  'teams' => $teams,
  'hasformselected' => ($formid > 0),
  'teamselected' => ($formid > 0 && $teamid > 0),
  'canexport' => ($formid > 0 && $teamid > 0),
  'exportcsvurl' => $exporturl,
  'viewsubmissionsurl' => $viewsubmissionsurl,
];

if (!empty($matrix)) {
    $report['matrix'] = $matrix;
}

// Table data for selected form.
$tabledata = ($formid > 0) ? configuration_service::table_headers_for_form($formid) : [];

echo $OUTPUT->header();

$renderable = new configuration_page($forms, $course->id, $cm->id, $formsdropdown, $tabledata, $report);

$renderer = $PAGE->get_renderer('mod_smartspe');
echo $renderer->render($renderable);

echo $OUTPUT->footer();
