<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_skillradar\observer::user_graded',
    ],
    [
        'eventname' => '\core\event\grade_item_deleted',
        'callback' => '\local_skillradar\observer::grade_item_deleted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_skillradar\observer::attempt_submitted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\local_skillradar\observer::attempt_graded',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_regraded',
        'callback' => '\local_skillradar\observer::attempt_regraded',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_manual_grading_completed',
        'callback' => '\local_skillradar\observer::attempt_manual_grading_completed',
    ],
    [
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback' => '\local_skillradar\observer::question_manually_graded',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\local_skillradar\observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\question_created',
        'callback' => '\local_skillradar\observer::question_created',
    ],
    [
        'eventname' => '\core\event\question_updated',
        'callback' => '\local_skillradar\observer::question_updated',
    ],
];
