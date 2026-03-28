<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use mod_quiz\hook\structure_modified;

/**
 * Quiz hooks: rebuild materialized analytics when structure changes (slots, versions, marks).
 */
class hook_callbacks {
    /**
     * Invalidate caches and queue a course rebuild when quiz slots/questions/max marks change.
     *
     * @param structure_modified $hook
     * @return void
     */
    public static function quiz_structure_modified(structure_modified $hook): void {
        $quiz = $hook->get_structure()->get_quiz();
        $courseid = (int)($quiz->course ?? 0);
        if ($courseid < 1) {
            return;
        }
        manager::invalidate_course_cache($courseid);
        manager::queue_course_rebuild($courseid);
    }
}
