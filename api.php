<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$withavg = optional_param('courseavg', 0, PARAM_BOOL);

$course = get_course($courseid);
$context = context_course::instance($courseid);

if ($userid < 1) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'no_user_selected',
        'strings' => [
            'notConfigured' => get_string('notconfigured', 'local_skillradar'),
        ],
    ]);
    exit;
}

if ((int)$userid !== (int)$USER->id) {
    if (!has_capability('local/skillradar:manage', $context)) {
        require_capability('moodle/grade:viewall', $context);
    }
    $targetuser = core_user::get_user($userid, '*', MUST_EXIST);
    core_user::require_active_user($targetuser);
    if (!groups_user_groups_visible($course, $userid)) {
        throw new moodle_exception('notingroup');
    }
} else {
    $canviewown = ($course->showgrades && has_capability('moodle/grade:view', $context))
        || has_capability('local/skillradar:manage', $context)
        || has_capability('local/skillradar:view', $context);
    if (!$canviewown) {
        throw new moodle_exception('nopermissions', 'error');
    }
}

if (!\local_skillradar\manager::is_course_skillradar_ready($courseid)) {
    require_capability('local/skillradar:manage', $context);
}

$config = \local_skillradar\manager::get_course_config($courseid);
if ($withavg && empty($config->courseavg)) {
    $withavg = false;
}

$cache = \cache::make('local_skillradar', 'skillpayload');
$base = \local_skillradar\manager::cache_key($courseid, $userid);
$key = $base . ($withavg ? '_avg' : '');

$payload = $cache->get($key);
if ($payload === false || !isset($payload['course_skills_radar']) || !isset($payload['local_skills_radar'])) {
    $payload = \local_skillradar\hybrid_provider::get_course_skill_radar($userid, $courseid, (bool)$withavg);
    $cache->set($key, $payload);
}

$payload['strings'] = array_merge($payload['strings'] ?? [], [
    'notConfigured' => get_string('notconfigured', 'local_skillradar'),
    'noResults' => get_string('noresults', 'local_skillradar'),
    'resultBreakdown' => get_string('resultbreakdown', 'local_skillradar'),
    'courseAverageLegend' => get_string('courseaveragelegend', 'local_skillradar'),
    'radarQuizModulesDataset' => get_string('radarquizmodulesdataset', 'local_skillradar'),
    'radarQuestionSkillsDataset' => get_string('radarquestionskillsdataset', 'local_skillradar'),
    'radarCourseSkillsDataset' => get_string('radarcourseskillsdataset', 'local_skillradar'),
    'radarQuizModulesAvg' => get_string('radarquizmodulesavg', 'local_skillradar'),
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload);
