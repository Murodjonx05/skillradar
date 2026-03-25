<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function user_graded(\core\event\user_graded $event): void {
        if (empty($event->courseid) || empty($event->relateduserid)) {
            return;
        }
        manager::invalidate_user_cache((int)$event->courseid, (int)$event->relateduserid);
    }

    public static function grade_item_deleted(\core\event\grade_item_deleted $event): void {
        if (empty($event->courseid)) {
            return;
        }
        manager::purge_stale_mappings((int)$event->courseid);
        manager::invalidate_course_cache((int)$event->courseid);
    }
}
