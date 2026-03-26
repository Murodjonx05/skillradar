<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Course skill radar: all quizzes, aggregated by skill (question-level analytics or grade mappings).
 */
class hybrid_provider {
    /**
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    public static function get_course_skill_radar(int $userid, int $courseid, bool $includecourseaverage = false): array {
        $courseskills = self::build_question_skills_payload($userid, $courseid, $includecourseaverage);

        return [
            'course_skills_radar' => $courseskills,
            'question_skills_radar' => $courseskills,
        ];
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    private static function build_question_skills_payload(int $userid, int $courseid, bool $includecourseaverage): array {
        $definitions = manager::get_definitions($courseid);
        $mappings = manager::get_mappings($courseid);
        $haslegacy = !empty($definitions) || !empty($mappings);

        $quizrows = grade_provider::get_materialized_skill_rows($courseid, $userid);
        $hasquiz = !empty($quizrows);

        if (!$haslegacy) {
            return grade_provider::get_course_skill_radar($userid, $courseid, $includecourseaverage);
        }

        if (!$hasquiz) {
            return calculator::build_payload($courseid, $userid, $includecourseaverage);
        }

        return grade_provider::get_course_skill_radar($userid, $courseid, $includecourseaverage);
    }
}
