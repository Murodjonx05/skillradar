<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use cache;
use stdClass;

/**
 * Plugin settings, grade-item mappings, and cache coordination.
 */
class manager {
    public const TABLE_MAP = 'local_skillradar_map';
    public const TABLE_DEF = 'local_skillradar_def';
    public const TABLE_CFG = 'local_skillradar_cfg';
    public const TABLE_QMAP = 'local_skillradar_qmap';

    /** @var string Application cache key prefix for per-course payload revision. */
    private const CACHE_REV_PREFIX = 'skillradar_rev_';
    /** @var int Bump when payload structure or aggregation logic changes and cached JSON must be rebuilt. */
    private const CACHE_FORMAT_VERSION = 3;
    /** @var null|bool */
    private static $qmaptableexists = null;
    /** @var array<int, array<int, int>> */
    private static $taggedskillquestioncountcache = [];
    /** @var array<int, array<int, \stdClass>> */
    private static $definitionscache = [];

    public static function get_course_config(int $courseid): stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE_CFG, ['courseid' => $courseid]);
        if ($record) {
            return $record;
        }
        return (object)[
            'courseid' => $courseid,
            'overallmode' => 'average',
            'courseavg' => 0,
            'primarycolor' => '#3B82F6',
        ];
    }

    public static function save_course_config(stdClass $data): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record(self::TABLE_CFG, ['courseid' => $data->courseid]);
        if ($existing) {
            $data->id = $existing->id;
            $data->timemodified = $now;
            $DB->update_record(self::TABLE_CFG, $data);
        } else {
            $data->timecreated = $now;
            $data->timemodified = $now;
            $DB->insert_record(self::TABLE_CFG, $data);
        }
        self::invalidate_course_cache((int)$data->courseid);
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function get_definitions(int $courseid): array {
        global $DB;
        if (!isset(self::$definitionscache[$courseid])) {
            self::$definitionscache[$courseid] = $DB->get_records(self::TABLE_DEF, ['courseid' => $courseid], 'sortorder ASC, id ASC');
        }
        return self::$definitionscache[$courseid];
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function get_mappings(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE_MAP, ['courseid' => $courseid], 'id ASC');
    }

    /**
     * @param int $courseid
     * @param array $rows
     * @return void
     */
    public static function replace_mappings(int $courseid, array $rows): void {
        global $DB;
        $DB->delete_records(self::TABLE_MAP, ['courseid' => $courseid]);
        $now = time();
        foreach ($rows as $row) {
            $row->courseid = $courseid;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record(self::TABLE_MAP, $row);
        }
        self::invalidate_course_cache($courseid);
    }

    /**
     * @param int $courseid
     * @return void
     */
    public static function purge_stale_mappings(int $courseid): void {
        global $DB;
        $sql = "SELECT m.id
                  FROM {" . self::TABLE_MAP . "} m
             LEFT JOIN {grade_items} gi ON gi.id = m.gradeitemid
                 WHERE m.courseid = :courseid
                   AND gi.id IS NULL";
        $ids = $DB->get_fieldset_sql($sql, ['courseid' => $courseid]);
        foreach ($ids as $id) {
            $DB->delete_records(self::TABLE_MAP, ['id' => $id]);
        }
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function count_items_per_skill(int $courseid): array {
        global $DB;
        $sql = "SELECT skill_key, COUNT(*) AS itemcount
                  FROM {" . self::TABLE_MAP . "}
                 WHERE courseid = :courseid
              GROUP BY skill_key";
        return $DB->get_records_sql_menu($sql, ['courseid' => $courseid]);
    }

    public static function find_preview_userid(int $courseid): int {
        global $DB, $USER;
        $userid = (int)$DB->get_field_sql(
            "SELECT DISTINCT userid
               FROM {local_skill_user_result}
              WHERE courseid = :courseid
           ORDER BY userid ASC",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );
        if ($userid > 0) {
            return $userid;
        }
        $userid = (int)$DB->get_field_sql(
            "SELECT DISTINCT gg.userid
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :courseid
                AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL)
           ORDER BY gg.userid ASC",
            ['courseid' => $courseid],
            IGNORE_MULTIPLE
        );
        return $userid > 0 ? $userid : (int)$USER->id;
    }

    public static function invalidate_course_cache(int $courseid): void {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $cache->set(self::CACHE_REV_PREFIX . $courseid, time());
        unset(self::$taggedskillquestioncountcache[$courseid]);
        unset(self::$definitionscache[$courseid]);
    }

    public static function invalidate_user_cache(int $courseid, int $userid): void {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $base = self::cache_key($courseid, $userid);
        $cache->delete($base);
        $cache->delete($base . '_avg');
    }

    /**
     * @param int $courseid
     * @return int[]
     */
    public static function get_course_quiz_ids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT id FROM {quiz} WHERE course = :courseid ORDER BY id ASC",
            ['courseid' => $courseid]
        ));
    }

    /**
     * Quizzes in the course for the radar quiz selector (id + formatted name).
     *
     * @param int $courseid
     * @return array<int, array{id:int, name:string}>
     */
    public static function get_course_quiz_options(int $courseid): array {
        global $DB;
        $records = $DB->get_records('quiz', ['course' => $courseid], 'name ASC, id ASC', 'id, name');
        $out = [];
        foreach ($records as $q) {
            $out[] = [
                'id' => (int)$q->id,
                'name' => format_string($q->name),
            ];
        }
        return $out;
    }

    /**
     * Default quiz for the single-quiz radar: first quiz with materialized rows for this user, else first quiz in course.
     *
     * @param int $courseid
     * @param int $userid
     * @return int
     */
    public static function default_quiz_id_for_skill_radar(int $courseid, int $userid): int {
        global $DB;
        $rec = $DB->get_record_sql(
            "SELECT quizid
               FROM {local_skill_user_result}
              WHERE courseid = :courseid
                AND userid = :userid
                AND aggregation_strategy = :strategy
           ORDER BY quizid ASC",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'strategy' => cache_manager::STRATEGY_LATEST,
            ],
            IGNORE_MULTIPLE
        );
        if ($rec && !empty($rec->quizid)) {
            return (int)$rec->quizid;
        }
        $first = $DB->get_record('quiz', ['course' => $courseid], 'id', IGNORE_MULTIPLE);
        return $first ? (int)$first->id : 0;
    }

    /**
     * @param int $courseid
     * @param int $quizid
     * @return bool
     */
    public static function quiz_belongs_to_course(int $courseid, int $quizid): bool {
        global $DB;
        if ($quizid < 1) {
            return false;
        }
        return $DB->record_exists('quiz', ['id' => $quizid, 'course' => $courseid]);
    }

    /**
     * Revision token so course setting changes do not require purging the whole store.
     */
    protected static function course_payload_revision(int $courseid): int {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $rev = $cache->get(self::CACHE_REV_PREFIX . $courseid);
        if ($rev === false) {
            return 0;
        }
        return (int)$rev;
    }

    public static function cache_key(int $courseid, int $userid): string {
        $r = self::course_payload_revision($courseid);
        return 'v' . self::CACHE_FORMAT_VERSION . '_c' . $courseid . '_r' . $r . '_u' . $userid;
    }

    /**
     * Course has skill definitions, grade mappings, or precomputed quiz analytics.
     */
    public static function is_course_skillradar_ready(int $courseid): bool {
        global $DB;
        if ($courseid < 1) {
            return false;
        }
        if ($DB->record_exists(self::TABLE_DEF, ['courseid' => $courseid])) {
            return true;
        }
        if ($DB->record_exists(cache_manager::TABLE_USER, ['courseid' => $courseid])) {
            return true;
        }
        if ($DB->record_exists(cache_manager::TABLE_ATTEMPT, ['courseid' => $courseid])) {
            return true;
        }
        return $DB->record_exists(self::TABLE_MAP, ['courseid' => $courseid]);
    }

    /**
     * Whether the question→skill table exists (upgrade completed).
     *
     * @return bool
     */
    public static function qmap_table_exists(): bool {
        global $DB;
        if (self::$qmaptableexists !== null) {
            return self::$qmaptableexists;
        }
        $dbman = $DB->get_manager();
        self::$qmaptableexists = $dbman->table_exists(new \xmldb_table(self::TABLE_QMAP));
        return self::$qmaptableexists;
    }

    /**
     * Distinct questions used in any quiz on this course (for per-question skill tags).
     *
     * Moodle 4.4+ stores slot→question via question_references, not quiz_slots.questionid.
     *
     * @param int $courseid
     * @return array<int, \stdClass> questionid => {questionid, name, qtype}
     */
    public static function get_course_quiz_questions(int $courseid): array {
        global $DB;

        $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'id ASC');
        if (!$quizzes) {
            return [];
        }

        if (!class_exists(\mod_quiz\question\bank\qbank_helper::class)) {
            return self::get_course_quiz_questions_legacy($courseid);
        }

        $questionids = [];
        foreach ($quizzes as $quiz) {
            $cm = get_coursemodule_from_instance('quiz', $quiz->id, $courseid, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $context = \context_module::instance($cm->id);
            try {
                $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure((int)$quiz->id, $context, null);
            } catch (\Throwable $e) {
                debugging('local_skillradar get_course_quiz_questions quiz ' . (int)$quiz->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            foreach ($structure as $slotdata) {
                $qid = $slotdata->questionid ?? null;
                if ($qid === null || !is_numeric($qid) || (int)$qid < 1) {
                    continue;
                }
                if (($slotdata->qtype ?? '') === 'random') {
                    continue;
                }
                $questionids[(int)$qid] = true;
            }
        }

        if ($questionids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($questionids), SQL_PARAMS_NAMED);
        $sql = "SELECT q.id AS questionid, q.name, q.qtype
                  FROM {question} q
                 WHERE q.id $insql
              ORDER BY q.name ASC, q.id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Non-random questions in one quiz (same shape as {@see get_course_quiz_questions} records).
     *
     * @param int $courseid
     * @param int $quizid
     * @return array<int, \stdClass> questionid => {questionid, name, qtype}
     */
    private static function get_quiz_questions(int $courseid, int $quizid): array {
        global $DB;

        if ($courseid < 1 || $quizid < 1) {
            return [];
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid, 'course' => $courseid], 'id,course', IGNORE_MISSING);
        if (!$quiz) {
            return [];
        }

        $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }

        if (!class_exists(\mod_quiz\question\bank\qbank_helper::class)) {
            return self::get_quiz_questions_legacy($quizid);
        }

        $questionids = [];
        $context = \context_module::instance($cm->id);
        try {
            $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure((int)$quiz->id, $context, null);
        } catch (\Throwable $e) {
            debugging('local_skillradar get_quiz_questions quiz ' . (int)$quiz->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
        foreach ($structure as $slotdata) {
            $qid = $slotdata->questionid ?? null;
            if ($qid === null || !is_numeric($qid) || (int)$qid < 1) {
                continue;
            }
            if (($slotdata->qtype ?? '') === 'random') {
                continue;
            }
            $questionids[(int)$qid] = true;
        }

        if ($questionids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($questionids), SQL_PARAMS_NAMED);
        $sql = "SELECT q.id AS questionid, q.name, q.qtype
                  FROM {question} q
                 WHERE q.id $insql
              ORDER BY q.name ASC, q.id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Single-quiz variant of {@see get_course_quiz_questions_legacy}.
     *
     * @param int $quizid
     * @return array<int, \stdClass>
     */
    private static function get_quiz_questions_legacy(int $quizid): array {
        global $DB;

        $columns = $DB->get_columns('quiz_slots');
        if (!isset($columns['questionid'])) {
            return [];
        }

        $sql = "SELECT DISTINCT qs.questionid,
                       q.name,
                       q.qtype
                  FROM {quiz_slots} qs
                  JOIN {question} q ON q.id = qs.questionid
                 WHERE qs.quizid = :quizid
                   AND q.qtype <> :randomqtype
              ORDER BY q.name ASC, qs.questionid ASC";

        return $DB->get_records_sql($sql, ['quizid' => $quizid, 'randomqtype' => 'random']);
    }

    /**
     * Counts how many non-random quiz questions in the course resolve to each course skill definition (positive skillid only).
     * Used to show axes for tagged skills that have no materialized learner row yet.
     *
     * @param int $courseid
     * @return array<int, int> definition id => question count
     */
    public static function get_tagged_skill_question_counts_in_course(int $courseid): array {
        if (isset(self::$taggedskillquestioncountcache[$courseid])) {
            return self::$taggedskillquestioncountcache[$courseid];
        }
        $questions = self::get_course_quiz_questions($courseid);
        if ($questions === []) {
            self::$taggedskillquestioncountcache[$courseid] = [];
            return [];
        }
        $counts = [];
        $questionids = [];
        foreach ($questions as $q) {
            $questionids[] = (int)$q->questionid;
        }
        $skills = skill_service::get_question_skills($courseid, $questionids);
        foreach ($questions as $q) {
            $skill = $skills[(int)$q->questionid] ?? null;
            if (!$skill || (int)$skill->skillid <= 0) {
                continue;
            }
            $sid = (int)$skill->skillid;
            if (!isset($counts[$sid])) {
                $counts[$sid] = 0;
            }
            $counts[$sid]++;
        }
        self::$taggedskillquestioncountcache[$courseid] = $counts;
        return $counts;
    }

    /**
     * Per-quiz tagged skill counts (skills whose questions appear in this quiz's structure).
     *
     * @param int $courseid
     * @param int $quizid
     * @return array<int, int> definition id => question count
     */
    public static function get_tagged_skill_question_counts_for_quiz(int $courseid, int $quizid): array {
        $questions = self::get_quiz_questions($courseid, $quizid);
        if ($questions === []) {
            return [];
        }
        $counts = [];
        $questionids = [];
        foreach ($questions as $q) {
            $questionids[] = (int)$q->questionid;
        }
        $skills = skill_service::get_question_skills($courseid, $questionids);
        foreach ($questions as $q) {
            $skill = $skills[(int)$q->questionid] ?? null;
            if (!$skill || (int)$skill->skillid <= 0) {
                continue;
            }
            $sid = (int)$skill->skillid;
            if (!isset($counts[$sid])) {
                $counts[$sid] = 0;
            }
            $counts[$sid]++;
        }
        return $counts;
    }

    /**
     * Fallback when quiz_slots still had questionid (very old DBs).
     *
     * @param int $courseid
     * @return array
     */
    private static function get_course_quiz_questions_legacy(int $courseid): array {
        global $DB;

        $columns = $DB->get_columns('quiz_slots');
        if (!isset($columns['questionid'])) {
            return [];
        }

        $sql = "SELECT DISTINCT qs.questionid,
                       q.name,
                       q.qtype
                  FROM {quiz_slots} qs
                  JOIN {quiz} qz ON qz.id = qs.quizid
                  JOIN {question} q ON q.id = qs.questionid
                 WHERE qz.course = :courseid
                   AND q.qtype <> :randomqtype
              ORDER BY q.name ASC, qs.questionid ASC";

        return $DB->get_records_sql($sql, ['courseid' => $courseid, 'randomqtype' => 'random']);
    }

    /**
     * @param int $courseid
     * @return array<int, string> questionid => skill_key
     */
    public static function get_question_skill_overrides(int $courseid): array {
        global $DB;
        if (!self::qmap_table_exists()) {
            return [];
        }
        $rows = $DB->get_records(self::TABLE_QMAP, ['courseid' => $courseid]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->questionid] = (string)$row->skill_key;
        }
        return $out;
    }

    /**
     * Replace per-question skill_key overrides. Empty skill_key removes mapping for that question.
     *
     * @param int $courseid
     * @param array<int, array{questionid:int, skill_key:string}> $rows
     * @return void
     */
    public static function replace_question_skill_mappings(int $courseid, array $rows): void {
        global $DB;
        if (!self::qmap_table_exists()) {
            return;
        }

        $valid = [];
        foreach (self::get_definitions($courseid) as $def) {
            $valid[$def->skill_key] = true;
        }

        $upserts = [];
        foreach ($rows as $row) {
            $qid = (int)$row['questionid'];
            if ($qid < 1) {
                continue;
            }
            $upserts[$qid] = trim((string)($row['skill_key'] ?? ''));
        }
        if ($upserts === []) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($upserts), SQL_PARAMS_NAMED, 'qmapqid');
        $params['courseid'] = $courseid;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records_select(self::TABLE_QMAP, "courseid = :courseid AND questionid {$insql}", $params);

        $now = time();
        foreach ($upserts as $qid => $sk) {
            if ($sk === '' || $sk === '_none' || empty($valid[$sk])) {
                continue;
            }
            $DB->insert_record(self::TABLE_QMAP, (object)[
                'courseid' => $courseid,
                'questionid' => $qid,
                'skill_key' => $sk,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
        self::invalidate_course_cache($courseid);
        self::reset_static_caches();
    }

    /**
     * Reset process-local caches. Useful for tests and schema transitions.
     *
     * @return void
     */
    public static function reset_static_caches(): void {
        self::$qmaptableexists = null;
        self::$taggedskillquestioncountcache = [];
        self::$definitionscache = [];
        calculator::reset_caches();
        skill_service::reset_caches();
    }

    /**
     * Recompute materialized analytics for all finished quiz attempts in a course (e.g. after question→skill changes).
     *
     * @param int $courseid
     * @return void
     */
    public static function rebuild_course_quiz_attempts(int $courseid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if (class_exists('\core_php_time_limit')) {
            \core_php_time_limit::raise(600);
        }

        $rs = $DB->get_recordset_sql(
            "SELECT qa.id
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
                AND qa.preview = 0
                AND qa.state = :state
           ORDER BY qa.id ASC",
            [
                'courseid' => $courseid,
                'state' => \mod_quiz\quiz_attempt::FINISHED,
            ]
        );
        $batchsize = 100;
        $buffer = [];
        foreach ($rs as $record) {
            $buffer[] = (int)$record->id;
            if (count($buffer) < $batchsize) {
                continue;
            }
            self::rebuild_attempt_batch($buffer);
            $buffer = [];
        }
        $rs->close();
        if ($buffer !== []) {
            self::rebuild_attempt_batch($buffer);
        }

        self::invalidate_course_cache($courseid);
    }

    /**
     * @param int $courseid
     * @return void
     */
    public static function queue_course_rebuild(int $courseid): void {
        if ($courseid < 1) {
            return;
        }
        foreach (\core\task\manager::get_adhoc_tasks(\local_skillradar\task\rebuild_course_analytics::class) as $task) {
            $data = (array)$task->get_custom_data();
            if ((int)($data['courseid'] ?? 0) === $courseid) {
                return;
            }
        }
        $task = new \local_skillradar\task\rebuild_course_analytics();
        $task->set_component('local_skillradar');
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * @param int[] $attemptids
     * @return void
     */
    private static function rebuild_attempt_batch(array $attemptids): void {
        foreach ($attemptids as $aid) {
            try {
                cache_manager::recompute_attempt((int)$aid);
            } catch (\Throwable $e) {
                debugging('local_skillradar rebuild attempt ' . $aid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
