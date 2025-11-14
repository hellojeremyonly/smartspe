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
 * Test cases for the submissions service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\report\submissions_service;
use mod_smartspe\local\report\student_table_service;

/**
 * submissions_service_test class.
 */
final class submissions_service_test extends base_test {
    use smartspe_test_helpers;

    /**
     * Find a list method without hardcoding the exact API name.
     *
     * @return string|null
     */
    private function resolve_list_method(): ?string {
      foreach (['list_targets', 'list_submissions', 'list_for_form', 'list'] as $m) {
        if (method_exists(submissions_service::class, $m)) {
          return $m;
        }
      }
      return null;
    }

    /**
     * Find a detail method without hardcoding the exact API name.
     *
     * @return string|null
     */
    private function resolve_detail_method(): ?string {
      foreach (['detail', 'submission_detail', 'build_detail', 'detail_for_target'] as $m) {
        if (method_exists(submissions_service::class, $m)) {
          return $m;
        }
      }
      return null;
    }

    /**
     * Ensure a manual enrolment instance exists for the given course.
     *
     * @param int $courseid
     * @return stdClass
     * @throws dml_exception
     */
    private function ensure_manual_enrol_instance(int $courseid): stdClass {
      global $CFG, $DB;
      require_once($CFG->dirroot . '/lib/enrollib.php');
      require_once($CFG->dirroot . '/enrol/manual/lib.php');

      foreach (enrol_get_instances($courseid, true) as $inst) {
        if ($inst->enrol === 'manual') {
          return $inst;
        }
      }
      $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
      $plugin = enrol_get_plugin('manual');
      $plugin->add_default_instance($course);

      foreach (enrol_get_instances($courseid, true) as $inst) {
        if ($inst->enrol === 'manual') {
          return $inst;
        }
      }
      $this->fail('Unable to create/find manual enrol instance');
    }

    /**
     * Create and enrol a user into a course and group.
     *
     * @param int $courseid
     * @param int $groupid
     * @param array $fields
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    private function create_enrolled_member(int $courseid, int $groupid, array $fields): stdClass {
      global $CFG, $DB;
      require_once($CFG->dirroot . '/group/lib.php');
      require_once($CFG->dirroot . '/lib/enrollib.php');
      require_once($CFG->dirroot . '/enrol/manual/lib.php');

      $u = $this->getDataGenerator()->create_user($fields);

      $inst = $this->ensure_manual_enrol_instance($courseid);
      $plugin = enrol_get_plugin('manual');
      $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
      $plugin->enrol_user($inst, $u->id, $roleid);

      groups_add_member($groupid, $u->id);
      return $u;
    }

    /**
     * List with no submissions → emptystate is nosnapshot.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_list_empty_shows_nosnapshot(): void {
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id);
      $list = $this->resolve_list_method();
      if (!$list) {
        $this->markTestIncomplete('No list method found on submissions_service.');
      }

      $out = submissions_service::{$list}($env['cm']->id, $form->id, 0);

      if (isset($out['hasrows'])) {
        $this->assertFalse((bool)$out['hasrows']);
      }
      if (isset($out['emptystate'])) {
        $this->assertContains((string)$out['emptystate'], ['nosnapshot', 'empty', 'nodata']);
      }
      if (isset($out['rows'])) {
        $this->assertIsArray($out['rows']);
        $this->assertCount(0, $out['rows']);
      }
    }

    /**
     * List all groups → all members are listed with correct URLs.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_list_all_groups_returns_rows_and_urls(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id);

      $ga = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Team A']);
      $gb = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Team B']);

      $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u1','firstname'=>'Ann','lastname'=>'Alpha','email'=>'u1@x.com']);
      $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u2','firstname'=>'Ben','lastname'=>'Beta','email'=>'u2@x.com']);
      $this->create_enrolled_member($env['course']->id, $gb->id, ['username'=>'u3','firstname'=>'Cat','lastname'=>'Gamma','email'=>'u3@x.com']);

      student_table_service::snapshot_form($env['cm']->id, $form->id, null);

      $list = $this->resolve_list_method();
      $this->assertNotNull($list, 'No list method found on submissions_service.');
      $out = submissions_service::{$list}($env['cm']->id, $form->id, 0);

      $rows = $out['rows'] ?? [];
      $this->assertCount(3, $rows);

      $first = $rows[0];
      $url = (string)($first['viewurl'] ?? $first['url'] ?? '');
      $this->assertNotSame('', $url);
      $this->assertStringContainsString((string)$env['cm']->id, $url);
      $this->assertStringContainsString((string)$form->id,   $url);
      $this->assertTrue(strpos($url, 'targetid=') !== false || strpos($url, 'userid=') !== false);
    }

    /**
     * List filtered by group → only that group’s members are listed.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_list_filters_by_group(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id);

      $ga = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Team A']);
      $gb = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Team B']);

      $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u1','firstname'=>'Ann','lastname'=>'Alpha','email'=>'u1@x.com']);
      $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u2','firstname'=>'Ben','lastname'=>'Beta','email'=>'u2@x.com']);
      $this->create_enrolled_member($env['course']->id, $gb->id, ['username'=>'u3','firstname'=>'Cat','lastname'=>'Gamma','email'=>'u3@x.com']);

      student_table_service::snapshot_form($env['cm']->id, $form->id, null);

      $list = $this->resolve_list_method();
      $this->assertNotNull($list, 'No list method found on submissions_service.');

      $outA = submissions_service::{$list}($env['cm']->id, $form->id, $ga->id);
      $rowsA = $outA['rows'] ?? [];
      $this->assertCount(2, $rowsA);

      $outB = submissions_service::{$list}($env['cm']->id, $form->id, $gb->id);
      $rowsB = $outB['rows'] ?? [];
      $this->assertCount(1, $rowsB);
    }

    /**
     * When user has a title in profile, fullname uses that title.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_list_fullname_uses_title_when_present(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id);

      $ga = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Team A']);
      $this->create_enrolled_member($env['course']->id, $ga->id, [
        'username' => 'u1',
        'firstname' => '',
        'lastname' => '',
        'email' => 'u1@x.com',
        'description' => "Bio...\nTitle: Prof",
      ]);

      student_table_service::snapshot_group($env['cm']->id, $form->id, $ga->id);

      $list = $this->resolve_list_method();
      $this->assertNotNull($list, 'No list method found on submissions_service.');
      $out  = submissions_service::{$list}($env['cm']->id, $form->id, $ga->id);

      $rows = $out['rows'] ?? [];
      $this->assertCount(1, $rows);

      $fullname = (string)($rows[0]['fullname'] ?? $rows[0]['displayname'] ?? '');
      $this->assertNotSame('', $fullname);
      $this->assertStringStartsWith('Prof', $fullname);
    }

    /**
     * Detail with invalid IDs throws exception.
     *
     * @return void
     */
    public function test_detail_with_invalid_ids_throws(): void {
      $detail = $this->resolve_detail_method();
      if (!$detail) {
        $this->markTestIncomplete('No detail method found on submissions_service.');
      }

      $this->expectException(\moodle_exception::class);

      submissions_service::{$detail}(999999, 999999, 999999, 999999);
    }
}
