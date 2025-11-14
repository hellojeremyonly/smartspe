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
 * Configuration page class
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output\config;

use renderable;
use renderer_base;
use templatable;

/**
 * Configuration page class
 *
 * @package    mod_smartspe
 */
class configuration_page implements renderable, templatable {
    /**
     * @var array List of forms to render on the configuration page
     */
    private $forms;

    /**
     * @var int Course ID to return to after configuration
     */
    private $courseid;

    /**
     * @var int Course module ID
     */
    private $cmid;

    /**
     * @var array Data for the report section.
     */
    private $report;

    /**
     * Constructor
     *
     * @param $forms Array of forms
     * @param $courseid Course ID
     * @param $cmid Course module ID
     * @param $formsdropdown Array for forms dropdown
     * @param $tabledata Array for table data
     * @param $report Optional report data array
     */
    public function __construct($forms, $courseid, $cmid, $formsdropdown = [], $tabledata = [], $report = []) {
        $this->forms = $forms;
        $this->courseid = $courseid;
        $this->cmid = $cmid;

        // If controller provided a report array, use it; otherwise fallback to minimal.
        $this->report = $report ?: [
            'formsdropdown' => $formsdropdown,
            'hasformselected' => false,
            'questions' => $tabledata['questions'] ?? [],
            'table' => null,
        ];
    }

    /**
     * Export for template
     *
     * @param renderer_base $output Renderer
     * @return array Data for template
     * @throws \coding_exception if invalid parameter
     * @throws \core\exception\moodle_exception\
     */
    public function export_for_template(renderer_base $output) {
        $selectedformid = optional_param('formid', 0, PARAM_INT);
        if ($selectedformid && isset($this->report)) {
            $this->report['hasformselected'] = true;
        }

        return [
          'cmid' => $this->cmid,
          'forms' => array_values($this->forms),
          'backtocourseurl' => (new \moodle_url('/course/view.php', ['id' => $this->courseid]))->out(),
          'report' => $this->report,
        ];
    }
}
