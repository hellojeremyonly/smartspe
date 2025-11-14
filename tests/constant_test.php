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
 * Common test constants for mod_smartspe PHPUnit tests.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Test constants for mod_smartspe.
 */
final class smartspe_test_constants {
  public const FORM_DRAFT = 0;
  public const FORM_PUBLISHED = 1;
  public const FORM_ARCHIVED = 2;

  public const AUDIENCE_SELF = 0;
  public const AUDIENCE_PEER = 1;
  public const AUDIENCE_BOTH = 2;

  public const RESPONSE_DRAFT = 0;
  public const RESPONSE_SUBMITTED = 1;
}
