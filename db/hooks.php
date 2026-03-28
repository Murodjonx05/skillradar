<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => mod_quiz\hook\structure_modified::class,
        'callback' => local_skillradar\hook_callbacks::class . '::quiz_structure_modified',
        'priority' => 500,
    ],
];
