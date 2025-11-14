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
 * CSV export script.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_smartspe\local\csv\export_service;

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

$cmid = required_param('id', PARAM_INT);
$formid = required_param('formid', PARAM_INT);
$teamid = required_param('teamid', PARAM_INT);

$cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/smartspe:manage', $context);

// Build rows via service that reuses the UI matrix.
$data = export_service::build_rows($cmid, $formid, $teamid);
$filename = $data['filename'] ?? 'SmartSPE_export';
$rows = $data['rows'] ?? [];

require_once($CFG->libdir . '/csvlib.class.php');

$csv = new csv_export_writer();
$csv->set_filename($filename);
foreach ($rows as $r) {
    $csv->add_data($r);
}
$csv->download_file();
