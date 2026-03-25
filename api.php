<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$withavg = optional_param('courseavg', 0, PARAM_BOOL);

$context = context_course::instance($courseid);
$canmanage = has_capability('local/skillradar:manage', $context);
$canusegrader = has_capability('gradereport/grader:view', $context) && has_capability('moodle/grade:viewall', $context);
$canviewlocal = has_capability('local/skillradar:view', $context);
$canviewuserreport = has_capability('gradereport/user:view', $context);
if (!$canmanage && !$canusegrader && !$canviewlocal && !$canviewuserreport && (int)$USER->id !== $userid) {
    throw new moodle_exception('nopermissions', 'error');
}
if ((int)$USER->id !== $userid && !$canmanage && !$canusegrader && !$canviewuserreport) {
    require_capability('moodle/grade:viewall', $context);
}

$config = \local_skillradar\manager::get_course_config($courseid);
if ($withavg && empty($config->courseavg)) {
    $withavg = false;
}

$cache = cache::make('local_skillradar', 'skillpayload');
$key = \local_skillradar\manager::cache_key($courseid, $userid) . ($withavg ? '_avg' : '');
$payload = $cache->get($key);
if ($payload === false) {
    $payload = \local_skillradar\calculator::build_payload($courseid, $userid, (bool)$withavg);
    $cache->set($key, $payload);
}

$payload['strings'] = [
    'notConfigured' => get_string('notconfigured', 'local_skillradar'),
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload);
