<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds UI payloads from materialized tables; local radar may fill gaps from gradebook mappings.
 */
class grade_provider {

    /**
     * Build radar payload from materialized rows; may fill zero-weight skills from gradebook mappings.
     *
     * One row per skill aggregates earned/max across all quizzes (per quiz grademethod in materialized rows).
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $includecourseaverage optional course-average series for the chart
     * @return array
     */
    public static function get_course_skill_radar(int $userid, int $courseid, bool $includecourseaverage = false): array {
        $detail = self::build_skill_rows($courseid, $userid);
        if ($detail === []) {
            return self::empty_tagged_radar_payload($courseid, $includecourseaverage);
        }
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
        return self::payload_from_detail($courseid, $detail, $includecourseaverage, $quizid);
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
     * @param int $quizid 0 = all quizzes (course-level radar), >0 = single quiz filter
     * @return array
     */
    private static function payload_from_detail(int $courseid, array $detail, bool $includecourseaverage, int $quizid = 0): array {
        $detail = self::filter_ghost_tagged_axes($detail);
        if ($detail === []) {
            return self::empty_tagged_radar_payload($courseid, $includecourseaverage);
        }

        $config = manager::get_course_config($courseid);
        // One chart point per skills_detail row (no min-3 padding); avoids an extra axis vs Result breakdown.
        $chart = radar_helper::build_chart_meta($detail, count($detail));

        $payload = [
            'skills' => radar_helper::build_skills_map($detail),
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
            $payload['course_average'] = self::build_course_average($courseid, $detail, $quizid);
        }

        return $payload;
    }

    /**
     * Drop axes for skills that are configured but have no tagged questions in scope (items=0, empty).
     * They skew the second radar, course_average (null holes), and Chart.js paths.
     *
     * @param array $detail
     * @return array
     */
    private static function filter_ghost_tagged_axes(array $detail): array {
        return array_values(array_filter($detail, static function(array $r): bool {
            if (!empty($r['empty']) && (int)($r['items'] ?? 0) < 1) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Radar uses only skills from «Skill Radar» question keys (local_skillradar_def.id), not bank category fallbacks.
     *
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    private static function empty_tagged_radar_payload(int $courseid, bool $includecourseaverage): array {
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
            'empty_tagged_skills' => true,
            'empty_message' => get_string('radar_tagged_skills_empty', 'local_skillradar'),
        ];
        if ($includecourseaverage && !empty($config->courseavg)) {
            $payload['course_average'] = null;
        }
        return $payload;
    }

    /**
     * Top (global) chart: no skill defs and no grade-item mappings — cannot use gradebook radar.
     *
     * @param int $courseid
     * @param bool $includecourseaverage
     * @return array
     */
    public static function get_empty_global_gradebook_radar_payload(int $courseid, bool $includecourseaverage = false): array {
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
            'empty_global_gradebook' => true,
            'empty_message' => get_string('radar_global_gradebook_empty', 'local_skillradar'),
        ];
        if ($includecourseaverage && !empty($config->courseavg)) {
            $payload['course_average'] = null;
        }

        return $payload;
    }

    /**
     * Exposed for hybrid merge: any materialized rows (includes category fallback skills).
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function get_materialized_skill_rows(int $courseid, int $userid): array {
        global $DB;

        $sql = "SELECT skillid
                  FROM {local_skill_user_result}
                 WHERE courseid = :courseid
                   AND userid = :userid
                   AND aggregation_strategy = :strategy";
        return $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);
    }

    /**
     * Course radar: only skills tied to a defined skill key on questions (positive skillid = def.id).
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
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
                   AND skillid > 0
              GROUP BY skillid";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);
        $definitions = self::get_definitions_by_id($courseid);

        $rows = [];
        foreach ($records as $record) {
            $percent = skill_aggregator::compute_percent((float)$record->earned, (float)$record->maxearned);
            $skillid = (int)$record->skillid;
            $def = $definitions[$skillid] ?? null;
            $rows[] = [
                'key' => (string)$skillid,
                'label' => $def ? self::axis_label_from_definition($def, $skillid) : (string)$record->skillname,
                'color' => $def && !empty($def->color) ? $def->color : self::color_for_skill($skillid),
                'value' => $percent,
                'items' => (int)$record->questions_count,
                'empty' => false,
                'placeholder' => false,
                'synthetic_empty' => false,
                'earned' => round((float)$record->earned, 5),
                'maxearned' => round((float)$record->maxearned, 5),
            ];
        }

        $rows = self::merge_missing_tagged_skills_used_in_quizzes($courseid, $rows);

        $rows = self::finalize_tagged_skill_rows($courseid, $rows);
        $rows = self::apply_gradebook_fallback_for_zero_weight_skills($courseid, $userid, $rows);

        return $rows;
    }

    /**
     * If question analytics carried no weight (maxearned≈0) but «Grade item mapping» links this definition to a grade item,
     * use the same journal % as the global radar so local axes align with gradebook when slot data is missing.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $rows
     * @return array
     */
    private static function apply_gradebook_fallback_for_zero_weight_skills(int $courseid, int $userid, array $rows): array {
        $mappings = manager::get_mappings($courseid);
        if (!$mappings) {
            return $rows;
        }

        $gradeitembykey = [];
        foreach ($mappings as $m) {
            $k = trim((string)$m->skill_key);
            if ($k !== '' && !isset($gradeitembykey[$k])) {
                $gradeitembykey[$k] = (int)$m->gradeitemid;
            }
        }
        if ($gradeitembykey === []) {
            return $rows;
        }

        $defbyid = self::get_definitions_by_id($courseid);
        $neededgradeitemids = [];
        foreach ($rows as $row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            if (!empty($row['synthetic_empty'])) {
                continue;
            }
            $sid = (int)$row['key'];
            if ($sid <= 0 || (float)($row['maxearned'] ?? 0) > 1e-6) {
                continue;
            }
            $def = $defbyid[$sid] ?? null;
            if (!$def) {
                continue;
            }
            $sk = trim((string)$def->skill_key);
            $gradeitemid = (int)($gradeitembykey[$sk] ?? 0);
            if ($gradeitemid > 0) {
                $neededgradeitemids[$gradeitemid] = $gradeitemid;
            }
        }
        $bulkpercents = $neededgradeitemids === [] ? [] :
            calculator::percent_for_grade_items($courseid, array_values($neededgradeitemids), $userid);

        foreach ($rows as $i => $row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            if (!empty($row['synthetic_empty'])) {
                continue;
            }
            $sid = (int)$row['key'];
            if ($sid <= 0) {
                continue;
            }
            $max = (float)($row['maxearned'] ?? 0);
            if ($max > 1e-6) {
                continue;
            }
            $def = $defbyid[$sid] ?? null;
            if (!$def) {
                continue;
            }
            $sk = trim((string)$def->skill_key);
            if ($sk === '' || empty($gradeitembykey[$sk])) {
                continue;
            }
            $pct = $bulkpercents[(int)$gradeitembykey[$sk]] ?? null;
            if ($pct === null) {
                continue;
            }
            $rows[$i]['value'] = round((float)$pct, 2);
            $rows[$i]['earned'] = round((float)$pct, 5);
            $rows[$i]['maxearned'] = 100.0;
            $rows[$i]['empty'] = false;
            if ((int)($rows[$i]['items'] ?? 0) < 1) {
                $rows[$i]['items'] = 1;
            }
        }

        return $rows;
    }

    /**
     * Ensure the local/question-based radar exposes all configured skill definitions.
     *
     * If a definition is already present from materialized analytics we keep that row.
     * Otherwise we append an empty axis immediately, even before any question is tagged or attempted.
     *
     * @param int $courseid
     * @param array $rows
     * @return array
     */
    private static function merge_missing_tagged_skills_used_in_quizzes(int $courseid, array $rows): array {
        $definitions = self::get_definitions_by_id($courseid);
        if ($definitions === []) {
            return $rows;
        }

        $usage = [];
        try {
            $usage = manager::get_tagged_skill_question_counts_in_course($courseid);
        } catch (\Throwable $e) {
            debugging('local_skillradar merge_missing_tagged_skills: ' . $e->getMessage(), DEBUG_NORMAL);
            foreach (array_keys($definitions) as $skillid) {
                $usage[(int)$skillid] = 0;
            }
        }

        $have = [];
        foreach ($rows as $row) {
            $have[(int)$row['key']] = true;
        }

        foreach ($definitions as $skillid => $definition) {
            if (isset($have[$skillid])) {
                continue;
            }
            if ((int)($usage[$skillid] ?? 0) < 1) {
                continue;
            }
            $rows[] = [
                'key' => (string)$skillid,
                'label' => self::axis_label_from_definition($definition, $skillid),
                'color' => !empty($definition->color) ? $definition->color : self::color_for_skill($skillid),
                'value' => null,
                'items' => (int)($usage[$skillid] ?? 0),
                'empty' => true,
                'placeholder' => false,
                'synthetic_empty' => true,
                'earned' => 0.0,
                'maxearned' => 0.0,
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
                   AND skillid > 0
              ORDER BY skillname ASC, skillid ASC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'quizid' => $quizid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ]);
        $definitions = self::get_definitions_by_id($courseid);

        $rows = [];
        foreach ($records as $record) {
            $percent = skill_aggregator::compute_percent((float)$record->earned, (float)$record->maxearned);
            $skillid = (int)$record->skillid;
            $def = $definitions[$skillid] ?? null;
            $rows[] = [
                'key' => (string)$skillid,
                'label' => $def ? self::axis_label_from_definition($def, $skillid) : (string)$record->skillname,
                'color' => $def && !empty($def->color) ? $def->color : self::color_for_skill($skillid),
                'value' => $percent,
                'items' => (int)$record->questions_count,
                'empty' => false,
                'placeholder' => false,
                'synthetic_empty' => false,
                'earned' => round((float)$record->earned, 5),
                'maxearned' => round((float)$record->maxearned, 5),
            ];
        }

        $rows = self::merge_missing_tagged_skills_for_quiz($courseid, $quizid, $rows);
        $rows = self::finalize_tagged_skill_rows($courseid, $rows);
        $rows = self::apply_gradebook_fallback_for_zero_weight_skills($courseid, $userid, $rows);

        return $rows;
    }

    /**
     * Append empty axes for skills tagged on this quiz but not yet present in materialized results.
     *
     * Mirrors course-level {@see merge_missing_tagged_skills_used_in_quizzes} but scoped to one quiz.
     *
     * @param int $courseid
     * @param int $quizid
     * @param array $rows
     * @return array
     */
    private static function merge_missing_tagged_skills_for_quiz(int $courseid, int $quizid, array $rows): array {
        $usage = [];
        try {
            $usage = manager::get_tagged_skill_question_counts_for_quiz($courseid, $quizid);
        } catch (\Throwable $e) {
            debugging('local_skillradar merge_missing_tagged_skills_for_quiz: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $definitions = self::get_definitions_by_id($courseid);
        if ($definitions === []) {
            return $rows;
        }

        $have = [];
        foreach ($rows as $row) {
            $have[(int)$row['key']] = true;
        }

        foreach ($definitions as $skillid => $definition) {
            if (isset($have[$skillid])) {
                continue;
            }
            if ((int)($usage[$skillid] ?? 0) < 1) {
                continue;
            }
            $rows[] = [
                'key' => (string)$skillid,
                'label' => self::axis_label_from_definition($definition, $skillid),
                'color' => !empty($definition->color) ? $definition->color : self::color_for_skill($skillid),
                'value' => null,
                'items' => (int)($usage[$skillid] ?? 0),
                'empty' => true,
                'placeholder' => false,
                'synthetic_empty' => true,
                'earned' => 0.0,
                'maxearned' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Use course skill display names (not keys) and definition sort order for radar axes.
     *
     * @param int $courseid
     * @param array $rows
     * @return array
     */
    private static function finalize_tagged_skill_rows(int $courseid, array $rows): array {
        if ($rows === []) {
            return [];
        }

        $defbyid = self::get_definitions_by_id($courseid);

        foreach ($rows as &$row) {
            $sid = (int)$row['key'];
            if ($sid > 0 && isset($defbyid[$sid])) {
                $row['label'] = self::axis_label_from_definition($defbyid[$sid], $sid);
                if (!empty($defbyid[$sid]->color)) {
                    $row['color'] = $defbyid[$sid]->color;
                }
            }
        }
        unset($row);

        usort($rows, static function(array $a, array $b) use ($defbyid): int {
            $ida = (int)$a['key'];
            $idb = (int)$b['key'];
            $oa = ($ida > 0 && isset($defbyid[$ida])) ? (int)$defbyid[$ida]->sortorder : 999999;
            $ob = ($idb > 0 && isset($defbyid[$idb])) ? (int)$defbyid[$idb]->sortorder : 999999;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcmp((string)$a['label'], (string)$b['label']);
        });

        return radar_helper::dedupe_duplicate_axis_labels($rows);
    }

    /**
     * @param \stdClass $config
     * @param array $detail
     * @return array
     */
    private static function compute_overall(\stdClass $config, array $detail): array {
        $overallmode = $config->overallmode ?? 'average';
        $available = array_values(array_filter($detail, static function(array $row): bool {
            if ($row['value'] === null) {
                return false;
            }
            if (!empty($row['empty'])) {
                return false;
            }
            return true;
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

        return ['percent' => $percent, 'letter' => radar_helper::percent_to_letter($percent)];
    }

    /**
     * Course-average series aligned to quiz materialized detail rows (same order as $detail).
     *
     * @param int $courseid
     * @param array $detail
     * @param int $quizid 0 = all quizzes, >0 = single quiz filter
     * @return array|null
     */
    public static function get_materialized_course_average(int $courseid, array $detail, int $quizid = 0): ?array {
        return self::build_course_average($courseid, $detail, $quizid);
    }

    /**
     * @param int $courseid
     * @param array $detail
     * @param int $quizid 0 = all quizzes, >0 = single quiz filter
     * @return array|null
     */
    private static function build_course_average(int $courseid, array $detail, int $quizid = 0): ?array {
        global $DB;

        if (!$detail) {
            return null;
        }

        $params = [
            'courseid' => $courseid,
            'strategy' => cache_manager::STRATEGY_LATEST,
        ];
        $quizfilter = '';
        if ($quizid > 0) {
            $quizfilter = ' AND quizid = :quizid';
            $params['quizid'] = $quizid;
        }

        $sql = "SELECT skillid,
                       SUM(earned) AS earned,
                       SUM(maxearned) AS maxearned
                  FROM {local_skill_user_result}
                 WHERE courseid = :courseid
                   AND aggregation_strategy = :strategy
                   AND skillid > 0
                   {$quizfilter}
              GROUP BY skillid";
        $byvalues = [];
        $recordset = $DB->get_records_sql($sql, $params);
        foreach ($recordset as $record) {
            $sid = (int)$record->skillid;
            $maxsum = (float)$record->maxearned;
            // Cohort has no question-level weight → do not treat as 0%; fill from gradebook below.
            if ($maxsum <= 1e-6) {
                $byvalues[$sid] = null;
            } else {
                $byvalues[$sid] = skill_aggregator::compute_percent((float)$record->earned, $maxsum);
            }
        }

        $courseavg = [
            'label' => get_string('courseaveragelegend', 'local_skillradar'),
            'values' => array_map(static function(array $row) use ($byvalues) {
                $skillid = (int)$row['key'];
                return array_key_exists($skillid, $byvalues) ? $byvalues[$skillid] : null;
            }, $detail),
        ];

        return self::apply_gradebook_course_average_fallback($courseid, $detail, $courseavg);
    }

    /**
     * Mean journal % for a grade item (users with a grade row), for cohort series when materialized sums lack weight.
     *
     * @param int $courseid
     * @param int $gradeitemid
     * @return float|null
     */
    private static function average_percent_for_grade_item(int $courseid, int $gradeitemid): ?float {
        global $DB;

        return calculator::average_percent_for_grade_item_bulk($courseid, $gradeitemid);
    }

    /**
     * When cohort materialized data has no earned/max weight for a skill, course average cannot be derived from
     * local_skill_user_result; use mean % on the mapped grade item (aligned with the user journal fallback).
     *
     * @param int $courseid
     * @param array $detail
     * @param array $courseavg
     * @return array
     */
    private static function apply_gradebook_course_average_fallback(int $courseid, array $detail, array $courseavg): array {
        $mappings = manager::get_mappings($courseid);
        if (!$mappings) {
            return $courseavg;
        }

        $gradeitembykey = [];
        foreach ($mappings as $m) {
            $k = trim((string)$m->skill_key);
            if ($k !== '' && !isset($gradeitembykey[$k])) {
                $gradeitembykey[$k] = (int)$m->gradeitemid;
            }
        }
        if ($gradeitembykey === []) {
            return $courseavg;
        }

        $defbyid = self::get_definitions_by_id($courseid);

        $values = $courseavg['values'];
        foreach ($detail as $i => $row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            if (!empty($row['synthetic_empty'])) {
                continue;
            }
            if (($values[$i] ?? null) !== null) {
                continue;
            }
            $sid = (int)$row['key'];
            if ($sid <= 0) {
                continue;
            }
            $def = $defbyid[$sid] ?? null;
            if (!$def) {
                continue;
            }
            $sk = trim((string)$def->skill_key);
            if ($sk === '' || empty($gradeitembykey[$sk])) {
                continue;
            }
            $avg = self::average_percent_for_grade_item($courseid, $gradeitembykey[$sk]);
            if ($avg !== null) {
                $values[$i] = $avg;
            }
        }
        $courseavg['values'] = $values;

        return $courseavg;
    }

    /**
     * @param int $skillid
     * @return string
     */
    /**
     * Axis label from a course skill definition: never show a bare numeric display name that equals the row id.
     *
     * @param \stdClass $def local_skillradar_def row
     * @param int $skillid Definition id (positive)
     * @return string
     */
    private static function axis_label_from_definition(\stdClass $def, int $skillid): string {
        $name = trim((string)($def->displayname ?? ''));
        $key = trim((string)($def->skill_key ?? ''));
        // Purely numeric display name that does not match this definition id — prefer skill_key (e.g. displayname "5", id 8).
        if ($name !== '' && $key !== '' && preg_match('/^\d+$/', $name) === 1 && (int)$name !== $skillid) {
            return format_string($def->skill_key);
        }
        if ($name !== '' && self::displayname_is_ambiguous_numeric_id($name, $skillid)) {
            if ($key !== '') {
                return format_string($def->skill_key);
            }
            return get_string('skill_label_fallback', 'local_skillradar', ['id' => $skillid]);
        }
        if ($name !== '') {
            return format_string($def->displayname);
        }
        if ($key !== '') {
            return format_string($def->skill_key);
        }
        return get_string('skill_label_fallback', 'local_skillradar', ['id' => $skillid]);
    }

    /**
     * True when the admin entered only digits as the display name and they match the definition primary key.
     *
     * @param string $name
     * @param int $skillid
     * @return bool
     */
    private static function displayname_is_ambiguous_numeric_id(string $name, int $skillid): bool {
        if ($skillid < 1) {
            return false;
        }
        if (preg_match('/^\d+$/', $name) !== 1) {
            return false;
        }
        return (int)$name === $skillid;
    }

    private static function color_for_skill(int $skillid): string {
        $palette = ['#2563EB', '#059669', '#DC2626', '#D97706', '#7C3AED', '#0891B2'];
        $idx = abs($skillid) % count($palette);

        return $palette[$idx];
    }

    /**
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    private static function get_definitions_by_id(int $courseid): array {
        $out = [];
        foreach (manager::get_definitions($courseid) as $def) {
            $out[(int)$def->id] = $def;
        }
        return $out;
    }
}
