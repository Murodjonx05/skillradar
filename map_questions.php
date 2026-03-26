<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();

global $OUTPUT, $PAGE;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/skillradar:manage', $context);

$PAGE->set_url(new moodle_url('/local/skillradar/map_questions.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('mapquestions', 'local_skillradar'));

if (!\local_skillradar\manager::qmap_table_exists()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('errorqmaptablemissing', 'local_skillradar'), \core\output\notification::NOTIFY_ERROR);
    echo html_writer::div(get_string('errorqmaptablemissing_help', 'local_skillradar'), 'alert alert-secondary');
    echo $OUTPUT->footer();
    exit;
}

$definitions = \local_skillradar\manager::get_definitions($courseid);
$questions = \local_skillradar\manager::get_course_quiz_questions($courseid);
$overrides = \local_skillradar\manager::get_question_skill_overrides($courseid);

if (data_submitted() && confirm_sesskey()) {
    $validids = [];
    foreach ($questions as $q) {
        $validids[(int)$q->questionid] = true;
    }
    $validkeys = [];
    foreach ($definitions as $def) {
        $validkeys[$def->skill_key] = true;
    }
    $rows = [];
    foreach (array_keys($validids) as $qid) {
        $sk = trim((string)optional_param('skill_key_' . $qid, '_none', PARAM_TEXT));
        if ($sk === '' || $sk === '_none' || !isset($validkeys[$sk])) {
            $rows[] = ['questionid' => $qid, 'skill_key' => '_none'];
        } else {
            $rows[] = ['questionid' => $qid, 'skill_key' => $sk];
        }
    }
    \local_skillradar\manager::replace_question_skill_mappings($courseid, $rows);
    \local_skillradar\manager::rebuild_course_quiz_attempts($courseid);
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mapquestions', 'local_skillradar'));

echo html_writer::div(get_string('mapquestions_help', 'local_skillradar'), 'alert alert-info mb-3');

echo html_writer::link(
    new moodle_url('/local/skillradar/manage.php', ['courseid' => $courseid]),
    '← ' . get_string('manageheading', 'local_skillradar'),
    ['class' => 'mb-3 d-inline-block']
);

if (!$definitions) {
    echo $OUTPUT->notification(get_string('mapquestions_needdefs', 'local_skillradar'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

if (!$questions) {
    echo html_writer::div(get_string('mapquestions_noquestions', 'local_skillradar'), 'alert alert-secondary');
    echo $OUTPUT->footer();
    exit;
}

$skilloptions = ['_none' => get_string('mapquestions_usecategory', 'local_skillradar')];
foreach ($definitions as $def) {
    $skilloptions[$def->skill_key] = $def->skill_key . ' — ' . format_string($def->displayname);
}

echo '<form method="post" action="' . s($PAGE->url->out(false)) . '" class="mb-4">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';

$table = new html_table();
$table->head = [
    get_string('questionname', 'question'),
    get_string('mapquestions_column_type', 'local_skillradar'),
    get_string('mapquestions_skilltag', 'local_skillradar'),
];
$table->attributes['class'] = 'generaltable';
foreach ($questions as $q) {
    $qid = (int)$q->questionid;
    $name = format_string($q->name);
    $current = $overrides[$qid] ?? '_none';
    $opts = '';
    foreach ($skilloptions as $val => $label) {
        $sel = ((string)$val === (string)$current) ? ' selected' : '';
        $opts .= '<option value="' . s($val) . '"' . $sel . '>' . format_string($label) . '</option>';
    }
    $select = '<select name="skill_key_' . $qid . '" class="form-select">' . $opts . '</select>';
    $table->data[] = [$name, s($q->qtype), $select];
}
echo html_writer::table($table);
echo '<button type="submit" class="btn btn-primary">' . get_string('savechanges', 'core') . '</button>';
echo '</form>';

echo $OUTPUT->footer();
