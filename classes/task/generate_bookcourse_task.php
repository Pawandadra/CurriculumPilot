<?php
namespace local_bookcourse\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;
use context_course;
use moodle_url;

class generate_bookcourse_task extends adhoc_task {

    public function get_component() {
        return 'local_bookcourse';
    }

    public function execute() {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/local/bookcourse/lib.php');

        $data = $this->get_custom_data();
        $courseid = $data->courseid ?? null;
        $itemid   = $data->itemid ?? null;

        if (empty($courseid) || empty($itemid)) {
            mtrace('local_bookcourse: missing courseid or itemid in task data.');
            return;
        }

        $course  = get_course($courseid);
        $context = context_course::instance($course->id);

        $fs = get_file_storage();

        // Fetch stored TOC and book files.
        $tocfiles = $fs->get_area_files(
            $context->id,
            'local_bookcourse',
            'toc',
            $itemid,
            'id',
            false
        );
        $bookfiles = $fs->get_area_files(
            $context->id,
            'local_bookcourse',
            'book',
            $itemid,
            'id',
            false
        );

        if (empty($tocfiles) || empty($bookfiles)) {
            mtrace('local_bookcourse: no stored files for task.');
            return;
        }

        $tocfile  = reset($tocfiles);
        $bookfile = reset($bookfiles);

        // Write them to temp files for curl.
        $tempdir = make_temp_directory('local_bookcourse');
        $toctmp  = tempnam($tempdir, 'toc_');
        $booktmp = tempnam($tempdir, 'book_');

        $tocfile->copy_content_to($toctmp);
        $bookfile->copy_content_to($booktmp);

        $apibaseurl = get_config('local_bookcourse', 'apibaseurl');
        $apitoken   = get_config('local_bookcourse', 'apitoken');

        if (empty($apibaseurl)) {
            mtrace('local_bookcourse: API base URL not configured.');
            return;
        }

        $curl = new \curl();

        $headers = ['Accept: application/json'];
        if (!empty($apitoken)) {
            $headers[] = 'Authorization: Bearer ' . $apitoken;
        }

        $options = [
            'CURLOPT_HTTPHEADER'     => $headers,
            'CURLOPT_TIMEOUT'        => 0,    // Let cron decide overall time limit.
            'CURLOPT_CONNECTTIMEOUT' => 30,
        ];

        $postfields = [
            'toc_file' => new \CURLFile(
                $toctmp,
                $tocfile->get_mimetype(),
                $tocfile->get_filename()
            ),
            'book_file' => new \CURLFile(
                $booktmp,
                $bookfile->get_mimetype(),
                $bookfile->get_filename()
            ),
        ];

        $url = rtrim($apibaseurl, '/') . '/generate-course';
        mtrace('local_bookcourse: calling API at ' . $url);

        $response = $curl->post($url, $postfields, $options);
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        if ($httpcode != 200) {
            mtrace('local_bookcourse: API error HTTP ' . $httpcode . ' - ' . $response);
            return;
        }

        $data = json_decode($response, true);
        if ($data === null) {
            mtrace('local_bookcourse: failed to decode JSON from API.');
            return;
        }

        // Create lessons for the course.
        \local_bookcourse_create_lessons_from_data($course, $data);

        mtrace('local_bookcourse: lessons created for course id ' . $courseid);
    }
}

