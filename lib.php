<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function local_skillradar_get_report_page_config(): array {
    global $PAGE, $SESSION, $USER;

    $path = $PAGE->url->get_path(false);
    // On grade reports, URL param "id" is the course id. On most other pages (e.g. mod/quiz/view.php)
    // "id" is the course module id — do not treat it as a course id or context_course::instance will throw.
    $isgrader = strpos($path, 'grade/report/grader/index.php') !== false;
    $isuserreport = strpos($path, 'grade/report/user/index.php') !== false;
    if (!$isgrader && !$isuserreport) {
        return ['courseid' => 0, 'userid' => 0, 'type' => ''];
    }

    $courseid = (int)$PAGE->url->param('id');
    if ($courseid < 1) {
        return ['courseid' => 0, 'userid' => 0, 'type' => ''];
    }

    $context = context_course::instance($courseid);
    if ($isgrader) {
        if (!has_capability('gradereport/grader:view', $context) || !has_capability('moodle/grade:viewall', $context)) {
            return ['courseid' => 0, 'userid' => 0, 'type' => ''];
        }
        return [
            'courseid' => $courseid,
            'userid' => (int)optional_param('gpr_userid', 0, PARAM_INT),
            'type' => 'grader',
        ];
    }

    if ($isuserreport) {
        // Match gradereport/user/index.php: userid may be absent from URL while the report uses
        // $SESSION->gradereport_user["useritem-{$context->id}"] for the selected student.
        $urlparams = $PAGE->url->params();
        $hasuseridparam = array_key_exists('userid', $urlparams);
        $useridparam = $hasuseridparam ? clean_param($urlparams['userid'], PARAM_INT) : null;
        if ($hasuseridparam && $useridparam > 0) {
            $userid = (int)$useridparam;
        } else if ($hasuseridparam && $useridparam === 0) {
            // Teacher "all users" report mode — no single user for the radar.
            $userid = 0;
        } else {
            if (has_capability('moodle/grade:viewall', $context)) {
                $sesskey = 'useritem-' . $context->id;
                $lastviewed = $SESSION->gradereport_user[$sesskey] ?? null;
                if ($lastviewed !== null && (int)$lastviewed > 0) {
                    $userid = (int)$lastviewed;
                } else {
                    $userid = 0;
                }
            } else {
                $userid = (int)$USER->id;
            }
        }
        $canview = has_capability('local/skillradar:view', $context) ||
            has_capability('local/skillradar:manage', $context) ||
            has_capability('gradereport/user:view', $context) ||
            has_capability('moodle/grade:viewall', $context) ||
            (int)$USER->id === $userid;
        if (!$canview) {
            return ['courseid' => 0, 'userid' => 0, 'type' => ''];
        }
        return [
            'courseid' => $courseid,
            'userid' => $userid,
            'type' => 'user',
        ];
    }

    return ['courseid' => 0, 'userid' => 0, 'type' => ''];
}

/**
 * Whether to inject Skill Radar on grade report pages (assets + block).
 * Non-managers only see it when the course has at least one skill (axis) defined.
 */
function local_skillradar_should_show_panel(int $courseid, \context_course $context): bool {
    if ($courseid < 1) {
        return false;
    }
    if (has_capability('local/skillradar:manage', $context)) {
        return true;
    }
    return \local_skillradar\manager::is_course_skillradar_ready($courseid);
}

/**
 * Collapsible debug block under a chart (meta + JSON + Chart.js slot).
 *
 * @param string $which 'course' (chart payload slice key)
 * @return string
 */
function local_skillradar_chart_debug_block(string $which): string {
    $title = get_string('chartdebug_course_title', 'local_skillradar');
    $idmeta = 'local-skillradar-debug-' . $which . '-meta';
    $idjson = 'local-skillradar-debug-' . $which . '-json';
    $idchartjs = 'local-skillradar-debug-' . $which . '-chartjs';

    $nested = html_writer::tag(
        'details',
        html_writer::tag('summary', get_string('chartdebug_chartjs', 'local_skillradar'), ['class' => 'local-skillradar-chart-debug-summary local-skillradar-chart-debug-summary--nested']) .
        html_writer::tag('pre', '', ['class' => 'local-skillradar-chart-debug-json local-skillradar-chart-debug-json--chartjs', 'id' => $idchartjs]),
        ['class' => 'local-skillradar-chart-debug-nested']
    );

    return html_writer::div(
        html_writer::tag(
            'details',
            html_writer::tag('summary', $title, ['class' => 'local-skillradar-chart-debug-summary']) .
            html_writer::div('', 'local-skillradar-chart-debug-meta', ['id' => $idmeta]) .
            html_writer::tag('pre', '', ['class' => 'local-skillradar-chart-debug-json', 'id' => $idjson]) .
            $nested,
            ['class' => 'local-skillradar-chart-debug']
        ),
        'local-skillradar-chart-debug-outer'
    );
}

function local_skillradar_before_http_headers() {
    global $PAGE;

    $pageconfig = local_skillradar_get_report_page_config();
    $path = $PAGE->url->get_path(false);
    $assetrev = (string)max(
        @filemtime(__DIR__ . '/js/chart.umd.min.js') ?: 0,
        @filemtime(__DIR__ . '/js/radar_arc_plugin.js') ?: 0,
        @filemtime(__DIR__ . '/js/common.js') ?: 0,
        @filemtime(__DIR__ . '/js/script.js') ?: 0,
        @filemtime(__DIR__ . '/js/quiz_random_by_skill.js') ?: 0,
        @filemtime(__DIR__ . '/styles/radar.css') ?: 0
    );

    if ($path === '/mod/quiz/edit.php') {
        $cmid = (int)optional_param('cmid', 0, PARAM_INT);
        if ($cmid > 0) {
            try {
                list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
                $modulecontext = context_module::instance((int)$cm->id);
                $coursecontext = context_course::instance((int)$course->id);
                if (has_capability('mod/quiz:manage', $modulecontext)
                        && has_capability('local/skillradar:manage', $coursecontext)
                        && !empty(\local_skillradar\manager::get_definitions((int)$course->id))) {
                    $PAGE->requires->js(new moodle_url('/local/skillradar/js/quiz_random_by_skill.js', ['v' => $assetrev]), true);
                }
            } catch (\Throwable $e) {
                debugging('local_skillradar quiz edit button: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    if (empty($pageconfig['courseid'])) {
        return;
    }
    $context = context_course::instance((int) $pageconfig['courseid']);
    if (!local_skillradar_should_show_panel((int) $pageconfig['courseid'], $context)) {
        return;
    }
    $PAGE->requires->css(new moodle_url('/local/skillradar/styles/radar.css', ['v' => $assetrev]));
    $PAGE->requires->js(new moodle_url('/local/skillradar/js/chart.umd.min.js', ['v' => $assetrev]), true);
    $PAGE->requires->js(new moodle_url('/local/skillradar/js/radar_arc_plugin.js', ['v' => $assetrev]), true);
    $PAGE->requires->js(new moodle_url('/local/skillradar/js/common.js', ['v' => $assetrev]), true);
    $PAGE->requires->js(new moodle_url('/local/skillradar/js/script.js', ['v' => $assetrev]), true);
}

function local_skillradar_extend_navigation_course($navigation, $course, $context) {
    if ($context->contextlevel !== CONTEXT_COURSE || !has_capability('local/skillradar:manage', $context)) {
        return;
    }
    $navigation->add(
        get_string('navskillradar', 'local_skillradar'),
        new moodle_url('/local/skillradar/manage.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_skillradar',
        new pix_icon('i/settings', '')
    );
    $navigation->add(
        get_string('mapquestions', 'local_skillradar'),
        new moodle_url('/local/skillradar/map_questions.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_skillradar_qmap',
        new pix_icon('i/item', '')
    );
}

/**
 * Banner on question-bank related pages: link to per-question skill mapping (managers only).
 *
 * @return string
 */
function local_skillradar_question_bank_skill_notice_html(): string {
    global $PAGE;

    $path = $PAGE->url->get_path(false);
    if (strpos($path, '/local/skillradar/') !== false) {
        return '';
    }
    if (strpos($path, '/question/') === false) {
        return '';
    }

    $courseid = 0;
    $cid = optional_param('courseid', 0, PARAM_INT);
    if ($cid > 0) {
        $courseid = (int)$cid;
    } else {
        $cmid = optional_param('cmid', 0, PARAM_INT);
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $courseid = (int)$cm->course;
            }
        }
    }
    if ($courseid < 1 && !empty($PAGE->course->id) && (int)$PAGE->course->id > 1) {
        $courseid = (int)$PAGE->course->id;
    }
    if ($courseid < 1) {
        return '';
    }

    $context = context_course::instance($courseid);
    if (!has_capability('local/skillradar:manage', $context)) {
        return '';
    }

    $text = get_string('questionbankskillhint', 'local_skillradar') . ' ';
    if (\core\plugininfo\qbank::is_plugin_enabled('qbank_skillradar')
            && class_exists(\local_skillradar\manager::class)
            && \local_skillradar\manager::qmap_table_exists()
            && !empty(\local_skillradar\manager::get_definitions($courseid))) {
        $text .= get_string('mapquestions_banner_column', 'local_skillradar');
        return html_writer::div(
            html_writer::div($text, 'mb-0'),
            'alert alert-info local-skillradar-qbank-hint mb-3'
        );
    }

    $mapurl = new moodle_url('/local/skillradar/map_questions.php', ['courseid' => $courseid]);
    $text .= get_string('mapquestions_banner', 'local_skillradar');
    $link = html_writer::link(
        $mapurl,
        get_string('mapquestions_open', 'local_skillradar'),
        ['class' => 'btn btn-primary mt-2']
    );

    return html_writer::div(
        html_writer::div($text, 'mb-0') . $link,
        'alert alert-info local-skillradar-qbank-hint mb-3'
    );
}

function local_skillradar_before_footer() {
    return local_skillradar_render_quiz_random_button()
        . local_skillradar_question_bank_skill_notice_html()
        . local_skillradar_render_grade_report_panel();
}

/**
 * Render entry point for random-by-skill authoring on the quiz edit page.
 *
 * @return string
 */
function local_skillradar_render_quiz_random_button(): string {
    global $PAGE;

    if ($PAGE->url->get_path(false) !== '/mod/quiz/edit.php') {
        return '';
    }

    $cmid = (int)optional_param('cmid', 0, PARAM_INT);
    if ($cmid < 1) {
        return '';
    }

    try {
        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
    } catch (\Throwable $e) {
        return '';
    }

    $modulecontext = context_module::instance((int)$cm->id);
    $coursecontext = context_course::instance((int)$course->id);
    if (!has_capability('mod/quiz:manage', $modulecontext) || !has_capability('local/skillradar:manage', $coursecontext)) {
        return '';
    }
    if (empty(\local_skillradar\manager::get_definitions((int)$course->id))) {
        return '';
    }

    $params = ['cmid' => $cmid];
    $cat = optional_param('cat', null, PARAM_SEQUENCE);
    $category = optional_param('category', null, PARAM_SEQUENCE);
    if (!empty($category)) {
        $params['category'] = $category;
    } else if (!empty($cat)) {
        $params['cat'] = $cat;
    }

    return html_writer::link(
        new moodle_url('/local/skillradar/random_by_skill.php', $params),
        get_string('randomskill_open', 'local_skillradar'),
        [
            'id' => 'local-skillradar-random-by-skill-link',
            'class' => 'btn btn-outline-secondary d-none mt-2',
        ]
    );
}

/**
 * Skill Radar chart on grader / user grade report only (course-wide skills).
 *
 * @return string
 */
function local_skillradar_render_grade_report_panel(): string {
    global $CFG;

    $pageconfig = local_skillradar_get_report_page_config();
    $courseid = (int)$pageconfig['courseid'];
    if ($courseid < 1) {
        return '';
    }

    $context = context_course::instance($courseid);
    if (!local_skillradar_should_show_panel($courseid, $context)) {
        return '';
    }

    $debugskillradar = !empty($CFG->debugdeveloper) && has_capability('local/skillradar:manage', $context);
    $chartdebug = has_capability('local/skillradar:manage', $context)
        || has_capability('moodle/grade:viewall', $context)
        || !empty($CFG->debugdeveloper);
    $userid = (int)$pageconfig['userid'];
    $coursecfg = \local_skillradar\manager::get_course_config($courseid);
    $primary = $coursecfg->primarycolor ?? '#3B82F6';
    $config = [
        'courseId' => $courseid,
        'userId' => $userid,
        'reportType' => $pageconfig['type'],
        'primaryColor' => $primary,
        'apiUrl' => (new moodle_url('/local/skillradar/api.php'))->out(false),
        'sesskey' => sesskey(),
        'strings' => [
            'loading' => get_string('loading', 'local_skillradar'),
            'notConfigured' => get_string('notconfigured', 'local_skillradar'),
            'selectStudent' => get_string('graderselectstudent', 'local_skillradar'),
            'resultBreakdown' => get_string('resultbreakdown', 'local_skillradar'),
            'noResults' => get_string('noresults', 'local_skillradar'),
            'courseAverageLegend' => get_string('courseaveragelegend', 'local_skillradar'),
            'fetchError' => get_string('fetcherror', 'local_skillradar'),
            'radarQuizModulesDataset' => get_string('radarquizmodulesdataset', 'local_skillradar'),
            'radarQuestionSkillsDataset' => get_string('radarquestionskillsdataset', 'local_skillradar'),
            'radarCourseSkillsDataset' => get_string('radarcourseskillsdataset', 'local_skillradar'),
            'radarLocalSkillsDataset' => get_string('radarlocalskillsdataset', 'local_skillradar'),
            'radarQuizModulesAvg' => get_string('radarquizmodulesavg', 'local_skillradar'),
            'chartdebugCourseTitle' => get_string('chartdebug_course_title', 'local_skillradar'),
            'chartdebugFullTitle' => get_string('chartdebug_full_title', 'local_skillradar'),
            'chartdebugRequest' => get_string('chartdebug_request', 'local_skillradar'),
            'chartdebugLabels' => get_string('chartdebug_labels', 'local_skillradar'),
            'chartdebugValues' => get_string('chartdebug_values', 'local_skillradar'),
            'chartdebugOverall' => get_string('chartdebug_overall', 'local_skillradar'),
            'chartdebugChartjs' => get_string('chartdebug_chartjs', 'local_skillradar'),
            'chartdebugNoPayload' => get_string('chartdebug_no_payload', 'local_skillradar'),
            'debugNoSkills' => get_string('debug_no_skills', 'local_skillradar'),
            'radarLocalSubtitle' => get_string('radarlocalsubtitle', 'local_skillradar'),
            'radarLocalQuizPrefix' => get_string('radarlocalquizprefix', 'local_skillradar'),
            'radarLocalScopeAll' => get_string('radarlocalscopeall', 'local_skillradar'),
        ],
        'debugSkillRadar' => $debugskillradar,
        'chartDebug' => $chartdebug,
    ];

    $heading = html_writer::tag('h4', get_string('graderblocktitle', 'local_skillradar'));
    $help = html_writer::div(get_string('graderhelp', 'local_skillradar'), 'text-muted small mb-2') .
        html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('skillmodel_summary', 'local_skillradar'), ['class' => 'small fw-bold']) .
            html_writer::div(get_string('skillmodel_help', 'local_skillradar'), 'text-muted small mt-2'),
            ['class' => 'mb-2 local-skillradar-skillmodel']
        );
    $toolbar = '';
    if (has_capability('local/skillradar:manage', $context)) {
        $toolbar = html_writer::div(
            html_writer::link(
                new moodle_url('/local/skillradar/manage.php', ['courseid' => $courseid]),
                get_string('graderconfigure', 'local_skillradar'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            ),
            'mb-2'
        );
    }

    $chartwrap = html_writer::div(
        html_writer::tag('canvas', '', ['id' => 'local-skillradar-canvas']) .
        html_writer::div(
            html_writer::div(
                html_writer::div('0%', 'local-skillradar-score-value', ['id' => 'local-skillradar-score-value']) .
                html_writer::div('AVERAGE', 'local-skillradar-score-label', ['id' => 'local-skillradar-score-label']),
                'local-skillradar-center-score',
                ['id' => 'local-skillradar-center-score', 'title' => 'Toggle score mode']
            ),
            'local-skillradar-center-anchor'
        ),
        'local-skillradar-chartwrap local-skillradar-chartwrap--course'
    );
    $sectioncourse = html_writer::div(
        html_writer::tag('h5', get_string('radarsectionunified', 'local_skillradar'), ['class' => 'local-skillradar-section-title']) .
        $chartwrap .
        html_writer::div('', 'local-skillradar-course-overall text-muted small mb-1', ['id' => 'local-skillradar-course-overall']) .
        html_writer::div('', 'local-skillradar-results', ['id' => 'local-skillradar-results']) .
        ($chartdebug ? local_skillradar_chart_debug_block('course') : ''),
        'local-skillradar-section local-skillradar-section--course'
    );
    $chartwraplocal = html_writer::div(
        html_writer::tag('canvas', '', ['id' => 'local-skillradar-canvas-local']) .
        html_writer::div(
            html_writer::div(
                html_writer::div('0%', 'local-skillradar-score-value', ['id' => 'local-skillradar-score-value-local']) .
                html_writer::div('AVERAGE', 'local-skillradar-score-label', ['id' => 'local-skillradar-score-label-local']),
                'local-skillradar-center-score',
                ['id' => 'local-skillradar-center-score-local', 'title' => 'Toggle score mode']
            ),
            'local-skillradar-center-anchor'
        ),
        'local-skillradar-chartwrap local-skillradar-chartwrap--local'
    );
    $sectionlocal = html_writer::div(
        html_writer::tag('h5', get_string('radarsectionsinglequiz', 'local_skillradar'), ['class' => 'local-skillradar-section-title']) .
        html_writer::div(get_string('radarlocalsubtitle', 'local_skillradar'), 'text-muted small mb-1', ['id' => 'local-skillradar-local-intro']) .
        html_writer::div('', 'text-muted small fw-semibold mb-2', ['id' => 'local-skillradar-quiz-name']) .
        $chartwraplocal .
        html_writer::div('', 'local-skillradar-local-overall text-muted small mb-1', ['id' => 'local-skillradar-local-overall']) .
        html_writer::div('', 'local-skillradar-results', ['id' => 'local-skillradar-results-local']),
        'local-skillradar-section local-skillradar-section--local local-skillradar-section--spaced'
    );
    $debugblocks = '';
    if ($debugskillradar) {
        $debugblocks = html_writer::div(
            html_writer::div('', 'local-skillradar-textdebug', ['id' => 'local-skillradar-text']) .
            html_writer::tag('pre', '', ['class' => 'local-skillradar-jsondebug', 'id' => 'local-skillradar-json']),
            'local-skillradar-debug-legacy-wrap'
        );
    }
    $fulldebug = '';
    if ($chartdebug) {
        $fulldebug = html_writer::div(
            html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('chartdebug_full_title', 'local_skillradar'), ['class' => 'local-skillradar-chart-debug-summary']) .
                html_writer::div('', 'local-skillradar-chart-debug-meta', ['id' => 'local-skillradar-debug-full-meta']) .
                html_writer::tag('pre', '', ['class' => 'local-skillradar-chart-debug-json', 'id' => 'local-skillradar-debug-full-json']),
                ['class' => 'local-skillradar-chart-debug']
            ),
            'local-skillradar-debug-api-wrap'
        );
    }
    $body = html_writer::div(
        $sectioncourse . $sectionlocal . $fulldebug . $debugblocks,
        'local-skillradar-body'
    );

    $bootstrapping = json_encode('Bootstrapping...');
    $scriptmissing = json_encode('script.js not loaded');

    return html_writer::div(
        $heading . $help . $toolbar . $body,
        'local-skillradar-panel',
        ['id' => 'local-skillradar-panel', 'data-config' => json_encode($config)]
    ) . html_writer::script(
        "(function() {" .
        "var results = document.getElementById('local-skillradar-results');" .
        "var resultsLocal = document.getElementById('local-skillradar-results-local');" .
        "function setMessage(node, text) {" .
        "if (!node || node.childNodes.length) { return; }" .
        "var p = document.createElement('p');" .
        "p.className = 'local-skillradar-results-empty';" .
        "p.textContent = text;" .
        "node.appendChild(p);" .
        "}" .
        "setMessage(results, " . $bootstrapping . ");" .
        "setMessage(resultsLocal, " . $bootstrapping . ");" .
        "function run() {" .
        "if (window.localSkillRadarBoot) {" .
        "window.localSkillRadarBoot();" .
        "} else if (results) {" .
        "results.textContent = '';" .
        "setMessage(results, " . $scriptmissing . ");" .
        "}" .
        "}" .
        "if (document.readyState === 'complete') { run(); } else { window.addEventListener('load', run, {once: true}); }" .
        "})();"
    );
}
