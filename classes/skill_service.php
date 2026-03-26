<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves a question to a skill for analytics (per-question, then default “set” S).
 *
 * Model: let S be a question set (Moodle: {@see question_categories} — one category can hold many questions).
 * Radar axes are skills (def.skill_key / displayname), not raw set ids. Each question can be tagged with
 * a skill (override); if not, the question’s bank category name is the default skill label (skillid = -categoryid).
 * The grade-report radar chart lists only tagged skills (positive skillid); category fallbacks are omitted there.
 *
 * 1) Optional course override: {@see manager::TABLE_QMAP} maps question → local_skillradar_def.skill_key.
 * 2) Else: question bank category id + name (negative skillid in storage to avoid collision with def.id).
 */
class skill_service {
    /**
     * Resolve a delivered question to a skill snapshot for materialized analytics.
     *
     * @param int $questionid Delivered question id from the attempt.
     * @param int $courseid Course id (required for per-course skill_key overrides).
     * @return \stdClass|null Object with skillid (int), skillname (string), or null if unmapped.
     */
    public static function get_question_skill(int $questionid, int $courseid): ?\stdClass {
        global $DB;

        if ($courseid > 0 && manager::qmap_table_exists()) {
            $map = $DB->get_record(manager::TABLE_QMAP, [
                'courseid' => $courseid,
                'questionid' => $questionid,
            ]);
            if ($map && $map->skill_key !== '') {
                $def = $DB->get_record(manager::TABLE_DEF, [
                    'courseid' => $courseid,
                    'skill_key' => $map->skill_key,
                ]);
                if ($def) {
                    return (object)[
                        'skillid' => (int)$def->id,
                        'skillname' => format_string($def->displayname),
                    ];
                }
            }
        }

        $sql = "SELECT q.id AS questionid,
                       q.category AS catid,
                       qc.name AS skillname,
                       qc.contextid AS contextid,
                       q.qtype
                  FROM {question} q
                  JOIN {question_categories} qc ON qc.id = q.category
                 WHERE q.id = :questionid";
        $record = $DB->get_record_sql($sql, ['questionid' => $questionid]);
        if (!$record || $record->qtype === 'description' || (int)$record->catid < 1) {
            return null;
        }

        try {
            $ctx = \context::instance_by_id((int)$record->contextid);
        } catch (\Exception $e) {
            $ctx = \context_system::instance();
        }

        $catid = (int)$record->catid;

        return (object)[
            'skillid' => -$catid,
            'skillname' => format_string($record->skillname, true, ['context' => $ctx]),
        ];
    }

    /**
     * Aggregated per-skill rows for one attempt (same shape as materialized attempt result, minus ids/timestamps).
     *
     * @param int $attemptid
     * @return array<int, array<string, mixed>>
     */
    public static function get_attempt_skills(int $attemptid): array {
        $data = attempt_analyzer::extract_attempt_data($attemptid);
        return skill_aggregator::aggregate_attempt($data);
    }
}
