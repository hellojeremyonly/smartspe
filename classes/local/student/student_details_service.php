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
 * Student details service for SmartSpe.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_smartspe\local\student;

use moodle_url;

/**
 * Student details service for SmartSpe.
 */
final class student_details_service {
    /**
     * Get the currently published form for a smartspe instance.
     * Optionally redirects back to the course with a notice if none exists.
     *
     * @param int  $smartspeid smartspe instance ID
     * @param int  $courseid course ID
     * @param bool $redirectonmissing whether to redirect if no published form exists
     * @return \stdClass|null The published form record, or null if none exists and no redirect
     */
    public function get_published_form(int $smartspeid, int $courseid, bool $redirectonmissing = true): ?\stdClass {
        global $DB;
        $form = $DB->get_record('smartspe_form', ['smartspeid' => $smartspeid, 'status' => 1]);
        if (!$form && $redirectonmissing) {
            redirect(
                new moodle_url('/course/view.php', ['id' => $courseid]),
                get_string('nopublishedform', 'mod_smartspe'),
                0,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        return $form ?: null;
    }

    /**
     * Ensure a response row exists for this user+form; create if missing.
     *
     * @param int $formid form ID
     * @param int $userid user ID
     * @return \stdClass The response record.
     */
    public function ensure_response(int $formid, int $userid): \stdClass {
        global $DB;
        $response = $DB->get_record('smartspe_response', ['formid' => $formid, 'studentid' => $userid]);
        if ($response) {
            return $response;
        }
        $response = (object)[
            'formid' => $formid,
            'studentid' => $userid,
            'status' => 0, // Draft.
            'detailsjson' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $response->id = $DB->insert_record('smartspe_response', $response);
        return $response;
    }

    /**
     * Build ordered labels for the student details inputs from form config.
     * UC types newline-separated labels; we store/display as plain text.
     *
     * @param \stdClass $form form record
     * @return string[] Indexed array of label texts in order.
     */
    public function build_detail_labels(\stdClass $form): array {
        $labels = [];
        $raw = (string)($form->studentfields ?? '');
        if ($raw === '') {
            return $labels;
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                // Strip ASCII and full-width colons and trailing spaces.
                $line = rtrim($line, " \t:：");
                $labels[] = $line;
            }
        }
        return $labels;
    }

    /**
     * Decode prefill values as a label → value map.
     * Supports back-compat: if legacy ordered array is found, map it onto current labels.
     *
     * @param \stdClass $response response record
     * @param array $currentlabels Indexed array of current label texts (in order).
     * @return array associative map 'Label text' => 'value'
     */
    public function prefill_details(\stdClass $response, array $currentlabels = []): array {
        if (empty($response->detailsjson)) {
            return [];
        }
        $decoded = json_decode((string)$response->detailsjson, true);

        // If it's associative already, return as-is.
        if (is_array($decoded) && array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return $decoded;
        }

        // Map ordered array onto current labels.
        if (is_array($decoded) && $currentlabels) {
            $map = [];
            foreach (array_values($currentlabels) as $i => $label) {
                $map[$label] = $decoded[$i] ?? '';
            }
            return $map;
        }

        return [];
    }

    /**
     * Persist details as a label → value map.
     *
     * @param \stdClass $response response record
     * @param array $labels Indexed array of label texts (current order).
     * @param array $values Indexed array of values aligned with $labels.
     * @return void
     */
    public function save_details_map(\stdClass $response, array $labels, array $values): void {
        global $DB;
        $map = [];
        foreach ($labels as $i => $label) {
            $map[(string)$label] = isset($values[$i]) ? (string)$values[$i] : '';
        }
        $response->detailsjson  = json_encode($map, JSON_UNESCAPED_UNICODE);
        $response->timemodified = time();
        $DB->update_record('smartspe_response', $response);
    }
}
