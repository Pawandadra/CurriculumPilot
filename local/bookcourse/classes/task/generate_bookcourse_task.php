<?php
namespace local_bookcourse\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;

class generate_bookcourse_task extends adhoc_task {

    public function get_component() {
        return 'local_bookcourse';
    }

    public function execute() {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/local/bookcourse/lib.php');

        $data = $this->get_custom_data();
        $courseid = isset($data->courseid) ? (int) $data->courseid : 0;
        $itemid = isset($data->itemid) ? (int) $data->itemid : 0;

        if ($courseid < 1 || $itemid < 1) {
            mtrace('local_bookcourse: missing courseid or itemid in task data.');
            return;
        }

        $fail = function (string $msg) use ($courseid, $itemid): void {
            mtrace('local_bookcourse: ' . $msg);
            local_bookcourse_status_set($courseid, $itemid, LOCAL_BOOKCOURSE_STATUS_FAILED, $msg);
        };

        try {
            local_bookcourse_status_set($courseid, $itemid, LOCAL_BOOKCOURSE_STATUS_PROCESSING, null);

            $course = get_course($courseid);
            $context = \context_course::instance($course->id);

            $fs = get_file_storage();

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
                $fail(get_string('error_nostoredfiles', 'local_bookcourse'));
                return;
            }

            $tocfile = reset($tocfiles);
            $bookfile = reset($bookfiles);

            $tempdir = make_temp_directory('local_bookcourse');
            $toctmp = tempnam($tempdir, 'toc_');
            $booktmp = tempnam($tempdir, 'book_');

            try {
                $tocfile->copy_content_to($toctmp);
                $bookfile->copy_content_to($booktmp);
            } catch (\Throwable $e) {
                mtrace('local_bookcourse: copy files: ' . $e->getMessage());
                $fail(get_string('error_copyfiles', 'local_bookcourse'));
                return;
            }

            $apibaseurl = get_config('local_bookcourse', 'apibaseurl');
            $apitoken = get_config('local_bookcourse', 'apitoken');

            if (empty($apibaseurl)) {
                $fail(get_string('error_apinotconfigured', 'local_bookcourse'));
                return;
            }

            $curl = new \curl();

            $headers = ['Accept: application/json'];
            if (!empty($apitoken)) {
                $headers[] = 'Authorization: Bearer ' . $apitoken;
            }

            $options = [
                'CURLOPT_HTTPHEADER'     => $headers,
                'CURLOPT_TIMEOUT'        => 0,
                'CURLOPT_CONNECTTIMEOUT' => 120,
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
            $info = $curl->get_info();
            $httpcode = (int) ($info['http_code'] ?? 0);

            @unlink($toctmp);
            @unlink($booktmp);

            if ($httpcode !== 200) {
                if ($httpcode === 401) {
                    $fail(get_string('error_api_unauthorized', 'local_bookcourse'));
                    return;
                }
                mtrace('local_bookcourse: HTTP ' . $httpcode . ' ' . \core_text::substr($response ?? '', 0, 500));
                $fail(get_string('error_import_service', 'local_bookcourse'));
                return;
            }

            $decoded = json_decode($response, true);
            if ($decoded === null) {
                mtrace('local_bookcourse: response was not valid JSON.');
                $fail(get_string('error_invalid_response', 'local_bookcourse'));
                return;
            }

            \local_bookcourse_create_lessons_from_data($course, $decoded);

            local_bookcourse_status_set($courseid, $itemid, LOCAL_BOOKCOURSE_STATUS_COMPLETE, null);
            mtrace('local_bookcourse: lessons created for course id ' . $courseid);
        } catch (\Throwable $e) {
            mtrace('local_bookcourse: ' . $e->getMessage());
            $fail(get_string('error_import_unexpected', 'local_bookcourse'));
        }
    }
}
