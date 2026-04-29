<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/bookcourse:generate', $context);

$PAGE->set_url('/local/bookcourse/generate.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('generateheading', 'local_bookcourse'));
$PAGE->set_heading($course->fullname);

if (data_submitted() && confirm_sesskey()) {
    if (!empty($_FILES['tocfile']['tmp_name']) && !empty($_FILES['bookfile']['tmp_name'])) {
        echo $OUTPUT->header();

        $fs = get_file_storage();
        $itemid = time();

        $fileinfo_toc = [
            'contextid' => $context->id,
            'component' => 'local_bookcourse',
            'filearea'  => 'toc',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => clean_param($_FILES['tocfile']['name'], PARAM_FILE),
        ];
        $fs->create_file_from_pathname($fileinfo_toc, $_FILES['tocfile']['tmp_name']);

        $fileinfo_book = [
            'contextid' => $context->id,
            'component' => 'local_bookcourse',
            'filearea'  => 'book',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => clean_param($_FILES['bookfile']['name'], PARAM_FILE),
        ];
        $fs->create_file_from_pathname($fileinfo_book, $_FILES['bookfile']['tmp_name']);

        $task = new \local_bookcourse\task\generate_bookcourse_task();
        $task->set_custom_data([
            'courseid' => $courseid,
            'itemid'   => $itemid,
        ]);
        $task->set_component('local_bookcourse');
        \core\task\manager::queue_adhoc_task($task);

        echo $OUTPUT->notification('Generation has been queued. Lessons will appear after cron runs.', 'notifysuccess');
        echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $courseid]));
        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();
echo html_writer::tag('h3', get_string('generateheading', 'local_bookcourse'));

echo html_writer::start_tag('form', [
    'method'  => 'post',
    'enctype' => 'multipart/form-data',
]);
echo html_writer::input_hidden_params(new moodle_url($PAGE->url));
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'sesskey',
    'value' => sesskey(),
]);

echo html_writer::tag('p',
    'Table of Contents file (PDF/Image): ' .
    html_writer::empty_tag('input', [
        'type'     => 'file',
        'name'     => 'tocfile',
        'required' => 'required',
    ])
);

echo html_writer::tag('p',
    'Book PDF: ' .
    html_writer::empty_tag('input', [
        'type'     => 'file',
        'name'     => 'bookfile',
        'required' => 'required',
    ])
);

echo html_writer::tag('p', 'Note: Book PDF should not contain Table of Contents page(s).');

echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('generate', 'local_bookcourse'),
    'class' => 'btn btn-primary',
]);

echo html_writer::end_tag('form');
echo $OUTPUT->footer();

