<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Writes deterministic analytics rows.
 */
class cache_manager {
    public const TABLE_ATTEMPT = 'local_skill_attempt_result';
    public const TABLE_USER = 'local_skill_user_result';
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
            self::store_attempt_skills($attemptid, $rows);
            self::update_user_skills((int)$attempt->userid, (int)$attempt->quizid);
            $transaction->allow_commit();

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
     * Rebuild the materialized latest-attempt-per-quiz facts for one user and quiz.
     *
     * @param int $userid
     * @param int $quizid
     * @return void
     */
    public static function update_user_skills(int $userid, int $quizid): void {
        global $DB;

        $DB->delete_records(self::TABLE_USER, [
            'userid' => $userid,
            'quizid' => $quizid,
            'aggregation_strategy' => self::STRATEGY_LATEST,
        ]);

        $sql = "SELECT qa.id,
                       qa.quiz AS quizid,
                       qa.userid,
                       q.course AS courseid
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                 WHERE qa.quiz = :quizid
                   AND qa.userid = :userid
                   AND qa.preview = 0
                   AND qa.state = :state
              ORDER BY qa.timefinish DESC, qa.timemodified DESC, qa.id DESC";
        $latest = $DB->get_record_sql($sql, [
            'quizid' => $quizid,
            'userid' => $userid,
            'state' => \mod_quiz\quiz_attempt::FINISHED,
        ], IGNORE_MULTIPLE);

        if (!$latest) {
            return;
        }

        $attemptrows = $DB->get_records(self::TABLE_ATTEMPT, ['attemptid' => $latest->id], 'skillid ASC');
        if (!$attemptrows) {
            return;
        }

        $now = time();
        foreach ($attemptrows as $row) {
            $record = (object)[
                'courseid' => (int)$latest->courseid,
                'quizid' => (int)$quizid,
                'userid' => (int)$userid,
                'skillid' => (int)$row->skillid,
                'skillname' => (string)$row->skillname,
                'source_attemptid' => (int)$latest->id,
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
}
