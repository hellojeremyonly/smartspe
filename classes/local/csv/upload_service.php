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
 * CSV Upload Service for User Enrolment and Grouping
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\local\csv;

use context_user;
use moodle_exception;
use Random\RandomException;
use stdClass;

/**
 * Service class to process uploaded CSV for user enrolment and grouping.
 */
class upload_service {
    /** @var stdClass Raw form data */
    protected $data;
    /** @var int */
    protected $courseid;
    /** @var int */
    protected $cmid;
    /** @var \context_module */
    protected $context;

    /** @var array Summary counters */
    protected $summary = [
        'total' => 0,
        'matchedbyid' => 0,
        'matchedbyname' => 0,
        'created' => 0,
        'enrolled' => 0,
        'alreadyenrolled' => 0,
        'groupscreated' => 0,
        'grouped' => 0,
        'ambiguous' => 0,
        'notfound' => 0,
        'cannotcreate' => 0,
        'errors' => 0,
    ];

    /** @var array Row-level notes for preview/report */
    protected $rows = [];

    /**
     * Constructor.
     *
     * @param stdClass $data Raw form data
     * @param int $courseid Course ID
     * @param int $cmid Course module ID
     * @param \context_module $context Module context
     */
    public function __construct(stdClass $data, int $courseid, int $cmid, \context_module $context) {
        $this->data = $data;
        $this->courseid = $courseid;
        $this->cmid = $cmid;
        $this->context = $context;
    }

    /**
     * Process the uploaded CSV: read, map, match, create/enrol, group.
     * Returns an array suitable for templating.
     *
     * @return array Summary and row notes
     */
    public function process(): array {
        global $CFG, $DB;

        $csv = $this->fetch_csv_content();
        if ($csv === '') {
            throw new moodle_exception('nofile', 'error');
        }

        [$headers, $rows] = $this->parse_csv($csv);

        foreach ($rows as $idx => $r) {
            $this->summary['total']++;
            $note = [
                'line' => $r['_line'] ?? ($idx + 2),
                'team' => $r['team'] ?? '',
                'studentid' => $r['studentid'] ?? '',
                'firstname' => $r['firstname'] ?? '',
                'lastname' => $r['lastname'] ?? '',
                'status' => '',
                'detail' => '',
                'userid' => null,
            ];

            $teamname = trim((string)($r['team'] ?? ''));
            $studentid = trim((string)($r['studentid'] ?? ''));
            $firstname = trim((string)($r['firstname'] ?? ''));
            $lastname = trim((string)($r['lastname'] ?? ''));
            $title = trim((string)($r['title'] ?? ''));
            $email = trim((string)($r['email'] ?? ''));

            $user = null;
            $matchedby = '';

            // Match by Student ID (username) if present.
            if ($studentid !== '') {
                $user = $DB->get_record('user', ['username' => $studentid, 'deleted' => 0], '*', IGNORE_MISSING);
                if ($user) {
                    $matchedby = 'id';
                    $this->summary['matchedbyid']++;
                }
            }

            // If no ID match, try email (case-insensitive).
            if (!$user && $email !== '') {
                $emaillc = \core_text::strtolower($email);
                $user = $DB->get_record_sql(
                    "SELECT id, firstname, lastname, username, email FROM {user} WHERE deleted = 0 AND LOWER(email) = ?",
                    [$emaillc],
                    IGNORE_MISSING
                );
                if ($user) {
                    $matchedby = 'email';
                    $this->summary['matchedbyemail'] = ($this->summary['matchedbyemail'] ?? 0) + 1;
                }
            }

            // If still no match, try deriving student ID from email.
            if (!$user && $email !== '') {
                if (preg_match('/^\\s*([0-9]{4,})@/i', $email, $m)) {
                    $sid = $m[1];
                    $user = $DB->get_record('user', ['username' => $sid, 'deleted' => 0], '*', IGNORE_MISSING);
                    if ($user) {
                        $matchedby = 'email-localpart';
                        $this->summary['matchedbyemaillocal'] = ($this->summary['matchedbyemaillocal'] ?? 0) + 1;
                    }
                }
            }

            // If no ID or email match, try unique last name match.
            if (!$user && $firstname === '' && $lastname !== '') {
                $lclast = \core_text::strtolower($lastname);

                // Try among enrolled users in this course first.
                $enrolled = get_enrolled_users($this->context, '', 0, 'u.id, u.firstname, u.lastname, u.username, u.email');
                $matches = array_values(array_filter($enrolled, function ($u) use ($lclast) {
                    return \core_text::strtolower($u->lastname) === $lclast;
                }));
                if (count($matches) === 1) {
                    $user = $matches[0];
                    $matchedby = 'name-last';
                } else if (count($matches) === 0) {
                    // Fall back to site-wide unique last name.
                    $users = $DB->get_records_select(
                        'user',
                        'deleted = 0 AND LOWER(lastname) = ?',
                        [$lclast],
                        '',
                        'id,
                        firstname,
                        lastname,
                        username,
                        email'
                    );
                    if (count($users) === 1) {
                        $user = reset($users);
                        $matchedby = 'name-last';
                    } else if (count($users) > 1) {
                        $this->summary['ambiguous']++;
                    }
                } else {
                      $this->summary['ambiguous']++;
                }
            }

            // If no ID or not found, try unique exact name match.
            if (!$user && $firstname !== '' && $lastname !== '') {
                $user = $this->find_user_by_name($firstname, $lastname);
                if ($user) {
                    $matchedby = 'name';
                    $this->summary['matchedbyname']++;
                }
            }

            if (!$user) {
                // Allow creation as long as Student ID exists and we can determine an email.
                if ($studentid !== '') {
                    // Derive school email if not provided via plugin config (optional).
                    $schooldomain = (string) get_config('mod_smartspe', 'studentemaildomain');
                    $schoolmail = '';
                    if ($email === '' && $schooldomain !== '') {
                        $schoolmail = $studentid . '@' . $schooldomain;
                    } else {
                        $schoolmail = $email;
                    }

                    if ($schoolmail === '') {
                        $this->summary['cannotcreate']++;
                        $note['status'] = 'cannotcreate';
                        $note['detail'] = 'Missing email to create user';
                        $this->rows[] = $note;
                        continue;
                    }

                    // Create user and if creation fails, retry with minimal placeholders.
                    try {
                        $user = $this->create_user($studentid, $firstname, $lastname, $schoolmail, $title);
                        $matchedby = 'created';
                        $this->summary['created']++;
                    } catch (\Throwable $e) {
                        try {
                            $fallbackfirstname = ($firstname === '') ? '-' : $firstname;
                            $fallbacklastname = ($lastname === '') ? '-' : $lastname;
                            $user = $this->create_user($studentid, $fallbackfirstname, $fallbacklastname, $schoolmail, $title);
                            $matchedby = 'created';
                            $this->summary['created']++;
                        } catch (\Throwable $e2) {
                            $this->summary['errors']++;
                            $note['status'] = 'error';
                            $note['detail'] = 'Create failed: ' . $e2->getMessage();
                            $this->rows[] = $note;
                            continue;
                        }
                    }
                } else {
                    $this->summary['notfound']++;
                    $note['status'] = 'notfound';
                    $note['detail'] = 'No unique match and cannot create without Student ID';
                    $this->rows[] = $note;
                    continue;
                }
            }

            // Ensure Title is in profile description.
            if ($user && $title !== '') {
                $this->ensure_title_in_description((int)$user->id, $title);
            }

            // If Moodle user has blank names but CSV provides them, update now.
            if ($user && ($user->firstname === '' || $user->lastname === '')) {
                $ufields = new \stdClass();
                $ufields->id = $user->id;
                $changed = false;
                if ($user->firstname === '' && $firstname !== '') {
                    $ufields->firstname = $firstname;
                    $changed = true;
                }
                if ($user->lastname === '' && $lastname !== '') {
                    $ufields->lastname = $lastname;
                    $changed = true;
                }
                if ($changed) {
                    require_once($CFG->dirroot . '/user/lib.php');
                    user_update_user($ufields, false, false);
                    // Update local copy.
                    $user->firstname = $ufields->firstname ?? $user->firstname;
                    $user->lastname = $ufields->lastname ?? $user->lastname;
                }
            }

            // Ensure enrolled before grouping.
            $enrolled = is_enrolled($this->context, $user->id);
            if (!$enrolled) {
                if ($this->enrol_user($user->id)) {
                    $this->summary['enrolled']++;
                } else {
                    $this->summary['errors']++;
                    $note['status'] = 'error';
                    $note['detail'] = 'Failed to enrol user';
                    $this->rows[] = $note;
                    continue;
                }
            } else {
                $this->summary['alreadyenrolled']++;
            }

            // Ensure group exists and add member.
            if ($teamname !== '') {
                [$groupid, $created] = $this->ensure_group($teamname);
                if ($created) {
                    $this->summary['groupscreated']++;
                }
                try {
                    groups_add_member($groupid, $user->id);
                    $this->summary['grouped']++;
                } catch (\Throwable $e) {
                    $this->summary['errors']++;
                    $note['status'] = 'error';
                    $note['detail'] = 'Failed to add to group: ' . $e->getMessage();
                    $this->rows[] = $note;
                    continue;
                }
            }

            $note['status'] = $matchedby ?: 'matched';
            $note['userid'] = $user->id;
            $this->rows[] = $note;
        }

        return [
            'summary' => $this->summary,
            'rows' => $this->rows,
        ];
    }


    /**
     * Fetch CSV content from direct input or draft filepicker.
     *
     * @return string CSV content
     */
    protected function fetch_csv_content(): string {
        global $USER;
        if (!empty($this->data->csvcontent) && is_string($this->data->csvcontent)) {
            return (string)$this->data->csvcontent;
        }
        // Try read from draft file area.
        if (!empty($this->data->csvfile) && is_numeric($this->data->csvfile)) {
            $fs = get_file_storage();
            $userctx = context_user::instance($USER->id);
            $files = $fs->get_area_files($userctx->id, 'user', 'draft', (int)$this->data->csvfile, 'id DESC', false);
            if ($files) {
                // Use the first file found.
                $file = reset($files);
                return $file->get_content();
            }
        }
        return '';
    }

    /**
     * Parse CSV content into mapped rows.
     *
     * @return array header and rows
     */
    protected function parse_csv(string $csv): array {
        $tmp = tmpfile();
        fwrite($tmp, $csv);
        fseek($tmp, 0);

        $fh = $tmp;
        $delim = ',';

        $header = fgetcsv($fh, 0, $delim);
        if ($header === false) {
            return [[], []];
        }
        $map = $this->map_headers($header);

        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($fh, 0, $delim)) !== false) {
            $line++;
            if ($this->row_all_empty($cols)) {
                continue;
            }
            $r = [
                'team' => $this->col($cols, $map, 'team'),
                'studentid' => $this->col($cols, $map, 'studentid'),
                'firstname' => $this->col($cols, $map, 'firstname'),
                'lastname' => $this->col($cols, $map, 'lastname'),
                'title' => $this->col($cols, $map, 'title'),
                'email' => $this->col($cols, $map, 'email'),
                '_line' => $line,
            ];
            // Trim and normalise spaces.
            foreach ($r as $k => $v) {
                if (is_string($v)) {
                    $r[$k] = trim(preg_replace('/\s+/', ' ', $v));
                }
            }
            $rows[] = $r;
        }
        fclose($tmp);

        return [$map, $rows];
    }

    /**
     * Check if all columns in a row are empty.
     *
     * @return bool True if all empty
     */
    protected function row_all_empty(array $cols): bool {
        foreach ($cols as $c) {
            if (trim((string)$c) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Map CSV header labels to known fields.
     *
     * @return array key => column index
     */
    protected function map_headers(array $header): array {
        $map = [];
        foreach ($header as $i => $raw) {
            $label = strtolower(trim((string)$raw));
            $label = preg_replace('/\s+/', ' ', $label);
            switch ($label) {
                case 'team':
                case 'team #':
                case 'team number':
                case 'team#':
                case 'team name':
                    $map['team'] = $i;
                    break;
                case 'student id':
                case 'studentid':
                case 'username':
                case 'id':
                    $map['studentid'] = $i;
                    break;
                case 'given names':
                case 'given name':
                case 'firstname':
                case 'first name':
                    $map['firstname'] = $i;
                    break;
                case 'surname':
                case 'last name':
                case 'lastname':
                    $map['lastname'] = $i;
                    break;
                case 'title':
                    $map['title'] = $i;
                    break;
                case 'email':
                case 'email address':
                    $map['email'] = $i;
                    break;
            }
        }
        return $map;
    }

    /**
     * Get column value by mapped key.
     *
     * @return string Value or empty string
     */
    protected function col(array $cols, array $map, string $key): string {
        if (!array_key_exists($key, $map)) {
            return '';
        }
        $i = $map[$key];
        return isset($cols[$i]) ? (string)$cols[$i] : '';
    }


    /**
     * Find a user by exact unique match of firstname + lastname.
     * Search enrolled first, then site-wide.
     *
     * @return stdClass|null Matched user or null
     */
    protected function find_user_by_name(string $firstname, string $lastname): ?stdClass {
        global $DB;
        $lcfirst = \core_text::strtolower(trim($firstname));
        $lclast = \core_text::strtolower(trim($lastname));

        // Enrolled users in course.
        $enrolled = get_enrolled_users($this->context, '', 0, 'u.id, u.firstname, u.lastname, u.username, u.email');
        $matches = [];
        foreach ($enrolled as $u) {
            if (\core_text::strtolower($u->firstname) === $lcfirst && \core_text::strtolower($u->lastname) === $lclast) {
                $matches[] = $u;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            $this->summary['ambiguous']++;
            return null;
        }

        // Site-wide search.
        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND LOWER(firstname) = ? AND LOWER(lastname) = ?',
            [$lcfirst, $lclast],
            '',
            'id,
            firstname,
            lastname,
            username,
            email'
        );
        if (count($users) === 1) {
            return reset($users);
        }
        if (count($users) > 1) {
            $this->summary['ambiguous']++;
            return null;
        }
        return null;
    }

    /**
     * Enrol user into course via manual enrolment.
     *
     * @return bool Success
     */
    protected function enrol_user(int $userid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/enrol/manual/lib.php');

        $instances = enrol_get_instances($this->courseid, true);
        $manualinstance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }

        // No manual enrolment instance found. Cannot enrol.
        if (!$manualinstance) {
            return false;
        }

        $plugin = enrol_get_plugin('manual');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        try {
            $plugin->enrol_user($manualinstance, $userid, $roleid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ensure a group with the given name exists in the course.
     *
     * @return array the group id and whether it was created
     * @throws moodle_exception if group creation fails
     */
    protected function ensure_group(string $name): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $gid = groups_get_group_by_name($this->courseid, $name);
        if ($gid) {
            return [$gid, false];
        }
        $g = new stdClass();
        $g->courseid = $this->courseid;
        $g->name = $name;
        $g->description = '';
        $gid = groups_create_group($g);
        return [$gid, true];
    }

    /**
     * Create a new user with given details.
     *
     * @return stdClass Created user record
     * @throws moodle_exception if creation fails
     */
    protected function create_user(
        string $studentid,
        string $firstname,
        string $lastname,
        string $email,
        string $title = ''
    ): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $user = new stdClass();
        $user->auth = 'manual';
        $user->username = $studentid;
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        $user->email = $email;
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $defaultpassword = 'Murdoch12345!';
        $user->password = $defaultpassword;
        $user->forcepasswordchange = 1;

        // Seed profile description with Title so reports can read it immediately.
        if ($title !== '') {
            $user->description = "Title: {$title}";
        }

        $userid = user_create_user($user, true, false);
        $user->id = $userid;
        return $user;
    }

    /**
     * Generate password with 12 random chars (A-Z,a-z,0-9).
     * Use when needed.
     *
     * @return string the generated password
     * @throws RandomException if random_int fails
     */
    protected function generate_password(): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = strlen($chars);
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $chars[random_int(0, $len - 1)];
        }
        return $out;
    }

    /**
     * Ensure that the user's profile description contains the given title.
     *
     * @param int $userid User ID
     * @param string $title Title to ensure in description
     * @return void
     * @throws \dml_exception if database error occurs
     */
    private function ensure_title_in_description(int $userid, string $title): void {
        global $DB;
        $title = trim($title);
        if ($title === '') {
            return;
        }

        $u = $DB->get_record('user', ['id' => $userid], 'id, description', MUST_EXIST);
        $desc = (string)($u->description ?? '');

        if (preg_match('/\bTitle\s*:/i', $desc)) {
            return;
        }

        $newline = ($desc !== '' && !str_ends_with($desc, "\n")) ? "\n" : '';
        $newdesc = $desc . $newline . "Title: {$title}";

        $DB->update_record('user', (object)[
          'id' => $userid,
          'description' => $newdesc,
        ]);
    }
}
