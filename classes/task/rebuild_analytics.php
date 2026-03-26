<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Repair/backfill task for finished attempts missing analytics.
 */
class rebuild_analytics extends \core\task\scheduled_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrebuildanalytics', 'local_skillradar');
    }

    /**
     * @return void
     */
    public function execute(): void {
        global $DB;

        $sql = "SELECT qa.id
                  FROM {quiz_attempts} qa
             LEFT JOIN {local_skill_attempt_result} sar ON sar.attemptid = qa.id
                 WHERE qa.preview = 0
                   AND qa.state = :state
                   AND sar.id IS NULL
              ORDER BY qa.timefinish ASC, qa.id ASC";
        $attemptids = $DB->get_fieldset_sql($sql, ['state' => \mod_quiz\quiz_attempt::FINISHED], 0, 200);

        foreach ($attemptids as $attemptid) {
            try {
                \local_skillradar\cache_manager::recompute_attempt((int)$attemptid);
            } catch (\Throwable $e) {
                mtrace('local_skillradar rebuild failed for attempt ' . (int)$attemptid . ': ' . $e->getMessage());
            }
        }
    }
}
