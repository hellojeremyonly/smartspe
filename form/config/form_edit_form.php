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
 * Define form structure and validation rules.
 *
 * @package    mod_smartspe
 * @copyright  2025 Jeremy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating and editing a smartspe form.
 *
 * @package    mod_smartspe
 */
class mod_smartspe_form_edit_form extends moodleform {
    /**
     * Define the form structure.
     *
     * @return void
     * @throws coding_exception If there is an error in the form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'formid', 0);
        $mform->setType('formid', PARAM_INT);

        // Title field.
        $mform->addElement('text', 'title', get_string('formtitle', 'mod_smartspe'), ['size' => '30', 'class' => 'form-title']);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        // Student detail section.
        $mform->addElement('header', 'studentdetailsection', get_string('studentdetailsection', 'mod_smartspe'));
        $mform->setExpanded('studentdetailsection', false);
        $mform->addElement(
            'editor',
            'studentfields',
            get_string('studentfields', 'mod_smartspe'),
            null,
            ['maxfiles' => 0, 'noclean' => false, 'trusttext' => false]
        );
        $mform->setType('studentfields', PARAM_RAW);
        $mform->addHelpButton('studentfields', 'studentfields', 'mod_smartspe');

        // Instruction section.
        $mform->addElement('header', 'instructionsection', get_string('instructionsection', 'mod_smartspe'));
        $mform->setExpanded('instructionsection', false);
        $mform->addElement(
            'editor',
            'instruction',
            get_string('forminstruction', 'mod_smartspe'),
            null,
            ['maxfiles' => 0, 'noclean' => false, 'trusttext' => false]
        );
        $mform->setType('instruction', PARAM_RAW);

        // Question section.
        $mform->addElement('header', 'questionsection', get_string('questionsection', 'mod_smartspe'));
        $mform->setExpanded('questionsection', false);
        $repeatarray = [];

        // Open visual group for question block.
        $repeatarray[] = $mform->createElement('html', '<div class="question-block">');

        // Question text editor.
        $repeatarray[] = $mform->createElement(
            'editor',
            'questiontext',
            get_string('questionlabel', 'mod_smartspe') . ' {no}',
            null,
            ['maxfiles' => 0, 'noclean' => false, 'trusttext' => false]
        );

        // Question type selector.
        $repeatarray[] = $mform->createElement(
            'select',
            'questiontype',
            get_string('questiontype', 'mod_smartspe'),
            [
                1 => get_string('likertscale', 'mod_smartspe'),
                2 => get_string('textresponse', 'mod_smartspe'),
            ]
        );

        // Audience selector (who answers this question).
        $repeatarray[] = $mform->createElement(
            'select',
            'audience',
            get_string('audience', 'mod_smartspe'),
            [
              1 => get_string('audienceboth', 'mod_smartspe'),
              2 => get_string('audienceself', 'mod_smartspe'),
              3 => get_string('audiencepeer', 'mod_smartspe'),
            ]
        );

        // Close visual group for question block.
        $repeatarray[] = $mform->createElement('html', '</div>');

        // Separator line between questions.
        $repeatarray[] = $mform->createElement('html', '<hr class="question-separator">');

        // Options for each element.
        $repeatoptions = [];
        $repeatoptions['questiontype']['type'] = PARAM_INT;
        $repeatoptions['questiontext']['type'] = PARAM_RAW;
        $repeatoptions['audience']['type'] = PARAM_INT;

        $mform->setType('questiontype', PARAM_INT);
        $mform->setType('audience', PARAM_INT);

        // Determine how many question blocks to show.
        $existingcount = 0;
        if (!empty($this->_customdata['questions']) && is_array($this->_customdata['questions'])) {
            $existingcount = count($this->_customdata['questions']);
        }

        // Ensure at least 2 questions are shown.
        $repeats = max(2, $existingcount);

        // Check if user pressed 'Add question' button during this session.
        $addquestion = optional_param('add_question', 0, PARAM_INT);
        if ($addquestion) {
            $repeats = $repeats + $addquestion;
        }

        // Repeat the question elements.
        $this->repeat_elements(
            $repeatarray,
            $repeats,
            $repeatoptions,
            'question_repeats',
            'add_question',
            1,
            get_string('addquestion', 'mod_smartspe'),
            true,
            'questionnumber'
        );

        // Save button.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate the form data.
     *
     * @param array $data The form data.
     * @param array $files The files associated with the form.
     * @return array An array of errors, if any.
     */
    public function validation($data, $files) {
        $errors = [];
        if (empty($data['title'])) {
            $errors['title'] = get_string('required');
        }
        return $errors;
    }
}
