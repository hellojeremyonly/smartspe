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
 * Common base for DB-touching PHPUnit tests.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Base class for mod_smartspe PHPUnit tests.
 */
abstract class base_test extends \advanced_testcase {
  /**
   * @var bool Whether time is currently frozen for this test.
   */
  protected $timefrozen = false;

  /**
   * Setup test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->resetAfterTest(true);
    $this->setAdminUser();
  }

  /**
   * Freeze time at given timestamp (or now if null).
   */
  protected function freeze_now(?int $t = null): void {
    $this->setCurrentTimeStart($t ?? time());
  }

  /**
   * Advance frozen time by given seconds.
   */
  protected function advance_time(int $seconds): void {
    parent::advance_time($seconds);
  }

  /**
   * Seed a course + smartspe instance and return [course, cm, context, instance].
   */
  protected function seed_course_module(): array {
    $gen = $this->getDataGenerator();
    $course = $gen->create_course();
    $instance = $gen->create_module('smartspe', ['course' => $course->id]);
    $cm = get_coursemodule_from_instance('smartspe', $instance->id, $course->id, false, MUST_EXIST);
    $ctx = \context_module::instance($cm->id);
    return compact('course', 'cm', 'ctx', 'instance');
  }

  /**
   * Minimal $PAGE bootstrap so renderers/action links work.
   */
  protected function set_page_context($cm = null, \context $ctx = null): void {
    global $PAGE;
    if ($ctx) {
      $PAGE->set_context($ctx);
    } elseif ($cm) {
      $PAGE->set_context(\context_module::instance($cm->id));
    }
    // Any stable URL is fine for tests.
    if (!$PAGE->has_set_url()) {
      $PAGE->set_url(new \moodle_url('/'));
    }
  }
}
