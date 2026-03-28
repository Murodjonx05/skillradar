<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Writes deterministic analytics rows.
 *
 * Per-quiz user rows follow the quiz activity «Grading method» (highest / average / first / last attempt),
 * matching {@see \mod_quiz\grade_calculator} behaviour for which attempts contribute.
 */
class cache_manager {
    public const TABLE_ATTEMPT = 'local_skill_attempt_result';
    public const TABLE_USER = 'local_skill_user_result';
    /** @var string Stored in aggregation_strategy; selection uses quiz.grademethod. */
    public const STRATEGY_LATEST = 'latest_finalized_per_quiz';

    /**
     * Recompute analytics for a single attempt in a concurrency-safe, idempotent way.
     *
     * @param int $attemptid
     * @return void
     */
    public static function recompute_attempt(int $attemptid): void {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_skillradar_attempt');
        $lock = $factory->get_lock('attempt_' . $attemptid, 30);
        if (!$lock) {
            throw new \moodle_exception('Unable to acquire skill analytics recompute lock.');
        }

        try {
            // Read-only phase: load, validate, extract slot facts, resolve skills, aggregate (no writes).
            $attemptdata = attempt_analyzer::extract_attempt_data($attemptid);
            $attempt = $attemptdata['attempt'];
            $rows = skill_aggregator::aggregate_attempt($attemptdata);

            $transaction = $DB->start_delegated_transaction();
            try {
                self::store_attempt_skills($attemptid, $rows);
                self::update_user_skills((int)$attempt->userid, (int)$attempt->quizid);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            manager::invalidate_user_cache((int)$attempt->courseid, (int)$attempt->userid);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param int $attemptid
     * @param array $rows
     * @return void
     */
    public static function store_attempt_skills(int $attemptid, array $rows): void {
        global $DB;

        $DB->delete_records(self::TABLE_ATTEMPT, ['attemptid' => $attemptid]);

        if (!$rows) {
            return;
        }

        $now = time();
        foreach ($rows as $row) {
            $record = (object)$row;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record(self::TABLE_ATTEMPT, $record);
        }
    }

    /**
     * Rebuild materialized per-quiz skill rows for one user using the quiz «Grading method» setting.
     *
     * @param int $userid
     * @param int $quizid
     * @return void
     */
    public static function update_user_skills(int $userid, int $quizid): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $DB->delete_records(self::TABLE_USER, [
            'userid' => $userid,
            'quizid' => $quizid,
            'aggregation_strategy' => self::STRATEGY_LATEST,
        ]);

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, course, grademethod', MUST_EXIST);
        $courseid = (int)$quiz->course;

        $attemptids = self::resolve_attempt_ids_for_quiz_grademethod($quiz, $userid);
        if ($attemptids === []) {
            return;
        }

        if (count($attemptids) === 1) {
            self::insert_user_skills_from_single_attempt($courseid, $userid, $quizid, (int)$attemptids[0]);
            return;
        }

        self::insert_user_skills_from_averaged_attempts($courseid, $userid, $quizid, $attemptids);
    }

    /**
     * Which finished attempts contribute for this user, following quiz.grademethod (mod_quiz constants).
     *
     * @param \stdClass $quiz quiz row with grademethod
     * @param int $userid
     * @return int[] attempt ids (one id, or all ids for QUIZ_GRADEAVERAGE)
     */
    public static function resolve_attempt_ids_for_quiz_grademethod(\stdClass $quiz, int $userid): array {
        global $DB;

        $attempts = $DB->get_records_sql(
            "SELECT id, sumgrades, attempt
               FROM {quiz_attempts}
              WHERE quiz = :quizid
                AND userid = :userid
                AND preview = 0
                AND state = :state
           ORDER BY attempt ASC, id ASC",
            [
                'quizid' => (int)$quiz->id,
                'userid' => $userid,
                'state' => \mod_quiz\quiz_attempt::FINISHED,
            ]
        );

        if ($attempts === []) {
            return [];
        }

        $list = array_values($attempts);
        $gm = (string)($quiz->grademethod ?? QUIZ_ATTEMPTLAST);

        switch ($gm) {
            case (string)QUIZ_ATTEMPTFIRST:
                return [(int)$list[0]->id];
            case (string)QUIZ_ATTEMPTLAST:
                return [(int)$list[count($list) - 1]->id];
            case (string)QUIZ_GRADEHIGHEST:
                return self::pick_highest_sumgrades_attempt_ids($list);
            case (string)QUIZ_GRADEAVERAGE:
                return array_map(static function(\stdClass $a): int {
                    return (int)$a->id;
                }, $list);
            default:
                return [(int)$list[count($list) - 1]->id];
        }
    }

    /**
     * @param array<int, \stdClass> $list ordered by attempt ASC
     * @return int[]
     */
    private static function pick_highest_sumgrades_attempt_ids(array $list): array {
        $best = null;
        foreach ($list as $a) {
            if ($a->sumgrades === null) {
                continue;
            }
            $sg = (float)$a->sumgrades;
            if ($best === null || $sg > (float)$best->sumgrades) {
                $best = $a;
            }
        }
        if ($best === null) {
            return [];
        }
        $maxsg = (float)$best->sumgrades;
        $tie = [];
        foreach ($list as $a) {
            if ($a->sumgrades === null) {
                continue;
            }
            if (abs((float)$a->sumgrades - $maxsg) < 1e-9) {
                $tie[] = (int)$a->id;
            }
        }
        if ($tie === []) {
            return [];
        }
        sort($tie);

        return [(int)end($tie)];
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @param int $quizid
     * @param int $attemptid
     * @return void
     */
    private static function insert_user_skills_from_single_attempt(int $courseid, int $userid, int $quizid, int $attemptid): void {
        global $DB;

        $attemptrows = $DB->get_records(self::TABLE_ATTEMPT, ['attemptid' => $attemptid], 'skillid ASC');
        if ($attemptrows === []) {
            return;
        }

        $now = time();
        foreach ($attemptrows as $row) {
            $record = (object)[
                'courseid' => $courseid,
                'quizid' => $quizid,
                'userid' => $userid,
                'skillid' => (int)$row->skillid,
                'skillname' => (string)$row->skillname,
                'source_attemptid' => $attemptid,
                'aggregation_strategy' => self::STRATEGY_LATEST,
                'earned' => (float)$row->earned,
                'maxearned' => (float)$row->maxearned,
                'percent' => skill_aggregator::compute_percent((float)$row->earned, (float)$row->maxearned),
                'questions_count' => (int)$row->questions_count,
                'attempts_count' => 1,
                'calculation_version' => (int)$row->calculation_version,
                'debugmeta' => $row->debugmeta,
                'timemodified' => $now,
            ];
            $DB->insert_record(self::TABLE_USER, $record);
        }
    }

    /**
     * Mean of per-attempt skill % across finished attempts (analogous to mean of sumgrades for the quiz grade).
     *
     * @param int $courseid
     * @param int $userid
     * @param int $quizid
     * @param int[] $attemptids
     * @return void
     */
    private static function insert_user_skills_from_averaged_attempts(int $courseid, int $userid, int $quizid, array $attemptids): void {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $rows = $DB->get_records_sql(
            "SELECT sar.skillid,
                    MAX(sar.skillname) AS skillname,
                    AVG(sar.percent) AS avgpercent,
                    MAX(sar.questions_count) AS questions_count,
                    MAX(sar.calculation_version) AS calculation_version
               FROM {" . self::TABLE_ATTEMPT . "} sar
              WHERE sar.attemptid {$insql}
           GROUP BY sar.skillid",
            $params
        );
        if ($rows === []) {
            return;
        }

        $now = time();
        $attemptcount = count($attemptids);
        $meta = json_encode([
            'quiz_grademethod' => 'average',
            'attempt_ids' => array_values($attemptids),
        ], JSON_UNESCAPED_UNICODE);

        foreach ($rows as $row) {
            $avgpercent = $row->avgpercent !== null ? round((float)$row->avgpercent, 2) : null;
            if ($avgpercent === null) {
                continue;
            }
            $record = (object)[
                'courseid' => $courseid,
                'quizid' => $quizid,
                'userid' => $userid,
                'skillid' => (int)$row->skillid,
                'skillname' => (string)$row->skillname !== '' ? (string)$row->skillname : 'skill',
                'source_attemptid' => 0,
                'aggregation_strategy' => self::STRATEGY_LATEST,
                'earned' => round($avgpercent, 5),
                'maxearned' => 100.0,
                'percent' => $avgpercent,
                'questions_count' => (int)$row->questions_count,
                'attempts_count' => $attemptcount,
                'calculation_version' => (int)$row->calculation_version,
                'debugmeta' => $meta,
                'timemodified' => $now,
            ];
            $DB->insert_record(self::TABLE_USER, $record);
        }
    }
}
