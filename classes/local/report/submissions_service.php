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
 * Submissions listing report service.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\report;

/**
 * Submissions listing report service.
 */
final class submissions_service {
    /**
     * List student targets from a form snapshot.
     *
     * @param int $cmid Course module ID
     * @param int $formid Form ID
     * @param int $teamid (Optional) Group ID to filter by, 0 = all groups
     * @return int[] List of student targets with view URLs
     * @throws \coding_exception if cmid or formid are invalid
     * @throws \core\exception\moodle_exception if course module or course do not exist
     * @throws \dml_exception if database errors occur
     */
    public static function list_targets(int $cmid, int $formid, int $teamid = 0): array {
        global $DB;

        // Validate cm & course.
        $cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // Pull roster snapshot for this form; optionally filter by group.
        $where = ['formid' => $formid];
        if ($teamid > 0) {
            $where['groupid'] = $teamid;
        }
        $rows = $DB->get_records(
            'smartspe_user_report',
            $where,
            'surname ASC,
            givenname ASC',
            'id,
            userid,
            studentid,
            surname,
            givenname,
            title,
            groupid'
        );

        if (!$rows) {
            return [
                'cmid' => $cmid,
                'formid' => $formid,
                'teamid' => $teamid,
                'hasrows' => false,
                'emptystate' => 'nosnapshot',
            ];
        }

        // Preload group names used by the snapshot rows.
        $groupnames = [];
        if ($teamid > 0) {
            $g = groups_get_group($teamid, 'id, name', MUST_EXIST);
            $groupnames[$teamid] = (string)$g->name;
        } else {
            $ids = array_unique(array_map(static fn($r) => (int)$r->groupid, $rows));
            if (!empty($ids)) {
                [$in, $p] = $DB->get_in_or_equal($ids, SQL_PARAMS_QM);
                $rs = $DB->get_records_select('groups', "id $in", $p, '', 'id, name');
                foreach ($rs as $g) {
                    $groupnames[(int)$g->id] = (string)$g->name;
                }
            }
        }

        $list = [];
        foreach ($rows as $r) {
            $fullname = trim(($r->givenname ?? '') . ' ' . ($r->surname ?? ''));
            if (!empty($r->title)) {
                $fullname = $r->title . ' ' . $fullname;
            }
            $list[] = [
              'userid' => (int)$r->userid,
              'studentid' => (string)$r->studentid,
              'fullname' => $fullname === '' ? get_string('noname', 'mod_smartspe') : $fullname,
              'groupname' => $groupnames[(int)$r->groupid] ?? '',
              'viewurl' => (new \moodle_url('/mod/smartspe/config/report/submissions.php', [
                'id' => $cmid, 'formid' => $formid, 'teamid' => $teamid, 'targetid' => (int)$r->userid,
              ]))->out(false),
            ];
        }

        return [
          'cmid' => $cmid,
          'formid' => $formid,
          'teamid' => $teamid,
          'hasrows' => true,
          'rows' => $list,
        ];
    }

    /**
     * Get detailed submission report for a specific student target.
     *
     * @param int $cmid Course module ID
     * @param int $formid Form ID
     * @param int $targetid Student user ID
     * @return array Detailed report data
     * @throws \coding_exception if cmid or formid are invalid
     * @throws \dml_exception if database errors occur
     */
    public static function detail(int $cmid, int $formid, int $targetid): array {
        global $DB;

        // Validate cm & course.
        $cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // Get student snapshot entry for this form & student.
        $snap = $DB->get_record(
            'smartspe_user_report',
            ['formid' => $formid, 'userid' => $targetid],
            'studentid,
            surname,
            givenname,
            title,
            groupid',
            IGNORE_MISSING
        );

        $u = $DB->get_record('user', ['id' => $targetid], 'id, username, firstname, lastname', IGNORE_MISSING);

        $studentid = $snap->studentid ?? ($u->username ?? '');
        $surname = $snap->surname ?? ($u->lastname ?? '');
        $givenname = $snap->givenname ?? ($u->firstname ?? '');
        $title = $snap->title ?? '';
        $groupid = $snap->groupid ?? 0;

        $groupname = '';
        if ($groupid) {
            $g = groups_get_group($groupid, 'id, name', IGNORE_MISSING);
            $groupname = $g ? (string)$g->name : '';
        }

        // Form info.
        $form = $DB->get_record('smartspe_form', ['id' => $formid], 'id, title, studentfields', MUST_EXIST);

        // Get all questions for this form.
        $qs   = $DB->get_records('smartspe_question', ['formid' => $formid], 'id ASC', 'id, questiontext');

        // Get the student's response if there is any.
        $response = $DB->get_record(
            'smartspe_response',
            ['formid' => $formid, 'studentid' => $targetid],
            'id,
            timecreated,
            timemodified,
             detailsjson',
            IGNORE_MISSING
        );

        // Self section.
        $qrows = [];
        if ($qs) {
            $answersbyquestions = [];
            if ($response) {
                $ans = $DB->get_records(
                    'smartspe_answer',
                    ['responseid' => $response->id, 'targetid' => $targetid],
                    'questionid ASC',
                    'id,
                    questionid,
                    scalevalue,
                     textvalue'
                );
                foreach ($ans as $a) {
                    $answersbyquestions[(int)$a->questionid] = $a;
                }
            }

            $i = 0;
            foreach ($qs as $qid => $q) {
                $i++;
                $row = ['num' => $i, 'question' => format_string($q->questiontext)];
                if (isset($answersbyquestions[$qid])) {
                    $a = $answersbyquestions[$qid];
                    if ($a->scalevalue !== null) {
                        $row['value'] = self::fmt((float)$a->scalevalue);
                    } else {
                        $row['text'] = trim((string)($a->textvalue ?? ''));
                    }
                } else {
                    $row['value'] = '';
                }
                $qrows[] = $row;
            }
        }

        // Peer section.
        // Get all peers from the snapshot for this form & group.
        $peersnap = $DB->get_records(
            'smartspe_user_report',
            ['formid' => $formid, 'groupid' => $groupid],
            '',
            'id,
            userid,
            surname,
            givenname,
            title'
        );

        $peerlist = [];
        foreach ($peersnap as $row) {
            $uid = (int)$row->userid;
            if ($uid && $uid !== (int)$targetid) {
                $peerlist[$uid] = (object)[
                    'userid' => $uid,
                    'surname' => $row->surname ?? '',
                    'givenname' => $row->givenname ?? '',
                    'title' => $row->title ?? '',
                ];
            }
        }

        // If no snapshot peers, try to get from actual responses.
        if (empty($peerlist) && $response) {
            $targets = $DB->get_records_sql_menu(
                "SELECT DISTINCT a.targetid, a.targetid FROM {smartspe_answer} a WHERE a.responseid = ?",
                [$response->id]
            );
            if ($targets) {
                $uids = array_map('intval', array_keys($targets));
                [$in, $p] = $DB->get_in_or_equal($uids, SQL_PARAMS_QM);

                // Try to get peer details from snapshot first.
                $snappeers = $DB->get_records_select(
                    'smartspe_user_report',
                    "formid = ? AND userid $in",
                    array_merge([$formid], $p),
                    '',
                    'userid,
                    surname,
                    givenname,
                    title'
                );

                // Fallback to user table if needed.
                $userpeers = [];
                if (count($snappeers) !== count($uids)) {
                    $userpeers = $DB->get_records_select('user', "id $in", $p, '', 'id, firstname, lastname');
                }

                // Build peer list.
                foreach ($uids as $uid) {
                    if (isset($snappeers[$uid])) {
                        $s = $snappeers[$uid];
                        $peerlist[$uid] = (object)[
                          'userid' => $uid,
                          'surname' => $s->surname ?? '',
                          'givenname' => $s->givenname ?? '',
                          'title' => $s->title ?? '',
                        ];
                    } else if (isset($userpeers[$uid])) {
                        $u2 = $userpeers[$uid];
                        $peerlist[$uid] = (object)[
                          'userid' => $uid,
                          'surname' => $u2->lastname ?? '',
                          'givenname' => $u2->firstname ?? '',
                          'title' => '',
                        ];
                    }
                }

                unset($peerlist[(int)$targetid]);
            }
        }

        // Check if peer blocks exist.
        $haspeerblocks = !empty($peerlist);
        $peerblocks = [];

        // Preload all answers given by the target student about their peers.
        $bypeer = [];
        if (!empty($peerlist) && $response) {
            $peerids = array_keys($peerlist);
            [$insql, $inparams] = $DB->get_in_or_equal($peerids, SQL_PARAMS_QM);

            $sql = "SELECT a.id AS id,
                     a.targetid AS peerid,
                     a.questionid,
                     a.scalevalue,
                     a.textvalue
                FROM {smartspe_answer} a
               WHERE a.responseid = ?
                 AND a.targetid $insql
            ORDER BY a.targetid ASC, a.questionid ASC";

            $rows = $DB->get_records_sql($sql, array_merge([$response->id], $inparams));
            foreach ($rows as $row) {
                $pid = (int)$row->peerid;
                $qid = (int)$row->questionid;
                $bypeer[$pid][$qid] = (object)[
                  'scalevalue' => $row->scalevalue,
                  'textvalue'  => $row->textvalue,
                ];
            }
        }

        // Build one block per peer.
        foreach ($peerlist as $uid => $pobj) {
            $rname = trim(($pobj->title ? $pobj->title . ' ' : '') . ($pobj->givenname ?? '') . ' ' . ($pobj->surname ?? ''));
            $rows = [];
            $i = 0;
            foreach ($qs as $qid => $q) {
                $i++;
                $row = ['num' => $i, 'question' => format_string($q->questiontext)];
                if (!empty($bypeer[$uid][$qid])) {
                    $a = $bypeer[$uid][$qid];
                    if ($a->scalevalue !== null) {
                        $row['value'] = self::fmt((float)$a->scalevalue);
                    } else {
                        $row['text'] = trim((string)$a->textvalue);
                    }
                }
                $rows[] = $row;
            }
            $peerblocks[] = ['raterid' => $uid, 'ratername' => $rname, 'qrows' => $rows];
        }

        // Session logs section.
        $sessionrows = [];
        $totalsecs = 0;
        if ($response) {
            $segs = $DB->get_records(
                'smartspe_response_session',
                ['responseid' => $response->id],
                'timestart ASC',
                'id,
                timestart,
                timeend,
                duration'
            );

            // Build session rows and accumulate total time.
            $n = 0;
            foreach ($segs as $seg) {
                $n++;
                $start = (int)$seg->timestart;
                $end = (int)$seg->timeend;

                // End sanity check.
                if ($end <= 0 || $end < $start) {
                    $end = $start;
                }

                // Duration sanity check.
                $dur = (int)$seg->duration;
                if ($dur <= 0 || $dur !== ($end - $start)) {
                    $dur = max(0, $end - $start);
                }

                $totalsecs += $dur;

                $sessionrows[] = [
                    'num' => $n,
                    'start' => userdate($start, get_string('strftimedatetimeshort', 'langconfig')),
                    'end' => userdate($end, get_string('strftimedatetimeshort', 'langconfig')),
                    'duration' => self::format_duration($dur),
                ];
            }
        }

        // Student name, team and form title.
        $fullname = trim($givenname . ' ' . $surname);
        if ($title !== '') {
            $fullname = $title . ' ' . $fullname;
        }

        $studentline = $fullname;
        if ($groupname !== '') {
            $studentline .= ' (' . $groupname . ')';
        }

        // Build the student info block from form-configured fields + the student's entered details.
        $infoblock = [];

        // Parse configured fields (order + labels) from the form.
        $cfgfields = [];
        if (!empty($form->studentfields)) {
            $tmp = json_decode($form->studentfields, true);
            if (is_array($tmp)) {
                $cfgfields = $tmp;
            }
        }

        // Parse the student's details payload.
        $detailvals = [];
        if ($response && !empty($response->detailsjson)) {
            $dj = json_decode($response->detailsjson, true);
            if (is_array($dj)) {
                $detailvals = $dj;
            }
        }

        // Fallback values that are not necessarily stored in detailsjson.
        $fallbacks = [
            'studentname' => trim(($title ? ($title . ' ') : '') . trim($givenname . ' ' . $surname)),
            'studentid' => (string)$studentid,
            'givenname' => (string)$givenname,
            'surname' => (string)$surname,
            'title' => (string)$title,
            'preferredname' => isset($snap->preferredname) ? (string)$snap->preferredname : '',
            'team' => (string)$groupname,
            'email' => isset($u->email) ? (string)$u->email : '',
            'username' => isset($u->username) ? (string)$u->username : '',
        ];

        if (!empty($cfgfields)) {
            foreach ($cfgfields as $f) {
                if (!is_array($f)) {
                    continue;
                }

                $key = $f['key'] ?? '';
                $label = $f['label'] ?? $key;

                if ($key === '') {
                    continue;
                }

                $raw = $detailvals[$key] ?? ($fallbacks[$key] ?? '');
                if (is_array($raw)) {
                    $raw = implode(', ', array_map('strval', $raw));
                }

                $val = trim((string)$raw);
                $infoblock[] = [
                  'label' => format_string($label),
                  'value' => s($val),
                ];
            }
        } else if (!empty($detailvals)) {
            // If no configured list exists, show all submitted detail key-values as a simple fallback.
            foreach ($detailvals as $k => $v) {
                $val = is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v;
                $infoblock[] = [
                    'label' => format_string((string)$k),
                    'value' => s(trim($val)),
                ];
            }
        }

        return [
            'hasdetail' => true,
            'header' => [
              'studentid' => $studentid,
              'fullname' => $fullname === '' ? get_string('noname', 'mod_smartspe') : $fullname,
              'groupname' => $groupname,
              'studentline' => $studentline,
              'formname' => format_string($form->title),
            ],
            'qrows' => $qrows,
            'peerblocks' => $peerblocks,
            'haspeerblocks' => $haspeerblocks,
            'sessions' => $sessionrows,
            'hassessions' => !empty($sessionrows),
            'totaltime' => self::format_duration($totalsecs),
            'hasinfoblock' => !empty($infoblock),
            'infoblock' => $infoblock,
        ];
    }

    /**
     * Format duration in seconds as "Xh YYm".
     *
     * @param int $secs Duration in seconds
     * @return string Formatted duration string
     */
    private static function format_duration(int $secs): string {
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        return sprintf('%dh %02dm', $h, $m);
    }

    /**
     * Format float value with no decimals, or empty string if null.
     *
     * @param float|null $v Value to format
     * @return string Formatted string
     */
    private static function fmt(?float $v): string {
        if ($v === null) {
            return '';
        }

        return number_format($v, 0, '.', '');
    }
}
