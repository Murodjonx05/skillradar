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

class calculator {
    public const MIN_AXES = 3;

    /**
     * Max points from activity settings (e.g. quiz "Оцениваемый балл"), not only grade_item row.
     *
     * @return float[]|null [grademin, grademax] or null to use generic logic
     */
    protected static function get_activity_grade_range(grade_item $gi): ?array {
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
     * Percent 0–100: (полученный балл − min) / (max − min) × 100.
     * For quiz/assign uses max from activity; otherwise raw range or grade_item scale.
     */
    protected static function grade_percent_from_grade(grade_grade $grade, grade_item $gradeitem): ?float {
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

    public static function build_payload(int $courseid, int $userid, bool $withcourseavg = false): array {
        $config = manager::get_course_config($courseid);
        $definitions = manager::get_definitions($courseid);
        $mappings = manager::get_mappings($courseid);

        $detail = self::build_skill_rows($courseid, $userid, $definitions, $mappings);
        $chart = self::build_chart_meta($detail);
        $overall = self::compute_overall($courseid, $userid, $config, $detail);

        $primary = $config->primarycolor ?? '#3B82F6';
        $payload = [
            'skills' => self::build_skills_map($detail),
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

    protected static function build_skill_rows(int $courseid, int $userid, array $definitions, array $mappings): array {
        $rows = [];
        foreach ($definitions as $definition) {
            $rows[$definition->skill_key] = [
                'key' => $definition->skill_key,
                'label' => $definition->displayname,
                'color' => $definition->color,
                'value' => null,
                'items' => 0,
                'empty' => true,
                'placeholder' => false,
                'weighted' => 0.0,
                'weightsum' => 0.0,
            ];
        }

        foreach ($mappings as $mapping) {
            if (!isset($rows[$mapping->skill_key])) {
                $rows[$mapping->skill_key] = [
                    'key' => $mapping->skill_key,
                    'label' => $mapping->skill_key,
                    'color' => '#64748B',
                    'value' => null,
                    'items' => 0,
                    'empty' => true,
                    'placeholder' => false,
                    'weighted' => 0.0,
                    'weightsum' => 0.0,
                ];
            }

            $rows[$mapping->skill_key]['items']++;
            $gradeitem = grade_item::fetch(['id' => $mapping->gradeitemid, 'courseid' => $courseid]);
            if (!$gradeitem) {
                continue;
            }

            $grade = grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
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
            $rows[$mapping->skill_key]['weighted'] += $normalized * $weight;
            $rows[$mapping->skill_key]['weightsum'] += $weight;
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

    protected static function build_skills_map(array $detail): array {
        $skills = [];
        foreach ($detail as $row) {
            if ($row['placeholder']) {
                continue;
            }
            $skills[$row['label']] = $row['value'] === null ? 0.0 : (float)$row['value'];
        }
        return $skills;
    }

    protected static function build_chart_meta(array $detail): array {
        $labels = [];
        $values = [];
        $colors = [];
        $placeholders = [];
        $keys = [];

        foreach ($detail as $row) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
            $colors[] = $row['empty'] ? '#94A3B8' : $row['color'];
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

    protected static function build_mapping_meta(int $courseid, array $definitions, array $mappings): array {
        global $DB;
        $meta = [];
        foreach ($definitions as $definition) {
            $meta[$definition->skill_key] = [
                'key' => $definition->skill_key,
                'label' => $definition->displayname,
                'color' => $definition->color,
                'items' => [],
            ];
        }

        foreach ($mappings as $mapping) {
            if (!isset($meta[$mapping->skill_key])) {
                $meta[$mapping->skill_key] = [
                    'key' => $mapping->skill_key,
                    'label' => $mapping->skill_key,
                    'color' => '#64748B',
                    'items' => [],
                ];
            }
            $gi = grade_item::fetch(['id' => $mapping->gradeitemid, 'courseid' => $courseid]);
            if (!$gi) {
                continue;
            }
            $activityrange = self::get_activity_grade_range($gi);
            if ($activityrange !== null) {
                $gmin = $activityrange[0];
                $gmax = $activityrange[1];
            } else {
                // Grader cell: raw points; sample range from grade_grades when useful.
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
            $meta[$mapping->skill_key]['items'][] = [
                'gradeitemid' => (int)$mapping->gradeitemid,
                'weight' => (float)$mapping->weight,
                'grademin' => $gmin,
                'grademax' => $gmax,
            ];
        }

        return array_values($meta);
    }

    protected static function compute_overall(int $courseid, int $userid, \stdClass $config, array $detail): array {
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
                $letter = self::percent_to_letter($percent);
            }
        } else {
            // Average mode: mean of skills that have a grade (non-null); ungraded axes are excluded.
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
            $letter = self::percent_to_letter($percent);
        }

        return [
            'percent' => $percent,
            'letter' => $letter,
        ];
    }

    /**
     * Letter grade from percent — same bands as getRank() in js/script.js (grader page).
     */
    protected static function percent_to_letter(?float $percent): string {
        if ($percent === null) {
            return '';
        }
        if ($percent >= 95) {
            return 'S+';
        }
        if ($percent >= 90) {
            return 'S';
        }
        if ($percent >= 85) {
            return 'S-';
        }
        if ($percent >= 80) {
            return 'A+';
        }
        if ($percent >= 75) {
            return 'A';
        }
        if ($percent >= 70) {
            return 'A-';
        }
        if ($percent >= 65) {
            return 'B+';
        }
        if ($percent >= 60) {
            return 'B';
        }
        if ($percent >= 55) {
            return 'B-';
        }
        if ($percent >= 50) {
            return 'C+';
        }
        if ($percent >= 40) {
            return 'C';
        }
        if ($percent >= 30) {
            return 'D';
        }
        if ($percent >= 15) {
            return 'E+';
        }
        if ($percent >= 5) {
            return 'E';
        }
        return 'E-';
    }

    protected static function build_course_average(int $courseid, array $detail): array {
        global $DB;
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT gg.userid
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = ?
                AND gg.finalgrade IS NOT NULL",
            [$courseid]
        );

        $bykey = [];
        foreach ($detail as $row) {
            $bykey[$row['key']] = [];
        }

        foreach ($userids as $userid) {
            $rows = self::build_skill_rows($courseid, (int)$userid, manager::get_definitions($courseid), manager::get_mappings($courseid));
            foreach ($rows as $row) {
                if ($row['value'] !== null) {
                    $bykey[$row['key']][] = (float)$row['value'];
                }
            }
        }

        $values = [];
        foreach ($detail as $row) {
            if ($row['placeholder']) {
                $values[] = null;
                continue;
            }
            $samples = $bykey[$row['key']] ?? [];
            $values[] = $samples ? round(array_sum($samples) / count($samples), 2) : null;
        }

        while (count($values) < self::MIN_AXES) {
            $values[] = null;
        }

        return [
            'label' => get_string('courseaveragelegend', 'local_skillradar'),
            'values' => $values,
        ];
    }
}
