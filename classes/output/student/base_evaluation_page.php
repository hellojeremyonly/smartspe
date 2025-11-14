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
 * Renderable context for a base evaluation page (self or peer).
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
 * base_evaluation_page class
 */
class base_evaluation_page implements renderable, templatable {
    /** @var string heading */
    private string $heading;

    /** @var string Preformatted HTML for instructions (already run through format_text). */
    private string $instructionhtml;

    /** @var string Unique id used to link header/collapse region in the instructions partial. */
    private string $instructionsid;

    /** @var bool Whether the instructions accordion should be expanded initially. */
    private bool $expanded;

    /** @var string Form POST action URL. */
    private string $posturl;

    /** @var string CSRF token. */
    private string $sesskey;

    /** @var array List of questions */
    private array $questions;

    /** @var string Prefix used to build unique input ids in the template. */
    private string $idprefix;

    /** @var string Optional back URL for a back button (null to hide). */
    private ?string $backurl;

    /** @var bool Show/hide back button. */
    private bool $showback;

    /** @var bool Show/hide save draft button. */
    private bool $showdraft;

    /**
     * Constructor.
     *
     * @param string $heading heading of the page
     * @param string $instructionhtml Preformatted HTML for instructions
     * @param string $posturl Form POST action URL
     * @param array $questions List of questions
     * @param string $sesskey CSRF token
     * @param string|null $instructionsid Unique id for instructions accordion
     * @param bool $expanded Whether instructions are expanded initially
     * @param string|null $backurl Optional back URL
     * @param bool $showdraft draft button visibility
     * @param bool $showback back button visibility
     * @param string|null $idprefix Prefix for input ids
     */
    public function __construct(
        string $heading,
        string $instructionhtml,
        string $posturl,
        array $questions,
        string $sesskey,
        ?string $instructionsid = null,
        bool $expanded = false,
        ?string $backurl = null,
        bool $showdraft = true,
        bool $showback = true,
        ?string $idprefix = null
    ) {
        $this->heading = $heading;
        $this->instructionhtml = $instructionhtml;
        $this->posturl = $posturl;
        $this->questions = $questions;
        $this->sesskey = $sesskey;

        $this->instructionsid  = ($instructionsid !== null && $instructionsid !== '')
          ? $instructionsid
          : \html_writer::random_id('inst');

        $this->expanded = $expanded;
        $this->backurl = $backurl;
        $this->showback = $showback;
        $this->showdraft = $showdraft;

        $this->idprefix = ($idprefix !== null && $idprefix !== '')
          ? $idprefix
          : \html_writer::random_id('selfq');
    }

    /**
     * Export data for rendering the template.
     *
     * @param renderer_base $output The renderer
     * @return array Data for the template
     */
    public function export_for_template(renderer_base $output): array {
        return [
          'heading' => $this->heading,
          'instructionhtml' => $this->instructionhtml,
          'instructionsid' => $this->instructionsid,
          'expanded' => $this->expanded,

          'posturl' => $this->posturl,
          'sesskey' => $this->sesskey,
          'questions' => $this->questions,
          'idprefix' => $this->idprefix,

          'backurl' => $this->backurl,
          'showback' => $this->showback,
          'showdraft' => $this->showdraft,
        ];
    }
}
