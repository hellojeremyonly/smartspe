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

require_once($CFG->libdir . '/formslib.php');

/**
 * This form renders a plain text input for each label configured by UC.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_smartspe_student_details_form extends moodleform {
    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;

        // Overall wrapper.
        $mform->addElement('html', html_writer::start_div('card mb-3'));
        $mform->addElement('html', html_writer::div(get_string('studentdetails', 'mod_smartspe'), 'card-header h6'));
        $mform->addElement('html', html_writer::start_div('card-body'));

        // Retrieve raw labels from customdata and normalize into an array.
        $rawlabels = $this->_customdata['labels'] ?? [];
        if (!is_array($rawlabels)) {
            $rawlabels = [$rawlabels];
        }

        // Process raw labels into a clean array of label texts.
        $labels = [];
        foreach ($rawlabels as $item) {
            if (!is_string($item)) {
                continue;
            }
            // Process each line: strip tags, trim, skip empty, remove trailing colon.
            $item = preg_replace(['~<\s*br\s*/?\s*>~i', '~</\s*p\s*>~i'], "\n", $item);
            // Strip all other HTML tags.
            $item = strip_tags($item);
            // Normalize newlines.
            $item = str_replace(["\r\n", "\r"], "\n", $item);
            // Split into lines and trim.
            $lines = preg_split("/\n+/", $item);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Remove trailing colon or fullwidth colon.
                $line = preg_replace('/[:\x{FF1A}]\s*$/u', '', $line);
                $labels[] = $line;
            }
        }

        // If no labels are configured, show a simple notice to the student.
        if (empty($labels)) {
              $mform->addElement(
                  'static',
                  'nolablenotice',
                  '',
                  get_string('nostudentfieldsconfigured', 'mod_smartspe')
              );
        } else {
            foreach ($labels as $idx => $labeltext) {
                $elementname = "details[$idx]";

                // Show a plain text input with the human label.
                $mform->addElement('text', $elementname, format_string($labeltext));
                $mform->setType($elementname, PARAM_TEXT);
                $mform->addRule($elementname, get_string('required'), 'required', null, 'client');

                // If label looks like a number/id/code such as Student Number, Team Number, ID
                // add client-side numeric validation. Server-side validation to be done in validation().
                $looksnumber = preg_match('/\b(number|no\.?|id|code)\b/i', $labeltext);
                if ($looksnumber) {
                    $mform->addRule($elementname, get_string('err_numeric', 'form'), 'numeric', null, 'client');
                }

                // If label looks like a name but not a team name, enforce letters-only on client.
                $looksname = preg_match('/\b(name|first\s*name|given\s*name|last\s*name|surname)\b/i', $labeltext);
                $isteam    = preg_match('/\bteam\b/i', $labeltext);
                if ($looksname && !$isteam) {
                    // Allow letters from any language, spaces, hyphens and apostrophes.
                    $mform->addRule(
                        $elementname,
                        'Only letters, spaces, hyphens and apostrophes are allowed.',
                        'regex',
                        '/^[\p{L}\s\'-]+$/u',
                        'client'
                    );
                }

                // Carry the exact label text back on submit so the controller can save a label -> value map robustly.
                $mform->addElement('hidden', "labels[$idx]", $labeltext);
                $mform->setType("labels[$idx]", PARAM_TEXT);
            }

            // Save and cancel buttons.
            $this->add_action_buttons(true, get_string('saveandcontinue', 'mod_smartspe'));
        }

        // Context parameters carried through as hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'formid');
        $mform->setType('formid', PARAM_INT);

        // Hint to controller about intent (continue to next page).
        $mform->addElement('hidden', 'continue', 1);
        $mform->setType('continue', PARAM_BOOL);

        $mform->addElement('html', html_writer::end_div());
        $mform->addElement('html', html_writer::end_div());
    }

    /**
     * Helper for controllers to prefill values by position.
     *
     * @param array $orderedvalues Values in the same order as $labels.
     * @param int|null $cmid
     * @param int|null $formid
     */
    public function set_initial_details(array $orderedvalues, ?int $cmid = null, ?int $formid = null): void {
        $data = new stdClass();
        $data->details = $orderedvalues;
        if ($cmid !== null) {
            $data->id = $cmid;
        }
        if ($formid !== null) {
            $data->formid = $formid;
        }
        parent::set_data($data); // Call moodleform's set_data().
    }

    /**
     * Helper for controllers to prefill values by label text.
     *
     * @param array $bylabel Associative array 'Label text' => 'value'
     * @param int|null $cmid
     * @param int|null $formid
     */
    public function set_initial_details_bylabel(array $bylabel, ?int $cmid = null, ?int $formid = null): void {
        $data = new stdClass();
        $ordered = [];

        // Reconstruct current labels order exactly like in definition().
        $rawlabels = $this->_customdata['labels'] ?? [];
        if (!is_array($rawlabels)) {
            $rawlabels = [$rawlabels];
        }
        $labels = [];
        foreach ($rawlabels as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = preg_replace(['~<\s*br\s*/?\s*>~i', '~</\s*p\s*>~i'], "\n", $item);
            $item = strip_tags($item);
            $item = str_replace(["\r\n", "\r"], "\n", $item);
            $lines = preg_split("/\n+/", $item);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $line = preg_replace('/[:\x{FF1A}]\s*$/u', '', $line);
                $labels[] = $line;
            }
        }

        foreach ($labels as $idx => $labeltext) {
            $ordered[$idx] = $bylabel[$labeltext] ?? '';
        }

        // Set ordered values.
        $data->details = $ordered;
        if ($cmid !== null) {
            $data->id = $cmid;
        }

        // Set formid if provided.
        if ($formid !== null) {
            $data->formid = $formid;
        }

        parent::set_data($data);
    }

    /**
     * Server-side validation for numeric-looking labels.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $labels  = $data['labels'] ?? [];
        $details = $data['details'] ?? [];

        if (is_array($labels) && is_array($details)) {
            foreach ($labels as $idx => $labeltext) {
                if (!array_key_exists($idx, $details)) {
                    continue;
                }
                $value = $details[$idx];

                // If label suggests a number such as Student Number, Team Number, ID, Code, enforce numeric server-side.
                if (preg_match('/\b(number|no\.?|id|code)\b/i', (string)$labeltext)) {
                    if ($value !== '' && !preg_match('/^\d+$/', (string)$value)) {
                        $errors["details[$idx]"] = get_string('err_numeric', 'form');
                    }
                }

                // If label suggests a name but not a team name, enforce letters-only server-side.
                if (
                    preg_match('/\b(name|first\s*name|given\s*name|last\s*name|surname)\b/i', (string)$labeltext) &&
                    !preg_match('/\bteam\b/i', (string)$labeltext)
                ) {
                    if ($value !== '' && !preg_match('/^[\p{L}\s\'-]+$/u', (string)$value)) {
                        $errors["details[$idx]"] = 'Only letters, spaces, hyphens and apostrophes are allowed.';
                    }
                }
            }
        }

        return $errors;
    }
}
