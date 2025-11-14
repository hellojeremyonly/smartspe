<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Prints an instance of mod_smartspe.
 *
 * @package     mod_smartspe
 * @copyright   2025 Jeremy
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Course module id.
$id = optional_param('id', 0, PARAM_INT);

// Activity instance id.
$s = optional_param('s', 0, PARAM_INT);

// Retrieve the course and module information.
if ($id) {
    $cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('smartspe', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('smartspe', ['id' => $s], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('smartspe', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

// Ensure the user is logged in and has access to the module.
require_login($course, true, $cm);

// Get the module context.
$modulecontext = context_module::instance($cm->id);

// Log the module view event before any redirects.
$event = \mod_smartspe\event\course_module_viewed::create([
    'objectid' => $moduleinstance->id,
    'context' => $modulecontext,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('smartspe', $moduleinstance);
$event->trigger();

// Redirect based on user capabilities.
// Teacher or manager view.
if (has_capability('mod/smartspe:manage', $modulecontext) || has_capability('mod/smartspe:configure', $modulecontext)) {
    redirect(new moodle_url('/mod/smartspe/config/configuration.php', ['id' => $cm->id]));
}

// Student view.
require_capability('mod/smartspe:submit', $modulecontext);
$published = $DB->get_record('smartspe_form', ['smartspeid' => $cm->instance, 'status' => 1]);

// If a published form exists, redirect to the student start page.
if ($published) {
    redirect(new moodle_url('/mod/smartspe/student/student_details.php', ['id' => $cm->id]));
}

// No published form found; inform the student and redirect to course page.
\core\notification::add(get_string('nopublishedform', 'mod_smartspe'), \core\output\notification::NOTIFY_INFO);
redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
