<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Radar axes = one quiz activity (mod_quiz) per course.
 *
 * Values prefer materialized question/slot analytics (skills = question bank categories), i.e.
 * SUM(earned)/SUM(maxearned) per quiz from {@see cache_manager::TABLE_USER}. Falls back to the
 * gradebook quiz item when no materialized rows exist (e.g. no attempt yet).
 */
class quiz_module_radar_provider {
    /**
     * Percent for one quiz from latest materialized per-skill rows (question-weighted pipeline).
     *
     * @param int $courseid
     * @param int $quizid
     * @param int $userid
     * @return float|null Null if nothing materialized
     */
    public static function materialized_quiz_percent(int $courseid, int $quizid, int $userid): ?float {
        global $DB;

        $record = $DB->get_record_sql(
            "SELECT SUM(earned) AS earned, SUM(maxearned) AS maxearned
               FROM {" . cache_manager::TABLE_USER . "}
              WHERE courseid = :courseid
                AND userid = :userid
                AND quizid = :quizid
                AND aggregation_strategy = :strategy",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'quizid' => $quizid,
                'strategy' => cache_manager::STRATEGY_LATEST,
            ]
        );
        if (!$record) {
            return null;
        }
        $max = (float)$record->maxearned;
        if ($max <= 0.0) {
            return null;
        }
        return skill_aggregator::compute_percent((float)$record->earned, $max);
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function build_payload(int $userid, int $courseid): array {
        global $DB;

        $sql = "SELECT MAX(gi.id) AS gradeitemid,
                       gi.iteminstance AS quizid,
                       MAX(q.name) AS quizname
                  FROM {grade_items} gi
             LEFT JOIN {quiz} q ON q.id = gi.iteminstance
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = 'mod'
                   AND gi.itemmodule = 'quiz'
              GROUP BY gi.iteminstance
              ORDER BY MIN(gi.sortorder) ASC, gi.iteminstance ASC";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $gradeitemids = [];
        $quizids = [];
        foreach ($rows as $row) {
            $gradeitemids[] = (int)$row->gradeitemid;
            $quizids[] = (int)$row->quizid;
        }
        $gradebookpercents = calculator::percent_for_grade_items($courseid, $gradeitemids, $userid);
        $materializedrecords = $DB->get_records_sql(
            "SELECT quizid, SUM(earned) AS earned, SUM(maxearned) AS maxearned
               FROM {" . cache_manager::TABLE_USER . "}
              WHERE courseid = :courseid
                AND userid = :userid
                AND aggregation_strategy = :strategy
           GROUP BY quizid",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'strategy' => cache_manager::STRATEGY_LATEST,
            ]
        );
        $materialized = [];
        foreach ($materializedrecords as $record) {
            $max = (float)$record->maxearned;
            $materialized[(int)$record->quizid] = $max > 0.0
                ? skill_aggregator::compute_percent((float)$record->earned, $max)
                : null;
        }

        $detail = [];
        $palette = ['#2563EB', '#059669', '#DC2626', '#D97706', '#7C3AED', '#0891B2'];
        $idx = 0;
        foreach ($rows as $row) {
            $gradeitemid = (int)$row->gradeitemid;
            $quizid = (int)$row->quizid;
            $label = !empty($row->quizname) ? format_string($row->quizname) : 'Quiz #' . $quizid;

            $materializedpct = $materialized[$quizid] ?? null;
            $gradebookpct = $gradebookpercents[$gradeitemid] ?? null;
            if ($materializedpct !== null) {
                $pct = $materializedpct;
                $metric = 'materialized_slots';
            } else {
                $pct = $gradebookpct;
                $metric = 'gradebook';
            }

            $detail[] = [
                'key' => 'quizmod_' . $quizid,
                'label' => $label,
                'color' => $palette[$idx % count($palette)],
                'value' => $pct !== null ? round($pct, 2) : null,
                'items' => 1,
                'empty' => $pct === null,
                'placeholder' => false,
                'source' => 'quiz_module',
                'metric' => $metric,
                'gradeitemid' => $gradeitemid,
                'quizid' => $quizid,
            ];
            $idx++;
        }

        $chart = radar_helper::build_chart_meta($detail, radar_helper::MIN_AXES, '#CBD5E1', true);
        $vals = [];
        foreach ($detail as $r) {
            if (empty($r['placeholder']) && $r['value'] !== null) {
                $vals[] = (float)$r['value'];
            }
        }
        $overall = ['percent' => null, 'letter' => null];
        if ($vals !== []) {
            $overall['percent'] = round(array_sum($vals) / count($vals), 2);
        }

        $cfg = manager::get_course_config($courseid);

        return [
            'skills_detail' => $detail,
            'chart' => $chart,
            'overall' => $overall,
            'primaryColor' => $cfg->primarycolor ?? '#3B82F6',
            'config' => [
                'overallmode' => 'average',
                'minaxes' => count($chart['labels']),
            ],
        ];
    }
}
