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
 * Test cases for the CSV upload service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\csv\upload_service;

/**
 * upload_service_test class.
 */
final class upload_service_test extends base_test {
    use smartspe_test_helpers;

    /**
     * Helper to create upload_service with given CSV.
     *
     * @param string $csv
     * @param int $courseid
     * @param int $cmid
     * @param context_module $ctx
     * @return upload_service
     */
    private function make_service_with_csv(string $csv, int $courseid, int $cmid, \context_module $ctx): upload_service {
      $data = (object)['csvcontent' => $csv];
      return new upload_service($data, $courseid, $cmid, $ctx);
    }

    /**
     * Ensure that a manual enrolment instance exists for the given course.
     *
     * @param int $courseid
     * @return void
     * @throws dml_exception
     */
    private function ensure_manual_enrol_instance(int $courseid): void {
      global $DB, $CFG;
      require_once($CFG->dirroot . '/lib/enrollib.php');
      require_once($CFG->dirroot . '/enrol/manual/lib.php');

      $instances = enrol_get_instances($courseid, true);
      foreach ($instances as $inst) {
        if ($inst->enrol === 'manual') {
          return;
        }
      }
      $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
      $plugin = enrol_get_plugin('manual');
      $plugin->add_default_instance($course);
    }

    /**
     * Manually enrol a student into a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    private function manually_enrol_student(int $courseid, int $userid): void {
      global $DB, $CFG;
      require_once($CFG->dirroot . '/enrol/manual/lib.php');

      $instances = enrol_get_instances($courseid, true);
      $manual = null;
      foreach ($instances as $inst) {
        if ($inst->enrol === 'manual') {
          $manual = $inst; break;
        }
      }
      $this->assertNotNull($manual, 'Manual enrol instance not found');
      $plugin = enrol_get_plugin('manual');
      $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
      $plugin->enrol_user($manual, $userid, $roleid);
    }

    /**
     * Test that nofile error is thrown when no file is provided.
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function test_nofile_throws(): void {
      $env = $this->seed_course_module();
      $svc = new upload_service((object)[], $env['course']->id, $env['cm']->id, $env['ctx']);

      $this->expectException(\moodle_exception::class);
      $this->expectExceptionMessage(get_string('nofile', 'error'));
      $svc->process();
    }

    /**
     * Test that user is created with email derived from domain config,
     *
     * @return void
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function test_create_user_with_domain_config_and_group_and_enrol_and_title(): void {
      global $DB;
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      set_config('studentemaildomain', 'murdoch.edu.sg', 'mod_smartspe');

      $csv = "Team,Student ID,First Name,Last Name,Title,Email\n"
        . "Team A,123456,Alex,Tan,Dr,\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, $sum['total']);
      $this->assertSame(1, $sum['created']);
      $this->assertSame(1, $sum['enrolled']);
      $this->assertSame(1, $sum['grouped']);
      $this->assertSame(1, $sum['groupscreated']);

      // User created with derived email and title appended to description.
      $user = $DB->get_record('user', ['username' => '123456'], '*', MUST_EXIST);
      $this->assertSame('123456@murdoch.edu.sg', $user->email);
      $this->assertStringContainsString('Title: Dr', (string)$user->description);
    }

    /**
     * Test that existing user matched by username is not re-enrolled if already enrolled.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_match_existing_by_username_already_enrolled(): void {
      global $DB;
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $u = $this->getDataGenerator()->create_user([
        'username' => 'stu1001',
        'firstname' => 'Sam',
        'lastname' => 'Lee',
        'email' => 'sam.lee@example.com',
      ]);
      $this->manually_enrol_student($env['course']->id, $u->id);

      $csv = "Team,Student ID,First Name,Last Name,Title,Email\n"
        . "Team B,stu1001,,,,";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, $sum['total']);
      $this->assertSame(1, $sum['matchedbyid']);
      $this->assertSame(1, $sum['alreadyenrolled']);
      $this->assertSame(1, $sum['grouped']);
      $this->assertArrayHasKey('rows', $out);
      $this->assertSame('id', $out['rows'][0]['status']);
    }

    /**
     * Test that email matching is case insensitive.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_match_by_email_case_insensitive(): void {
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $this->getDataGenerator()->create_user([
        'username' => 'x001',
        'firstname' => 'Bea',
        'lastname' => 'Tan',
        'email' => 'STUDENT.UPPER@EXAMPLE.COM',
      ]);

      $csv = "Team,Email\nTeam C,student.upper@example.com\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, $sum['total']);
      $this->assertSame(1, (int)($sum['matchedbyemail'] ?? 0));
      $this->assertSame(1, $sum['grouped']);
    }

    /**
     * Test that email localpart matching username works.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_email_localpart_matches_username(): void {
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $this->getDataGenerator()->create_user([
        'username' => '55555',
        'firstname' => 'Kai',
        'lastname' => 'Ng',
        'email' => 'kai@example.com',
      ]);

      $csv = "Team,Email\nTeam D,55555@myschool.edu\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, (int)($sum['matchedbyemaillocal'] ?? 0));
      $this->assertSame(1, $sum['grouped']);
    }

    /**
     * Test that user cannot be created when no email and no domain config.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_cannotcreate_when_no_email_and_no_domain(): void {
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      set_config('studentemaildomain', '', 'mod_smartspe');

      $csv = "Team,Student ID,First Name,Last Name,Email\n"
        . "Team E,88888,Ann,Tan,\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, $sum['total']);
      $this->assertSame(1, $sum['cannotcreate']);
      $this->assertSame('cannotcreate', $out['rows'][0]['status']);
    }

    /**
     * Test that blank first and last names are updated from CSV.
     *
     * @return void
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function test_update_blank_names_from_csv(): void {
      global $DB;
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $u = $this->getDataGenerator()->create_user([
        'username' => '11111',
        'firstname' => '',
        'lastname' => '',
        'email' => 'anon@example.com',
      ]);

      $csv = "Team,Student ID,First Name,Last Name\n"
        . "Team F,11111,Amy,Wong\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $svc->process();

      $refreshed = $DB->get_record('user', ['id' => $u->id], 'firstname,lastname', MUST_EXIST);
      $this->assertSame('Amy', (string)$refreshed->firstname);
      $this->assertSame('Wong', (string)$refreshed->lastname);
    }

    /**
     * Test that ambiguous last name without student ID results in notfound and ambiguous.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_ambiguous_lastname_without_studentid_results_notfound_and_ambiguous(): void {
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $u1 = $this->getDataGenerator()->create_user(['lastname' => 'Tan', 'firstname' => 'A', 'username' => 'a1', 'email' => 'a1@x.com']);
      $u2 = $this->getDataGenerator()->create_user(['lastname' => 'Tan', 'firstname' => 'B', 'username' => 'b1', 'email' => 'b1@x.com']);
      $this->manually_enrol_student($env['course']->id, $u1->id);
      $this->manually_enrol_student($env['course']->id, $u2->id);

      $csv = "Team,Last Name\nTeam G,Tan\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $out = $svc->process();

      $sum = $out['summary'];
      $this->assertSame(1, $sum['total']);
      $this->assertSame(1, $sum['ambiguous']);
      $this->assertSame(1, $sum['notfound']);
      $this->assertSame('notfound', $out['rows'][0]['status']);
    }

    /**
     * Test that title is appended only once to user description.
     *
     * @return void
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function test_title_is_appended_once_to_description(): void {
      global $DB;
      $env = $this->seed_course_module();
      $this->ensure_manual_enrol_instance($env['course']->id);

      $u = $this->getDataGenerator()->create_user([
        'username' => '99999',
        'firstname' => 'Zed',
        'lastname' => 'Foo',
        'email' => 'zed@example.com',
        'description' => 'Existing bio',
      ]);

      $csv = "Team,Student ID,Title\nTeam H,99999,Prof\n";

      $svc = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $svc->process();

      $user = $DB->get_record('user', ['id' => $u->id], 'description', MUST_EXIST);
      $this->assertStringContainsString('Existing bio', (string)$user->description);
      $this->assertStringContainsString('Title: Prof', (string)$user->description);

      $svc2 = $this->make_service_with_csv($csv, $env['course']->id, $env['cm']->id, $env['ctx']);
      $svc2->process();

      $user2 = $DB->get_record('user', ['id' => $u->id], 'description', MUST_EXIST);
      $this->assertSame((string)$user->description, (string)$user2->description);
    }
}
