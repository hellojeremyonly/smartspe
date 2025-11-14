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
 * Form edit service for the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\config;

/**
 * Service for form edit operations (load, prepare, save).
 */
final class form_edit_service {
    /**
     * Check if a form is archived.
     *
     * @param int $formid
     * @return bool True if archived, false otherwise.
     * @throws \dml_exception if DB error occurs.
     */
    public function is_archived(int $formid): bool {
        global $DB;
        if (!$form = $DB->get_record('smartspe_form', ['id' => $formid], 'id,status')) {
            return false;
        }
        return ((int)$form->status === 2); // 2 = archived.
    }

    /**
     * Build custom data used by the moodle form repeat_elements configuration.
     *
     * @param int $formid Existing form id (0 for new).
     * @param bool $addinrequest Whether the current request pressed "add question".
     * @return array Custom data for the mform constructor.
     * @throws \dml_exception if DB error occurs.
     */
    public function get_customdata_for_form(int $formid, bool $addinrequest): array {
        global $DB;
        $customdata = [];
        if ($formid > 0) {
            $questions = $DB->get_records('smartspe_question', ['formid' => $formid]);
            $questioncount = $questions ? count($questions) : 0;
            if ($addinrequest) {
                $questioncount++;
            }
            $customdata['repeatcount'] = max(2, $questioncount);
            $customdata['questions'] = $questions ? array_values($questions) : [];
        }

        return $customdata;
    }

    /**
     * Prepare an existing form record (including editor fields and question arrays) for editing.
     *
     * @param int $formid Existing form id.
     * @return \stdClass Prepared form data for moodle form.
     */
    public function prepare_existing_for_edit(int $formid): \stdClass {
        global $DB;

        $existing = $DB->get_record('smartspe_form', ['id' => $formid], '*', MUST_EXIST);

        // Editor fields back to arrays.
        $existing->instruction = [
            'text' => $existing->instruction ?? '',
            'format' => FORMAT_HTML,
        ];
        $existing->studentfields = [
            'text' => $existing->studentfields ?? '',
            'format' => FORMAT_HTML,
        ];

        // Load questions and convert to editor arrays + types.
        $questions = $DB->get_records('smartspe_question', ['formid' => $formid]);
        if ($questions) {
            $questions = array_values($questions);
            $existing->questiontext = array_map(function ($q) {
                return [
                    'text' => (string)$q->questiontext,
                    'format' => FORMAT_HTML,
                ];
            }, $questions);
            // Map types safely even if 'questiontype' is missing on some records.
            $existing->questiontype = array_map(static fn($q) => (int)($q->questiontype ?? 1), $questions);
            // Map audience safely even if 'audience' is missing on some records.
            $existing->audience = array_map(static fn($q) => (int)($q->audience ?? 3), $questions);
        } else {
            $existing->questiontext = [];
            $existing->questiontype = [];
            $existing->audience = [];
        }

        $existing->formid = $formid;
        return $existing;
    }

    /**
     * Persist data from the moodleform into DB (create or update + questions).
     *
     * @param \stdClass $data Raw $mform->get_data() result.
     * @param int $instanceid $cm->instance (smartspe id)
     * @param int|null $formid Existing form id or null for new.
     * @return int The form id saved.
     */
    public function save_from_mform(\stdClass $data, int $instanceid, ?int $formid = null): int {
        global $DB;

        // Normalise editor fields to plain text.
        if (is_array($data->instruction ?? null)) {
            $data->instruction = $data->instruction['text'] ?? '';
        }
        // Normalise editor fields to plain text.
        if (is_array($data->studentfields ?? null)) {
            $data->studentfields = $data->studentfields['text'] ?? '';
        }

        // Update existing form record.
        if ($formid) {
            global $DB;
            // Ensure the form exists; throws dml_missing_record_exception if not.
            $DB->get_record('smartspe_form', ['id' => $formid], 'id', MUST_EXIST);
            $record = (object)[
              'id' => $formid,
              'title' => (string)($data->title ?? ''),
              'instruction' => (string)($data->instruction ?? ''),
              'studentfields' => (string)($data->studentfields ?? ''),
              'timemodified' => time(),
            ];
            $DB->update_record('smartspe_form', $record);

            // Replace questions and keep ordering consistent.
            $DB->delete_records('smartspe_question', ['formid' => $formid]);
            $this->insert_questions_from_data($formid, $data);
            return $formid;
        } else {
            // Create new form record first, then insert questions.
            $formid = $this->create_form_record($instanceid, $data);
            $this->insert_questions_from_data($formid, $data);
            return $formid;
        }
    }

    /**
     * Helper to insert questions from the moodleform data.
     *
     * @param int $formid
     * @param \stdClass $data
     * @return void
     */
    private function insert_questions_from_data(int $formid, \stdClass $data): void {
        if (empty($data->questiontext)) {
            return;
        }

        // Arrays coming from the repeated elements.
        $audiences = $data->audience ?? [];
        $types = $data->questiontype ?? [];

        foreach ($data->questiontext as $index => $qtext) {
            // Extract editor array to plain text if needed.
            if (is_array($qtext) && isset($qtext['text'])) {
                $qtext = $qtext['text'];
            }

            $qtext = trim((string)$qtext);

            // Skip empty question.
            if ($qtext === '') {
                continue;
            }

            // Set question type.
            $qtype = (int)($types[$index] ?? 1);

            // Set audience.
            $audience = (int)($audiences[$index] ?? 3);

            $this->insert_question($formid, $qtext, $qtype, $audience);
        }
    }

    /**
     * Create a new form record.
     *
     * @param int $instanceid
     * @param \stdClass $data
     * @return int
     * @throws \dml_exception
     */
    private function create_form_record(int $instanceid, \stdClass $data): int {
        global $DB;
        $record = (object)[
          'smartspeid' => $instanceid,
          'title' => (string)($data->title ?? ''),
          'instruction' => (string)($data->instruction ?? ''),
          'studentfields' => (string)($data->studentfields ?? ''),
          'status' => 0,
          'timecreated' => time(),
          'timemodified' => time(),
        ];
        return $DB->insert_record('smartspe_form', $record);
    }

    /**
     * Insert a question record.
     *
     * @param int $formid
     * @param string $text
     * @param int $type
     * @param int $audience
     * @return void
     * @throws \dml_exception
     */
    private function insert_question(int $formid, string $text, int $type, int $audience): void {
        global $DB;
        $DB->insert_record('smartspe_question', (object)[
          'formid' => $formid,
          'questiontext' => $text,
          'questiontype' => $type,
          'audience' => $audience,
        ]);
    }
}
