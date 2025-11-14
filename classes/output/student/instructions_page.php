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
 * Instructions_page class
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output\student;

use renderable;
use templatable;
use renderer_base;

/**
 * instructions_page class
 */
class instructions_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param string $instructionhtml HTML content of the instructions
     * @param string $instructionsid ID for the instructions element
     * @param bool $expanded Whether the instructions are expanded by default
     */
    public function __construct(
        /** @var string HTML content of the instructions */
        private string $instructionhtml,
        /** @var string ID for the instructions element */
        private string $instructionsid,
        /** @var bool Whether the instructions are expanded by default */
        private bool $expanded = false
    ) {
    }

    /**
     * Export data for rendering the template.
     *
     * @param renderer_base $output Renderer
     * @return array Data for the template
     */
    public function export_for_template(renderer_base $output): array {
        return [
          'instructionhtml' => $this->instructionhtml,
          'instructionsid'  => $this->instructionsid,
          'expanded'        => $this->expanded,
        ];
    }
}
