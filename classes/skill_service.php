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
     * Clear in-request skill resolution caches (e.g. after qmap / definitions change).
     */
    public static function reset_caches(): void {
        self::$questionskillcache = [];
        self::$questioncontextcache = [];
        self::$questionqtypecache = [];
    }

    /**
     * Load question rows with bank category id, category name, context, and qtype.
     *
     * Modern Moodle stores category on {@see question_bank_entries}; legacy installs had {@see question}.category.
     *
     * @param int[] $questionids
     * @return array<int, \stdClass> Keyed by question id (questionid property matches key).
     */
    private static function load_question_bank_category_records(array $questionids): array {
        global $DB;

        if ($questionids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'qq');
        $manager = $DB->get_manager();
        $hasversions = $manager->table_exists(new \xmldb_table('question_versions'));
        $hasentries = $manager->table_exists(new \xmldb_table('question_bank_entries'));

        if ($hasversions && $hasentries) {
            $ready = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
            $params['qvsready'] = $ready;
            $params['qvsready2'] = $ready;

            return $DB->get_records_sql(
                "SELECT q.id AS questionid,
                        qbe.id AS entryid,
                        qbe.questioncategoryid AS catid,
                        qc.name AS skillname,
                        qc.contextid AS contextid,
                        q.qtype
                   FROM {question} q
                   JOIN (
                     SELECT questionid, MAX(version) AS maxver
                       FROM {question_versions}
                      WHERE status = :qvsready
                   GROUP BY questionid
                   ) qvn ON qvn.questionid = q.id
                   JOIN {question_versions} qv ON qv.questionid = q.id AND qv.version = qvn.maxver AND qv.status = :qvsready2
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                   JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  WHERE q.id {$insql}",
                $params
            );
        }

        $columns = $DB->get_columns('question');
        if (isset($columns['category'])) {
            return $DB->get_records_sql(
                "SELECT q.id AS questionid,
                        0 AS entryid,
                        q.category AS catid,
                        qc.name AS skillname,
                        qc.contextid AS contextid,
                        q.qtype
                   FROM {question} q
                   JOIN {question_categories} qc ON qc.id = q.category
                  WHERE q.id {$insql}",
                $params
            );
        }

        return [];
    }

    /**
     * Resolve per-course overrides by question bank entry for delivered questions from older/newer versions.
     *
     * This lets one mapping on the current question version apply to attempts that reference another version
     * of the same question bank entry.
     *
     * @param int $courseid
     * @param int[] $entryids
     * @return array<int, string> questionbankentryid => skill_key
     */
    private static function get_override_keys_by_entry(int $courseid, array $entryids): array {
        global $DB;

        $entryids = array_values(array_unique(array_filter(array_map('intval', $entryids))));
        if ($courseid < 1 || $entryids === []) {
            return [];
        }

        $manager = $DB->get_manager();
        if (!$manager->table_exists(new \xmldb_table('question_versions')) ||
                !$manager->table_exists(new \xmldb_table('question_bank_entries'))) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED, 'qbe');
        $params['courseid'] = $courseid;
        $params['ready'] = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $rows = $DB->get_records_sql(
            "SELECT qv.questionbankentryid AS entryid,
                    m.skill_key,
                    qv.version,
                    qv.questionid
               FROM {" . manager::TABLE_QMAP . "} m
               JOIN {question_versions} qv ON qv.questionid = m.questionid
              WHERE m.courseid = :courseid
                AND qv.status = :ready
                AND qv.questionbankentryid {$insql}
           ORDER BY qv.questionbankentryid ASC, qv.version DESC, qv.questionid DESC",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $entryid = (int)$row->entryid;
            if ($entryid < 1 || isset($out[$entryid])) {
                continue;
            }
            $out[$entryid] = (string)$row->skill_key;
        }
        return $out;
    }

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

            $records = self::load_question_bank_category_records($missing);
            $entryids = [];
            foreach ($records as $record) {
                $entryid = (int)($record->entryid ?? 0);
                if ($entryid > 0) {
                    $entryids[$entryid] = $entryid;
                }
            }
            $overridebyentry = self::get_override_keys_by_entry($courseid, array_values($entryids));

            foreach ($missing as $questionid) {
                $record = $records[$questionid] ?? null;
                self::$questionskillcache[$courseid][$questionid] = null;
                if (!$record || $record->qtype === 'description' || (int)$record->catid < 1) {
                    continue;
                }

                $overridekey = $overridebyquestion[$questionid] ?? '';
                if ($overridekey === '' && $record) {
                    $overridekey = $overridebyentry[(int)($record->entryid ?? 0)] ?? '';
                }
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
