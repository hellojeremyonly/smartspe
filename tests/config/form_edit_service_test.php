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
 * Test cases for the form edit service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\config\form_edit_service;

/**
 * form_edit_service_test class.
 */
final class form_edit_service_test extends base_test {
    use smartspe_test_helpers;

    /**
     * is_archived(): true for archived forms, false otherwise.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_is_archived_true_false_and_missing(): void {
        $env = $this->seed_course_module();
        $draft = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
        $pub = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED]);
        $arch = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_ARCHIVED]);

        $svc = new form_edit_service();

        $this->assertFalse($svc->is_archived($draft->id));
        $this->assertFalse($svc->is_archived($pub->id));
        $this->assertTrue($svc->is_archived($arch->id));
        $this->assertFalse($svc->is_archived(0));
    }

    /**
     * get_customdata_for_form(): returns repeatcount and questions; repeatcount min 2, plus 1 if adding.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_get_customdata_for_form_repeatcount_and_questions(): void {
        $env = $this->seed_course_module();
        $svc = new form_edit_service();
        $this->assertSame([], $svc->get_customdata_for_form(0, false));

        $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
        $this->seed_questions_safe($form->id, 2);

        $cd = $svc->get_customdata_for_form($form->id, false);
        $this->assertIsArray($cd);
        $this->assertArrayHasKey('repeatcount', $cd);
        $this->assertArrayHasKey('questions', $cd);
        $this->assertSame(2, (int)$cd['repeatcount']);
        $this->assertCount(2, $cd['questions']);

        $cdadd = $svc->get_customdata_for_form($form->id, true);
        $this->assertSame(3, (int)$cdadd['repeatcount']);
    }

    /**
     * prepare_existing_for_edit(): shapes fields for editing existing form.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_prepare_existing_for_edit_shapes_fields(): void {
        $env = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id, [
            'status' => smartspe_test_constants::FORM_DRAFT,
            'title' => 'Edit Me',
            'instruction' => 'Inst',
            'studentfields' => 'Fields',
        ]);
        $this->seed_questions_safe($form->id, 3, [0,1,2], [0,1,2], ['Q1','Q2','Q3']);

        $svc = new form_edit_service();
        $data = $svc->prepare_existing_for_edit($form->id);

        $this->assertIsObject($data);
        $this->assertIsArray($data->instruction);
        $this->assertIsArray($data->studentfields);
        $this->assertArrayHasKey('text', $data->instruction);
        $this->assertArrayHasKey('text', $data->studentfields);

        $this->assertIsArray($data->questiontext);
        $this->assertIsArray($data->questiontype);
        $this->assertIsArray($data->audience);
        $this->assertCount(3, $data->questiontext);
        $this->assertCount(3, $data->questiontype);
        $this->assertCount(3, $data->audience);
        $this->assertSame('Q1', $data->questiontext[0]['text']);
        $this->assertSame('Q2', $data->questiontext[1]['text']);
        $this->assertSame('Q3', $data->questiontext[2]['text']);
    }

    /**
     * save_from_mform(): create new form with questions, skipping empty ones.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_save_creates_skipping_empty_questions(): void {
        global $DB;
        $env = $this->seed_course_module();

        $svc = new form_edit_service();

        // Create with some blanks to ensure skip behaviour.
        $data = $this->build_mform_data(
            'Form A', 'Inst', 'Fields',
            ['Q1', '', 'Q3', '   '],
            [0, 0, 2, 1],
            [0, 1, 2, 0]
        );

        $newid = $svc->save_from_mform($data, $env['instance']->id, null);
        $this->assertGreaterThan(0, $newid);

        $rows = array_values($DB->get_records('smartspe_question', ['formid' => $newid], 'id ASC'));
        $this->assertCount(2, $rows);
        $this->assertSame('Q1', $rows[0]->questiontext);
        $this->assertSame('Q3', $rows[1]->questiontext);

        // Check form fields persisted.
        $formrec = $DB->get_record('smartspe_form', ['id' => $newid], 'title,instruction,studentfields', MUST_EXIST);
        $this->assertSame('Form A', (string)$formrec->title);
        $this->assertSame('Inst', (string)$formrec->instruction);
        $this->assertSame('Fields', (string)$formrec->studentfields);
    }

  /**
   * save_from_mform(): update existing, replacing questions and updating fields.
   *
   * @return void
   * @throws dml_exception
   */
    public function test_update_replaces_questions(): void {
        global $DB;
        $env = $this->seed_course_module();

        $svc = new form_edit_service();
        $formid = $svc->save_from_mform(
            $this->build_mform_data('Base', 'I', 'S', ['A', 'B'], [0, 1], [0, 1]),
            $env['instance']->id,
            null
        );

        $updated = $this->build_mform_data('Base 2', 'I2', 'S2', ['Qx'], [1], [2]);
        $retid = $svc->save_from_mform($updated, $env['instance']->id, $formid);
        $this->assertSame($formid, $retid);

        $rows2 = array_values($DB->get_records('smartspe_question', ['formid' => $formid], 'id ASC'));
        $this->assertCount(1, $rows2);
        $this->assertSame('Qx', $rows2[0]->questiontext);
        $this->assertSame(1, (int)$rows2[0]->questiontype);
        $this->assertSame(2, (int)$rows2[0]->audience);

        $formrec = $DB->get_record('smartspe_form', ['id' => $formid], 'title,instruction,studentfields', MUST_EXIST);
        $this->assertSame('Base 2', (string)$formrec->title);
        $this->assertSame('I2', (string)$formrec->instruction);
        $this->assertSame('S2', (string)$formrec->studentfields);
    }

    /**
     * build_mform_data(): helper to build mform-like data object.
     *
     * @param string $title
     * @param string $instruction
     * @param string $studentfields
     * @param array $qtexts
     * @param array $qtypes
     * @param array $audiences
     * @return stdClass
     */
    private function build_mform_data(string $title, string $instruction, string $studentfields,
                                      array $qtexts, array $qtypes, array $audiences): \stdClass {
        $obj = new \stdClass();
        $obj->title = $title;
        $obj->instruction = ['text' => $instruction, 'format' => FORMAT_HTML];
        $obj->studentfields = ['text' => $studentfields, 'format' => FORMAT_HTML];

        $obj->questiontext = [];
        foreach ($qtexts as $qt) {
            $obj->questiontext[] = ['text' => $qt, 'format' => FORMAT_HTML];
        }
        $obj->questiontype = $qtypes;
        $obj->audience = $audiences;
        return $obj;
    }

    /**
     * seed_questions_safe(): seeds questions, compatible with older test base classes lacking seed_questions().
     *
     * @param int $formid
     * @param int $count
     * @param array|null $types
     * @param array|null $audiences
     * @param array|null $labels
     * @return void
     * @throws dml_exception
     */
    private function seed_questions_safe(int $formid, int $count, ?array $types = null, ?array $audiences = null, ?array $labels = null): void {
        if (method_exists($this, 'seed_questions')) {
            $this->seed_questions($formid, $count);
            return;
        }
        global $DB;
        for ($i = 1; $i <= $count; $i++) {
            $DB->insert_record('smartspe_question', (object) [
                'formid' => $formid,
                'questiontext' => $labels[$i-1] ?? ('Q' . $i),
                'questiontype' => (int)($types[$i-1] ?? 0),
                'audience' => (int)($audiences[$i-1] ?? 0),
            ]);
        }
    }

    /**
     * prepare_existing_for_edit(): throws exception when formid is missing.
     *
     * @return void
     */
    public function test_prepare_existing_for_edit_throws_for_missing(): void {
      $this->expectException(dml_missing_record_exception::class);
      (new form_edit_service())->prepare_existing_for_edit(999999);
    }

    /**
     * save_from_mform(): throws exception when updating with missing formid.
     *
     * @return void
     */
    public function test_save_update_with_missing_formid_throws(): void {
      $env = $this->seed_course_module();
      $svc = new form_edit_service();
      $data = $this->build_mform_data('T','I','S',['Q1'],[1],[2]);
      $this->expectException(\dml_exception::class);
      $svc->save_from_mform($data, $env['instance']->id, 999999);
    }

    /**
     * get_customdata_for_form(): enforces min repeatcount of 2 when no questions exist.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_customdata_min_repeatcount_when_zero_questions(): void {
      $env = $this->seed_course_module();
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
      $svc = new form_edit_service();
      $cd = $svc->get_customdata_for_form($form->id, false);
      $this->assertSame(2, (int)$cd['repeatcount']);
      $cdAdd = $svc->get_customdata_for_form($form->id, true);
      $this->assertSame(2, (int)$cdAdd['repeatcount']);
    }

    /**
     * save_from_mform(): skips questions that are only whitespace.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_save_skips_whitespace_only_questions(): void {
      global $DB;
      $env = $this->seed_course_module();
      $svc = new form_edit_service();
      $data = $this->build_mform_data('T','I','S', ['Q1','  ','Q3'], [0,0,0], [0,0,0]);
      $id = $svc->save_from_mform($data, $env['instance']->id, null);
      $rows = $DB->get_records('smartspe_question', ['formid' => $id]);
      $this->assertCount(2, $rows);
    }

    /**
     * save_from_mform(): applies defaults when types or audience arrays are missing entries.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_defaults_when_types_or_audience_missing(): void {
      global $DB;
      $env = $this->seed_course_module();
      $svc = new form_edit_service();
      $data = $this->build_mform_data('T','I','S', ['Q1','Q2'], /*types*/[1], /*aud*/[]);
      $id = $svc->save_from_mform($data, $env['instance']->id, null);
      $rows = array_values($DB->get_records('smartspe_question', ['formid' => $id], 'id ASC'));
      $this->assertSame(1, (int)$rows[0]->questiontype);
      $this->assertSame(3, (int)$rows[0]->audience);
      $this->assertSame(1, (int)$rows[1]->questiontype);
      $this->assertSame(3, (int)$rows[1]->audience);
    }
}
