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
 * Evaluation service for SmartSpe.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\student;

/**
 * Evaluation service for SmartSpe.
 *
 * Handles retrieval and saving of forms, questions, answers, and responses.
 */
final class evaluation_service {
    /** Audience constants for questions. */
    public const AUDIENCE_BOTH = 1;
    /** Audience constant for self-evaluation questions. */
    public const AUDIENCE_SELF = 2;
    /** Audience constant for peer-evaluation questions. */
    public const AUDIENCE_PEER = 3;
    /** Response status constants. */
    public const RESPONSE_STATUS_DRAFT = 0;
    /** Response status constant for submitted responses. */
    public const RESPONSE_STATUS_SUBMITTED = 1;

    /**
     * Get the published form for the given SmartSpe instance.
     *
     * @param int $smartspeid The SmartSpe instance ID.
     * @return \stdClass The published form record.
     * @throws \dml_exception If the form does not exist.
     */
    public function get_published_form(int $smartspeid): \stdClass {
        global $DB;
        return $DB->get_record('smartspe_form', ['smartspeid' => $smartspeid, 'status' => 1], '*', MUST_EXIST);
    }

    /**
     * Ensure a response record exists for the given form and user.
     *
     * @param int $formid Form ID
     * @param int $userid User ID
     * @return \stdClass The existing or newly created response record.
     * @throws \dml_exception If a database error occurs.
     */
    public function ensure_response(int $formid, int $userid): \stdClass {
        global $DB;
        $response = $DB->get_record('smartspe_response', [
          'formid' => $formid, 'studentid' => $userid,
        ]);
        if ($response) {
            return $response;
        }

        $now = time();
        $rec = (object)[
          'formid' => $formid,
          'studentid' => $userid,
          'status' => 0,
          'timecreated' => $now,
          'timemodified' => $now,
        ];
        $rec->id = $DB->insert_record('smartspe_response', $rec);
        return $rec;
    }

    /**
     * Check if the details section of a response is completed.
     *
     * @param \stdClass $response The response record.
     * @return bool True if details are completed, false otherwise.
     */
    public function details_completed(\stdClass $response): bool {
        return trim((string)($response->detailsjson ?? '')) !== '';
    }

    /**
     * Get all questions for the given form.
     *
     * @param int $formid Form ID
     * @return array List of question records
     * @throws \dml_exception If a database error occurs.
     */
    public function get_questions(int $formid): array {
        global $DB;
        return array_values($DB->get_records('smartspe_question', ['formid' => $formid], 'id ASC'));
    }

    /**
     * Questions visible on the Self-evaluation page (BOTH or SELF).
     *
     * @param int $formid Form ID
     * @return array List of question records
     */
    public function get_questions_for_self(int $formid): array {
        return $this->get_questions_by_audience($formid, [
            self::AUDIENCE_BOTH,
            self::AUDIENCE_SELF,
        ]);
    }

    /**
     * Questions visible on the Peer-evaluation page (BOTH or PEER).
     *
     * @param int $formid Form ID
     * @return array List of question records
     */
    public function get_questions_for_peer(int $formid): array {
        return $this->get_questions_by_audience($formid, [
            self::AUDIENCE_BOTH,
            self::AUDIENCE_PEER,
        ]);
    }

    /**
     * Get questions for the given form filtered by audience.
     *
     * @param int $formid Form ID
     * @param array $audiences List of audience constants to include
     * @return array List of question records
     * @throws \dml_exception If a database error occurs.
     */
    private function get_questions_by_audience(int $formid, array $audiences): array {
        global $DB;

        // Build named placeholders for the IN() clause.
        $placeholder = [];
        $params = ['formid' => $formid];
        foreach (array_values($audiences) as $i => $aud) {
            $key = 'aud' . $i;
            $placeholder[] = ':' . $key;
            $params[$key] = (int)$aud;
        }

        // Allow for legacy rows where audience may be NULL or 0 (treat as BOTH).
        $where = 'formid = :formid AND ((audience IS NULL) OR (audience = 0)';
        if (!empty($placeholder)) {
            $where .= ' OR audience IN (' . implode(',', $placeholder) . ')';
        }
        $where .= ')';

        return array_values($DB->get_records_select('smartspe_question', $where, $params, 'id ASC'));
    }

    /**
     * Get existing answers for a given response and target.
     *
     * @param int $responseid Response ID
     * @param int $targetid Target ID
     * @return array Map of questionid => answer record
     * @throws \dml_exception If a database error occurs.
     */
    public function get_existing_answers(int $responseid, int $targetid): array {
        global $DB;
        $answers = $DB->get_records('smartspe_answer', [
          'responseid' => $responseid,
          'targetid' => $targetid,
        ], '', 'id,questionid,scalevalue,textvalue');

        // Map by questionid for quick lookup.
        $byqid = [];
        foreach ($answers as $a) {
            $byqid[(int)$a->questionid] = $a;
        }
        return $byqid;
    }

    /**
     * Build answers payload from posted data.
     *
     * @param array $questions List of question records
     * @param array|null $postedscale List of posted scale answers
     * @param array|null $postedtext List of posted text answers
     * @return array Payload of answers
     */
    public function build_answers_payload(array $questions, ?array $postedscale, ?array $postedtext): array {
        $payload = [];
        $postedscale = $postedscale ?? [];
        $postedtext = $postedtext ?? [];

        foreach ($questions as $q) {
            $qid = (int)$q->id;
            $qtype = (int)$q->questiontype;
            $row = ['type' => $qtype];

            if ($qtype === 1 && array_key_exists($qid, $postedscale)) {
                $row['scale'] = (int)$postedscale[$qid];
            }
            if ($qtype === 2 && array_key_exists($qid, $postedtext)) {
                $row['text'] = (string)$postedtext[$qid];
            }

            // Only include if something was actually submitted for this question.
            if (count($row) > 1) {
                $payload[$qid] = $row;
            }
        }
        return $payload;
    }

    /**
     * Save/Upsert answers for self (with validation, clamping, transaction).
     *
     * @param int $formid Form ID
     * @param int $responseid Response ID
     * @param int $userid User ID
     * @param array $answers Answers payload
     * @return void
     */
    public function save_answers_self(int $formid, int $responseid, int $userid, array $answers): void {
        $this->save_answers(0, $formid, $responseid, $userid, $answers, null, self::AUDIENCE_SELF);
    }

    /**
     * Save/Upsert answers for peer (with validation, clamping, transaction).
     *
     * @param int $courseid Course ID
     * @param int $formid Form ID
     * @param int $responseid Response ID
     * @param int $evaluatorid Evaluator User ID
     * @param int $targetid Target User ID
     * @param array $answers Answers payload
     * @return void
     */
    public function save_answers_peer(
        int $courseid,
        int $formid,
        int $responseid,
        int $evaluatorid,
        int $targetid,
        array $answers
    ): void {
        $this->save_answers($courseid, $formid, $responseid, $evaluatorid, $answers, $targetid, self::AUDIENCE_PEER);
    }

    /**
     * Save/Upsert answers (with validation, clamping, transaction).
     *
     * @param int $courseid Course ID
     * @param int $formid Form ID
     * @param int $responseid Response ID
     * @param int $evaluatorid Evaluator user ID
     * @param array $answers Answers payload
     * @param int|null $targetid Target user ID (for peer evaluations)
     * @param int $audience Audience constant
     * @return void
     * @throws \dml_exception If the form does not exist.
     * @throws \dml_transaction_exception If a database error occurs.
     */
    public function save_answers(
        int $courseid,
        int $formid,
        int $responseid,
        int $evaluatorid,
        array $answers,
        ?int $targetid = null,
        int $audience = self::AUDIENCE_SELF
    ): void {
        global $DB;

        // Which questions are allowed?
        $typemap = ($audience === self::AUDIENCE_PEER)
          ? $this->peer_question_type_map($formid)
          : $this->self_question_type_map($formid);

        if (empty($typemap) || empty($answers)) {
            return;
        }

        // Resolve the target being evaluated.
        $target = $evaluatorid;
        if ($audience === self::AUDIENCE_PEER) {
            if (empty($targetid) || $targetid === $evaluatorid) {
                return;
            }

            // Validate that target is a teammate.
            $allowed = array_column($this->get_team_members($courseid, $evaluatorid), 'id');
            $allowed = array_map('intval', $allowed);
            $target  = (int)$targetid;

            // If invalid target, abort silently.
            if ($allowed && !in_array($target, $allowed, true)) {
                return;
            }
            $target = (int)$targetid;
        }

        $tx = $DB->start_delegated_transaction();

        // Existing answers for this response about $target.
        $existing = $this->get_existing_answers($responseid, $target);

        foreach ($answers as $qid => $payload) {
            if (!isset($typemap[$qid])) {
                continue;
            }

            $qtype = (int)$typemap[$qid];
            $hasscale = array_key_exists('scale', $payload);
            $hastext = array_key_exists('text', $payload);

            // Skip if nothing to change for this question.
            if (!$hasscale && !$hastext) {
                continue;
            }

            $scale = null;
            $text  = null;

            if ($qtype === 1 && $hasscale) {
                $val = (int)$payload['scale'];
                if ($val >= 1 && $val <= 5) {
                    $scale = $val;
                }
            }
            if ($qtype === 2 && $hastext) {
                $text = (string)$payload['text'];
            }

            if (isset($existing[$qid])) {
                $row = $existing[$qid];
                if ($hasscale) {
                    $row->scalevalue = $scale;
                }
                if ($hastext) {
                    $row->textvalue = $text;
                }
                $DB->update_record('smartspe_answer', $row);
            } else {
                // Only insert if at least one field is supplied.
                $DB->insert_record('smartspe_answer', (object)[
                  'responseid' => $responseid,
                  'questionid' => $qid,
                  'targetid' => $target,
                  'scalevalue' => $hasscale ? $scale : null,
                  'textvalue' => $hastext ? $text : null,
                ]);
            }
        }

        // Update response modified time.
        $DB->set_field('smartspe_response', 'timemodified', time(), ['id' => $responseid]);
        $tx->allow_commit();
    }


    /**
     * Get a map of question IDs to their types for self-evaluation questions.
     *
     * @param int $formid Form ID
     * @return array Map of questionid => questiontype
     */
    private function self_question_type_map(int $formid): array {
        $rows = $this->get_questions_for_self($formid);
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->id] = (int)($r->questiontype ?? 1);
        }
        return $map;
    }

    /**
     * Get a map of question IDs to their types for peer-evaluation questions.
     *
     * @param int $formid Form ID
     * @return array Map of questionid => questiontype
     */
    private function peer_question_type_map(int $formid): array {
        $rows = $this->get_questions_for_peer($formid);
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->id] = (int)($r->questiontype ?? 1);
        }
        return $map;
    }

    /**
     * Build question view models for rendering.
     *
     * @param array $questions List of question records
     * @param array $existing Existing answers map (questionid => answer record)
     * @param int $cmid Course module ID
     * @return array List of question view models
     * @throws \coding_exception If an invalid question type is encountered.
     */
    public function build_question_viewmodels(array $questions, array $existing, int $cmid): array {
        $idprefix = 'q' . $cmid;
        $qvm = [];
        $index = 1;

        foreach ($questions as $q) {
            $qid = (int)$q->id;
            $qtype = (int)$q->questiontype;
            $row = $existing[$qid] ?? null;

            $groupname = "scale[$qid]";

            $options = [];
            if ($qtype === 1) {
                foreach ([1, 2, 3, 4, 5] as $v) {
                    $options[] = [
                      'value' => $v,
                      'checked' => ($row && $row->scalevalue !== null && (int)$row->scalevalue === $v),
                      'inputid' => "q{$cmid}-scale-{$qid}-{$v}",
                    ];
                }
            }

            $qvm[] = [
              'id' => $qid,
              'number' => $index,
              'qhtml' => format_text((string)($q->questiontext ?? ''), FORMAT_HTML, ['filter' => true, 'para' => false]),
              'isScale' => ($qtype === 1),
              'isText' => ($qtype === 2),
              'scalevalue' => ($row && $row->scalevalue !== null) ? (int)$row->scalevalue : null,
              'textvalue' => ($row && $row->textvalue !== null) ? (string)$row->textvalue : '',
              'groupname' => $groupname,
              'options' => $options,
            ];

            $index++;
        }

        return $qvm;
    }

    /**
     * Get team members for the given user in the course (excluding self).
     *
     * @param int $courseid Course ID
     * @param int $userid User ID
     * @return array List of user records
     */
    public function get_team_members(int $courseid, int $userid): array {
        $groups = groups_get_user_groups($courseid, $userid);
        if (empty($groups[0])) {
            return [];
        }
        $groupid = reset($groups[0]);
        $members = groups_get_members(
            $groupid,
            'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename'
        );

        // Remove self from members.
        unset($members[$userid]);
        return array_values($members);
    }

    /**
     * Mark the response as submitted.
     *
     * @param int $responseid Response ID
     * @return void
     * @throws \dml_exception If a database error occurs.
     */
    public function mark_submitted(int $responseid): void {
        global $DB;
        $DB->update_record('smartspe_response', (object)[
            'id' => $responseid,
            'status' => self::RESPONSE_STATUS_SUBMITTED,
            'timemodified' => time(),
        ]);
    }

    /**
     * Get peer evaluation targets (teammates) for the given evaluator in the course.
     *
     * @param int $courseid Course ID
     * @param int $evaluatorid Evaluator User ID
     * @return array List of targets with 'id' and 'fullname'
     */
    public function get_peer_targets(int $courseid, int $evaluatorid): array {
        $members = $this->get_team_members($courseid, $evaluatorid);
        // Normalise & stable order by fullname then id.
        $targets = array_map(static function ($u) {
            return [
              'id' => (int)$u->id,
              'fullname' => fullname($u),
            ];
        }, $members);
        usort($targets, static function ($a, $b) {
            return [$a['fullname'], $a['id']] <=> [$b['fullname'], $b['id']];
        });
        return $targets;
    }

    /**
     * Check if $targetid is a valid peer target for $evaluatorid in $courseid.
     *
     * @param int $courseid Course ID
     * @param int $evaluatorid Evaluator user ID
     * @param int $targetid Target user ID
     * @return bool True if $targetid is a valid peer target for $evaluatorid in $courseid.
     */
    public function is_valid_peer_target(int $courseid, int $evaluatorid, int $targetid): bool {
        foreach ($this->get_peer_targets($courseid, $evaluatorid) as $t) {
            if ($t['id'] === $targetid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the next peer target ID after the current one.
     *
     * @param int $courseid Course ID
     * @param int $evaluatorid Evaluator user ID
     * @param int|null $currenttargetid Current target user ID
     * @return int|null Next target user ID, or null if none
     */
    public function get_next_peer_target(int $courseid, int $evaluatorid, ?int $currenttargetid): ?int {
        $targets = $this->get_peer_targets($courseid, $evaluatorid);
        if (!$targets) {
            return null;
        }
        if ($currenttargetid === null) {
            return $targets[0]['id'];
        }
        foreach ($targets as $i => $t) {
            if ($t['id'] === $currenttargetid) {
                return $targets[$i + 1]['id'] ?? null;
            }
        }
        return null;
    }

    /**
     * Check if the given target is the last peer target for the evaluator in the course.
     *
     * @param int $courseid Course ID
     * @param int $evaluatorid Evaluator user ID
     * @param int $targetid Target user ID
     * @return bool True if $targetid is the last peer target for $evaluatorid in $courseid.
     */
    public function is_last_peer_target(int $courseid, int $evaluatorid, int $targetid): bool {
        $targets = $this->get_peer_targets($courseid, $evaluatorid);
        return $targets && end($targets)['id'] === $targetid;
    }

    /**
     * Get the previous peer target ID before the current one.
     *
     * @param int $courseid Course ID
     * @param int $evaluatorid Evaluator user ID
     * @param int $currenttargetid Current target user ID
     * @return int|null Previous target user ID, or null if none
     */
    public function get_prev_peer_target(int $courseid, int $evaluatorid, int $currenttargetid): ?int {
        $targets = $this->get_peer_targets($courseid, $evaluatorid);
        if (!$targets) {
            return null;
        }
        $ids = array_column($targets, 'id');
        $pos = array_search($currenttargetid, $ids, true);
        if ($pos === false) {
            return $ids[0];
        }
        return $ids[$pos - 1] ?? null;
    }
}
