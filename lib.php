<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function local_skillradar_get_report_page_config(): array {
    global $PAGE, $USER;

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
        $userid = (int)optional_param('userid', 0, PARAM_INT);
        if ($userid < 1) {
            $userid = (int)$USER->id;
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

function local_skillradar_before_http_headers() {
    global $PAGE;

    $pageconfig = local_skillradar_get_report_page_config();
    if (empty($pageconfig['courseid'])) {
        return;
    }

    $assetrev = (string)max(
        @filemtime(__DIR__ . '/js/chart.umd.min.js') ?: 0,
        @filemtime(__DIR__ . '/js/script.js') ?: 0,
        @filemtime(__DIR__ . '/styles/radar.css') ?: 0
    );
    $PAGE->requires->css(new moodle_url('/local/skillradar/styles/radar.css', ['v' => $assetrev]));
    $PAGE->requires->js(new moodle_url('/local/skillradar/js/chart.umd.min.js', ['v' => $assetrev]), true);
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
}

function local_skillradar_before_footer() {
    $pageconfig = local_skillradar_get_report_page_config();
    $courseid = (int)$pageconfig['courseid'];
    if ($courseid < 1) {
        return '';
    }

    $context = context_course::instance($courseid);
    $userid = (int)$pageconfig['userid'];
    $config = [
        'courseId' => $courseid,
        'userId' => $userid,
        'reportType' => $pageconfig['type'],
        'apiUrl' => (new moodle_url('/local/skillradar/api.php'))->out(false),
        'sesskey' => sesskey(),
        'strings' => [
            'loading' => get_string('loading', 'local_skillradar'),
            'notConfigured' => get_string('notconfigured', 'local_skillradar'),
            'selectStudent' => get_string('graderselectstudent', 'local_skillradar'),
            'resultBreakdown' => get_string('resultbreakdown', 'local_skillradar'),
            'noResults' => get_string('noresults', 'local_skillradar'),
            'courseAverageLegend' => get_string('courseaveragelegend', 'local_skillradar'),
        ],
    ];

    $heading = html_writer::tag('h4', get_string('graderblocktitle', 'local_skillradar'));
    $help = html_writer::div(get_string('graderhelp', 'local_skillradar'), 'text-muted small mb-2');
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

    $chart = html_writer::div(
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
        'local-skillradar-chartwrap'
    );
    $results = html_writer::div('', 'local-skillradar-results', ['id' => 'local-skillradar-results']);
    $textdebug = html_writer::div('', 'local-skillradar-textdebug', ['id' => 'local-skillradar-text']);
    $jsondebug = html_writer::tag('pre', '', ['class' => 'local-skillradar-jsondebug', 'id' => 'local-skillradar-json']);
    $body = html_writer::div(
        $chart . $results . $textdebug . $jsondebug,
        'local-skillradar-body'
    );

    return html_writer::div(
        $heading . $help . $toolbar . $body,
        'local-skillradar-panel',
        ['id' => 'local-skillradar-panel', 'data-config' => json_encode($config)]
    ) . html_writer::script(
        "(function() {" .
        "var results = document.getElementById('local-skillradar-results');" .
        "if (results && !results.innerHTML) { results.innerHTML = '<p class=\"local-skillradar-results-empty\">Bootstrapping...</p>'; }" .
        "function run() {" .
        "if (window.localSkillRadarBoot) {" .
        "window.localSkillRadarBoot();" .
        "} else if (results) {" .
        "results.innerHTML = '<p class=\"local-skillradar-results-empty\">script.js not loaded</p>';" .
        "}" .
        "}" .
        "if (document.readyState === 'complete') { run(); } else { window.addEventListener('load', run, {once: true}); }" .
        "})();"
    );
}
