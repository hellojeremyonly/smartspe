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
 * Test cases for the CSV export service of the SmartSPE activity module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Mock the analysis_service used by export_service.
 */
namespace mod_smartspe\local\report {
    if (!class_exists(analysis_service::class, false)) {
        class analysis_service {
            public static $headers = [];
            public static $payload = ['targets' => [], 'roster' => []];

            public static function table_headers_for_form(int $formid): array {
                return ['questions' => self::$headers];
            }

            public static function build_team_matrix(int $cmid, int $formid, int $teamid): array {
                return self::$payload;
            }
        }
    }
}

// Back to the export_service_test namespace.
namespace {

  defined('MOODLE_INTERNAL') || die();

  require_once(__DIR__ . '/../base_test.php');
  require_once(__DIR__ . '/../helpers_test.php');
  require_once(__DIR__ . '/../constant_test.php');

  use mod_smartspe\local\csv\export_service;

  /**
   * export_service_test class.
   */
  final class export_service_test extends base_test {
      use smartspe_test_helpers;

      /**
       * Test that the CSV export has the correct layout and content.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_csv_layout(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->sm_set_matrix_payload($this->sm_sample_matrix(3));

        $out = export_service::build_rows($env['cm']->id, $form->id, $group->id);
        $rows = $out['rows'];

        $this->assertIsArray($out);
        $this->assertArrayHasKey('filename', $out);
        $this->assertArrayHasKey('rows', $out);
        $this->assertGreaterThanOrEqual(4, count($rows));

        $r1 = $rows[0];
        $r2 = $rows[1];
        $footer = $rows[count($rows) - 1];

        $this->assertSame(count($r1), count($r2));
        foreach ($rows as $r) {
          $this->assertSame(count($r2), count($r));
        }

        $this->assertSame('Target One', $r1[5]);
        $this->assertSame('Target Two', $r1[9]);

        $this->assertSame(['Team', 'Student ID', 'Surname', 'Title', 'Given Name'], array_slice($r2, 0, 5));
        $this->assertSame('1', $r2[5]);
        $this->assertSame('2', $r2[6]);
        $this->assertSame('3', $r2[7]);
        $this->assertSame('Average from each', $r2[8]);

        $this->assertSame('Average of criteria', $footer[4]);
        $this->assertNotSame('', $footer[5]);
        $this->assertNotSame('', $footer[6]);
        $this->assertNotSame('', $footer[7]);
        $this->assertNotSame('', $footer[8]);
      }

      /**
       * Test that the generated filename is correctly sanitised.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_filename_sanitisation(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A (2025) #1');
        $this->sm_set_matrix_payload($this->sm_sample_matrix(3));

        $out = export_service::build_rows($env['cm']->id, $form->id, $group->id);
        $this->assertSame('SmartSPE_Team_Result_TeamA20251', $out['filename']);
      }

      /**
       * Test that an exception is thrown when there are no targets in the matrix.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_no_targets_throws(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->sm_set_matrix_payload(['targets' => [], 'roster' => []]);
        $this->expectException(\moodle_exception::class);
        export_service::build_rows($env['cm']->id, $form->id, $group->id);
      }

      /**
       * Test that an exception is thrown when there are no questions in the targets.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_no_questions_throws(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $matrix = $this->sm_sample_matrix(0);
        $matrix['targets'][0]['criteria'] = [];
        $matrix['targets'][1]['criteria'] = [];
        $this->sm_set_matrix_payload($matrix);
        $this->expectException(\moodle_exception::class);
        export_service::build_rows($env['cm']->id, $form->id, $group->id);
      }

      /**
       * Test that an exception is thrown when an invalid cmid is provided.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_invalid_cmid_throws(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->expectException(\dml_missing_record_exception::class);
        export_service::build_rows(999999, $form->id, $group->id);
      }

      /**
       * Test that an exception is thrown when an invalid formid is provided.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_invalid_formid_throws(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->expectException(\dml_missing_record_exception::class);
        export_service::build_rows($env['cm']->id, 999999, $group->id);
      }

      /**
       * Test that an exception is thrown when an invalid teamid is provided.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_invalid_teamid_throws(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->expectException(\dml_missing_record_exception::class);
        export_service::build_rows($env['cm']->id, $form->id, 999999);
      }

      /**
       * Test that missing scores are represented as blank cells in the CSV export.
       *
       * @return void
       * @throws moodle_exception
       */
      public function test_missing_scores_are_padded_with_blanks(): void {
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->sm_set_matrix_payload($this->sm_sample_matrix(3));

        $out = export_service::build_rows($env['cm']->id, $form->id, $group->id);
        $rows = $out['rows'];

        $s2 = $rows[3];

        $this->assertSame('1', (string)$rows[1][9]);
        $this->assertSame('', (string)$s2[11]);
        $this->assertSame('1.5', (string)$s2[12]);
      }

      /**
       * Test that export_service::build_rows does not modify any database records.
       *
       * @return void
       * @throws dml_exception
       * @throws moodle_exception
       */
      public function test_build_rows_is_read_only(): void {
        global $DB;
        [$env, $form, $group] = $this->sm_make_env_with_group('Team A');
        $this->sm_set_matrix_payload($this->sm_sample_matrix(3));

        $beforeForms = $DB->count_records('smartspe_form');
        $beforeQs = $DB->count_records('smartspe_question');

        export_service::build_rows($env['cm']->id, $form->id, $group->id);

        $this->assertSame($beforeForms, $DB->count_records('smartspe_form'));
        $this->assertSame($beforeQs, $DB->count_records('smartspe_question'));
      }
    }
}
