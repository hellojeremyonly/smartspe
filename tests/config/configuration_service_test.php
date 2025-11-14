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
 * Test cases for the configuration service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\config\configuration_service;

/**
 * configuration_service_test class.
 */
class configuration_service_test extends \base_test {
    use smartspe_test_helpers;

    /**
     * Test environment is working correctly.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_smoke_environment(): void {
        $env = $this->seed_course_module();
        $this->set_page_context($env['cm'], $env['ctx']);
        $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
        $this->assertNotEmpty($form->id);

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_smartspe');
        $this->assertInstanceOf(mod_smartspe_generator::class, $gen);
    }

    /**
     * Test that status_label method maps statuses to non-empty labels.
     *
     * @return void
     * @throws ReflectionException
     */
    public function test_status_label_mapping(): void {
        $rm = new \ReflectionMethod(configuration_service::class, 'status_label');
        $rm->setAccessible(true);

        // Reflection to call private static method.
        $draft = $rm->invoke(null, smartspe_test_constants::FORM_DRAFT);
        $published = $rm->invoke(null, smartspe_test_constants::FORM_PUBLISHED);
        $archived = $rm->invoke(null, smartspe_test_constants::FORM_ARCHIVED);

        $this->assertNotEmpty($draft);
        $this->assertNotEmpty($published);
        $this->assertNotEmpty($archived);
        $this->assertNotEquals($draft, $published);
    }

    /**
     * Test that display method decorates forms with statuslabel property.
     *
     * @return string|null
     */
    private function resolve_display_method(): ?string {
        foreach (['build_forms_for_display', 'decorate_forms_for_display', 'list_forms_for_display'] as $candidate) {
            if (method_exists(configuration_service::class, $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Test that display method decorates forms with statuslabel property.
     *
     * @return string|null
     */
    private function resolve_archived_method(): ?string {
        foreach (['archived_forms_dropdown', 'build_archived_options', 'list_archived_forms'] as $candidate) {
            if (method_exists(configuration_service::class, $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Test that archived forms dropdown/listing methods return forms ordered by timemodified DESC.
     *
     * @return void
     */
    public function test_archived_dropdown_ordering_if_available(): void {
        $env = $this->seed_course_module();
        $this->set_page_context($env['cm'], $env['ctx']);
        $base = time();

        // Create with explicit times instead of freeze/advance helpers.
        $old = $this->make_form($env['instance']->id, [
          'title' => 'Old',
          'status' => smartspe_test_constants::FORM_ARCHIVED,
          'timecreated' => $base,
          'timemodified' => $base,
        ]);
        $mid = $this->make_form($env['instance']->id, [
          'title' => 'Mid',
          'status' => smartspe_test_constants::FORM_ARCHIVED,
          'timecreated' => $base + 10,
          'timemodified' => $base + 10,
        ]);
        $new = $this->make_form($env['instance']->id, [
          'title' => 'New',
          'status' => smartspe_test_constants::FORM_ARCHIVED,
          'timecreated' => $base + 20,
          'timemodified' => $base + 20,
        ]);

        $method = $this->resolve_archived_method();
        if (!$method) {
            $this->markTestIncomplete('No archived list/dropdown method found (expect archived_forms_dropdown/build_archived_options/list_archived_forms).');
        }

        // Call the method.
        $opts = configuration_service::{$method}($env['instance']->id, $mid->id);

        // Extract labels.
        $labels = [];
        if (is_array($opts)) {
            foreach ($opts as $k => $v) {
                $labels[] = is_array($v) ? (string)($v['label'] ?? $v['name'] ?? '') : (string)$v;
            }
        }
        $this->assertGreaterThanOrEqual(3, count($labels));
        $this->assertSame('New', $labels[0]);
        $this->assertSame('Mid', $labels[1]);
        $this->assertSame('Old', $labels[2]);
    }

    /**
     * Test that build_forms_for_display method builds buttons and urls correctly.
     *
     * @return void
     */
    public function test_build_forms_buttons_and_urls(): void {
        $env = $this->seed_course_module();
        $this->set_page_context($env['cm'], $env['ctx']);

        $draft = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT, 'title' => 'Draft']);
        $pub = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_PUBLISHED, 'title' => 'Pub']);

        global $PAGE;
        $forms = configuration_service::build_forms_for_display(
            $env['cm'], $env['ctx'], $PAGE->get_renderer('core')
        );

        // Index by title for easy assertions.
        $bytitle = [];
        foreach ($forms as $f) {
            $bytitle[(string)$f->title] = $f;
        }
        $this->assertArrayHasKey('Draft', $bytitle);
        $this->assertArrayHasKey('Pub', $bytitle);

        $drafthtml = (string)$bytitle['Draft']->publishbutton . ' ' . (string)$bytitle['Draft']->archivebutton;
        $pubhtml = (string)$bytitle['Pub']->publishbutton   . ' ' . (string)$bytitle['Pub']->archivebutton;

        // Draft: publish + archive, with sesskey and correct ids.
        $this->assertStringContainsString('/mod/smartspe/config/form_publish.php', $drafthtml);
        $this->assertStringContainsString('action=publish', $drafthtml);
        $this->assertStringContainsString('sesskey=', $drafthtml);
        $this->assertStringContainsString('id=' . $env['cm']->id, $drafthtml);
        $this->assertStringContainsString('formid=' . $draft->id, $drafthtml);
        $this->assertStringContainsString('/mod/smartspe/config/form_archive.php', $drafthtml);

        // Published: unpublish + archive, with sesskey and correct ids.
        $this->assertStringContainsString('/mod/smartspe/config/form_publish.php', $pubhtml);
        $this->assertStringContainsString('action=unpublish', $pubhtml);
        $this->assertStringContainsString('sesskey=', $pubhtml);
        $this->assertStringContainsString('id=' . $env['cm']->id, $pubhtml);
        $this->assertStringContainsString('formid=' . $pub->id, $pubhtml);
        $this->assertStringContainsString('/mod/smartspe/config/form_archive.php', $pubhtml);
    }

    /**
     * Test that edit button does not have sesskey.
     *
     * @return void
     */
    public function test_edit_button_has_no_sesskey(): void {
        $env = $this->seed_course_module();
        $this->set_page_context($env['cm'], $env['ctx']);
        $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT, 'title' => 'Draft']);

        global $PAGE;
        $forms = configuration_service::build_forms_for_display(
            $env['cm'], $env['ctx'], $PAGE->get_renderer('core')
        );

        $target = null;
        foreach ($forms as $f) {
            if ((int)$f->id === (int)$form->id) {
                $target = $f; break;
            }
        }
        $this->assertNotNull($target);
        $html = (string)$target->editbutton;

        $this->assertStringContainsString('/mod/smartspe/config/form_edit.php', $html);
        $this->assertStringContainsString('id=' . $env['cm']->id, $html);
        $this->assertStringContainsString('formid=' . $form->id, $html);
        $this->assertStringNotContainsString('sesskey=', $html, 'Edit must not include sesskey');
    }

    /**
     * Test archived forms dropdown selection flag and empty case.
     *
     * @return void
     */
    public function test_archived_dropdown_selection_flag_and_empty_case(): void {
      $env = $this->seed_course_module();
      $this->set_page_context($env['cm'], $env['ctx']);

      // Empty case: no archived forms yet.
      $opts = configuration_service::archived_forms_dropdown($env['instance']->id, 0);
      $this->assertIsArray($opts);
      $this->assertCount(0, $opts);

      // Now create three archived and pick one as selected.
      $base = time();
      $old = $this->make_form($env['instance']->id, ['title' => 'Old', 'status' => smartspe_test_constants::FORM_ARCHIVED, 'timemodified' => $base]);
      $mid = $this->make_form($env['instance']->id, ['title' => 'Mid', 'status' => smartspe_test_constants::FORM_ARCHIVED, 'timemodified' => $base+10]);
      $new = $this->make_form($env['instance']->id, ['title' => 'New', 'status' => smartspe_test_constants::FORM_ARCHIVED, 'timemodified' => $base+20]);

      $opts = configuration_service::archived_forms_dropdown($env['instance']->id, $mid->id);
      $this->assertCount(3, $opts);

      // newest first
      $this->assertSame('New', $opts[0]['name']);
      $this->assertSame('Mid', $opts[1]['name']);
      $this->assertSame('Old', $opts[2]['name']);

      // selection flag
      $byid = [];
      foreach ($opts as $o) { $byid[$o['id']] = $o; }
      $this->assertTrue($byid[$mid->id]['selected']);
      $this->assertFalse($byid[$old->id]['selected']);
      $this->assertFalse($byid[$new->id]['selected']);
    }

    /**
     * Test that table_headers_for_form method counts questions correctly.
     *
     * @return void
     * @throws dml_exception
     */
    public function test_table_headers_fallback_counts_questions(): void {
      global $DB;
      $env = $this->seed_course_module();
      $this->set_page_context($env['cm'], $env['ctx']);
      $form = $this->make_form($env['instance']->id, ['status' => smartspe_test_constants::FORM_DRAFT]);
      $this->seed_questions($form->id, 3);

      $headers = configuration_service::table_headers_for_form($form->id);
      $this->assertIsArray($headers);
      $this->assertArrayHasKey('questions', $headers);
      $this->assertCount(3, $headers['questions']);
      $this->assertSame('Q1', $headers['questions'][0]['label']);
      $this->assertSame('Q2', $headers['questions'][1]['label']);
      $this->assertSame('Q3', $headers['questions'][2]['label']);
    }

    /**
     * Test that unknown status gets 'unknown' label via display method.
     *
     * @return void
     * @throws coding_exception
     */
    public function test_unknown_status_label_via_display(): void {
      $env = $this->seed_course_module();
      $this->set_page_context($env['cm'], $env['ctx']);
      // Status 99 triggers unknown fallback.
      $weird = $this->make_form($env['instance']->id, ['status' => 99, 'title' => 'Weird']);

      global $PAGE;
      $forms = configuration_service::build_forms_for_display(
          $env['cm'], $env['ctx'], $PAGE->get_renderer('core')
      );

      $target = null;
      foreach ($forms as $f) { if ((int)$f->id === (int)$weird->id) { $target = $f; break; } }
      $this->assertNotNull($target);
      $this->assertSame(get_string('unknownstatus', 'mod_smartspe'), (string)$target->statuslabel);
    }

    /**
     * Seed questions for a form.
     *
     * @param int $formid
     * @param int $count
     * @return array
     * @throws dml_exception
     */
    protected function seed_questions(int $formid, int $count = 3): array {
        global $DB;
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
          $ids[] = $DB->insert_record('smartspe_question', (object)[
            'formid' => $formid,
            'position' => $i,
            'questiontype' => 0,
            'audience' => 0,
            'title' => 'Q' . $i,
            'questiontext' => 'Q' . $i . ' text',
            'timecreated' => time(),
            'timemodified' => time(),
          ]);
        }
        return $ids;
    }
}
