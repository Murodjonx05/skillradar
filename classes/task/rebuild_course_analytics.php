<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Deferred rebuild for all finished attempts in a course.
 */
class rebuild_course_analytics extends \core\task\adhoc_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrebuildanalytics', 'local_skillradar');
    }

    /**
     * @return void
     */
    public function execute(): void {
        $data = (array)$this->get_custom_data();
        $courseid = (int)($data['courseid'] ?? 0);
        if ($courseid < 1) {
            return;
        }
        \local_skillradar\manager::rebuild_course_quiz_attempts($courseid);
    }
}
