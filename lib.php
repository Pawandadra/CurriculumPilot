<?php
defined('MOODLE_INTERNAL') || die();

function local_bookcourse_extend_navigation_course($navigation, $course, $context) {
    global $PAGE;

    if (!has_capability('local/bookcourse:generate', $context)) {
        return;
    }
    $url = new moodle_url('/local/bookcourse/generate.php', ['courseid' => $course->id]);
    $node = navigation_node::create(
        get_string('generateheading', 'local_bookcourse'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_bookcourse_generate'
    );
    $navigation->add_node($node);
}

function local_bookcourse_create_lessons_from_data(stdClass $course, array $chapters) {
    global $CFG;
    require_once($CFG->dirroot . '/course/modlib.php');
    require_once($CFG->dirroot . '/mod/lesson/locallib.php');
    require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
    require_once($CFG->dirroot . '/mod/lesson/pagetypes/multichoice.php');

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
            }
        }
    }
}
