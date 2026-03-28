<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/grade/grade_grade.php');

use grade_grade;
use grade_item;

/**
 * Grade-item weighted skill axes (legacy mapping UI).
 */
class calculator {
    /** @var array<int, \stdClass> */
    private static $gradeitemcache = [];
    /** @var array<string, \stdClass> */
    private static $gradegradecache = [];
    /** @var array<string, array<int, float|null>> */
    private static $bulkpercentcache = [];
    /** @var array<string, array<int, array{0: float, 1: float}|null>> */
    private static $rangebatchcache = [];

    /**
     * Max points from activity settings (e.g. quiz grade), not only grade_item row.
     *
     * @return float[]|null [grademin, grademax] or null to use generic logic
     */
    protected static function get_activity_grade_range($gi): ?array {
        global $DB;
        if ($gi->itemtype !== 'mod' || empty($gi->itemmodule) || empty($gi->iteminstance)) {
            return null;
        }
        $id = (int) $gi->iteminstance;
        if ($gi->itemmodule === 'quiz') {
            $rec = $DB->get_record('quiz', ['id' => $id], 'grade');
            if ($rec && (float) $rec->grade > 0) {
                return [0.0, (float) $rec->grade];
            }
        } else if ($gi->itemmodule === 'assign') {
            $rec = $DB->get_record('assign', ['id' => $id], 'grade');
            if ($rec && (float) $rec->grade > 0) {
                return [0.0, (float) $rec->grade];
            }
        }
        return null;
    }

    /**
     * Percent 0–100 from grade_grade + grade_item.
     */
    protected static function grade_percent_from_grade($grade, $gradeitem): ?float {
        $activityrange = self::get_activity_grade_range($gradeitem);
        if ($activityrange !== null) {
            $gmin = $activityrange[0];
            $gmax = $activityrange[1];
            $grange = $gmax - $gmin;
            if ($grange <= 0.0) {
                return null;
            }
            if ($grade->rawgrade !== null) {
                return (((float) $grade->rawgrade - $gmin) / $grange) * 100.0;
            }
            if ($grade->finalgrade !== null) {
                return (((float) $grade->finalgrade - $gmin) / $grange) * 100.0;
            }
            return null;
        }

        $gmin = (float) $gradeitem->grademin;
        $gmax = (float) $gradeitem->grademax;
        $grange = $gmax - $gmin;

        if ($grade->rawgrade !== null) {
            $rmin = (float) $grade->rawgrademin;
            $rmax = (float) $grade->rawgrademax;
            $imax = (float) $gradeitem->grademax;
            $rawmaxlooksdefault = abs($rmax - 100.0) < 0.01 && abs($imax - 100.0) > 0.5;
            if ($rmax > $rmin && !$rawmaxlooksdefault) {
                return (((float) $grade->rawgrade - $rmin) / ($rmax - $rmin)) * 100.0;
            }
            if ($rawmaxlooksdefault && $grange > 0.0) {
                return (((float) $grade->rawgrade - $gmin) / $grange) * 100.0;
            }
        }
        if ($grade->finalgrade === null) {
            return null;
        }
        if ($grange <= 0.0) {
            return null;
        }
        return (((float) $grade->finalgrade - $gmin) / $grange) * 100.0;
    }

    /**
     * Percent 0–100 for a course grade item (e.g. quiz module total).
     *
     * @param int $courseid
     * @param int $gradeitemid
     * @param int $userid
     * @return float|null
     */
    public static function percent_for_grade_item(int $courseid, int $gradeitemid, int $userid): ?float {
        $items = self::get_grade_items_by_ids($courseid, [$gradeitemid]);
        $gi = $items[$gradeitemid] ?? null;
        if (!$gi) {
            return null;
        }
        $grade = self::get_grade_for_user_item($userid, $gi->id);
        if (!$grade) {
            return null;
        }
        return self::grade_percent_from_grade($grade, $gi);
    }

    public static function build_payload(int $courseid, int $userid, bool $withcourseavg = false): array {
        $config = manager::get_course_config($courseid);
        $definitions = manager::get_definitions($courseid);
        $mappings = manager::get_mappings($courseid);

        $detail = self::build_skill_rows($courseid, $userid, $definitions, $mappings);
        $detail = radar_helper::dedupe_duplicate_axis_labels($detail);
        $chart = radar_helper::build_chart_meta($detail, radar_helper::MIN_AXES, '#CBD5E1', true);
        $overall = self::compute_overall($courseid, $userid, $config, $detail);

        $primary = $config->primarycolor ?? '#3B82F6';
        $payload = [
            'skills' => radar_helper::build_skills_map($detail),
            'skills_detail' => $detail,
            'mapping_meta' => self::build_mapping_meta($courseid, $definitions, $mappings),
            'chart' => $chart,
            'overall' => $overall,
            'primaryColor' => $primary,
            'config' => [
                'overallmode' => $config->overallmode ?? 'average',
                'minaxes' => count($chart['labels']),
                'primaryColor' => $primary,
            ],
        ];

        if ($withcourseavg && !empty($config->courseavg)) {
            $payload['course_average'] = self::build_course_average($courseid, $detail);
        }

        return $payload;
    }

    public static function build_skill_rows(int $courseid, int $userid, array $definitions, array $mappings): array {
        $defbykey = [];
        foreach ($definitions as $definition) {
            $defbykey[$definition->skill_key] = $definition;
        }

        // First chart: only axes for skills that are actually mapped to a grade item (manage UI), not every course definition.
        $orderedkeys = [];
        $seen = [];
        foreach ($mappings as $mapping) {
            $sk = trim((string) $mapping->skill_key);
            if ($sk === '' || $sk === '_none') {
                continue;
            }
            if (isset($seen[$sk])) {
                continue;
            }
            $seen[$sk] = true;
            $orderedkeys[] = $sk;
        }

        $gradeitemids = [];
        foreach ($mappings as $mapping) {
            $gradeitemid = (int)$mapping->gradeitemid;
            if ($gradeitemid > 0) {
                $gradeitemids[$gradeitemid] = $gradeitemid;
            }
        }
        $gradeitems = self::get_grade_items_by_ids($courseid, array_values($gradeitemids));
        $gradegrades = self::get_grade_grades_for_user($userid, array_values($gradeitemids));

        $rows = [];
        foreach ($orderedkeys as $skillkey) {
            if (isset($defbykey[$skillkey])) {
                $d = $defbykey[$skillkey];
                $rows[$skillkey] = [
                    'key' => $skillkey,
                    'label' => $d->displayname,
                    'color' => $d->color,
                    'value' => null,
                    'items' => 0,
                    'empty' => true,
                    'placeholder' => false,
                    'weighted' => 0.0,
                    'weightsum' => 0.0,
                ];
            } else {
                $rows[$skillkey] = [
                    'key' => $skillkey,
                    'label' => get_string('notconfigured', 'local_skillradar') . ' (' . format_string($skillkey) . ')',
                    'color' => '#64748B',
                    'value' => null,
                    'items' => 0,
                    'empty' => true,
                    'placeholder' => false,
                    'weighted' => 0.0,
                    'weightsum' => 0.0,
                ];
            }
        }

        foreach ($mappings as $mapping) {
            $sk = trim((string) $mapping->skill_key);
            if ($sk === '' || $sk === '_none' || !isset($rows[$sk])) {
                continue;
            }

            $rows[$sk]['items']++;
            $gradeitem = $gradeitems[(int)$mapping->gradeitemid] ?? null;
            if (!$gradeitem) {
                continue;
            }

            $grade = $gradegrades[(int)$gradeitem->id] ?? null;
            if (!$grade) {
                continue;
            }

            $weight = (float)$mapping->weight;
            if ($weight <= 0) {
                continue;
            }

            $normalized = self::grade_percent_from_grade($grade, $gradeitem);
            if ($normalized === null) {
                continue;
            }
            $rows[$sk]['weighted'] += $normalized * $weight;
            $rows[$sk]['weightsum'] += $weight;
        }

        foreach ($rows as &$row) {
            if ($row['weightsum'] > 0) {
                $row['value'] = round($row['weighted'] / $row['weightsum'], 2);
                $row['empty'] = false;
            }
            unset($row['weighted'], $row['weightsum']);
        }
        unset($row);

        return array_values($rows);
    }

    public static function build_mapping_meta(int $courseid, array $definitions, array $mappings): array {
        global $DB;
        $defbykey = [];
        foreach ($definitions as $definition) {
            $defbykey[$definition->skill_key] = $definition;
        }

        $meta = [];
        $gradeitemids = [];
        foreach ($mappings as $mapping) {
            $gradeitemid = (int)$mapping->gradeitemid;
            if ($gradeitemid > 0) {
                $gradeitemids[$gradeitemid] = $gradeitemid;
            }
        }
        $gradeitems = self::get_grade_items_by_ids($courseid, array_values($gradeitemids));
        foreach ($mappings as $mapping) {
            $sk = trim((string) $mapping->skill_key);
            if ($sk === '' || $sk === '_none') {
                continue;
            }
            if (!isset($meta[$sk])) {
                if (isset($defbykey[$sk])) {
                    $d = $defbykey[$sk];
                    $meta[$sk] = [
                        'key' => $sk,
                        'label' => $d->displayname,
                        'color' => $d->color,
                        'items' => [],
                    ];
                } else {
                    $meta[$sk] = [
                        'key' => $sk,
                        'label' => $sk,
                        'color' => '#64748B',
                        'items' => [],
                    ];
                }
            }
            $gi = $gradeitems[(int)$mapping->gradeitemid] ?? null;
            if (!$gi) {
                continue;
            }
            $activityrange = self::get_activity_grade_range($gi);
            if ($activityrange !== null) {
                $gmin = $activityrange[0];
                $gmax = $activityrange[1];
            } else {
                $sample = $DB->get_record_sql(
                    "SELECT rawgrademin, rawgrademax
                       FROM {grade_grades}
                      WHERE itemid = ?
                        AND rawgrademax > rawgrademin
                   ORDER BY timemodified DESC
                      LIMIT 1",
                    [$mapping->gradeitemid]
                );
                $gmin = (float) $gi->grademin;
                $gmax = (float) $gi->grademax;
                if ($sample) {
                    $rmin = (float) $sample->rawgrademin;
                    $rmax = (float) $sample->rawgrademax;
                    $imax = (float) $gi->grademax;
                    $rawmaxlooksdefault = abs($rmax - 100.0) < 0.01 && abs($imax - 100.0) > 0.5;
                    if ($rmax > $rmin && !$rawmaxlooksdefault) {
                        $gmin = $rmin;
                        $gmax = $rmax;
                    }
                }
            }
            $grange = $gmax - $gmin;
            if ($grange <= 0.0) {
                $gmax = $gmin + 1.0;
            }
            $meta[$sk]['items'][] = [
                'gradeitemid' => (int)$mapping->gradeitemid,
                'weight' => (float)$mapping->weight,
                'grademin' => $gmin,
                'grademax' => $gmax,
            ];
        }

        return array_values($meta);
    }

    public static function compute_overall(int $courseid, int $userid, \stdClass $config, array $detail): array {
        $percent = null;
        $letter = '';

        if (($config->overallmode ?? 'average') === 'final') {
            $courseitem = grade_item::fetch_course_item($courseid);
            if ($courseitem) {
                $grade = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
                if ($grade && ($grade->finalgrade !== null || $grade->rawgrade !== null)) {
                    $pct = self::grade_percent_from_grade($grade, $courseitem);
                    if ($pct !== null) {
                        $percent = round($pct, 2);
                    }
                    $rawval = $grade->finalgrade;
                    if ($rawval === null) {
                        $rawval = $grade->rawgrade;
                    }
                    if ($rawval !== null) {
                        $moodleletter = \grade_format_gradevalue_letter((float) $rawval, $courseitem);
                        $moodleletter = trim((string) $moodleletter);
                        if ($moodleletter !== '' && $moodleletter !== '-') {
                            $letter = $moodleletter;
                        }
                    }
                }
            }
            if ($letter === '' && $percent !== null) {
                $letter = (string)(radar_helper::percent_to_letter($percent) ?? '');
            }
        } else {
            $nonempty = [];
            foreach ($detail as $row) {
                if (!empty($row['placeholder'])) {
                    continue;
                }
                if ($row['value'] === null) {
                    continue;
                }
                $nonempty[] = (float) $row['value'];
            }
            if ($nonempty !== []) {
                $percent = round(array_sum($nonempty) / count($nonempty), 2);
            }
            $letter = (string)(radar_helper::percent_to_letter($percent) ?? '');
        }

        return [
            'percent' => $percent,
            'letter' => $letter,
        ];
    }

    public static function build_course_average(int $courseid, array $detail): array {
        global $DB;
        $mappings = manager::get_mappings($courseid);
        if ($mappings === []) {
            return [
                'label' => get_string('courseaveragelegend', 'local_skillradar'),
                'values' => array_fill(0, max(count($detail), radar_helper::MIN_AXES), null),
            ];
        }

        $weightsbyskill = [];
        $gradeitemids = [];
        foreach ($mappings as $mapping) {
            $skillkey = trim((string)$mapping->skill_key);
            $weight = (float)$mapping->weight;
            $gradeitemid = (int)$mapping->gradeitemid;
            if ($skillkey === '' || $skillkey === '_none' || $weight <= 0 || $gradeitemid < 1) {
                continue;
            }
            $weightsbyskill[$skillkey][$gradeitemid] = $weight;
            $gradeitemids[$gradeitemid] = true;
        }
        if ($weightsbyskill === [] || $gradeitemids === []) {
            return [
                'label' => get_string('courseaveragelegend', 'local_skillradar'),
                'values' => array_fill(0, max(count($detail), radar_helper::MIN_AXES), null),
            ];
        }

        [$itemsql, $itemparams] = $DB->get_in_or_equal(array_keys($gradeitemids), SQL_PARAMS_NAMED, 'gi');
        $gradeitems = $DB->get_records_select('grade_items', "id {$itemsql}", $itemparams, '', '*');
        if ($gradeitems === []) {
            return [
                'label' => get_string('courseaveragelegend', 'local_skillradar'),
                'values' => array_fill(0, max(count($detail), radar_helper::MIN_AXES), null),
            ];
        }

        $itemranges = self::resolve_grade_item_ranges($courseid, $gradeitems);
        $recordset = $DB->get_recordset_sql(
            "SELECT gg.userid,
                    gg.itemid,
                    gg.rawgrade,
                    gg.rawgrademin,
                    gg.rawgrademax,
                    gg.finalgrade
               FROM {grade_grades} gg
              WHERE gg.itemid {$itemsql}
                AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL)
           ORDER BY gg.userid ASC, gg.itemid ASC",
            $itemparams
        );

        $totals = [];
        foreach ($recordset as $record) {
            $itemid = (int)$record->itemid;
            $gradeitem = $gradeitems[$itemid] ?? null;
            if (!$gradeitem) {
                continue;
            }
            $userid = (int)$record->userid;
            $percent = self::grade_percent_from_grade_record($record, $gradeitem, $itemranges[$itemid] ?? null);
            if ($percent === null) {
                continue;
            }
            foreach ($weightsbyskill as $skillkey => $itemweights) {
                if (!isset($itemweights[$itemid])) {
                    continue;
                }
                $weight = (float)$itemweights[$itemid];
                if (!isset($totals[$skillkey][$userid])) {
                    $totals[$skillkey][$userid] = ['weighted' => 0.0, 'weightsum' => 0.0];
                }
                $totals[$skillkey][$userid]['weighted'] += $percent * $weight;
                $totals[$skillkey][$userid]['weightsum'] += $weight;
            }
        }

        $bykey = [];
        foreach ($detail as $row) {
            $skillkey = (string)$row['key'];
            $bykey[$skillkey] = [];
            foreach ($totals[$skillkey] ?? [] as $total) {
                if (($total['weightsum'] ?? 0.0) > 0.0) {
                    $bykey[$skillkey][] = round($total['weighted'] / $total['weightsum'], 2);
                }
            }
        }
        $recordset->close();

        $values = [];
        foreach ($detail as $row) {
            if ($row['placeholder']) {
                $values[] = null;
                continue;
            }
            $samples = $bykey[$row['key']] ?? [];
            $values[] = $samples ? round(array_sum($samples) / count($samples), 2) : null;
        }

        while (count($values) < radar_helper::MIN_AXES) {
            $values[] = null;
        }

        return [
            'label' => get_string('courseaveragelegend', 'local_skillradar'),
            'values' => $values,
        ];
    }

    /**
     * @param array<int, \stdClass> $gradeitems
     * @return array<int, array{0: float, 1: float}|null>
     */
    private static function resolve_grade_item_ranges(int $courseid, array $gradeitems): array {
        $cachekey = $courseid . ':' . implode(',', array_keys($gradeitems));
        if (isset(self::$rangebatchcache[$cachekey])) {
            return self::$rangebatchcache[$cachekey];
        }
        global $DB;

        $ranges = [];
        $quizids = [];
        $assignids = [];
        foreach ($gradeitems as $itemid => $gradeitem) {
            $ranges[(int)$itemid] = null;
            if (($gradeitem->itemtype ?? '') !== 'mod' || empty($gradeitem->itemmodule) || empty($gradeitem->iteminstance)) {
                continue;
            }
            if ($gradeitem->itemmodule === 'quiz') {
                $quizids[(int)$gradeitem->iteminstance] = true;
            } else if ($gradeitem->itemmodule === 'assign') {
                $assignids[(int)$gradeitem->iteminstance] = true;
            }
        }

        $quizgrades = [];
        if ($quizids !== []) {
            [$sql, $params] = $DB->get_in_or_equal(array_keys($quizids), SQL_PARAMS_NAMED, 'qz');
            $quizgrades = $DB->get_records_select_menu('quiz', "id {$sql}", $params, '', 'id, grade');
        }
        $assigngrades = [];
        if ($assignids !== []) {
            [$sql, $params] = $DB->get_in_or_equal(array_keys($assignids), SQL_PARAMS_NAMED, 'as');
            $assigngrades = $DB->get_records_select_menu('assign', "id {$sql}", $params, '', 'id, grade');
        }

        foreach ($gradeitems as $itemid => $gradeitem) {
            if (($gradeitem->itemmodule ?? '') === 'quiz') {
                $grade = (float)($quizgrades[(int)$gradeitem->iteminstance] ?? 0);
                if ($grade > 0) {
                    $ranges[(int)$itemid] = [0.0, $grade];
                }
            } else if (($gradeitem->itemmodule ?? '') === 'assign') {
                $grade = (float)($assigngrades[(int)$gradeitem->iteminstance] ?? 0);
                if ($grade > 0) {
                    $ranges[(int)$itemid] = [0.0, $grade];
                }
            }
        }

        self::$rangebatchcache[$cachekey] = $ranges;
        return $ranges;
    }

    /**
     * Lightweight variant for bulk course-average aggregation.
     *
     * @param \stdClass $grade
     * @param \stdClass $gradeitem
     * @param array{0: float, 1: float}|null $activityrange
     * @return float|null
     */
    private static function grade_percent_from_grade_record(\stdClass $grade, \stdClass $gradeitem, ?array $activityrange): ?float {
        if ($activityrange !== null) {
            $gmin = $activityrange[0];
            $gmax = $activityrange[1];
            $grange = $gmax - $gmin;
            if ($grange <= 0.0) {
                return null;
            }
            if ($grade->rawgrade !== null) {
                return (((float)$grade->rawgrade - $gmin) / $grange) * 100.0;
            }
            if ($grade->finalgrade !== null) {
                return (((float)$grade->finalgrade - $gmin) / $grange) * 100.0;
            }
            return null;
        }

        $gmin = (float)$gradeitem->grademin;
        $gmax = (float)$gradeitem->grademax;
        $grange = $gmax - $gmin;

        if ($grade->rawgrade !== null) {
            $rmin = (float)$grade->rawgrademin;
            $rmax = (float)$grade->rawgrademax;
            $imax = (float)$gradeitem->grademax;
            $rawmaxlooksdefault = abs($rmax - 100.0) < 0.01 && abs($imax - 100.0) > 0.5;
            if ($rmax > $rmin && !$rawmaxlooksdefault) {
                return (((float)$grade->rawgrade - $rmin) / ($rmax - $rmin)) * 100.0;
            }
            if ($rawmaxlooksdefault && $grange > 0.0) {
                return (((float)$grade->rawgrade - $gmin) / $grange) * 100.0;
            }
        }
        if ($grade->finalgrade === null || $grange <= 0.0) {
            return null;
        }

        return (((float)$grade->finalgrade - $gmin) / $grange) * 100.0;
    }

    /**
     * @param int $courseid
     * @param int[] $gradeitemids
     * @return array<int, \stdClass>
     */
    private static function get_grade_items_by_ids(int $courseid, array $gradeitemids): array {
        global $DB;

        $gradeitemids = array_values(array_unique(array_filter(array_map('intval', $gradeitemids))));
        $missing = [];
        foreach ($gradeitemids as $gradeitemid) {
            if (!isset(self::$gradeitemcache[$gradeitemid])) {
                $missing[] = $gradeitemid;
            }
        }
        if ($missing !== []) {
            [$insql, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'gi');
            $params['courseid'] = $courseid;
            $records = $DB->get_records_sql(
                "SELECT *
                   FROM {grade_items}
                  WHERE courseid = :courseid
                    AND id {$insql}",
                $params
            );
            foreach ($missing as $gradeitemid) {
                if (isset($records[$gradeitemid])) {
                    self::$gradeitemcache[$gradeitemid] = $records[$gradeitemid];
                }
            }
        }
        $out = [];
        foreach ($gradeitemids as $gradeitemid) {
            if (isset(self::$gradeitemcache[$gradeitemid])) {
                $out[$gradeitemid] = self::$gradeitemcache[$gradeitemid];
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param int[] $gradeitemids
     * @return array<int, \stdClass>
     */
    private static function get_grade_grades_for_user(int $userid, array $gradeitemids): array {
        global $DB;

        $gradeitemids = array_values(array_unique(array_filter(array_map('intval', $gradeitemids))));
        $out = [];
        $missing = [];
        foreach ($gradeitemids as $gradeitemid) {
            $key = $userid . ':' . $gradeitemid;
            if (isset(self::$gradegradecache[$key])) {
                $out[$gradeitemid] = self::$gradegradecache[$key];
            } else {
                $missing[] = $gradeitemid;
            }
        }
        if ($missing !== []) {
            [$insql, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'gg');
            $params['userid'] = $userid;
            $records = $DB->get_records_sql(
                "SELECT *
                   FROM {grade_grades}
                  WHERE userid = :userid
                    AND itemid {$insql}",
                $params
            );
            foreach ($records as $record) {
                self::$gradegradecache[$userid . ':' . (int)$record->itemid] = $record;
                $out[(int)$record->itemid] = $record;
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param int $gradeitemid
     * @return \stdClass|null
     */
    private static function get_grade_for_user_item(int $userid, int $gradeitemid): ?\stdClass {
        $grades = self::get_grade_grades_for_user($userid, [$gradeitemid]);
        return $grades[$gradeitemid] ?? null;
    }

    /**
     * @param int $courseid
     * @param int[] $gradeitemids
     * @param int $userid
     * @return array<int, float|null>
     */
    public static function percent_for_grade_items(int $courseid, array $gradeitemids, int $userid): array {
        $gradeitemids = array_values(array_unique(array_filter(array_map('intval', $gradeitemids))));
        $cachekey = $courseid . ':' . $userid . ':' . implode(',', $gradeitemids);
        if (isset(self::$bulkpercentcache[$cachekey])) {
            return self::$bulkpercentcache[$cachekey];
        }
        $gradeitems = self::get_grade_items_by_ids($courseid, $gradeitemids);
        $grades = self::get_grade_grades_for_user($userid, array_keys($gradeitems));
        $ranges = self::resolve_grade_item_ranges($courseid, $gradeitems);
        $out = [];
        foreach ($gradeitems as $gradeitemid => $gradeitem) {
            $grade = $grades[$gradeitemid] ?? null;
            $out[$gradeitemid] = $grade ? self::grade_percent_from_grade_record($grade, $gradeitem, $ranges[$gradeitemid] ?? null) : null;
        }
        self::$bulkpercentcache[$cachekey] = $out;
        return $out;
    }

    /**
     * @param int $courseid
     * @param int $gradeitemid
     * @return float|null
     */
    public static function average_percent_for_grade_item_bulk(int $courseid, int $gradeitemid): ?float {
        global $DB;

        $items = self::get_grade_items_by_ids($courseid, [$gradeitemid]);
        $gradeitem = $items[$gradeitemid] ?? null;
        if (!$gradeitem) {
            return null;
        }
        $range = self::resolve_grade_item_ranges($courseid, [$gradeitemid => $gradeitem])[$gradeitemid] ?? null;
        $recordset = $DB->get_recordset('grade_grades', ['itemid' => $gradeitemid], '', 'userid,itemid,rawgrade,rawgrademin,rawgrademax,finalgrade');
        $samples = [];
        foreach ($recordset as $record) {
            $percent = self::grade_percent_from_grade_record($record, $gradeitem, $range);
            if ($percent !== null) {
                $samples[] = $percent;
            }
        }
        $recordset->close();
        if ($samples === []) {
            return null;
        }
        return round(array_sum($samples) / count($samples), 2);
    }
}
