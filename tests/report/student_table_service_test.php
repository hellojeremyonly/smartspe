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
 * Test cases for the student table service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\report\student_table_service;

/**
 * student_table_service_test class.
 */
final class student_table_service_test extends base_test {
  use smartspe_test_helpers;

  // ---------- local helpers (test-only) ----------

  /** Ensure manual enrol instance exists and return it. */
  private function ensure_manual_enrol_instance(int $courseid): stdClass {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/lib/enrollib.php');
    require_once($CFG->dirroot . '/enrol/manual/lib.php');

    foreach (enrol_get_instances($courseid, true) as $inst) {
      if ($inst->enrol === 'manual') {
        return $inst;
      }
    }
    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $plugin  = enrol_get_plugin('manual');
    $plugin->add_default_instance($course);

    foreach (enrol_get_instances($courseid, true) as $inst) {
      if ($inst->enrol === 'manual') {
        return $inst;
      }
    }
    $this->fail('Unable to create/find manual enrol instance');
  }

  /** Create a user, enrol into course (as student), add to group, return user. */
  private function create_enrolled_member(int $courseid, int $groupid, array $fields): stdClass {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/group/lib.php');
    require_once($CFG->dirroot . '/lib/enrollib.php');
    require_once($CFG->dirroot . '/enrol/manual/lib.php');

    $u = $this->getDataGenerator()->create_user($fields);

    $inst   = $this->ensure_manual_enrol_instance($courseid);
    $plugin = enrol_get_plugin('manual');
    $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $plugin->enrol_user($inst, $u->id, $roleid);

    groups_add_member($groupid, $u->id);
    return $u;
  }

  // ---------- tests ----------

  public function test_snapshot_form_all_groups_inserts_rows(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env   = $this->seed_course_module();
    $form  = $this->make_form($env['instance']->id);
    $ga    = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'A']);
    $gb    = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'B']);

    $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u1','firstname'=>'Ann','lastname'=>'Alpha','email'=>'u1@x.com']);
    $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u2','firstname'=>'Ben','lastname'=>'Beta','email'=>'u2@x.com']);
    $this->create_enrolled_member($env['course']->id, $gb->id, ['username'=>'u3','firstname'=>'Cat','lastname'=>'Gamma','email'=>'u3@x.com']);

    $stats = student_table_service::snapshot_form($env['cm']->id, $form->id, null);

    $this->assertSame(2, $stats['totalgroups']);
    $this->assertSame(3, $stats['totalmembers']);
    $this->assertSame(3, $stats['inserted']);
    $this->assertSame(0, $stats['updated']);

    $rows = $DB->get_records('smartspe_user_report', ['formid' => $form->id], 'id ASC');
    $this->assertCount(3, $rows);

    // Field mapping sanity (pick one row).
    $one = reset($rows);
    $this->assertNotEmpty($one->studentid);
    $this->assertNotEmpty($one->surname);
    $this->assertNotEmpty($one->givenname);
    $this->assertNull($one->preferredname);
  }

  public function test_snapshot_group_only_selected_group(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env  = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id);
    $ga   = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'A']);
    $gb   = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'B']);

    $this->create_enrolled_member($env['course']->id, $ga->id, ['username'=>'u1','firstname'=>'Ann','lastname'=>'Alpha','email'=>'u1@x.com']);
    $this->create_enrolled_member($env['course']->id, $gb->id, ['username'=>'u2','firstname'=>'Ben','lastname'=>'Beta','email'=>'u2@x.com']);

    $stats = student_table_service::snapshot_group($env['cm']->id, $form->id, $ga->id);

    $this->assertSame(1, $stats['totalgroups']);
    $this->assertSame(1, $stats['totalmembers']);
    $this->assertSame(1, $stats['inserted']);

    $rowsA = $DB->get_records('smartspe_user_report', ['formid' => $form->id, 'groupid' => $ga->id]);
    $rowsB = $DB->get_records('smartspe_user_report', ['formid' => $form->id, 'groupid' => $gb->id]);
    $this->assertCount(1, $rowsA);
    $this->assertCount(0, $rowsB);
  }

  public function test_snapshot_is_idempotent_updates_instead_of_duplicate(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env  = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id);
    $g    = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Solo']);

    $this->create_enrolled_member($env['course']->id, $g->id, ['username'=>'u1','firstname'=>'Ann','lastname'=>'Alpha','email'=>'u1@x.com']);

    $stats1 = student_table_service::snapshot_group($env['cm']->id, $form->id, $g->id);
    $this->assertSame(1, $stats1['inserted']);
    $this->assertSame(0, $stats1['updated']);

    $stats2 = student_table_service::snapshot_group($env['cm']->id, $form->id, $g->id);
    $this->assertSame(0, $stats2['inserted']);
    $this->assertSame(1, $stats2['updated']);

    $this->assertCount(1, $DB->get_records('smartspe_user_report', ['formid' => $form->id, 'groupid' => $g->id]));
  }

  public function test_title_is_extracted_from_description(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env  = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id);
    $g    = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'T']);

    $this->create_enrolled_member($env['course']->id, $g->id, [
      'username'    => 'u1',
      'firstname'   => 'Tina',
      'lastname'    => 'Title',
      'email'       => 'u1@x.com',
      'description' => "Bio...\nTitle: Prof.",
    ]);

    $stats = student_table_service::snapshot_group($env['cm']->id, $form->id, $g->id);
    $this->assertSame(1, $stats['inserted']);

    $row = $DB->get_record('smartspe_user_report', ['formid'=>$form->id,'groupid'=>$g->id], '*', MUST_EXIST);
    $this->assertSame('Prof', (string)$row->title);
  }

  public function test_keep_existing_title_when_description_has_no_title(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env  = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id);
    $g    = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Keep']);

    $u = $this->create_enrolled_member($env['course']->id, $g->id, [
      'username'=>'u1','firstname'=>'Kim','lastname'=>'Keep','email'=>'u1@x.com',
      'description' => "No title line here.",
    ]);

    // First snapshot inserts with no title.
    student_table_service::snapshot_group($env['cm']->id, $form->id, $g->id);
    $row = $DB->get_record('smartspe_user_report', ['formid'=>$form->id,'groupid'=>$g->id,'userid'=>$u->id], '*', MUST_EXIST);
    $row->title = 'Dr';
    $DB->update_record('smartspe_user_report', $row);

    // Run snapshot again; description still has no Title:, should keep 'Dr'.
    $stats = student_table_service::snapshot_group($env['cm']->id, $form->id, $g->id);
    $this->assertSame(1, $stats['updated']);

    $row2 = $DB->get_record('smartspe_user_report', ['formid'=>$form->id,'groupid'=>$g->id,'userid'=>$u->id], '*', MUST_EXIST);
    $this->assertSame('Dr', (string)$row2->title);
  }

  public function test_empty_or_unknown_group_is_skipped_gracefully(): void {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/group/lib.php');

    $env  = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id);

    // Create an empty group.
    $empty = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'Empty']);

    // Snapshot the empty group.
    $statsEmpty = student_table_service::snapshot_group($env['cm']->id, $form->id, $empty->id);
    $this->assertSame(1, $statsEmpty['totalgroups']);
    $this->assertSame(0, $statsEmpty['totalmembers']);
    $this->assertSame(0, $statsEmpty['inserted']);

    // Also try a made-up group id; service should just skip.
    $statsUnknown = student_table_service::snapshot_form($env['cm']->id, $form->id, [999999]);
    $this->assertSame(1, $statsUnknown['totalgroups']);
    $this->assertSame(0, $statsUnknown['totalmembers']);
    $this->assertSame(0, $statsUnknown['inserted']);

    $this->assertCount(0, $DB->get_records('smartspe_user_report', ['formid' => $form->id]));
  }
}
