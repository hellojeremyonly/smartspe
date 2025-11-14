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
 * * Test cases for the student_details_service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\student\student_details_service;

/**
 * student_details_service_test class.
 */
final class student_details_service_test extends base_test {
  use smartspe_test_helpers;

  /**
   * Update form fields helper.
   *
   * @param stdClass $form
   * @param array $fields
   * @return stdClass
   * @throws dml_exception
   */
  private function update_form_fields(stdClass $form, array $fields): stdClass {
    global $DB;
    foreach ($fields as $k => $v) {
      $form->{$k} = $v;
    }
    $DB->update_record('smartspe_form', $form);
    return $DB->get_record('smartspe_form', ['id' => $form->id], '*', MUST_EXIST);
  }

  /**
   * get_published_form returns the published form.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_get_published_form_returns_form(): void {
    $svc = new student_details_service();
    $env = $this->seed_course_module();

    $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
    $pub = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

    $got = $svc->get_published_form($env['instance']->id, $env['course']->id, false);
    $this->assertEquals((int)$pub->id, (int)$got->id);
  }

  /**
   * get_published_form returns null when no published form.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_get_published_form_returns_null_when_missing(): void {
    $svc = new student_details_service();
    $env = $this->seed_course_module();

    $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
    $got = $svc->get_published_form($env['instance']->id, $env['course']->id, false);
    $this->assertNull($got);
  }

  /**
   * ensure_response creates and reuses response records.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_ensure_response_creates_and_reuses(): void {
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
    $user = $this->getDataGenerator()->create_user();

    $r1 = $svc->ensure_response($form->id, $user->id);
    $r2 = $svc->ensure_response($form->id, $user->id);

    $this->assertNotEmpty($r1->id);
    $this->assertEquals((int)$r1->id, (int)$r2->id);
    $this->assertSame(0, (int)$r1->status);
    $this->assertNull($r1->detailsjson);
    $this->assertGreaterThan(0, (int)$r1->timecreated);
    $this->assertGreaterThan(0, (int)$r1->timemodified);
  }

  /**
   * build_detail_labels parses and trims labels correctly.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_build_detail_labels_parses_and_trims(): void {
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

    $raw = "Student ID:\r\n Full Name ：\n\n Email: \n Team";
    $form = $this->update_form_fields($form, ['studentfields' => $raw]);

    $labels = $svc->build_detail_labels($form);
    $this->assertSame(['Student ID', 'Full Name', 'Email', 'Team'], $labels);
  }

  /**
   * Test round trip work cleanly.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_prefill_associative_passthrough(): void {
    global $DB;
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

    $resp = $svc->ensure_response($form->id, $this->getDataGenerator()->create_user()->id);
    $resp->detailsjson = json_encode(['Student ID' => 'S1', 'Email' => 'x@y.com'], JSON_UNESCAPED_UNICODE);
    $DB->update_record('smartspe_response', $resp);

    $fresh = $DB->get_record('smartspe_response', ['id' => $resp->id], '*', MUST_EXIST);
    $out = $svc->prefill_details($fresh, ['Student ID', 'Email']);
    $this->assertSame(['Student ID' => 'S1', 'Email' => 'x@y.com'], $out);
  }

  /**
   * prefill_legacy_array_maps_to_labels maps legacy array to labels.
   * @return void
   * @throws dml_exception
   */
  public function test_prefill_legacy_array_maps_to_labels(): void {
    global $DB;
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

    $labels = ['Student ID', 'Full Name', 'Email'];
    $resp = $svc->ensure_response($form->id, $this->getDataGenerator()->create_user()->id);
    $resp->detailsjson = json_encode(['S2', 'Alice Wonderland'], JSON_UNESCAPED_UNICODE);
    $DB->update_record('smartspe_response', $resp);

    $fresh = $DB->get_record('smartspe_response', ['id' => $resp->id], '*', MUST_EXIST);
    $out = $svc->prefill_details($fresh, $labels);

    $this->assertSame(['Student ID' => 'S2', 'Full Name' => 'Alice Wonderland', 'Email' => ''], $out);
  }

  /**
   * save_details_map persists and updates time.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_save_details_map_persists_and_updates_time(): void {
    global $DB;
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
    $user = $this->getDataGenerator()->create_user();

    $resp = $svc->ensure_response($form->id, $user->id);
    $before = (int)$resp->timemodified;

    $labels = ['Student ID', 'Full Name', 'Email'];
    $values = ['S9', 'Tony Tan', 12345];

    $svc->save_details_map($resp, $labels, $values);

    $fresh = $DB->get_record('smartspe_response', ['id' => $resp->id], '*', MUST_EXIST);
    $this->assertGreaterThanOrEqual($before, (int)$fresh->timemodified);

    $decoded = json_decode((string)$fresh->detailsjson, true);
    $this->assertSame(['Student ID' => 'S9', 'Full Name' => 'Tony Tan', 'Email' => '12345'], $decoded);
  }

  /**
   * test_unicode_and_fullwidth_colon_trim trims unicode colons.
   *
   * @return void
   * @throws dml_exception
   */
  public function test_unicode_and_fullwidth_colon_trim(): void {
    $svc = new student_details_service();
    $env = $this->seed_course_module();
    $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);

    $raw = "Student ID：\nFull Name：\nTeam：";
    $form = $this->update_form_fields($form, ['studentfields' => $raw]);

    $labels = $svc->build_detail_labels($form);
    $this->assertSame(['Student ID', 'Full Name', 'Team'], $labels);
  }
}
