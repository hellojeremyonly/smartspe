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

defined('MOODLE_INTERNAL') || die();

/**
 * Test data generator for mod_smartspe.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_smartspe_generator extends testing_module_generator {
    /**
     * Create a new instance of the module.
     *
     * @param $record
     * @param array|null $options
     * @return stdClass
     * @throws coding_exception
     */
    public function create_instance($record = null, ?array $options = null) {
        return parent::create_instance($record, $options);
    }

    public function create_form_with_questions(int $smartspeid, int $numquestions = 3): array {
      global $DB;
      $now = time();
      $form = (object)[
        'smartspeid'    => $smartspeid,
        'title'         => 'Generated Form',
        'status'        => 0,
        'instruction'   => '',
        'studentfields' => '',
        'timecreated'   => $now,
        'timemodified'  => $now,
      ];
      $form->id = $DB->insert_record('smartspe_form', $form);

      $qids = [];
      for ($i = 1; $i <= $numquestions; $i++) {
        $q = (object)[
          'formid'       => $form->id,
          'questiontext' => "Q{$i}",
          'questiontype' => 1,
          'audience'     => 2, // BOTH
        ];
        $qids[] = $DB->insert_record('smartspe_question', $q);
      }
      return [$form, $qids];
    }
}
