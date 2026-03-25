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

    public static function build_payload(int $courseid, int $userid, bool $withcourseavg = false): array {
        $config = manager::get_course_config($courseid);
        $definitions = manager::get_definitions($courseid);
        $mappings = manager::get_mappings($courseid);

        $detail = self::build_skill_rows($courseid, $userid, $definitions, $mappings);
        $chart = self::build_chart_meta($detail);
        $overall = self::compute_overall($courseid, $userid, $config, $detail);

        $payload = [
            'skills' => self::build_skills_map($detail),
            'skills_detail' => $detail,
            'mapping_meta' => self::build_mapping_meta($definitions, $mappings),
            'chart' => $chart,
            'overall' => $overall,
            'config' => [
                'overallmode' => $config->overallmode ?? 'average',
                'minaxes' => count($chart['labels']),
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
            if (!$gradeitem || (float)$gradeitem->grademax <= 0.0) {
                continue;
            }

            $grade = grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
            if (!$grade || $grade->finalgrade === null) {
                continue;
            }

            $weight = (float)$mapping->weight;
            if ($weight <= 0) {
                continue;
            }

            $normalized = ((float)$grade->finalgrade / (float)$gradeitem->grademax) * 100.0;
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

    protected static function build_mapping_meta(array $definitions, array $mappings): array {
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
            $meta[$mapping->skill_key]['items'][] = [
                'gradeitemid' => (int)$mapping->gradeitemid,
                'weight' => (float)$mapping->weight,
            ];
        }

        return array_values($meta);
    }

    protected static function compute_overall(int $courseid, int $userid, \stdClass $config, array $detail): array {
        $percent = null;
        if (($config->overallmode ?? 'average') === 'final') {
            $courseitem = grade_item::fetch_course_item($courseid);
            if ($courseitem && (float)$courseitem->grademax > 0) {
                $grade = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $userid]);
                if ($grade && $grade->finalgrade !== null) {
                    $percent = round((((float)$grade->finalgrade / (float)$courseitem->grademax) * 100.0), 2);
                }
            }
        } else {
            $values = [];
            foreach ($detail as $row) {
                if ($row['placeholder'] || $row['value'] === null) {
                    continue;
                }
                $values[] = (float)$row['value'];
            }
            if ($values) {
                $percent = round(array_sum($values) / count($values), 2);
            }
        }

        return [
            'percent' => $percent,
            'letter' => self::percent_to_letter($percent),
        ];
    }

    protected static function percent_to_letter(?float $percent): string {
        if ($percent === null) {
            return '';
        }
        if ($percent >= 97) {
            return 'S-';
        }
        if ($percent >= 90) {
            return 'A';
        }
        if ($percent >= 80) {
            return 'B';
        }
        if ($percent >= 70) {
            return 'C';
        }
        if ($percent >= 60) {
            return 'D';
        }
        return 'F';
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
