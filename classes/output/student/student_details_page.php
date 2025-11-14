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
 * Student details page class.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_smartspe\output\student;

use renderable;
use renderer_base;
use templatable;

/**
 * Student details page class.
 */
class student_details_page implements renderable, templatable {
    /** @var string Heading */
    private string $heading;
    /** @var string Instruction HTML */
    private string $instructionhtml;
    /** @var string ID */
    private string $instructionsid;
    /**
     * Constructor.
     *
     * @param string $heading
     * @param string $instructionhtml
     * @param string $instructionsid
     */
    public function __construct(string $heading, string $instructionhtml = '', string $instructionsid = 'instructions') {
        $this->heading = $heading;
        $this->instructionhtml = $instructionhtml;
        $this->instructionsid = $instructionsid;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output Renderer
     * @return array Data for template
     */
    public function export_for_template(renderer_base $output): array {
        return [
          'heading' => $this->heading,
          'instructionhtml' => $this->instructionhtml,
          'instructionsid' => $this->instructionsid,
        ];
    }
}
