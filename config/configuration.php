<?php
require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('smartspe', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/smartspe/configuration.php', ['id' => $cm->id]);
$PAGE->set_title('SmartSPE Configuration');
$PAGE->set_heading('SmartSPE Configuration');

echo $OUTPUT->header();
echo html_writer::tag('h3', 'This is a dummy UC configuration page.');
echo $OUTPUT->footer();
