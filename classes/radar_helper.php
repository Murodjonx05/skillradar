<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared helpers for radar payload formatting.
 */
class radar_helper {
    public const MIN_AXES = 3;

    /**
     * @param array $rows
     * @return array
     */
    public static function dedupe_duplicate_axis_labels(array $rows): array {
        $labels = [];
        foreach ($rows as $row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            $labels[] = trim((string)($row['label'] ?? ''));
        }
        $counts = array_count_values($labels);
        foreach ($rows as &$row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if (($counts[$label] ?? 0) > 1) {
                $key = trim((string)($row['key'] ?? ''));
                $row['label'] = $label . ' · ' . ($key !== '' ? $key : '?');
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array $detail
     * @return array
     */
    public static function build_skills_map(array $detail): array {
        $skills = [];
        foreach ($detail as $row) {
            if (!empty($row['placeholder'])) {
                continue;
            }
            $skills[$row['label']] = $row['value'] === null ? 0.0 : (float)$row['value'];
        }
        return $skills;
    }

    /**
     * @param array $detail
     * @param int $minaxes
     * @param string $placeholdercolor
     * @param bool $useemptycolor
     * @return array
     */
    public static function build_chart_meta(
        array $detail,
        int $minaxes = self::MIN_AXES,
        string $placeholdercolor = '#CBD5E1',
        bool $useemptycolor = false
    ): array {
        $labels = [];
        $values = [];
        $colors = [];
        $placeholders = [];
        $keys = [];

        foreach ($detail as $row) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
            $colors[] = $useemptycolor && !empty($row['empty']) ? '#94A3B8' : $row['color'];
            $placeholders[] = !empty($row['placeholder']);
            $keys[] = $row['key'];
        }

        $i = 0;
        while (count($labels) < $minaxes) {
            $labels[] = get_string('notconfigured', 'local_skillradar');
            $values[] = null;
            $colors[] = $placeholdercolor;
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
     * @param float|null $percent
     * @return string|null
     */
    public static function percent_to_letter(?float $percent): ?string {
        if ($percent === null) {
            return null;
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
}
