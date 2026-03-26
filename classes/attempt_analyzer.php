<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/question/engine/datalib.php');
require_once($GLOBALS['CFG']->dirroot . '/question/engine/states.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/locallib.php');

/**
 * Loads one quiz attempt and emits normalized per-slot facts.
 *
 * Weight is always quiz_slots.maxmark for the quiz/slot (never question bank default or qa.maxmark alone).
 * Skill is resolved only via {@see skill_service} from the delivered {@see question_attempts}.questionid.
 */
class attempt_analyzer {
    /** @var string[] latest-step states accepted as graded/finalized outcomes. */
    private const GRADED_STATES = [
        'gradedwrong',
        'gradedpartial',
        'gradedright',
        'gaveup',
        'mangrwrong',
        'mangrpartial',
        'mangrright',
        'mangaveup',
    ];

    /**
     * @param int $attemptid
     * @return array{attempt:\stdClass, slots:array}
     */
    public static function extract_attempt_data(int $attemptid): array {
        global $DB;

        $sql = "SELECT qa.id,
                       qa.quiz AS quizid,
                       qa.userid,
                       qa.uniqueid,
                       qa.state,
                       qa.preview,
                       q.course AS courseid
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                 WHERE qa.id = :attemptid";
        $attempt = $DB->get_record_sql($sql, ['attemptid' => $attemptid], MUST_EXIST);

        if (!self::is_attempt_eligible($attempt)) {
            return ['attempt' => $attempt, 'slots' => []];
        }

        // Authoritative slot weights from quiz structure (not question_attempt.maxmark in isolation).
        $slotweights = $DB->get_records_menu('quiz_slots', ['quizid' => $attempt->quizid], '', 'slot, maxmark');
        if (!$slotweights) {
            return ['attempt' => $attempt, 'slots' => []];
        }

        $dm = new \question_engine_data_mapper();
        $lateststeps = $dm->load_questions_usages_latest_steps(new \qubaid_list([(int)$attempt->uniqueid]));

        $rows = [];
        foreach ($lateststeps as $step) {
            $slot = (int)$step->slot;
            if (!isset($slotweights[$slot])) {
                continue;
            }
            $slotmaxmark = (float)$slotweights[$slot];

            $state = (string)$step->state;
            $pendinggrade = self::is_pending_manual_grade($state);
            if (!self::is_question_step_eligible($state, $step->fraction) && !$pendinggrade) {
                continue;
            }

            $skill = skill_service::get_question_skill((int)$step->questionid, (int)$attempt->courseid);
            if ($skill === null) {
                continue;
            }

            if ($pendinggrade) {
                // Finished attempt but question not graded yet (e.g. essay): still weight the slot in skill %.
                $fraction = 0.0;
                $earned = 0.0;
            } else {
                $fraction = (float)$step->fraction;
                $earned = $fraction * $slotmaxmark;
            }

            $rows[] = [
                'attemptid' => (int)$attempt->id,
                'quizid' => (int)$attempt->quizid,
                'courseid' => (int)$attempt->courseid,
                'userid' => (int)$attempt->userid,
                'slot' => $slot,
                'questionid' => (int)$step->questionid,
                'skillid' => (int)$skill->skillid,
                'skillname' => (string)$skill->skillname,
                'fraction' => $fraction,
                'earned' => $earned,
                'maxearned' => $slotmaxmark,
                'state' => $state,
            ];
        }

        return ['attempt' => $attempt, 'slots' => $rows];
    }

    /**
     * @param \stdClass $attempt
     * @return bool
     */
    public static function is_attempt_eligible(\stdClass $attempt): bool {
        if (!empty($attempt->preview)) {
            return false;
        }
        return $attempt->state === \mod_quiz\quiz_attempt::FINISHED;
    }

    /**
     * @param string $state
     * @param mixed $fraction
     * @return bool
     */
    private static function is_question_step_eligible(string $state, $fraction): bool {
        if ($fraction === null || !in_array($state, self::GRADED_STATES, true)) {
            return false;
        }

        $questionstate = \question_state::get($state);
        if ($questionstate === null) {
            return false;
        }

        $summary = $questionstate->get_summary_state();
        return $summary === 'autograded' || $summary === 'manuallygraded';
    }

    /**
     * Essay / file responses etc.: submitted in a finished attempt but teacher has not graded yet.
     */
    private static function is_pending_manual_grade(string $state): bool {
        return $state === 'needsgrading';
    }
}
