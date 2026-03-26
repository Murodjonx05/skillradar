<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds UI payloads from materialized tables only.
 */
class grade_provider {
    private const MIN_AXES = 3;

    /**
     * Build radar payload from materialized tables only (no quiz/grade-item joins).
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage optional course-average series for the chart
     * @return array
     */
    public static function get_course_skill_radar(int $userid, int $courseid, bool $includecourseaverage = false): array {
        $config = manager::get_course_config($courseid);
        $detail = self::build_skill_rows($courseid, $userid);
        return self::payload_from_detail($courseid, $detail, $includecourseaverage);
    }

    /**
     * One quiz only: skill axes from questions in that quiz (latest attempt materialized per skill).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $quizid
     * @param bool $includecourseaverage
     * @return array
     */
    public static function get_quiz_skill_radar(int $userid, int $courseid, int $quizid, bool $includecourseaverage = false): array {
        $detail = self::build_skill_rows_for_quiz($courseid, $userid, $quizid);
        if ($detail === []) {
            return self::empty_single_quiz_payload($courseid, $includecourseaverage);
        }
        return self::payload_from_detail($courseid, $detail, $includecourseaverage);
    }

    /**
     * No rows in local_skill_user_result for this quiz — do not show placeholder axes.
     *
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    private static function empty_single_quiz_payload(int $courseid, bool $includecourseaverage): array {
        $config = manager::get_course_config($courseid);
        $payload = [
            'skills' => [],
            'skills_detail' => [],
            'mapping_meta' => [],
            'chart' => null,
            'overall' => ['percent' => null, 'letter' => null],
            'primaryColor' => $config->primarycolor ?? '#3B82F6',
            'config' => [
                'overallmode' => $config->overallmode ?? 'average',
                'minaxes' => 0,
                'primaryColor' => $config->primarycolor ?? '#3B82F6',
            ],
            'empty_single_quiz' => true,
            'empty_message' => get_string('singleradar_noanalytics', 'local_skillradar'),
        ];
        if ($includecourseaverage && !empty($config->courseavg)) {
            $payload['course_average'] = null;
        }
        return $payload;
    }

    /**
     * @param int $courseid
     * @param array $detail
     * @param bool $includecourseaverage
     * @return array
     */
    private static function payload_from_detail(int $courseid, array $detail, bool $includecourseaverage): array {
        $config = manager::get_course_config($courseid);
        $chart = self::build_chart_meta($detail);

        $payload = [
            'skills' => self::build_skills_map($detail),
            'skills_detail' => $detail,
            'mapping_meta' => [],
            'chart' => $chart,
            'overall' => self::compute_overall($config, $detail),
            'primaryColor' => $config->primarycolor ?? '#3B82F6',
            'config' => [
                'overallmode' => $config->overallmode ?? 'average',
                'minaxes' => count($chart['labels']),
                'primaryColor' => $config->primarycolor ?? '#3B82F6',
            ],
        ];

        if ($includecourseaverage && !empty($config->courseavg)) {
            $payload['course_average'] = self::build_course_average($courseid, $detail);
        }

        return $payload;
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    /**
     * Exposed for hybrid merge: quiz category axes from materialized tables only.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function get_materialized_skill_rows(int $courseid, int $userid): array {
        return self::build_skill_rows($courseid, $userid);
    }

    private static function build_skill_rows(int $courseid, int $userid): array {
        global $DB;

        $sql = "SELECT skillid,
                       MAX(skillname) AS skillname,
                       SUM(earned) AS earned,
                       SUM(maxearned) AS maxearned,
                       SUM(questions_count) AS questions_count
                  FROM {local_skill_user_result}
                 WHERE courseid = :courseid
                   AND userid = :userid
                   AND aggregation_strategy = :strategy
              GROUP BY skillid
              ORDER BY MAX(skillname) ASC, skillid ASC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);

        $rows = [];
        foreach ($records as $record) {
            $percent = skill_aggregator::compute_percent((float)$record->earned, (float)$record->maxearned);
            $rows[] = [
                'key' => (string)$record->skillid,
                'label' => (string)$record->skillname,
                'color' => self::color_for_skill_row($courseid, (int)$record->skillid),
                'value' => $percent,
                'items' => (int)$record->questions_count,
                'empty' => false,
                'placeholder' => false,
                'earned' => round((float)$record->earned, 5),
                'maxearned' => round((float)$record->maxearned, 5),
            ];
        }

        return $rows;
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @param int $quizid
     * @return array
     */
    private static function build_skill_rows_for_quiz(int $courseid, int $userid, int $quizid): array {
        global $DB;

        $sql = "SELECT skillid,
                       skillname,
                       earned,
                       maxearned,
                       questions_count
                  FROM {local_skill_user_result}
                 WHERE courseid = :courseid
                   AND userid = :userid
                   AND quizid = :quizid
                   AND aggregation_strategy = :strategy
              ORDER BY skillname ASC, skillid ASC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'quizid' => $quizid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);

        $rows = [];
        foreach ($records as $record) {
            $percent = skill_aggregator::compute_percent((float)$record->earned, (float)$record->maxearned);
            $rows[] = [
                'key' => (string)$record->skillid,
                'label' => (string)$record->skillname,
                'color' => self::color_for_skill_row($courseid, (int)$record->skillid),
                'value' => $percent,
                'items' => (int)$record->questions_count,
                'empty' => false,
                'placeholder' => false,
                'earned' => round((float)$record->earned, 5),
                'maxearned' => round((float)$record->maxearned, 5),
            ];
        }

        return $rows;
    }

    /**
     * @param array $detail
     * @return array
     */
    private static function build_skills_map(array $detail): array {
        $skills = [];
        foreach ($detail as $row) {
            if ($row['placeholder']) {
                continue;
            }
            $skills[$row['label']] = $row['value'] === null ? 0.0 : (float)$row['value'];
        }
        return $skills;
    }

    /**
     * @param array $detail
     * @return array
     */
    private static function build_chart_meta(array $detail): array {
        $labels = [];
        $values = [];
        $colors = [];
        $placeholders = [];
        $keys = [];

        foreach ($detail as $row) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
            $colors[] = $row['color'];
            $placeholders[] = false;
            $keys[] = $row['key'];
        }

        $i = 0;
        while (count($labels) < self::MIN_AXES) {
            $labels[] = get_string('notconfigured', 'local_skillradar');
            $values[] = null;
            $colors[] = '#CBD5E1';
            $placeholders[] = true;
            $keys[] = '_placeholder_' . $i;
            $i++;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'colors' => $colors,
            'placeholder' => $placeholders,
            'keys' => $keys,
        ];
    }

    /**
     * @param \stdClass $config
     * @param array $detail
     * @return array
     */
    private static function compute_overall(\stdClass $config, array $detail): array {
        $overallmode = $config->overallmode ?? 'average';
        $available = array_values(array_filter($detail, static function(array $row): bool {
            return $row['value'] !== null;
        }));

        if (!$available) {
            return ['percent' => null, 'letter' => null];
        }

        if ($overallmode === 'final') {
            $earned = 0.0;
            $maxearned = 0.0;
            foreach ($available as $row) {
                $earned += (float)$row['earned'];
                $maxearned += (float)$row['maxearned'];
            }
            $percent = skill_aggregator::compute_percent($earned, $maxearned);
        } else {
            $sum = array_reduce($available, static function(float $carry, array $row): float {
                return $carry + (float)$row['value'];
            }, 0.0);
            $percent = round($sum / count($available), 2);
        }

        return ['percent' => $percent, 'letter' => null];
    }

    /**
     * Course-average series aligned to quiz materialized detail rows (same order as $detail).
     *
     * @param int $courseid
     * @param array $detail
     * @return array|null
     */
    public static function get_materialized_course_average(int $courseid, array $detail): ?array {
        return self::build_course_average($courseid, $detail);
    }

    private static function build_course_average(int $courseid, array $detail): ?array {
        global $DB;

        if (!$detail) {
            return null;
        }

        $sql = "SELECT skillid,
                       SUM(earned) AS earned,
                       SUM(maxearned) AS maxearned
                  FROM {local_skill_user_result}
                 WHERE courseid = :courseid
                   AND aggregation_strategy = :strategy
              GROUP BY skillid";
        $byvalues = [];
        $recordset = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);
        foreach ($recordset as $record) {
            $byvalues[(int)$record->skillid] = skill_aggregator::compute_percent((float)$record->earned, (float)$record->maxearned);
        }

        return [
            'label' => get_string('courseaveragelegend', 'local_skillradar'),
            'values' => array_map(static function(array $row) use ($byvalues) {
                $skillid = (int)$row['key'];
                return $byvalues[$skillid] ?? null;
            }, $detail),
        ];
    }

    /**
     * Colour for a materialized axis: course skill definition when skillid matches def.id, else palette by id.
     *
     * @param int $courseid
     * @param int $skillid Positive = local_skillradar_def.id; negative = question category id.
     * @return string
     */
    private static function color_for_skill_row(int $courseid, int $skillid): string {
        global $DB;

        if ($skillid > 0) {
            $def = $DB->get_record(manager::TABLE_DEF, ['courseid' => $courseid, 'id' => $skillid]);
            if ($def && !empty($def->color)) {
                return $def->color;
            }
        }

        return self::color_for_skill($skillid);
    }

    /**
     * @param int $skillid
     * @return string
     */
    private static function color_for_skill(int $skillid): string {
        $palette = ['#2563EB', '#059669', '#DC2626', '#D97706', '#7C3AED', '#0891B2'];
        $idx = abs($skillid) % count($palette);

        return $palette[$idx];
    }
}
