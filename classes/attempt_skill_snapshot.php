<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Persists the first-resolved skill per (quiz attempt, delivered question) so later retags do not rewrite history.
 *
 * Moodle keeps graded outcomes on the attempt; this table freezes which skill axis those marks fed at first materialization.
 */
class attempt_skill_snapshot {
    public const TABLE = 'local_skill_attempt_qskill';

    /**
     * @param int $attemptid
     * @return array<int, \stdClass> questionid => { skillid, skillname }
     */
    public static function get_map_for_attempt(int $attemptid): array {
        global $DB;

        if ($attemptid < 1) {
            return [];
        }
        if (!self::table_exists()) {
            return [];
        }

        $records = $DB->get_records(self::TABLE, ['attemptid' => $attemptid], '', 'questionid, skillid, skillname');
        $out = [];
        foreach ($records as $r) {
            $qid = (int)$r->questionid;
            $out[$qid] = (object)[
                'skillid' => (int)$r->skillid,
                'skillname' => (string)$r->skillname,
            ];
        }
        return $out;
    }

    /**
     * Inserts rows only when that (attemptid, questionid) pair is not yet frozen.
     *
     * @param int $attemptid
     * @param array<int, array{questionid:int, skillid:int, skillname:string}> $rows
     * @return void
     */
    public static function insert_new(int $attemptid, array $rows): void {
        global $DB;

        if ($attemptid < 1 || $rows === [] || !self::table_exists()) {
            return;
        }

        $now = time();
        foreach ($rows as $row) {
            $qid = (int)($row['questionid'] ?? 0);
            if ($qid < 1) {
                continue;
            }
            try {
                $DB->insert_record(self::TABLE, (object)[
                    'attemptid' => $attemptid,
                    'questionid' => $qid,
                    'skillid' => (int)($row['skillid'] ?? 0),
                    'skillname' => (string)($row['skillname'] ?? ''),
                    'timecreated' => $now,
                ]);
            } catch (\dml_exception $e) {
                if ($DB->record_exists(self::TABLE, ['attemptid' => $attemptid, 'questionid' => $qid])) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * @return bool
     */
    public static function table_exists(): bool {
        global $DB;
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $exists = $DB->get_manager()->table_exists(new \xmldb_table(self::TABLE));
        return $exists;
    }
}
