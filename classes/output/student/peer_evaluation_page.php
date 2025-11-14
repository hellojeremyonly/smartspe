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
 * Renderable context for the peer evaluation page.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output\student;

/**
 * peer_evaluation_page class
 */
final class peer_evaluation_page extends base_evaluation_page {
    /**
     * @var string Name of the member being evaluated
     */
    private string $membername;
    /**
     * @var bool Whether the submit button should be shown
     */
    private bool $submit;

    /**
     * Constructor.
     *
     * @param string $heading Heading of the evaluation page
     * @param string $instructionhtml HTML content of the instructions
     * @param string $posturl URL to post the evaluation form to
     * @param array $questions List of questions for the evaluation
     * @param string $sesskey Session key for form submission
     * @param string $membername Name of the member being evaluated
     * @param bool $submit Whether the submit button should be shown
     * @param string|null $instructionsid ID for the instructions element
     * @param bool $expanded Whether the instructions are expanded by default
     * @param string|null $backurl URL for the back button
     * @param bool $showdraft Whether to show the save draft button
     * @param bool $showback Whether to show the back button
     * @param string|null $idprefix Prefix for HTML element IDs
     */
    public function __construct(
        string $heading,
        string $instructionhtml,
        string $posturl,
        array $questions,
        string $sesskey,
        string $membername,
        bool $submit,
        ?string $instructionsid = null,
        bool $expanded = false,
        ?string $backurl = null,
        bool $showdraft = true,
        bool $showback = true,
        ?string $idprefix = null
    ) {
        parent::__construct(
            $heading,
            $instructionhtml,
            $posturl,
            $questions,
            $sesskey,
            $instructionsid,
            $expanded,
            $backurl,
            $showdraft,
            $showback,
            $idprefix
        );

        $this->membername = $membername;
        $this->submit = $submit;
    }

    /**
     * Export data for rendering the template.
     *
     * @param \renderer_base $output Renderer
     * @return array Data for the template
     */
    public function export_for_template(\renderer_base $output): array {
        $context = parent::export_for_template($output);
        $context['membername'] = $this->membername;
        $context['submit'] = $this->submit;
        return $context;
    }
}
