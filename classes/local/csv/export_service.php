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
 * CSV export service for the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\csv;

use mod_smartspe\local\report\analysis_service;

/**
 * Service for exporting team matrix data as CSV.
 */
final class export_service {
    /**
     * Build the rows for CSV export of a team matrix.
     *
     * @param int $cmid Course module id
     * @param int $formid Form id
     * @param int $teamid Team (group) id
     * @return array the filename and rows
     * @throws \moodle_exception when no data to export
     */
    public static function build_rows(int $cmid, int $formid, int $teamid): array {
        global $DB;

        // Load necessary records.
        $cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $form = $DB->get_record('smartspe_form', ['id' => $formid], 'id, title', MUST_EXIST);
        $group = $DB->get_record('groups', ['id' => $teamid], 'id, name', MUST_EXIST);

        // Reuse the UI matrix exactly.
        $m = analysis_service::build_team_matrix($cmid, $formid, $teamid);

        $targets = $m['targets'] ?? [];
        $roster = $m['roster'] ?? [];
        if (empty($targets)) {
            throw new \moodle_exception('Nothing to export (no targets). Archive snapshot and/or select a valid team.');
        }

        // Number of criteria per block from the first target.
        $qcount = count($targets[0]['criteria'] ?? []);
        if ($qcount < 1) {
            throw new \moodle_exception('Nothing to export (no questions in this form).');
        }

        $rows = [];

        // Student name for each block in first row.
        $r1 = ['', '', '', '', ''];
        foreach ($targets as $t) {
            $label = (string)($t['name'] ?? '');
            $r1[] = $label;
            for ($i = 0; $i < $qcount; $i++) {
                $r1[] = '';
            }
        }
        $rows[] = $r1;

        // Necessary header in the second row.
        $r2 = ['Team', 'Student ID', 'Surname', 'Title', 'Given Name'];
        foreach ($targets as $t) {
            for ($i = 1; $i <= $qcount; $i++) {
                  $r2[] = (string)$i;
            }
            $r2[] = 'Average from each';
        }
        $rows[] = $r2;

        // One student per row with their scores.
        foreach ($roster as $r) {
            $row = [
              (string)($r['team'] ?? ''),
              (string)($r['studentid'] ?? ''),
              (string)($r['surname'] ?? ''),
              (string)($r['title'] ?? ''),
              (string)($r['givenname'] ?? ''),
            ];

            $blocks = $r['blocks'] ?? [];
            foreach ($blocks as $b) {
                $scores = $b['scores'] ?? [];
                for ($i = 0; $i < $qcount; $i++) {
                    $row[] = array_key_exists($i, $scores) ? (string)$scores[$i] : '';
                }
                $row[] = (string)($b['average'] ?? '');
            }

            $rows[] = $row;
        }

        // Final row with average per criterion and overall.
        $footer = ['', '', '', '', 'Average of criteria'];
        foreach ($targets as $t) {
            $percrit = $t['avgpercriterion'] ?? [];
            for ($i = 0; $i < $qcount; $i++) {
                $footer[] = array_key_exists($i, $percrit) ? (string)$percrit[$i] : '';
            }
            $footer[] = (string)($t['overall'] ?? '');
        }
        $rows[] = $footer;

        // Build filename for export.
        $teamshort = preg_replace('/[^A-Za-z0-9_-]+/', '', $group->name ?: 'team');
        $filename  = "SmartSPE_Team_Result_{$teamshort}";
        return ['filename' => $filename, 'rows' => $rows];
    }
}
