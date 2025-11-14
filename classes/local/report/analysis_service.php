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
 * Analysis service for SmartSPE reporting.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\report;

/**
 * Analysis service class.
 */
final class analysis_service {
    /**
     * Get table headers for a given form.
     *
     * @param int $formid Form ID.
     * @return array Table headers structure.
     */
    public static function table_headers_for_form(int $formid): array {
        global $DB;
        $questions = $DB->get_records(
            'smartspe_question',
            ['formid' => $formid],
            'id ASC',
            'id'
        );

        $count = count($questions);
        $headers = [];
        for ($i = 1; $i <= $count; $i++) {
            $headers[] = ['label' => 'Q' . $i];
        }
        return ['questions' => $headers];
    }

    /**
     * Build the team matrix data structure.
     *
     * @param int $cmid Course module ID
     * @param int $formid Form ID
     * @param int $teamid Team (group) ID
     * @return array Matrix data structure
     */
    public static function build_team_matrix(int $cmid, int $formid, int $teamid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Fetch course module & group info.
        $cm = get_coursemodule_from_id('smartspe', $cmid, 0, false, MUST_EXIST);
        $group = groups_get_group($teamid, 'id, name', MUST_EXIST);
        $groupname = (string)$group->name;

        // Fetch questions for this form.
        $qs = $DB->get_records('smartspe_question', ['formid' => $formid], 'id ASC', 'id');
        $questionids = array_map(static fn($q) => (int)$q->id, $qs);
        $qcount = count($questionids);

        // If no questions, return empty skeleton.
        if ($qcount === 0) {
            return [
              'leftcols' => 5,
              'targets' => [],
              'roster' => [],
              'hasdata' => false,
              'emptyreason' => 'noquestions',
            ];
        }

        // Prepare criteria labels.
        $criterialabels = [];
        for ($i = 1; $i <= $qcount; $i++) {
            $criterialabels[] = (string)$i;
        }

        // Fetch the latest snapshot of user info for this form & team.
        $snapshot = $DB->get_records(
            'smartspe_user_report',
            ['formid' => $formid, 'groupid' => $teamid],
            'surname ASC, givenname ASC',
            'id, userid, studentid, surname, givenname, title'
        );

        $roster = [];
        $targets = [];
        $memberids = [];

        if ($snapshot) {
            foreach ($snapshot as $row) {
                $uid = (int)$row->userid;
                $memberids[] = $uid;

                $title = trim((string)($row->title ?? ''));
                if ($title === '') {
                    // Try to extract from user description as fallback.
                    $desc = $DB->get_field('user', 'description', ['id' => (int)$row->userid]);
                    if (!empty($desc)) {
                        $title = self::extract_title_from_description($desc);
                    }
                }
                $rawname = trim($row->givenname . ' ' . $row->surname . ' ' . $row->studentid);
                $displayname = $title !== '' ? ($title . ' ' . $rawname) : $rawname;

                $roster[] = [
                  'userid' => $uid,
                  'team' => $groupname,
                  'studentid' => (string)$row->studentid,
                  'surname' => (string)$row->surname,
                  'title' => $title,
                  'givenname' => (string)$row->givenname,
                ];

                // Keep existing keys; add displayname only.
                $targets[] = [
                  'userid' => $uid,
                  'name' => $rawname,
                  'displayname' => $displayname,
                  'colspan' => $qcount + 1,
                  'criteria' => $criterialabels,
                ];
            }
        } else {
            $members = groups_get_members(
                $teamid,
                'u.id,
                u.username,
                u.firstname,
                u.lastname,
                u.description',
                'lastname ASC,
                firstname ASC'
            );
            if (!$members) {
                return [
                  'leftcols' => 5,
                  'targets' => [],
                  'roster' => [],
                  'hasdata' => false,
                  'emptyreason' => 'nomembers',
                ];
            }

            foreach ($members as $u) {
                $uid = (int)$u->id;
                $memberids[] = $uid;

                $title = self::extract_title_from_description($u->description);
                $rawname = trim($u->firstname . ' ' . $u->lastname . ' ' . $u->username);
                $displayname = $title !== '' ? ($title . ' ' . $rawname) : $rawname;

                $roster[] = [
                    'userid' => $uid,
                    'team' => $groupname,
                    'studentid' => (string)$u->username,
                    'surname' => (string)$u->lastname,
                    'title' => $title,
                    'givenname' => (string)$u->firstname,
                ];

                // Optional display name with title.
                $targets[] = [
                    'userid' => $uid,
                    'name' => $rawname,
                    'displayname' => $displayname,
                    'colspan' => $qcount + 1,
                    'criteria' => $criterialabels,
                ];
            }
        }

        // If somehow no memberids, return skeleton.
        if (empty($memberids)) {
            return [
              'leftcols' => 5,
              'targets' => [],
              'roster' => [],
              'hasdata' => false,
              'emptyreason' => 'nomembers',
            ];
        }

        // Fetch latest answers per rater, target, question.
        [$inr, $pr] = $DB->get_in_or_equal($memberids, SQL_PARAMS_NAMED, 'r');
        [$int, $pt] = $DB->get_in_or_equal($memberids, SQL_PARAMS_NAMED, 't');
        [$inq, $pq] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'q');

        // SQL query to get latest answers.
        $sqllatesttriples = "
                SELECT sr.studentid AS raterid,
                       sa.targetid  AS targetid,
                       sa.questionid AS questionid,
                       MAX(sr.timecreated) AS maxtime
                  FROM {smartspe_answer} sa
                  JOIN {smartspe_response} sr ON sr.id = sa.responseid
                 WHERE sr.formid    = :formid_sub
                   AND sr.studentid $inr
                   AND sa.targetid  $int
                   AND sa.questionid $inq
              GROUP BY sr.studentid, sa.targetid, sa.questionid
            ";

        // Join back to pick the row with the max time.
        $sqlanswers = "
                SELECT sa.id        AS id,
                       sr.studentid AS raterid,
                       sa.targetid  AS targetid,
                       sa.questionid,
                       sa.scalevalue,
                       sa.textvalue,
                       sr.timecreated
                  FROM {smartspe_answer} sa
                  JOIN {smartspe_response} sr ON sr.id = sa.responseid
                  JOIN ( $sqllatesttriples ) x
                    ON x.raterid    = sr.studentid
                   AND x.targetid   = sa.targetid
                   AND x.questionid = sa.questionid
                   AND x.maxtime    = sr.timecreated
                 WHERE sr.formid    = :formid_outer
            ";

        $params = array_merge(['formid_sub' => $formid, 'formid_outer' => $formid], $pr, $pt, $pq);
        $rows = $DB->get_records_sql($sqlanswers, $params);

        // If no responses, return empty matrix structure.
        if (!$rows) {
            $out = self::format_matrix_output($groupname, $roster, $targets, [], $questionids);
            $out['hasdata'] = false;
            $out['emptyreason'] = 'noresponses';
            return $out;
        }

        // Build ratings.
        $qindex = [];
        $k = 1;
        foreach ($questionids as $qid) {
            $qindex[$qid] = $k++;
        }

        $ratings = [];
        foreach ($rows as $row) {
            $qid = (int)$row->questionid;
            if (!isset($qindex[$qid])) {
                continue;
            }
            $ri = (int)$row->raterid;
            $ti = (int)$row->targetid;
            $ki = $qindex[$qid];
            $value = null;
            if ($row->scalevalue !== null) {
                $value = (float)$row->scalevalue;
            } else {
                $txt = (string)($row->textvalue ?? '');
                $value = (trim($txt) === '') ? 0.0 : self::sentiment_to_scale($txt);
            }

            if ($value !== null) {
                $ratings[$ri][$ti][$ki] = $value;
            }
        }

        // Build row blocks & footer aggregates.
        $targetsorder = array_map(static fn($t) => (int)$t['userid'], $targets);

        $footersums = [];
        $footercounts = [];
        $rosterout = [];
        foreach ($roster as $row) {
            $raterid = (int)$row['userid'];
            $blocks = [];

            foreach ($targetsorder as $targetid) {
                $vals = $ratings[$raterid][$targetid] ?? [];
                $sum = 0.0;
                $count = 0;
                $scores = [];

                for ($ki = 1; $ki <= $qcount; $ki++) {
                    if (array_key_exists($ki, $vals)) {
                        $v = (float)$vals[$ki];
                        $scores[] = self::fmt($v);
                        $sum += $v;
                        $count++;

                        // Footer accumulation per criterion for this target.
                        $footersums[$targetid][$ki] = ($footersums[$targetid][$ki] ?? 0.0) + $v;
                        $footercounts[$targetid][$ki] = ($footercounts[$targetid][$ki] ?? 0) + 1;
                    } else {
                        $scores[] = '';
                    }
                }

                $blocks[] = [
                  'targetid' => $targetid,
                  'isself' => ($raterid === $targetid),
                  'scores' => $scores,
                  'average' => ($count > 0) ? self::fmt($sum / $count) : '',
                ];
            }

            $row['blocks'] = $blocks;
            $rosterout[] = $row;
        }

        // Footer “Average of Criteria” per target block.
        $targetsout = [];
        foreach ($targets as $t) {
            $tid = (int)$t['userid'];

            $percriterion = [];
            $overallsum = 0.0;
            $overallcount = 0;

            for ($ki = 1; $ki <= $qcount; $ki++) {
                $sum = $footersums[$tid][$ki] ?? 0.0;
                $count = $footercounts[$tid][$ki] ?? 0;
                if ($count > 0) {
                    $average = $sum / $count;
                    $percriterion[] = self::fmt($average);
                    $overallsum += $average;
                    $overallcount++;
                } else {
                    $percriterion[] = '';
                }
            }

            // Compute overall average of each or criteria and divide by 2 upon 2.5.
            $targetsout[] = $t + [
              'avgpercriterion' => $percriterion,
              'overall' => ($overallcount > 0) ? self::fmt(($overallsum / $overallcount) / 2) : '',
            ];
        }

        return [
          'leftcols' => 5,
          'targets' => $targetsout,
          'roster' => $rosterout,
          'hasdata' => true,
        ];
    }

    /**
     * Format float value to string with 2 decimal places, or empty if null/NaN.
     *
     * @param float|null $value Input value.
     * @return string Formatted string.
     */
    private static function fmt(?float $value): string {
        if ($value === null || is_nan($value)) {
            return '';
        }
        return number_format($value, 2, '.', '');
    }

    /**
     * Build an empty matrix when there are headers but no responses.
     */
    private static function format_matrix_output(
        string $groupname,
        array $roster,
        array $targets,
        array $ratings,
        array $questionids
    ): array {
        $qcount = count($questionids);

        // Clone targets adding empty footer parts.
        $targetsout = [];
        for ($i = 0; $i < count($targets); $i++) {
            $t = $targets[$i];
            $t['criteria'] = array_map(static fn($k) => (string)$k, range(1, $qcount));
            $t['avgpercriterion'] = array_fill(0, $qcount, '');
            $t['overall'] = '';
            $targetsout[] = $t;
        }

        // Clone roster adding empty blocks per target.
        $rosterout = [];
        foreach ($roster as $row) {
            $blocks = [];
            foreach ($targets as $t) {
                $blocks[] = [
                    'targetid' => (int)$t['userid'],
                    'isself' => ((int)$row['userid'] === (int)$t['userid']),
                    'scores' => array_fill(0, $qcount, ''),
                    'average' => '',
                ];
            }
            $row['blocks'] = $blocks;
            $rosterout[] = $row;
        }

        return [
            'leftcols' => 5,
            'targets' => $targetsout,
            'roster' => $rosterout,
        ];
    }

    /**
     * Convert free-text sentiment to 1-5 scale.
     *
     * @param string $text Input text.
     * @return float|null Scale value (1.0 to 5.0) or null if undetermined.
     */
    private static function sentiment_to_scale(string $text): ?float {
        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }

        // Load lexicon.
        $lex = self::sent_lexicon();
        $text = self::normalize_phrases($text, $lex);
        $tokens = self::sent_tokenize($text);
        if (!$tokens) {
            return 0.0;
        }

        // Prepare lookup sets.
        $positivetiera = array_flip($lex['posA']);
        $negativetiera = array_flip($lex['negA']);
        $positivetierb = array_flip($lex['posB']);
        $negativetierb = array_flip($lex['negB']);
        $boostset = array_flip($lex['boosters']);
        $downset = array_flip($lex['downtoners']);
        $contrastset = array_flip($lex['contrast']);

        // Multipliers.
        $boostmultiply = 1.5;
        $downmultiply = 0.75;
        $postconstrastmultiply = 1.25;
        $precontrastdecay = 0.5;

        // State carried while scanning tokens.
        $pendingmult  = 1.0;
        $postcontrast = false;

        $wposa = (float)$lex['w_posA'];
        $wnega = (float)$lex['w_negA'];
        $wposb = (float)$lex['w_posB'];
        $wnegb = (float)$lex['w_negB'];
        $th = isset($lex['diff_threshold']) ? (float)$lex['diff_threshold'] : 2.0;

        $negators = !empty($lex['negators']) ? $lex['negators'] : ['not', 'never', 'no', 'hardly', 'rarely', 'scarcely'];

        $pos = 0.0;
        $neg = 0.0;
        $flip = false;

        foreach ($tokens as $tok) {
            if (in_array($tok, $negators, true)) {
                $flip = !$flip;
                continue;
            }

            // Keep the strongest booster if multiple appear before the next hit.
            if (isset($boostset[$tok])) {
                $pendingmult = max($pendingmult, $boostmultiply);
                continue;
            }

            // Keep the strongest downtoner if multiple appear before the next hit.
            if (isset($downset[$tok])) {
                $pendingmult = min($pendingmult, $downmultiply);
                continue;
            }

            // Set post-contrast state.
            if (isset($contrastset[$tok])) {
                $pos *= $precontrastdecay;
                $neg *= $precontrastdecay;
                $postcontrast = true;
                continue;
            }

            $hit = 0.0;
            $polarity = 0;

            if (isset($positivetiera[$tok])) {
                $hit = $wposa;
                $polarity = +1;
            } else if (isset($negativetiera[$tok])) {
                $hit = $wnega;
                $polarity = -1;
            } else if (isset($positivetierb[$tok])) {
                $hit = $wposb;
                $polarity = +1;
            } else if (isset($negativetierb[$tok])) {
                $hit = $wnegb;
                $polarity = -1;
            }

            if ($hit > 0.0) {
                // Apply pending booster/downtoner, and post-contrast bias.
                $mult = $pendingmult * ($postcontrast ? $postconstrastmultiply : 1.0);
                $hit *= $mult;

                // Only post-contrast applies to one hit.
                if ($postcontrast) {
                    $postcontrast = false;
                }

                // Consume the multiplier once a hit has occurred.
                $pendingmult = 1.0;

                // Apply negation flip after scaling, then reset flip.
                if ($flip) {
                    $polarity = -$polarity;
                    $flip = false;
                }
                if ($polarity > 0) {
                    $pos += $hit;
                } else {
                    $neg += $hit;
                }
            }
        }

        // Decide final scale value.
        if ($pos == 0.0 && $neg == 0.0) {
            return 3.0;
        }
        if ($pos > 0.0 && $neg == 0.0) {
            return 5.0;
        }
        if ($neg > 0.0 && $pos == 0.0) {
            return 1.0;
        }
        $diff = $pos - $neg;
        if ($diff >= $th) {
            return 5.0;
        }
        if ($diff <= -$th) {
            return 1.0;
        }
        return 3.0;
    }

    /**
     * Load sentiment lexicon data.
     *
     * @return array Sentiment lexicon structure.
     */
    private static function sent_lexicon(): array {
        global $CFG;
        $base = $CFG->dirroot . '/mod/smartspe/sentiment_data';

        // Helper to read lines from a file, skipping comments/blanks, lowercasing.
        $read = function (string $path, bool $lower = true): array {
            if (!is_readable($path)) {
                  return [];
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $out = [];
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '' || $ln[0] === '#') {
                    continue;
                }
                $out[] = $lower ? mb_strtolower($ln, 'UTF-8') : $ln;
            }
            return array_values(array_unique($out));
        };

        $tok = function (array $a): array {
            $ok = [];
            foreach ($a as $w) {
                if (preg_match('/^[a-z][a-z\\-]{0,49}$/u', $w)) {
                    $ok[$w] = true;
                }
            }
            return array_keys($ok);
        };

        // Tier A lexicon, weight = 1.
        $positivetiera = $tok($read("$base/tier_a/positive_token.txt"));
        $negativetiera = $tok($read("$base/tier_a/negative_token.txt"));
        $positivephrase = $read("$base/tier_a/positive_phrases.txt", true);
        $negativephrase = $read("$base/tier_a/negative_phrases.txt", true);

        $hyphen = static function (string $p): string {
            $p = preg_replace('/\s+/u', '-', trim($p)) ?? $p;
            return $p;
        };

        $posphrtokens = array_map($hyphen, $positivephrase);
        $negphrtokens = array_map($hyphen, $negativephrase);

        // Merge into tier A tokens so phrases are treated like single tokens.
        $positivetiera = array_values(array_unique(array_merge($positivetiera, $posphrtokens)));
        $negativetiera = array_values(array_unique(array_merge($negativetiera, $negphrtokens)));

        // Also return a map of original phrase to hyphenated form.
        $phrasemap = [];
        foreach ($positivephrase as $p) {
            $phrasemap[$p] = $hyphen($p);
        }
        foreach ($negativephrase as $p) {
            $phrasemap[$p] = $hyphen($p);
        }

        // Tier B lexicon, weight = 0.5, with allow/deny filtering.
        $positivebraw = $tok($read("$base/tier_b/kaggle_positive.txt"));
        $negativebraw = $tok($read("$base/tier_b/kaggle_negative.txt"));
        $allow = $tok($read("$base/tier_b/allow_list.txt"));
        $deny = $tok($read("$base/tier_b/deny_list.txt"));

        // Apply deny filter.
        $denyset = array_flip($deny);
        $positivetierb = array_values(array_filter($positivebraw, static fn($t) => !isset($denyset[$t])));
        $negativetierb = array_values(array_filter($negativebraw, static fn($t) => !isset($denyset[$t])));

        // Apply allow filter if present.
        if (!empty($allow)) {
            $allowset = array_flip($allow);
            $positivetierb = array_values(array_filter($positivetierb, static fn($t) => isset($allowset[$t])));
            $negativetierb = array_values(array_filter($negativetierb, static fn($t) => isset($allowset[$t])));
        }

        // Load rule lists.
        $negators = $read("$base/rules/negators.txt");
        $boosters = $read("$base/rules/boosters.txt");
        $downtoners = $read("$base/rules/downtoners.txt");
        $contrast = $read("$base/rules/contrast.txt");

        return [
            // Tiered token sets.
            'posA' => $positivetiera, 'negA' => $negativetiera,
            'posB' => $positivetierb, 'negB' => $negativetierb,

            // Phrase normalization map.
            'phrase_map' => $phrasemap,

            // Weights for tiers.
            'w_posA' => 1.0, 'w_negA' => 1.0,
            'w_posB' => 0.5, 'w_negB' => 0.5,

            // Tie-break threshold for mixed comments.
            'diff_threshold' => 2.0,

            // Rule lists for sentiment modifiers.
            'negators' => $negators,
            'boosters' => $boosters,
            'downtoners' => $downtoners,
            'contrast' => $contrast,
        ];
    }

    /**
     * Simple sentence tokenizer: lowercases, removes punctuation, splits on spaces.
     *
     * @param string $s Input string.
     * @return array Array of tokens.
     */
    private static function sent_tokenize(string $s): array {
        $s = mb_strtolower($s, 'UTF-8');

        // Replace punctuation with spaces.
        $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        if ($s === '') {
            return [];
        }

        // Use to normalized common multiword phrases into single tokens.May remove if not in use.
        $s = str_replace('on time', 'on-time', $s);
        return explode(' ', $s);
    }

    /**
     * Extract title from user description using pattern matching.
     *
     * @param string|null $desc User description.
     * @return string Extracted title or empty string.
     */
    private static function extract_title_from_description(?string $desc): string {
        if (empty($desc)) {
            return '';
        }
        if (preg_match('/\bTitle\s*:\s*([A-Za-z0-9\.\- ]{1,20})/i', $desc, $m)) {
            return trim($m[1], " \t\n\r\0\x0B.");
        }
        return '';
    }

    /**
     * Normalize phrases in input string using lexicon phrase map.
     *
     * @param string $s Input string.
     * @param array $lex Sentiment lexicon.
     * @return string Normalized string.
     */
    private static function normalize_phrases(string $s, array $lex): string {
        if (empty($lex['phrase_map'])) {
            return $s;
        }

        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        if ($s === '') {
            return $s;
        }

        // Sort phrases by length descending to match longer phrases first.
        $phrases = array_keys($lex['phrase_map']);
        usort($phrases, static function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        // Replace phrases with hyphenated forms.
        foreach ($phrases as $p) {
            $h = $lex['phrase_map'][$p];
            $pattern = '/\b' . preg_quote($p, '/') . '\b/u';
            $s = preg_replace($pattern, $h, $s) ?? $s;
        }
        return $s;
    }
}
