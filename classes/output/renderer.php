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
 * Renderers for SmartSPE module.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output;

use mod_smartspe\output\config\configuration_page;
use mod_smartspe\output\config\form_edit_page;
use mod_smartspe\output\csv\upload_page;
use mod_smartspe\output\student\self_evaluation_page;
use mod_smartspe\output\student\peer_evaluation_page;
use mod_smartspe\output\student\student_details_page;
use mod_smartspe\output\student\instructions_page;

/**
 * renderer class
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the configuration page.
     *
     * @param configuration_page $page The configuration page to render
     * @return string The rendered HTML
     * @throws \coding_exception if the template cannot be found
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_configuration_page(configuration_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/config/configuration_page', $context);
    }

    /**
     * Render the form edit page.
     *
     * @param form_edit_page $page The form edit page to render
     * @return string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_form_edit_page(form_edit_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/config/form_edit_page', $context);
    }

    /**
     * Render the student details page.
     *
     * @param student_details_page $page The student details page to render
     * @return string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_student_details_page(student_details_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/student/student_details_page', $context);
    }

    /**
     * Render the instructions page.
     *
     * @param instructions_page $page The instructions page to render
     * @return string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_instructions_page(instructions_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/partials/instructions', $context);
    }

    /**
     * Render the self-evaluation page.
     *
     * @param self_evaluation_page $page The self-evaluation page to render
     * @return string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_self_evaluation_page(self_evaluation_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/student/self_evaluation_page', $context);
    }

    /**
     * Render the peer evaluation page.
     *
     * @param peer_evaluation_page $page The peer evaluation page to render
     * @return string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_peer_evaluation_page(peer_evaluation_page $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('mod_smartspe/student/peer_evaluation_page', $context);
    }

    /**
     * Render the CSV upload page.
     *
     * @param upload_page $page The upload page to render
     * @return bool|string The rendered HTML
     * @throws \core\exception\moodle_exception if an error occurs during rendering
     */
    public function render_upload_page(upload_page $page) {
        $data = $page->export_for_template($this);
        return parent::render_from_template('mod_smartspe/csv/upload_page', $data);
    }
}
