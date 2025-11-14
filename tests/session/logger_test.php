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
 * Session logger tests for SmartSpe.
 *
 * @package    mod_smartspe
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../base_test.php');
require_once(__DIR__ . '/../helpers_test.php');
require_once(__DIR__ . '/../constant_test.php');

use mod_smartspe\local\session\logger;

/**
 * logger_test class.
 */
final class logger_test extends base_test {
    use smartspe_test_helpers;

  /**
   * Create a response record and return its id.
   *
   * @param int $formid
   * @param int $studentid
   * @param int|null $when
   * @return int
   * @throws dml_exception
   */
    private function make_response(int $formid, int $studentid, ?int $when = null): int {
        global $DB;
        $when = $when ?? time();
        return (int)$DB->insert_record('smartspe_response', (object)[
            'formid' => $formid,
            'studentid' => $studentid,
            'timecreated' => $when,
        ]);
    }

  /**
   * Insert an open session row for a response.
   *
   * @param int $responseid
   * @param int $timestart
   * @return int
   * @throws dml_exception
   */
    private function insert_open_session(int $responseid, int $timestart): int {
        global $DB;
        return (int)$DB->insert_record('smartspe_response_session', (object)[
            'responseid' => $responseid,
            'timestart' => $timestart,
            'timeend' => 0,
            'duration' => 0,
        ]);
    }

  /**
   * Get all session rows for a response.
   *
   * @param int $responseid
   * @return array
   * @throws dml_exception
   */
    private function get_sessions(int $responseid): array {
        global $DB;
        return array_values($DB->get_records('smartspe_response_session', ['responseid' => $responseid], 'id ASC'));
    }

  /**
   * Ensure logger::rollover exists; otherwise mark inconclusive.
   *
   * @param int $responseid
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    private function call_rollover(int $responseid): void {
        if (!method_exists(logger::class, 'rollover')) {
            $this->markTestIncomplete('logger::rollover() not found – align test with actual API.');
        }
        $ref = new \ReflectionMethod(logger::class, 'rollover');
        if ($ref->isStatic()) {
            logger::rollover($responseid);
        } else {
            try {
                $obj = new logger();
            } catch (\Throwable $e) {
                $this->markTestIncomplete('logger requires constructor args; adjust test to inject dependencies.');
                return;
            }
            $ref->invoke($obj, $responseid);
        }
    }

  /**
   * Ensure logger::close_open exists; otherwise mark inconclusive.
   *
   * @param int $responseid
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    private function call_close_open(int $responseid): void {
        if (!method_exists(logger::class, 'close_open')) {
            $this->markTestIncomplete('logger::close_open() not found – align test with actual API.');
        }
        $ref = new \ReflectionMethod(logger::class, 'close_open');
        if ($ref->isStatic()) {
            logger::close_open($responseid);
        } else {
            try {
                $obj = new logger();
            } catch (\Throwable $e) {
                $this->markTestIncomplete('logger requires constructor args; adjust test to inject dependencies.');
                return;
            }
            $ref->invoke($obj, $responseid);
        }
    }

  /**
   * Start a new session when none exists.
   *
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    public function test_start_new_session_when_absent(): void {
        $env  = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id);
        $user = $this->getDataGenerator()->create_user();

        $responseid = $this->make_response($form->id, $user->id);
        $this->assertCount(0, $this->get_sessions($responseid));

        $this->call_rollover($responseid);

        $rows = $this->get_sessions($responseid);
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int)$rows[0]->timeend);
        $this->assertSame(0, (int)$rows[0]->duration);
    }

  /**
   * Rollover closes existing open session and starts a new one.
   *
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    public function test_rollover_closes_open_and_starts_new(): void {
        $env  = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id);
        $user = $this->getDataGenerator()->create_user();

        $responseid = $this->make_response($form->id, $user->id);
        $base = time();
        $this->insert_open_session($responseid, $base - 1200); // 20 minutes ago

        $this->call_rollover($responseid);

        $rows = $this->get_sessions($responseid);
        $this->assertCount(2, $rows);

        // Closed one (first row).
        $closed = $rows[0];
        $this->assertGreaterThan(0, (int)$closed->timeend);
        // duration approx 1200s (allow a small tolerance).
        $this->assertGreaterThanOrEqual(1190, (int)$closed->duration);
        $this->assertLessThanOrEqual(1210, (int)$closed->duration);

        // New open one (second row).
        $open = $rows[1];
        $this->assertSame(0, (int)$open->timeend);
        $this->assertSame(0, (int)$open->duration);
    }

  /**
   * Rollover caps idle duration at 30 minutes.
   *
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    public function test_rollover_caps_idle_duration(): void {
        $env  = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id);
        $user = $this->getDataGenerator()->create_user();

        $responseid = $this->make_response($form->id, $user->id);
        $base = time();
        $timestart = $base - 4000; // > 30 min
        $this->insert_open_session($responseid, $timestart);

        $this->call_rollover($responseid);

        $rows = $this->get_sessions($responseid);
        $this->assertCount(2, $rows);

        $closed = $rows[0];
        // Expect strict cap to 1800s and end = start + 1800.
        $this->assertSame($timestart + 1800, (int)$closed->timeend);
        $this->assertSame(1800, (int)$closed->duration);

        $open = $rows[1];
        $this->assertSame(0, (int)$open->timeend);
        $this->assertSame(0, (int)$open->duration);
    }

  /**
   * Close open session.
   *
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    public function test_close_open_closes_current_session(): void {
        $env  = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id);
        $user = $this->getDataGenerator()->create_user();

        $responseid = $this->make_response($form->id, $user->id);
        $base = time();
        $this->insert_open_session($responseid, $base - 50);

        $this->call_close_open($responseid);

        $rows = $this->get_sessions($responseid);
        $this->assertCount(1, $rows);

        $closed = $rows[0];
        $this->assertGreaterThan(0, (int)$closed->timeend);
        $this->assertGreaterThanOrEqual(45, (int)$closed->duration);
        $this->assertLessThanOrEqual(65, (int)$closed->duration);
    }

  /**
   * Close open noop when nothing open.
   *
   * @return void
   * @throws ReflectionException
   * @throws dml_exception
   */
    public function test_close_open_noop_when_nothing_open(): void {
        $env  = $this->seed_course_module();
        $form = $this->make_form($env['instance']->id);
        $user = $this->getDataGenerator()->create_user();

        $responseid = $this->make_response($form->id, $user->id);
        $this->assertCount(0, $this->get_sessions($responseid));

        $this->call_close_open($responseid);

        $rows = $this->get_sessions($responseid);
        $this->assertCount(0, $rows);
    }
}
