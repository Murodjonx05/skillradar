<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Quiz analytics recompute + grade-item cache invalidation.
 */
class observer {
    public static function user_graded(\core\event\user_graded $event): void {
        if (empty($event->courseid)) {
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

    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        self::recompute_attempt((int)$event->objectid);
    }

    public static function attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        self::recompute_attempt((int)$event->objectid);
    }

    public static function attempt_regraded(\mod_quiz\event\attempt_regraded $event): void {
        self::recompute_attempt((int)$event->objectid);
    }

    public static function question_manually_graded(\mod_quiz\event\question_manually_graded $event): void {
        self::recompute_attempt((int)$event->other['attemptid']);
    }

    /**
     * Analytics only apply to finalized quiz attempts (not submitted-awaiting-grade, in progress, etc.).
     *
     * @param int $attemptid
     * @return void
     */
    private static function recompute_attempt(int $attemptid): void {
        global $DB;

        if ($attemptid < 1) {
            return;
        }

        $state = $DB->get_field('quiz_attempts', 'state', ['id' => $attemptid]);
        if ($state !== \mod_quiz\quiz_attempt::FINISHED) {
            return;
        }

        cache_manager::recompute_attempt($attemptid);
    }

    /**
     * Persists question→skill_key from the question edit form (POST skillradar_skill_key).
     * Runs on question_created because each save creates a new question version row.
     *
     * @param \core\event\question_created $event
     * @return void
     */
    public static function question_created(\core\event\question_created $event): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !array_key_exists('skillradar_skill_key', $_POST)) {
            return;
        }

        if (!isset($_POST['cmid'], $_POST['courseid'], $_POST['sesskey'])) {
            return;
        }

        if (!confirm_sesskey($_POST['sesskey'])) {
            return;
        }

        if (!class_exists(manager::class) || !manager::qmap_table_exists()) {
            return;
        }

        $cmid = (int) $_POST['cmid'];
        $courseid = (int) $_POST['courseid'];
        if ($cmid < 1 || $courseid < 1) {
            return;
        }

        try {
            list(, $cm) = get_course_and_cm_from_cmid($cmid);
        } catch (\Throwable $e) {
            return;
        }
        if ((int) $cm->course !== $courseid) {
            return;
        }

        $coursecontext = \context_course::instance($courseid);
        if (!has_capability('local/skillradar:manage', $coursecontext)) {
            return;
        }

        $skillkey = clean_param($_POST['skillradar_skill_key'], PARAM_TEXT);
        $skillkey = trim($skillkey);

        $validkeys = [];
        foreach (manager::get_definitions($courseid) as $def) {
            $validkeys[$def->skill_key] = true;
        }

        if ($skillkey !== '' && $skillkey !== '_none' && !isset($validkeys[$skillkey])) {
            return;
        }

        $questionid = (int) $event->objectid;
        if ($questionid < 1) {
            return;
        }

        $rows = [[
            'questionid' => $questionid,
            'skill_key' => ($skillkey === '' || $skillkey === '_none') ? '_none' : $skillkey,
        ]];

        manager::replace_question_skill_mappings($courseid, $rows);
        manager::rebuild_course_quiz_attempts($courseid);
    }
}
