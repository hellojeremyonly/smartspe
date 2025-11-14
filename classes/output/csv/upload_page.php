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
 * Renderable context for the CSV upload page.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_smartspe\output\csv;

/**
 * upload_page class
 */
class upload_page implements \renderable, \templatable {
    /** @var array */
    private $data;

    /**
     * Constructor.
     *
     * @param array $data Data for the upload page
     */
    public function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Export data for rendering the template.
     *
     * @param \renderer_base $output Renderer
     * @return array Data for the template
     */
    public function export_for_template(\renderer_base $output): array {
        $out = $this->data;
        if (!empty($out['summary']) && is_array($out['summary'])) {
            $s = $out['summary'];
            $out['summary'] = [
              'total' => (int)($s['total'] ?? 0),
              'newusers' => (int)($s['created'] ?? 0),
              'existing' => (int)($s['matchedbyid'] ?? 0) + (int)($s['matchedbyname'] ?? 0),
              'groups' => (int)($s['groupscreated'] ?? 0),
            ];
        }

        // Remove rows data before rendering.
        unset($out['rows']);
        return $out;
    }
}
