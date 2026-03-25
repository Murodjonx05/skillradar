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
];
