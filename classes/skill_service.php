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
    /** @var array<int, array<int, \stdClass|null>> */
    private static $questionskillcache = [];
    /** @var array<int, array<int, string>> */
    private static $questioncontextcache = [];
    /** @var array<int, array<int, string>> */
    private static $questionqtypecache = [];

    /**
     * Resolve a delivered question to a skill snapshot for materialized analytics.
     *
     * @param int $questionid Delivered question id from the attempt.
     * @param int $courseid Course id (required for per-course skill_key overrides).
     * @return \stdClass|null Object with skillid (int), skillname (string), or null if unmapped.
     */
    public static function get_question_skill(int $questionid, int $courseid): ?\stdClass {
        $resolved = self::get_question_skills($courseid, [$questionid]);
        return $resolved[$questionid] ?? null;
    }

    /**
     * Batch-resolve delivered questions to skill snapshots.
     *
     * @param int $courseid
     * @param int[] $questionids
     * @return array<int, \stdClass|null>
     */
    public static function get_question_skills(int $courseid, array $questionids): array {
        global $DB;

        $questionids = array_values(array_unique(array_filter(array_map('intval', $questionids))));
        if ($questionids === []) {
            return [];
        }

        if (!isset(self::$questionskillcache[$courseid])) {
            self::$questionskillcache[$courseid] = [];
        }

        $missing = [];
        foreach ($questionids as $questionid) {
            if (!array_key_exists($questionid, self::$questionskillcache[$courseid])) {
                $missing[] = $questionid;
            }
        }

        if ($missing !== []) {
            $defsbykey = [];
            foreach (manager::get_definitions($courseid) as $def) {
                $defsbykey[(string)$def->skill_key] = $def;
            }

            $overridebyquestion = [];
            if ($courseid > 0 && manager::qmap_table_exists()) {
                [$insql, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'qid');
                $params['courseid'] = $courseid;
                $maps = $DB->get_records_sql(
                    "SELECT questionid, skill_key
                       FROM {" . manager::TABLE_QMAP . "}
                      WHERE courseid = :courseid
                        AND questionid {$insql}",
                    $params
                );
                foreach ($maps as $map) {
                    $overridebyquestion[(int)$map->questionid] = (string)$map->skill_key;
                }
            }

            [$insql, $params] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED, 'qq');
            $records = $DB->get_records_sql(
                "SELECT q.id AS questionid,
                        q.category AS catid,
                        qc.name AS skillname,
                        qc.contextid AS contextid,
                        q.qtype
                   FROM {question} q
                   JOIN {question_categories} qc ON qc.id = q.category
                  WHERE q.id {$insql}",
                $params
            );

            foreach ($missing as $questionid) {
                $record = $records[$questionid] ?? null;
                self::$questionskillcache[$courseid][$questionid] = null;
                if (!$record || $record->qtype === 'description' || (int)$record->catid < 1) {
                    continue;
                }

                $overridekey = $overridebyquestion[$questionid] ?? '';
                if ($overridekey !== '' && isset($defsbykey[$overridekey])) {
                    $def = $defsbykey[$overridekey];
                    self::$questionskillcache[$courseid][$questionid] = (object)[
                        'skillid' => (int)$def->id,
                        'skillname' => format_string($def->displayname),
                    ];
                    continue;
                }

                try {
                    $ctx = \context::instance_by_id((int)$record->contextid);
                } catch (\Exception $e) {
                    $ctx = \context_system::instance();
                }

                self::$questionskillcache[$courseid][$questionid] = (object)[
                    'skillid' => -(int)$record->catid,
                    'skillname' => format_string($record->skillname, true, ['context' => $ctx]),
                ];
            }
        }

        $out = [];
        foreach ($questionids as $questionid) {
            $out[$questionid] = self::$questionskillcache[$courseid][$questionid] ?? null;
        }
        return $out;
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
