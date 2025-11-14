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
 * Common test helpers for mod_smartspe PHPUnit tests.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_smartspe\local\report\analysis_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Trait smartspe_test_helpers
 * Common test helpers for mod_smartspe PHPUnit tests.
 */
trait smartspe_test_helpers {
    /**
     * Enrol and act as a user with given role shortname in the given course.
     *
     * @param string $roleshortname
     * @param int $courseid
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
  protected function act_as(string $roleshortname, int $courseid): \stdClass {
      global $DB;
      $user = $this->getDataGenerator()->create_user();
      $role = $DB->get_record('role', ['shortname' => $roleshortname], '*', IGNORE_MISSING);
      if (!$role) {
        $roleid = $this->getDataGenerator()->create_role(['shortname' => $roleshortname, 'name' => ucfirst($roleshortname)]);
      } else {
        $roleid = $role->id;
      }
      $this->getDataGenerator()->enrol_user($user->id, $courseid, $roleid);
      $this->setUser($user);
      return $user;
    }

    /**
     * Enrol and act as a student in the given course.
     *
     * @param int $courseid
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function act_as_student(int $courseid): \stdClass {
      return $this->act_as('student', $courseid);
    }

    /**
     * Enrol and act as a teacher in the given course.
     *
     * @param int $courseid
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function act_as_teacher(int $courseid): \stdClass {
      return $this->act_as('editingteacher', $courseid);
    }

    /**
     * Create a group with given members.
     *
     * @param int $courseid
     * @param string $name
     * @param array $userids
     * @return stdClass
     * @throws coding_exception
     */
    protected function create_group_with_members(int $courseid, string $name, array $userids): \stdClass {
      $group = $this->getDataGenerator()->create_group(['courseid' => $courseid, 'name' => $name]);
      foreach ($userids as $uid) {
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $uid]);
      }
      return $group;
    }

    /**
     * Create a form linked to a smartspe instance.
     *
     * @param int $smartspeid
     * @param array $overrides
     * @return stdClass
     * @throws dml_exception
     */
    protected function make_form(int $smartspeid, array $overrides = []): \stdClass {
      global $DB;
      $this->freeze_now();
      $row = (object)array_merge([
        'smartspeid' => $smartspeid,
        'title' => 'Test Form',
        'status' => 0,
        'instruction' => '',
        'studentfields' => '',
        'timecreated' => time(),
        'timemodified' => time(),
      ], $overrides);
      $row->id = $DB->insert_record('smartspe_form', $row);
      return $row;
    }

    /**
     * Create a question linked to a form.
     *
     * @param int $formid
     * @param array $overrides
     * @return stdClass
     * @throws dml_exception
     */
    protected function make_question(int $formid, array $overrides = []): \stdClass {
      global $DB;
      $row = (object)array_merge([
        'formid' => $formid,
        'questiontext' => 'How helpful?',
        'questiontype' => 1,
        'audience' => 2,
      ], $overrides);
      $row->id = $DB->insert_record('smartspe_question', $row);
      return $row;
    }

    /**
     * Create a response linked to a form and student.
     *
     * @param int $formid
     * @param int $studentid
     * @param array $overrides
     * @return stdClass
     * @throws dml_exception
     */
    protected function make_response(int $formid, int $studentid, array $overrides = []): \stdClass {
      global $DB;
      $this->freeze_now();
      $row = (object)array_merge([
        'formid' => $formid,
        'studentid' => $studentid,
        'status' => 0,
        'detailsjson' => null,
        'timecreated' => time(),
        'timemodified' => time(),
      ], $overrides);
      $row->id = $DB->insert_record('smartspe_response', $row);
      return $row;
    }

    /**
     * Create an answer linked to a response, question, and target.
     *
     * @param int $responseid
     * @param int $questionid
     * @param int $targetid
     * @param array $overrides
     * @return stdClass
     * @throws dml_exception
     */
    protected function make_answer(int $responseid, int $questionid, int $targetid, array $overrides = []): \stdClass {
      global $DB;
      $row = (object)array_merge([
        'responseid' => $responseid,
        'questionid' => $questionid,
        'targetid' => $targetid,
        'scalevalue' => null,
        'textvalue' => '',
      ], $overrides);
      $row->id = $DB->insert_record('smartspe_answer', $row);
      return $row;
    }

    /**
     * Assert that given HTML or URL contains an action link with given path and params.
     *
     * @param $htmlOrUrl
     * @param string $path
     * @param array $params
     * @return void
     */
    protected function assertHasAction($htmlOrUrl, string $path, array $params = []): void {
      $url = null;

      if ($htmlOrUrl instanceof \moodle_url) {
        $url = $htmlOrUrl->out(false);
      } elseif (is_string($htmlOrUrl)) {
        if (stripos($htmlOrUrl, 'href=') !== false) {
          if (preg_match('~href=[\"\']([^\"\']+)[\"\']~i', $htmlOrUrl, $m)) {
            $url = $m[1];
          }
        } else {
          $url = $htmlOrUrl;
        }
      }

      $this->assertNotEmpty($url, 'No URL found in provided value.');

      $parts = parse_url($url);
      $this->assertStringEndsWith($path, $parts['path'] ?? '', "Expected path to end with {$path}");

      $query = [];
      if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
      }
      foreach ($params as $k => $v) {
        $this->assertArrayHasKey($k, $query, "Missing query param: {$k}");
        if ($v !== null) {
          $this->assertEquals((string)$v, (string)$query[$k], "Param '{$k}' mismatch.");
        }
      }
    }

    /**
     * Translation helper for mod_smartspe strings.
     *
     * @param string $key
     * @return string
     * @throws coding_exception
     */
    protected function t(string $key): string {
      return get_string($key, 'mod_smartspe');
    }

    /**
     * Stub the headers payload in analysis_service.
     *
     * @param array $headers
     * @return void
     */
    protected function sm_set_headers_payload(array $headers): void {
      if (!property_exists(analysis_service::class, 'headers')) {
        $this->markTestSkipped('analysis_service (real) already loaded; stub not available.');
      }
      analysis_service::$headers = $headers;
    }

    /**
     * Create a course + smartspe instance + group and return them along with a form.
     *
     * @param string $groupname
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function sm_make_env_with_group(string $groupname = 'Team A'): array {
      $env = $this->seed_course_module();
      $group = $this->getDataGenerator()->create_group([
        'courseid' => $env['course']->id,
        'name' => $groupname,
      ]);
      $form = $this->make_form($env['instance']->id, ['title' => 'Form 1']);
      return [$env, $form, $group];
    }

    /**
     * Create a sample matrix payload for testing exports.
     *
     * @param int $qcount
     * @return array[]
     */
    protected function sm_sample_matrix(int $qcount = 3): array {
      $criteria = range(1, $qcount);
      return [
        'targets' => [
          ['name' => 'Target One', 'criteria' => $criteria,
            'avgpercriterion' => array_map(fn($i)=> (string)(2.0 + $i/10), range(1,$qcount)),
            'overall' => '3.21'],
          ['name' => 'Target Two', 'criteria' => $criteria,
            'avgpercriterion' => array_map(fn($i)=> (string)(3.0 + $i/10), range(1,$qcount)),
            'overall' => '3.65'],
        ],
        'roster' => [
          ['team'=>'Team A','studentid'=>'S1','surname'=>'Tan','title'=>'','givenname'=>'Alex',
            'blocks'=>[
              ['scores'=>['3','4','5'],'average'=>'4.0'],
              ['scores'=>['2','3','4'],'average'=>'3.0'],
            ]],
          ['team'=>'Team A','studentid'=>'S2','surname'=>'Lee','title'=>'','givenname'=>'Bea',
            'blocks'=>[
              ['scores'=>['','',''],'average'=>''],
              ['scores'=>['1','2'],'average'=>'1.5'],
            ]],
        ],
      ];
    }

    /**
     * Stub the matrix payload in analysis_service.
     *
     * @param array $payload
     * @return void
     */
    protected function sm_set_matrix_payload(array $payload): void {
      if (!property_exists(analysis_service::class, 'payload')) {
        $this->markTestSkipped('analysis_service already loaded; cannot stub for export tests.');
      }
      analysis_service::$payload = $payload;
    }
}
