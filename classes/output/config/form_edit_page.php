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
 * Renderable context for the form edit page.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output\config;

use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * form_edit_page class
 */
final class form_edit_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param int $cmid Course module ID
     * @param int|null $formid Form ID (null for new form)
     */
    public function __construct(
        /**
         * @var int Course ID
         */
        private readonly int $courseid,
        /**
         * @var int Course module ID
         */
        private readonly int $cmid,
        /**
         * @var int|null Form ID (null for new form)
         */
        private readonly ?int $formid = null
    ) {
    }

    /**
     * Export data for rendering the template.
     *
     * @param renderer_base $output The renderer
     * @return array Data for the template
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'courseid' => $this->courseid,
            'cmid' => $this->cmid,
            'formid' => $this->formid,
            'heading' => get_string('formedit', 'mod_smartspe'),
            'backtocourseurl' => (new moodle_url('/course/view.php', ['id' => $this->courseid]))->out(false),
        ];
    }
}
