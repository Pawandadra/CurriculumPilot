<?php

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/bookcourse/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$itemid    = optional_param('itemid', 0, PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/bookcourse:generate', $context);

$PAGE->set_url('/local/bookcourse/status.php', ['courseid' => $courseid, 'itemid' => $itemid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('statusheading', 'local_bookcourse'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo html_writer::tag('h3', get_string('statusheading', 'local_bookcourse'));

$statusurl = new moodle_url('/local/bookcourse/status.php', ['courseid' => $courseid]);
echo html_writer::div(
    html_writer::link($statusurl, get_string('statusviewall', 'local_bookcourse')) . ' | ' .
    html_writer::link(
        new moodle_url('/local/bookcourse/generate.php', ['courseid' => $courseid]),
        get_string('generateheading', 'local_bookcourse')
    ),
    'mb-2'
);

if ($itemid > 0) {
    $row = local_bookcourse_status_get($courseid, $itemid);
    if (!$row) {
        echo $OUTPUT->notification(get_string('statusnotfound', 'local_bookcourse'), 'notifyproblem');
    } else {
        $label = get_string('status_' . $row->status, 'local_bookcourse');
        echo html_writer::tag('p', get_string('statusitemid', 'local_bookcourse', $itemid));
        echo html_writer::tag('p', get_string('statuslabel', 'local_bookcourse', $label));
        echo html_writer::tag('p', get_string('statustimes', 'local_bookcourse', (object) [
            'created'  => userdate($row->timecreated),
            'modified' => userdate($row->timemodified),
        ]));
        if (!empty($row->errormessage)) {
            echo $OUTPUT->notification(s($row->errormessage), 'notifyproblem');
        }
        if ($row->status === LOCAL_BOOKCOURSE_STATUS_COMPLETE) {
            echo $OUTPUT->notification(get_string('statuscompletehint', 'local_bookcourse'), 'notifysuccess');
            echo $OUTPUT->single_button(
                new moodle_url('/course/view.php', ['id' => $courseid]),
                get_string('backtocourse', 'local_bookcourse')
            );
        } else if (in_array($row->status, [LOCAL_BOOKCOURSE_STATUS_QUEUED, LOCAL_BOOKCOURSE_STATUS_PROCESSING], true)) {
            echo $OUTPUT->notification(get_string('statuspollhint', 'local_bookcourse'), 'notifymessage');
            $refreshurl = $PAGE->url->out(false);
            echo html_writer::script('setTimeout(function(){ location.href = ' . json_encode($refreshurl) . '; }, 8000);');
        } else if ($row->status === LOCAL_BOOKCOURSE_STATUS_FAILED) {
            echo $OUTPUT->single_button(
                new moodle_url('/local/bookcourse/generate.php', ['courseid' => $courseid]),
                get_string('statusretry', 'local_bookcourse')
            );
        }
    }
} else {
    $rows = local_bookcourse_status_list_course($courseid, 30);
    if (!$rows) {
        echo html_writer::tag('p', get_string('statusnone', 'local_bookcourse'));
    } else {
        $table = new html_table();
        $table->head = [
            get_string('statuscoltime', 'local_bookcourse'),
            get_string('statuscolitem', 'local_bookcourse'),
            get_string('statuscolstate', 'local_bookcourse'),
            '',
        ];
        $table->attributes['class'] = 'generaltable';
        foreach ($rows as $r) {
            $detail = new moodle_url('/local/bookcourse/status.php', ['courseid' => $courseid, 'itemid' => $r->itemid]);
            $table->data[] = [
                userdate($r->timecreated),
                $r->itemid,
                get_string('status_' . $r->status, 'local_bookcourse'),
                html_writer::link($detail, get_string('statusview', 'local_bookcourse')),
            ];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
