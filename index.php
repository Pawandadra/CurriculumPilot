<?php
require(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$context  = context_system::instance();

require_login();
$PAGE->set_url('/local/bookcourse/index.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_bookcourse'));
$PAGE->set_heading(get_string('pluginname', 'local_bookcourse'));

echo $OUTPUT->header();

if ($courseid) {
    $course = get_course($courseid);
    $url = new moodle_url('/local/bookcourse/generate.php', ['courseid' => $courseid]);
    echo $OUTPUT->single_button($url, get_string('generateheading', 'local_bookcourse'));
} else {
    global $CFG;
    echo html_writer::tag('p', 'Provide a courseid in the URL to start generation, e.g.:');
    echo html_writer::tag('pre', $CFG->wwwroot . '/local/bookcourse/generate.php?courseid=2');
}

echo $OUTPUT->footer();

