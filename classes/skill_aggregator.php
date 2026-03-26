<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates normalized slot facts by skill.
 */
class skill_aggregator {
    public const CALCULATION_VERSION = 1;

    /**
     * @param array $attemptdata
     * @return array
     */
    public static function aggregate_attempt(array $attemptdata): array {
        $attempt = $attemptdata['attempt'];
        $slots = $attemptdata['slots'] ?? [];

        $grouped = [];
        foreach ($slots as $row) {
            $skillid = (int)$row['skillid'];
            if (!isset($grouped[$skillid])) {
                $grouped[$skillid] = [
                    'attemptid' => (int)$attempt->id,
                    'quizid' => (int)$attempt->quizid,
                    'courseid' => (int)$attempt->courseid,
                    'userid' => (int)$attempt->userid,
                    'skillid' => $skillid,
                    'skillname' => (string)$row['skillname'],
                    'earned' => 0.0,
                    'maxearned' => 0.0,
                    'questions_count' => 0,
                    'calculation_version' => self::CALCULATION_VERSION,
                    'debugmeta' => null,
                ];
            }

            $grouped[$skillid]['skillname'] = (string)$row['skillname'];
            $grouped[$skillid]['earned'] += (float)$row['earned'];
            $grouped[$skillid]['maxearned'] += (float)$row['maxearned'];
            $grouped[$skillid]['questions_count']++;
        }

        foreach ($grouped as &$row) {
            $row['percent'] = self::compute_percent((float)$row['earned'], (float)$row['maxearned']);
        }
        unset($row);

        return array_values($grouped);
    }

    /**
     * @param float $earned
     * @param float $maxearned
     * @return float
     */
    public static function compute_percent(float $earned, float $maxearned): float {
        if ($maxearned <= 0.0) {
            return 0.0;
        }
        return round(($earned / $maxearned) * 100, 2);
    }
}
