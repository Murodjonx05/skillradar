<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Two radars: (1) global — Moodle gradebook via grade-item→skill mapping;
 * (2) local — question-tag skills aggregated across all course quizzes (latest attempt per quiz per skill).
 */
class hybrid_provider {
    /**
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    public static function get_course_skill_radar(int $userid, int $courseid, bool $includecourseaverage = false): array {
        $courseskills = self::build_global_gradebook_radar($userid, $courseid, $includecourseaverage);

        $localskills = grade_provider::get_course_skill_radar($userid, $courseid, $includecourseaverage);
        $localskills['local_skills_all_quizzes'] = true;

        return [
            'course_skills_radar' => $courseskills,
            'question_skills_radar' => $courseskills,
            'local_skills_radar' => $localskills,
        ];
    }

    /**
     * Top chart: weighted quiz (and other) grade items from the Moodle gradebook — «Grade item mapping».
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    private static function build_global_gradebook_radar(int $userid, int $courseid, bool $includecourseaverage): array {
        $definitions = manager::get_definitions($courseid);
        $mappings = manager::get_mappings($courseid);
        if (!empty($definitions) || !empty($mappings)) {
            return calculator::build_payload($courseid, $userid, $includecourseaverage);
        }
        // Do not fall back to question-aggregated course radar — it duplicates the local (per-quiz) chart.
        return grade_provider::get_empty_global_gradebook_radar_payload($courseid, $includecourseaverage);
    }
}
