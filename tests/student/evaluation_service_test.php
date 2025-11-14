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
 * Test cases for the evaluation service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\student\evaluation_service;

/**
 * evaluation_service_test class.
 */
final class evaluation_service_test extends base_test {
    use smartspe_test_helpers;

    /**
     * Ensure that a manual enrolment instance exists for the given course.
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
     * Create and enrol a user as a student in the given course and optional group.
     *
     * @param int $courseid
     * @param int|null $groupid
     * @param array $fields
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    private function create_enrolled_member(int $courseid, ?int $groupid, array $fields): stdClass {
      global $CFG, $DB;
      require_once($CFG->dirroot . '/group/lib.php');
      require_once($CFG->dirroot . '/lib/enrollib.php');
      require_once($CFG->dirroot . '/enrol/manual/lib.php');

      $defaults = [
        'firstnamephonetic' => '',
        'lastnamephonetic' => '',
        'middlename' => '',
        'alternatename' => '',
      ];
      $u = $this->getDataGenerator()->create_user(array_merge($defaults, $fields));
      $inst = $this->ensure_manual_enrol_instance($courseid);
      $plugin = enrol_get_plugin('manual');
      $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
      $plugin->enrol_user($inst, $u->id, $roleid);

      if ($groupid) {
        groups_add_member($groupid, $u->id);
      }
      return $u;
    }

    /**
     * Create a form for the given smartspe instance.
     *
     * @param int $formid
     * @param int $type
     * @param int $audience
     * @param string $text
     * @return int
     * @throws dml_exception
     */
    private function make_question(int $formid, int $type, int $audience, string $text): int {
      global $DB;
      return (int)$DB->insert_record('smartspe_question', (object)[
        'formid' => $formid,
        'questiontype' => $type,
        'audience' => $audience,
        'questiontext' => $text,
        'timecreated' => time(),
      ]);
    }

    /**
     * Test that get_published_form() returns the correct published form.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_get_published_form_returns_form(): void {
      $svc = new evaluation_service();
      $env = $this->seed_course_module();

      $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
      $pub = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

      $got = $svc->get_published_form($env['instance']->id);
      $this->assertEquals((int)$pub->id, (int)$got->id);
    }

    /**
     * Test that ensure_response() creates a new response or reuses existing one.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_ensure_response_creates_and_reuses(): void {
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
      $user = $this->getDataGenerator()->create_user();

      $r1 = $svc->ensure_response($form->id, $user->id);
      $r2 = $svc->ensure_response($form->id, $user->id);

      $this->assertNotEmpty($r1->id);
      $this->assertEquals((int)$r1->id, (int)$r2->id);
      $this->assertSame(0, (int)($r1->status ?? 0));
    }

    /**
     * Test that details_completed() correctly identifies completion based on detailsjson.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_details_completed_flag(): void {
      global $DB;
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
      $user = $this->getDataGenerator()->create_user();

      $resp = $svc->ensure_response($form->id, $user->id);
      $this->assertFalse($svc->details_completed($resp));

      $resp->detailsjson = json_encode(['studentid' => 'S123']);
      $DB->update_record('smartspe_response', $resp);

      $fresh = $DB->get_record('smartspe_response', ['id' => $resp->id], '*', MUST_EXIST);
      $this->assertTrue($svc->details_completed($fresh));
    }

    /**
     * Test that question filters for self and peer audiences work correctly.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_questions_filters_for_self_and_peer(): void {
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

      $qboth = $this->make_question($form->id, 1, 1, 'BOTH scale');
      $qself = $this->make_question($form->id, 2, 2, 'SELF text');
      $qpeer = $this->make_question($form->id, 1, 3, 'PEER scale');
      $qzero = $this->make_question($form->id, 1, 0, 'LEGACY BOTH');

      $self = $svc->get_questions_for_self($form->id);
      $peer = $svc->get_questions_for_peer($form->id);

      $selfids = array_map(fn($r) => (int)$r->id, $self);
      $peerids = array_map(fn($r) => (int)$r->id, $peer);

      $this->assertContains($qboth, $selfids);
      $this->assertContains($qself, $selfids);
      $this->assertContains($qzero, $selfids);
      $this->assertNotContains($qpeer, $selfids);

      $this->assertContains($qboth, $peerids);
      $this->assertContains($qpeer, $peerids);
      $this->assertContains($qzero, $peerids);
      $this->assertNotContains($qself, $peerids);
    }

    /**
     * Test that build_answers_payload() constructs correct payload structure.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_build_answers_payload_typing_and_presence(): void {
      $svc  = new evaluation_service();
      $env  = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

      $qscale = $this->make_question($form->id, 1, 1, 'Rate 1..5');
      $qtext = $this->make_question($form->id, 2, 1, 'Comment');

      $questions = $svc->get_questions($form->id);
      $payload = $svc->build_answers_payload(
        $questions,
        [$qscale => 4],
        [$qtext  => 'hello']
      );

      $this->assertArrayHasKey($qscale, $payload);
      $this->assertArrayHasKey($qtext,  $payload);
      $this->assertSame(4, $payload[$qscale]['scale']);
      $this->assertSame('hello', $payload[$qtext]['text']);
    }

    /**
     * Test that save_answers_self() inserts and updates self answers correctly,
     *
     * @return void
     * @throws dml_exception
     */
    public function test_save_answers_self_insert_update_clamp(): void {
      global $DB;
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
      $user = $this->getDataGenerator()->create_user();

      // Self-visible questions.
      $qscale = $this->make_question($form->id, 1, 2, 'Self scale');
      $qtext = $this->make_question($form->id, 2, 2, 'Self text');

      $resp = $svc->ensure_response($form->id, $user->id);

      $svc->save_answers_self($form->id, $resp->id, $user->id, [
        $qscale => ['type' => 1, 'scale' => 7],
        $qtext  => ['type' => 2, 'text'  => 'first'],
      ]);

      $rows = $DB->get_records('smartspe_answer', ['responseid' => $resp->id, 'targetid' => $user->id]);
      $this->assertCount(2, $rows);
      $byqid = [];
      foreach ($rows as $r) { $byqid[(int)$r->questionid] = $r; }
      $this->assertNull($byqid[$qscale]->scalevalue);
      $this->assertSame('first', (string)$byqid[$qtext]->textvalue);

      // Update scale into range and change text.
      $svc->save_answers_self($form->id, $resp->id, $user->id, [
        $qscale => ['type' => 1, 'scale' => 4],
        $qtext => ['type' => 2, 'text'  => 'second'],
      ]);

      $rows2 = $DB->get_records('smartspe_answer', ['responseid' => $resp->id, 'targetid' => $user->id]);
      $byqid2 = [];
      foreach ($rows2 as $r) { $byqid2[(int)$r->questionid] = $r; }

      $this->assertSame(4, (int)$byqid2[$qscale]->scalevalue);
      $this->assertSame('second', (string)$byqid2[$qtext]->textvalue);
    }

    /**
     * Test that save_answers_peer() only saves answers for valid peer targets.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_save_answers_peer_target_validation(): void {
      global $CFG, $DB;
      require_once($CFG->dirroot . '/group/lib.php');

      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

      $gA = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'A']);
      $gB = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'B']);

      // Evaluator and valid teammate in group A; outsider in group B.
      $eval  = $this->create_enrolled_member($env['course']->id, $gA->id, ['username'=>'e','firstname'=>'Eva','lastname'=>'Lu','email'=>'e@x.com']);
      $mateA = $this->create_enrolled_member($env['course']->id, $gA->id, ['username'=>'a','firstname'=>'Al','lastname'=>'Alpha','email'=>'a@x.com']);
      $mateB = $this->create_enrolled_member($env['course']->id, $gB->id, ['username'=>'b','firstname'=>'Bea','lastname'=>'Beta','email'=>'b@x.com']);

      $qscale = $this->make_question($form->id, 1, 3, 'Peer scale');
      $resp = $svc->ensure_response($form->id, $eval->id);

      $svc->save_answers_peer($env['course']->id, $form->id, $resp->id, $eval->id, $mateB->id, [
        $qscale => ['type' => 1, 'scale' => 5],
      ]);
      $this->assertCount(0, $DB->get_records('smartspe_answer', ['responseid' => $resp->id]));

      $svc->save_answers_peer($env['course']->id, $form->id, $resp->id, $eval->id, $mateA->id, [
        $qscale => ['type' => 1, 'scale' => 5],
      ]);
      $this->assertCount(1, $DB->get_records('smartspe_answer', ['responseid' => $resp->id, 'targetid' => $mateA->id]));
    }

    /**
     * Test that build_question_viewmodels() creates correct viewmodels
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_build_question_viewmodels_shapes_and_checked(): void {
      global $DB;
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
      $user = $this->getDataGenerator()->create_user();

      $qscale = $this->make_question($form->id, 1, 1, 'Rate 1..5');
      $qtext = $this->make_question($form->id, 2, 1, 'Comment');

      $resp = $svc->ensure_response($form->id, $user->id);
      $DB->insert_record('smartspe_answer', (object)[
        'responseid' => $resp->id,
        'questionid' => $qscale,
        'targetid' => $user->id,
        'scalevalue' => 3,
        'textvalue' => null,
      ]);
      $DB->insert_record('smartspe_answer', (object)[
        'responseid' => $resp->id,
        'questionid' => $qtext,
        'targetid' => $user->id,
        'scalevalue' => null,
        'textvalue' => 'hi',
      ]);

      $existing = $svc->get_existing_answers($resp->id, $user->id);
      $vms = $svc->build_question_viewmodels($svc->get_questions($form->id), $existing, $env['cm']->id);

      $this->assertCount(2, $vms);

      $scalevm = array_values(array_filter($vms, fn($vm) => $vm['id'] === $qscale))[0];
      $this->assertTrue((bool)$scalevm['isScale']);
      $this->assertCount(5, $scalevm['options']);
      $checked = array_values(array_filter($scalevm['options'], fn($o) => !empty($o['checked'])));
      $this->assertCount(1, $checked);
      $this->assertStringContainsString('-'.$qscale.'-3', (string)$checked[0]['inputid']);

      $textvm = array_values(array_filter($vms, fn($vm) => $vm['id'] === $qtext))[0];
      $this->assertTrue((bool)$textvm['isText']);
      $this->assertSame('hi', (string)$textvm['textvalue']);
    }

    /**
     * Test that mark_submitted() sets the response status to SUBMITTED.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_mark_submitted_sets_status(): void {
      global $DB;
      $svc = new evaluation_service();
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
      $user = $this->getDataGenerator()->create_user();

      $resp = $svc->ensure_response($form->id, $user->id);
      $this->assertSame(evaluation_service::RESPONSE_STATUS_DRAFT, (int)$resp->status);

      $svc->mark_submitted($resp->id);

      $fresh = $DB->get_record('smartspe_response', ['id' => $resp->id], '*', MUST_EXIST);
      $this->assertSame(evaluation_service::RESPONSE_STATUS_SUBMITTED, (int)$fresh->status);
    }

    /**
     * Test peer targets retrieval and navigation methods.
     *
     * @return void
     * @throws coding_exception
     */
    public function test_peer_targets_and_navigation(): void {
      global $CFG;
      require_once($CFG->dirroot . '/group/lib.php');

      $svc = new evaluation_service();
      $env = $this->seed_course_module();

      $gA = $this->getDataGenerator()->create_group(['courseid' => $env['course']->id, 'name' => 'A']);

      $eval = $this->create_enrolled_member($env['course']->id, $gA->id, ['username'=>'eva','firstname'=>'Eva','lastname'=>'Lu','email'=>'eva@x.com']);
      // Intentionally choose names to test ordering by fullname.
      $mate1 = $this->create_enrolled_member($env['course']->id, $gA->id, ['username'=>'a','firstname'=>'Al','lastname'=>'Alpha','email'=>'a@x.com']);
      $mate2 = $this->create_enrolled_member($env['course']->id, $gA->id, ['username'=>'b','firstname'=>'Bea','lastname'=>'Beta','email'=>'b@x.com']);

      $targets = $svc->get_peer_targets($env['course']->id, $eval->id);
      $this->assertDebuggingCalledCount(2);
      $ids = array_column($targets, 'id');

      $next0 = $svc->get_next_peer_target($env['course']->id, $eval->id, null);
      $this->assertDebuggingCalledCount(2);

      $next1 = $svc->get_next_peer_target($env['course']->id, $eval->id, $ids[0]);
      $this->assertDebuggingCalledCount(2);

      $this->assertTrue($svc->is_last_peer_target($env['course']->id, $eval->id, $ids[1]));
      $this->assertDebuggingCalledCount(2);

      $this->assertFalse($svc->is_last_peer_target($env['course']->id, $eval->id, $ids[0]));
      $this->assertDebuggingCalledCount(2);

      $prevUnknown = $svc->get_prev_peer_target($env['course']->id, $eval->id, 999999);
      $this->assertDebuggingCalledCount(2);

      $prev1 = $svc->get_prev_peer_target($env['course']->id, $eval->id, $ids[1]);
      $this->assertDebuggingCalledCount(2);

      $prev0 = $svc->get_prev_peer_target($env['course']->id, $eval->id, $ids[0]);
      $this->assertDebuggingCalledCount(2);
    }
}
