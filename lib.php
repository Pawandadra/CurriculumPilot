<?php
defined('MOODLE_INTERNAL') || die();

/** @var string Queued, waiting for cron */
define('LOCAL_BOOKCOURSE_STATUS_QUEUED', 'queued');
/** @var string Cron is running the job */
define('LOCAL_BOOKCOURSE_STATUS_PROCESSING', 'processing');
/** @var string Lessons created */
define('LOCAL_BOOKCOURSE_STATUS_COMPLETE', 'complete');
/** @var string Error (see errormessage) */
define('LOCAL_BOOKCOURSE_STATUS_FAILED', 'failed');

function local_bookcourse_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/bookcourse:generate', $context)) {
        return;
    }
    $url = new moodle_url('/local/bookcourse/generate.php', ['courseid' => $course->id]);
    $navigation->add_node(navigation_node::create(
        get_string('generateheading', 'local_bookcourse'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_bookcourse_generate'
    ));
    $surl = new moodle_url('/local/bookcourse/status.php', ['courseid' => $course->id]);
    $navigation->add_node(navigation_node::create(
        get_string('statusheading', 'local_bookcourse'),
        $surl,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_bookcourse_status'
    ));
}

/**
 * Insert a generation status row (call after files are stored, before the adhoc task is queued).
 *
 * @param int $courseid
 * @param int $userid
 * @param int $itemid File storage item id for this run
 * @return int New row id
 */
function local_bookcourse_status_create(int $courseid, int $userid, int $itemid): int {
    global $DB;
    $now = time();
    $rec = (object) [
        'courseid'     => $courseid,
        'userid'       => $userid,
        'itemid'       => $itemid,
        'status'       => LOCAL_BOOKCOURSE_STATUS_QUEUED,
        'errormessage' => null,
        'timecreated'  => $now,
        'timemodified' => $now,
    ];
    return (int) $DB->insert_record('local_bookcourse_gen', $rec);
}

/**
 * @param int $courseid
 * @param int $itemid
 * @param string $status One of LOCAL_BOOKCOURSE_STATUS_* constants
 * @param string|null $errormessage
 */
function local_bookcourse_status_set(int $courseid, int $itemid, string $status, ?string $errormessage = null): void {
    global $DB;
    $rec = $DB->get_record('local_bookcourse_gen', ['courseid' => $courseid, 'itemid' => $itemid]);
    if (!$rec) {
        return;
    }
    $rec->status = $status;
    $rec->timemodified = time();
    if ($status === LOCAL_BOOKCOURSE_STATUS_PROCESSING) {
        $rec->errormessage = null;
    } else if ($errormessage !== null) {
        $rec->errormessage = $errormessage;
    }
    $DB->update_record('local_bookcourse_gen', $rec);
}

/**
 * @param int $courseid
 * @param int $itemid
 * @return stdClass|null
 */
function local_bookcourse_status_get(int $courseid, int $itemid): ?stdClass {
    global $DB;
    $rec = $DB->get_record('local_bookcourse_gen', ['courseid' => $courseid, 'itemid' => $itemid]);
    return $rec ?: null;
}

/**
 * Recent generation runs for a course (newest first).
 *
 * @param int $courseid
 * @param int $limit
 * @return array<int, stdClass>
 */
function local_bookcourse_status_list_course(int $courseid, int $limit = 25): array {
    global $DB;
    return $DB->get_records_sql(
        'SELECT * FROM {local_bookcourse_gen} WHERE courseid = ? ORDER BY timecreated DESC',
        [$courseid],
        0,
        $limit
    );
}

/**
 * Resolve (and if needed create) the default question category for storing generated MCQs.
 *
 * On Moodle 5+, questions must live under a mod_qbank activity context; this creates a hidden
 * "CurriculumPilot question bank" activity when none exists. On older Moodle, the legacy
 * course-level default category is used.
 *
 * @param stdClass $course Course record (must include ->id).
 * @return stdClass|null Category row with id and contextid, or null if not possible.
 */
function local_bookcourse_get_question_bank_default_category(stdClass $course): ?stdClass {
    global $CFG, $DB;

    require_once($CFG->libdir . '/questionlib.php');

    if (!question_bank::qtype_enabled('multichoice')) {
        return null;
    }

    $qbankdir = core_component::get_plugin_directory('mod', 'qbank');
    if ($qbankdir) {
        $cmrow = $DB->get_records_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = ?
              WHERE cm.course = ? AND cm.deletioninprogress = 0
           ORDER BY cm.id ASC",
            ['qbank', $course->id],
            0,
            1
        );
        $cmrow = reset($cmrow);

        if (!$cmrow) {
            $coursectx = context_course::instance($course->id);
            if (!has_capability('mod/qbank:addinstance', $coursectx) ||
                    !has_capability('moodle/course:manageactivities', $coursectx)) {
                debugging(
                    'local_bookcourse: need mod/qbank:addinstance and moodle/course:manageactivities to create a question bank.',
                    DEBUG_DEVELOPER
                );
                return null;
            }

            require_once($CFG->dirroot . '/course/modlib.php');

            $qbtype = 'standard';
            if (class_exists(\core_question\local\bank\question_bank_helper::class)) {
                $rc = new \ReflectionClass(\core_question\local\bank\question_bank_helper::class);
                if ($rc->hasConstant('TYPE_STANDARD')) {
                    $qbtype = $rc->getConstant('TYPE_STANDARD');
                }
            }

            $mi = new stdClass();
            $mi->modulename = 'qbank';
            $mi->course = $course->id;
            $mi->section = 0;
            $mi->visible = 0;
            $mi->visibleoncoursepage = 0;
            $mi->name = get_string('qbankactivityname', 'local_bookcourse');
            $mi->introeditor = [
                'text'   => '<p></p>',
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
            $mi->type = $qbtype;
            $mi->showdescription = 0;

            try {
                $mi = create_module($mi);
            } catch (\Throwable $e) {
                debugging('local_bookcourse: could not create mod_qbank: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return null;
            }

            $cm = get_coursemodule_from_instance('qbank', $mi->instance, $course->id, false, MUST_EXIST);
            $modctx = context_module::instance($cm->id);
        } else {
            $modctx = context_module::instance((int) $cmrow->id);
        }

        if (!has_capability('moodle/question:add', $modctx)) {
            debugging('local_bookcourse: no moodle/question:add in question bank activity context.', DEBUG_DEVELOPER);
            return null;
        }

        try {
            $rf = new \ReflectionFunction('question_get_default_category');
            if ($rf->getNumberOfParameters() >= 2) {
                $cat = question_get_default_category($modctx->id, true);
            } else {
                $cat = question_get_default_category($modctx->id);
            }
        } catch (\Throwable $e) {
            debugging('local_bookcourse: question_get_default_category: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        return $cat ?: null;
    }

    $coursecontext = context_course::instance($course->id);
    if (!has_capability('moodle/question:add', $coursecontext)) {
        return null;
    }
    if (!function_exists('question_make_default_categories')) {
        return null;
    }
    try {
        $qbankcontexts = new \core_question\local\bank\question_edit_contexts($coursecontext);
        return question_make_default_categories($qbankcontexts->all());
    } catch (\Throwable $e) {
        debugging('local_bookcourse: question bank category: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return null;
    }
}

/**
 * Add one multichoice question to the course default question bank category.
 *
 * @param stdClass $defaultcategory Row with id and contextid for the question category.
 * @param int $courseid
 * @param string $name Question name (short).
 * @param string $questiontexthtml Question stem HTML.
 * @param array $choicelabels Non-empty choice texts in order.
 * @param int $correctzeroindexed Index of the correct choice in $choicelabels.
 */
function local_bookcourse_save_multichoice_to_course_bank(
    stdClass $defaultcategory,
    int $courseid,
    string $name,
    string $questiontexthtml,
    array $choicelabels,
    int $correctzeroindexed
): void {
    $qt = question_bank::get_qtype('multichoice');

    $fromform = new stdClass();
    $fromform->category = $defaultcategory->id . ',' . $defaultcategory->contextid;
    $fromform->courseid = $courseid;
    $fromform->name = shorten_text(trim($name), 200);
    $fromform->questiontext = [
        'text' => $questiontexthtml,
        'format' => FORMAT_HTML,
    ];
    $fromform->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
    $fromform->defaultmark = 1;
    $fromform->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

    $fromform->single = 1;
    $fromform->shuffleanswers = 0;
    $fromform->answernumbering = 'abc';
    $fromform->showstandardinstruction = 0;
    $fromform->penalty = 0.3333333;

    $fromform->answer = [];
    $fromform->fraction = [];
    $fromform->feedback = [];
    foreach (array_values($choicelabels) as $i => $label) {
        $fromform->answer[$i] = [
            'text' => format_string($label, true),
            'format' => FORMAT_HTML,
        ];
        $fromform->fraction[$i] = ($i === $correctzeroindexed) ? 1.0 : 0.0;
        $fromform->feedback[$i] = ['text' => '', 'format' => FORMAT_HTML];
    }

    $fromform->correctfeedback = [
        'text' => get_string('correctfeedbackdefault', 'question'),
        'format' => FORMAT_HTML,
    ];
    $fromform->partiallycorrectfeedback = [
        'text' => get_string('partiallycorrectfeedbackdefault', 'question'),
        'format' => FORMAT_HTML,
    ];
    $fromform->incorrectfeedback = [
        'text' => get_string('incorrectfeedbackdefault', 'question'),
        'format' => FORMAT_HTML,
    ];
    $fromform->shownumcorrect = 0;

    $qobj = new stdClass();
    $qobj->category = $defaultcategory->id;
    $qobj->qtype = 'multichoice';

    $qt->save_question($qobj, $fromform);
}

function local_bookcourse_create_lessons_from_data(stdClass $course, array $chapters) {
    global $CFG;
    require_once($CFG->dirroot . '/course/modlib.php');
    require_once($CFG->dirroot . '/mod/lesson/locallib.php');
    require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
    require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');
    $bankcategory = local_bookcourse_get_question_bank_default_category($course);

    $sectionnum = 0;
    foreach ($chapters as $chapter) {
        if (empty($chapter['chapter_title']) || empty($chapter['topics'])) {
            continue;
        }
        $chaptertitle = $chapter['chapter_title'];
        $totalquestions = 0;
        foreach ($chapter['topics'] as $t) {
            $totalquestions += count($t['questions'] ?? []);
        }

        $intro = html_writer::tag('p', s($chaptertitle));
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'lesson';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->name = $chaptertitle;
        $moduleinfo->introeditor = [
            'text'   => $intro,
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $moduleinfo->grade = $totalquestions;
        $moduleinfo->ongoing = 0;
        $moduleinfo->custom = 1;
        $moduleinfo->practice = 0;
        $moduleinfo->modattempts = 0;
        $moduleinfo->usepassword = 0;
        $moduleinfo->retake = 1;
        $moduleinfo->maxanswers = 4;
        $moduleinfo->maxpages = 100;
        $moduleinfo = create_module($moduleinfo);

        $lesson = lesson::load($moduleinfo->instance);
        $cm = get_coursemodule_from_instance('lesson', $lesson->id, $course->id);
        $context = context_module::instance($cm->id);
        $maxbytes = get_user_max_upload_file_size($context);

        $prevpageid = 0;
        foreach ($chapter['topics'] as $topic) {
            $topicname = trim($topic['topic_name'] ?? '');
            $topiccontent = trim($topic['topic_content'] ?? '');
            if ($topicname === '' && $topiccontent === '') {
                continue;
            }
            $props = new stdClass();
            $props->title = $topicname !== '' ? $topicname : get_string('page', 'lesson');
            $props->qtype = LESSON_PAGE_BRANCHTABLE;
            $props->pageid = $prevpageid;
            $props->contents_editor = [
                'text'   => nl2br(s($topiccontent !== '' ? $topiccontent : $topicname)),
                'format' => FORMAT_HTML,
            ];
            $props->answer_editor = [];
            $props->response_editor = [];
            $props->jumpto = [];
            $props->score = [];
            $page = lesson_page::create($props, $lesson, $context, $maxbytes);
            $prevpageid = $page->id;

            $questions = $topic['questions'] ?? [];
            foreach ($questions as $qindex => $question) {
                $qtext = trim($question['question'] ?? '');
                $options = $question['options'] ?? [];
                $answeridx = isset($question['answer']) ? (int)$question['answer'] : -1;
                if ($qtext === '' || empty($options)) {
                    continue;
                }
                $qprops = new stdClass();
                $qprops->title = mb_strimwidth($qtext, 0, 60, '...');
                $qprops->qtype = LESSON_PAGE_MULTICHOICE;
                $qprops->pageid = $prevpageid;
                $qprops->contents_editor = [
                    'text'   => format_string($qtext, true),
                    'format' => FORMAT_HTML,
                ];
                $qprops->answer_editor = [];
                $qprops->response_editor = [];
                $qprops->jumpto = [];
                $qprops->score = [];

                $ansidx = 0;
                foreach ($options as $idx => $opttext) {
                    $optclean = trim((string)$opttext);
                    if ($optclean === '') {
                        continue;
                    }
                    $qprops->answer_editor[$ansidx] = [
                        'text'   => $optclean,
                        'format' => FORMAT_HTML,
                    ];
                    $qprops->response_editor[$ansidx] = [
                        'text'   => '',
                        'format' => FORMAT_HTML,
                    ];
                    $qprops->jumpto[$ansidx] = -1;
                    $qprops->score[$ansidx] = ($idx === $answeridx) ? 1 : 0;
                    $ansidx++;
                }
                if (empty($qprops->answer_editor)) {
                    continue;
                }
                $qpage = lesson_page::create($qprops, $lesson, $context, $maxbytes);
                $prevpageid = $qpage->id;

                if ($bankcategory) {
                    $bankchoices = [];
                    $bankcorrect = -1;
                    $bidx = 0;
                    foreach ($options as $oidx => $opttext) {
                        $oclean = trim((string)$opttext);
                        if ($oclean === '') {
                            continue;
                        }
                        $bankchoices[] = $oclean;
                        if ($oidx === $answeridx) {
                            $bankcorrect = $bidx;
                        }
                        $bidx++;
                    }
                    if (count($bankchoices) >= 2 && $bankcorrect >= 0) {
                        try {
                            local_bookcourse_save_multichoice_to_course_bank(
                                $bankcategory,
                                (int) $course->id,
                                $chaptertitle . ': ' . $qtext,
                                format_string($qtext, true),
                                $bankchoices,
                                $bankcorrect
                            );
                        } catch (\Throwable $e) {
                            debugging('local_bookcourse: question bank save: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                }
            }
        }
    }
}
