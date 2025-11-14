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
 * Student table report service.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\report;

/**
 * Student table report service.
 */
final class student_table_service {
    /**
     * Snapshot all groups of a course module into the student report table.
     *
     * @param int $cmid Course module ID.
     * @param int $formid Form ID.
     * @param int[]|null $groupids If null, snapshot all course groups.
     * @return array Statistics about the operation.
     */
    public static function snapshot_form(int $cmid, int $formid, ?array $groupids = null): array {
        global $DB;

        $cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;

        // Resolve groups.
        if ($groupids === null) {
            $groups = groups_get_all_groups($courseid, 0, 0, 'g.id');
            $groupids = array_map(static fn($g) => (int)$g->id, $groups);
        }

        $stats = ['totalgroups' => 0, 'totalmembers' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($groupids as $groupid) {
            $stats['totalgroups']++;

            // Get group members.
            $members = groups_get_members($groupid, 'u.id, u.username, u.firstname, u.lastname, u.description');
            if (!$members) {
                continue;
            }

            $stats['totalmembers'] += count($members);

            foreach ($members as $u) {
                $userid = (int)$u->id;

                // Check for existing record.
                $existing = $DB->get_record(
                    'smartspe_user_report',
                    ['formid' => $formid, 'groupid' => $groupid, 'userid' => $userid],
                    'id, title',
                    IGNORE_MISSING
                );

                // Build record.
                $rec = (object)[
                  'courseid' => $courseid,
                  'formid' => $formid,
                  'groupid' => $groupid,
                  'userid' => $userid,
                  'studentid' => (string)$u->username,
                  'surname' => (string)$u->lastname,
                  'givenname' => (string)$u->firstname,
                  'title' => (($t = self::extract_title_from_description($u->description)) !== '' ? $t : ($existing->title ?? '')),
                  'preferredname' => null,
                  'timemodified' => time(),
                ];

                if ($existing) {
                    $rec->id = (int)$existing->id;
                    $DB->update_record('smartspe_user_report', $rec);
                    $stats['updated']++;
                } else {
                    $rec->timecreated = time();
                    $DB->insert_record('smartspe_user_report', $rec);
                    $stats['inserted']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Snapshot a single group of a course module into the student report table.
     *
     * @param int $cmid Course module ID.
     * @param int $formid Form ID.
     * @param int $groupid Group ID.
     * @return int[] Statistics about the operation.
     */
    public static function snapshot_group(int $cmid, int $formid, int $groupid): array {
        return self::snapshot_form($cmid, $formid, [$groupid]);
    }

    /**
     * Extract title from user description.
     *
     * @param string|null $desc User description.
     * @return string Extracted title or empty string.
     */
    private static function extract_title_from_description(?string $desc): string {
        if (empty($desc)) {
            return '';
        }
        if (preg_match('/\bTitle\s*:\s*([A-Za-z\.]{2,10})/i', $desc, $m)) {
            return trim($m[1], " \t\n\r\0\x0B.");
        }
        return '';
    }
}
