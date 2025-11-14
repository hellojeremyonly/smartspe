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
 * * Test cases for the analysis service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\report\analysis_service;

/**
 * analysis_service_test class.
 */
final class analysis_service_test extends base_test {
    use smartspe_test_helpers;

    /**
     * @var array to cache response id.
     */
    private array $responsebyrater = [];

    /**
     * Insert a question for given form and return its id.
     *
     * @param int $formid
     * @param string $label
     * @return int
     * @throws dml_exception
     */
    private function insert_question(int $formid, string $label = 'Q text'): int {
      global $DB;
      return (int)$DB->insert_record('smartspe_question', (object)[
        'formid' => $formid,
        'questiontype' => 0,
        'audience' => 0,
        'questiontext' => $label,
        'timecreated' => time(),
        'timemodified' => time(),
      ]);
    }

    /** Create a user, enrol into course, add to group, and return the user. */
    private function create_member(int $courseid, int $groupid, array $fields): stdClass {
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
     * Add a numeric answer record for given parameters.
     *
     * @param int $formid
     * @param int $raterid
     * @param int $targetid
     * @param int $questionid
     * @param float $value
     * @param int $when
     * @return void
     * @throws dml_exception
     */
    private function add_numeric_answer(int $formid, int $raterid, int $targetid, int $questionid, float $value, int $when): void {
      global $DB;
      $key = $formid . ':' . $raterid;

      if (!isset($this->responsebyrater[$key])) {
        $this->responsebyrater[$key] = (int)$DB->insert_record('smartspe_response', (object)[
          'formid' => $formid,
          'studentid' => $raterid,
          'timecreated' => $when,
        ]);
      }
      $responseid = $this->responsebyrater[$key];

      $DB->insert_record('smartspe_answer', (object)[
        'responseid' => $responseid,
        'targetid' => $targetid,
        'questionid' => $questionid,
        'scalevalue' => $value,
        'textvalue' => null,
      ]);
    }

    /**
     * Ensure a manual enrol instance exists for given course and return it.
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
     * Test that table headers include correct question counts and labels.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_table_headers_counts_and_labels(): void {
      $env  = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id);

      $this->insert_question($form->id, 'Q1');
      $this->insert_question($form->id, 'Q2');
      $this->insert_question($form->id, 'Q3');

      $out = analysis_service::table_headers_for_form($form->id);
      $this->assertIsArray($out);
      $this->assertArrayHasKey('questions', $out);

      $qs = $out['questions'];
      $this->assertCount(3, $qs);
      $this->assertSame('Q1', (string)$qs[0]['label']);
      $this->assertSame('Q2', (string)$qs[1]['label']);
      $this->assertSame('Q3', (string)$qs[2]['label']);
    }

    /**
     * Test that build_team_matrix returns noquestions when no questions exist.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_build_team_matrix_no_questions_returns_noquestions(): void {
      [$env, $form, $group] = $this->sm_make_env_with_group('Team Z');

      $out = analysis_service::build_team_matrix($env['cm']->id, $form->id, $group->id);

      $this->assertIsArray($out);
      $this->assertArrayHasKey('hasdata', $out);
      $this->assertFalse((bool)$out['hasdata']);
      $this->assertSame('noquestions', (string)$out['emptyreason']);
      $this->assertSame(5, (int)$out['leftcols']);
      $this->assertSame([], $out['targets']);
      $this->assertSame([], $out['roster']);
    }

    /**
     * Test that build_team_matrix returns nomembers when group has no members.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_build_team_matrix_nomembers_returns_nomembers(): void {
      [$env, $form, $group] = $this->sm_make_env_with_group('Empty Team');

      $this->insert_question($form->id, 'Q1');

      $out = analysis_service::build_team_matrix($env['cm']->id, $form->id, $group->id);

      $this->assertFalse((bool)$out['hasdata']);
      $this->assertSame('nomembers', (string)$out['emptyreason']);
      $this->assertSame([], $out['targets']);
      $this->assertSame([], $out['roster']);
    }

    /**
     * Test that build_team_matrix with no responses gives empty blocks and labels.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_build_team_matrix_noresponses_gives_empty_blocks_and_labels(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
      $q1 = $this->insert_question($form->id, 'Q1');
      $q2 = $this->insert_question($form->id, 'Q2');

      $a = $this->create_member($env['course']->id, $group->id, [
        'username' => 'a1', 'firstname' => 'Ann', 'lastname' => 'Alpha', 'email' => 'a1@example.com',
      ]);
      $b = $this->create_member($env['course']->id, $group->id, [
        'username' => 'b1', 'firstname' => 'Bob', 'lastname' => 'Beta', 'email' => 'b1@example.com',
      ]);

      $out = analysis_service::build_team_matrix($env['cm']->id, $form->id, $group->id);

      $this->assertFalse((bool)$out['hasdata']);
      $this->assertSame('noresponses', (string)($out['emptyreason'] ?? ''));
      $this->assertSame(5, (int)$out['leftcols']);

      $this->assertCount(2, $out['targets']);
      foreach ($out['targets'] as $t) {
        $this->assertSame(['1','2'], $t['criteria']);
        $this->assertSame(['',''], $t['avgpercriterion']);
        $this->assertSame('', $t['overall']);
        $this->assertSame(3, (int)$t['colspan']); // qcount(2)+1
      }

      $this->assertCount(2, $out['roster']);
      foreach ($out['roster'] as $row) {
        $this->assertCount(2, $row['blocks']);
        foreach ($row['blocks'] as $blk) {
          $this->assertSame(['',''], $blk['scores']);
          $this->assertSame('', $blk['average']);
        }
      }
    }

    /**
     * Test that title extraction populates displayname and roster title.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_title_extraction_populates_displayname_and_roster_title(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      [$env, $form, $group] = $this->sm_make_env_with_group('Team T');
      $this->insert_question($form->id, 'Q1');

      $u = $this->create_member($env['course']->id, $group->id, [
        'username' => 't1',
        'firstname' => 'Tina',
        'lastname' => 'Title',
        'email' => 't1@example.com',
        'description' => "Existing bio\nTitle: Prof",
      ]);

      $out = analysis_service::build_team_matrix($env['cm']->id, $form->id, $group->id);

      $this->assertCount(1, $out['targets']);
      $t = $out['targets'][0];
      $this->assertStringStartsWith('Prof ', (string)$t['displayname']);
      $this->assertSame('Prof', (string)$out['roster'][0]['title']);
    }

    /**
     * Test that latest answers win and footer overall is half mean of per-criterion averages.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_latest_answers_win_and_footer_overall_is_half_mean_of_percriterion(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      [$env, $form, $group] = $this->sm_make_env_with_group('Team M');
      $q1 = $this->insert_question($form->id, 'Q1');
      $q2 = $this->insert_question($form->id, 'Q2');

      $a = $this->create_member($env['course']->id, $group->id, [
        'username' => 'a1', 'firstname' => 'Ann', 'lastname' => 'Alpha', 'email' => 'a1@example.com',
      ]);
      $b = $this->create_member($env['course']->id, $group->id, [
        'username' => 'b1', 'firstname' => 'Bob', 'lastname' => 'Beta', 'email' => 'b1@example.com',
      ]);

      $base = time();

      $this->add_numeric_answer($form->id, $a->id, $a->id, $q1, 1.0, $base);
      $this->add_numeric_answer($form->id, $a->id, $a->id, $q1, 4.0, $base + 10);
      $this->add_numeric_answer($form->id, $a->id, $a->id, $q2, 1.0, $base + 5);
      $this->add_numeric_answer($form->id, $a->id, $a->id, $q2, 2.0, $base + 15);

      $this->add_numeric_answer($form->id, $b->id, $a->id, $q1, 1.0, $base + 2);
      $this->add_numeric_answer($form->id, $b->id, $a->id, $q1, 2.0, $base + 12);
      $this->add_numeric_answer($form->id, $b->id, $a->id, $q2, 3.0, $base + 4);
      $this->add_numeric_answer($form->id, $b->id, $a->id, $q2, 4.0, $base + 14);

      $out = analysis_service::build_team_matrix($env['cm']->id, $form->id, $group->id);
      $this->assertTrue((bool)$out['hasdata']);

      $t0 = $out['targets'][0];
      $this->assertSame(['1','2'], $t0['criteria']);
      $this->assertSame(['3.00','3.00'], $t0['avgpercriterion']);
      $this->assertSame('1.50', $t0['overall']);

      $r0blk0 = $out['roster'][0]['blocks'][0];
      $this->assertSame(['4.00','2.00'], $r0blk0['scores']);
      $this->assertSame('3.00', $r0blk0['average']);
  }
}
