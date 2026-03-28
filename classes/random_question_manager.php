<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use core_question\local\bank\condition;
use mod_quiz\quiz_settings;

/**
 * Helpers for creating true Moodle random slots filtered by Skill Radar skills.
 */
class random_question_manager {
    /**
     * Get available explicit question pools for each course skill.
     *
     * Modern Moodle counts distinct question bank entries. Legacy fallback counts distinct question ids.
     *
     * @param int $courseid
     * @return array<int, array{definition:\stdClass, questioncount:int, categoryids:int[]}>
     */
    public static function get_available_skill_pools(int $courseid, int $bankcontextid = 0): array {
        global $DB;

        $definitions = manager::get_definitions($courseid);
        if ($courseid < 1 || $definitions === []) {
            return [];
        }

        $pools = [];
        foreach ($definitions as $definition) {
            $pools[(int)$definition->id] = [
                'definition' => $definition,
                'questioncount' => 0,
                'categoryids' => [],
            ];
        }

        $dbman = $DB->get_manager();
        $hasversions = $dbman->table_exists(new \xmldb_table('question_versions'));
        $hasentries = $dbman->table_exists(new \xmldb_table('question_bank_entries'));

        $allowedcategoryids = self::get_bank_category_ids($bankcontextid);
        $allowedcategories = array_flip($allowedcategoryids);

        if ($hasversions && $hasentries) {
            $params = [
                'courseid' => $courseid,
                'ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
                'randomqtype' => 'random',
            ];
            $rows = $DB->get_recordset_sql(
                "SELECT d.id AS skillid,
                        d.skill_key,
                        qv.questionbankentryid AS entryid,
                        qbe.questioncategoryid AS categoryid
                   FROM {" . manager::TABLE_DEF . "} d
                   LEFT JOIN {" . manager::TABLE_QMAP . "} m
                          ON m.courseid = d.courseid
                         AND m.skill_key = d.skill_key
                   LEFT JOIN {question_versions} qv
                          ON qv.questionid = m.questionid
                         AND qv.status = :ready
                   LEFT JOIN {question} q
                          ON q.id = qv.questionid
                   LEFT JOIN {question_bank_entries} qbe
                          ON qbe.id = qv.questionbankentryid
                  WHERE d.courseid = :courseid
                    AND (q.id IS NULL OR q.qtype <> :randomqtype)
               ORDER BY d.sortorder ASC, d.id ASC",
                $params
            );

            $seenentries = [];
            foreach ($rows as $row) {
                $skillid = (int)$row->skillid;
                if (!isset($pools[$skillid])) {
                    continue;
                }
                $entryid = (int)($row->entryid ?? 0);
                $categoryid = (int)($row->categoryid ?? 0);
                if ($allowedcategoryids !== [] && !isset($allowedcategories[$categoryid])) {
                    continue;
                }
                if ($entryid > 0 && !isset($seenentries[$skillid][$entryid])) {
                    $seenentries[$skillid][$entryid] = true;
                    $pools[$skillid]['questioncount']++;
                }
                if ($categoryid > 0) {
                    $pools[$skillid]['categoryids'][$categoryid] = $categoryid;
                }
            }
            $rows->close();
        } else {
            $questioncolumns = $DB->get_columns('question');
            if (isset($questioncolumns['category'])) {
                $rows = $DB->get_recordset_sql(
                    "SELECT d.id AS skillid,
                            q.id AS questionid,
                            q.category AS categoryid
                       FROM {" . manager::TABLE_DEF . "} d
                       LEFT JOIN {" . manager::TABLE_QMAP . "} m
                              ON m.courseid = d.courseid
                             AND m.skill_key = d.skill_key
                       LEFT JOIN {question} q
                              ON q.id = m.questionid
                      WHERE d.courseid = :courseid
                        AND (q.id IS NULL OR q.qtype <> :randomqtype)
                   ORDER BY d.sortorder ASC, d.id ASC",
                    ['courseid' => $courseid, 'randomqtype' => 'random']
                );

                $seenquestions = [];
                foreach ($rows as $row) {
                    $skillid = (int)$row->skillid;
                    if (!isset($pools[$skillid])) {
                        continue;
                    }
                    $questionid = (int)($row->questionid ?? 0);
                    $categoryid = (int)($row->categoryid ?? 0);
                    if ($allowedcategoryids !== [] && !isset($allowedcategories[$categoryid])) {
                        continue;
                    }
                    if ($questionid > 0 && !isset($seenquestions[$skillid][$questionid])) {
                        $seenquestions[$skillid][$questionid] = true;
                        $pools[$skillid]['questioncount']++;
                    }
                    if ($categoryid > 0) {
                        $pools[$skillid]['categoryids'][$categoryid] = $categoryid;
                    }
                }
                $rows->close();
            }
        }

        foreach ($pools as $skillid => $pool) {
            $pools[$skillid]['categoryids'] = array_values($pool['categoryids']);
        }

        if (class_exists(\qbank_skillradar\skill_condition::class)) {
            foreach ($pools as $skillid => $pool) {
                $definition = $pool['definition'];
                [$where, $params] = \qbank_skillradar\skill_condition::build_query_from_filter([
                    'values' => [(string)$definition->skill_key],
                    'jointype' => \core\output\datafilter::JOINTYPE_ANY,
                    'filteroptions' => [
                        'courseid' => $courseid,
                        'bankcontextid' => $bankcontextid,
                    ],
                ]);
                if ($where === '') {
                    $pools[$skillid]['questioncount'] = 0;
                    continue;
                }
                $pools[$skillid]['questioncount'] = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT q.id)
                       FROM {question} q
                      WHERE {$where}",
                    $params
                );
            }
        }

        return $pools;
    }

    /**
     * Build a standard Moodle random-question filter condition for one skill.
     *
     * @param int $courseid
     * @param string $skillkey
     * @param int[] $categoryids
     * @return array<string, mixed>
     */
    public static function build_skill_filter_condition(
        int $courseid,
        string $skillkey,
        array $categoryids,
        int $bankcontextid = 0
    ): array {
        $categoryids = array_values(array_unique(array_filter(array_map('intval', $categoryids))));
        return [
            'filter' => [
                'category' => [
                    'jointype' => condition::JOINTYPE_DEFAULT,
                    'values' => $categoryids,
                    'filteroptions' => ['includesubcategories' => false],
                ],
                'skillradar' => [
                    'jointype' => condition::JOINTYPE_DEFAULT,
                    'values' => [$skillkey],
                    'filteroptions' => [
                        'courseid' => $courseid,
                        'bankcontextid' => $bankcontextid,
                    ],
                ],
            ],
        ];
    }

    /**
     * Validate requested random-slot quotas against current skill pools.
     *
     * @param int $courseid
     * @param array<string, int> $skillcounts skill_key => count
     * @return array<string, string> skill_key => error message
     */
    public static function validate_skill_quotas(int $courseid, array $skillcounts, int $bankcontextid = 0): array {
        return self::validate_skill_quotas_for_quiz($courseid, 0, $skillcounts, $bankcontextid);
    }

    /**
     * Validate requested random-slot quotas against current skill pools and existing quiz contents.
     *
     * @param int $courseid
     * @param int $quizid
     * @param array<string, int> $skillcounts skill_key => count
     * @return array<string, string> skill_key => error message
     */
    public static function validate_skill_quotas_for_quiz(
        int $courseid,
        int $quizid,
        array $skillcounts,
        int $bankcontextid = 0
    ): array {
        $pools = self::get_available_skill_pools($courseid, $bankcontextid);
        $bykey = [];
        foreach ($pools as $pool) {
            $definition = $pool['definition'];
            $bykey[(string)$definition->skill_key] = $pool;
        }

        $existingdirectquestionids = [];
        $existingrandomslots = [];
        if ($quizid > 0) {
            [$existingdirectquestionids, $existingrandomslots] = self::get_existing_quiz_skill_usage($quizid);
        }

        $errors = [];
        foreach ($skillcounts as $skillkey => $count) {
            $count = (int)$count;
            if ($count < 1) {
                continue;
            }
            if (!isset($bykey[$skillkey])) {
                $errors[$skillkey] = get_string('randomskill_error_invalidskill', 'local_skillradar', $skillkey);
                continue;
            }
            $pool = $bykey[$skillkey];
            if ($pool['categoryids'] === []) {
                $errors[$skillkey] = get_string(
                    'randomskill_error_nocategory',
                    'local_skillradar',
                    format_string($pool['definition']->displayname)
                );
                continue;
            }

            $available = (int)$pool['questioncount'];
            if ($quizid > 0) {
                $available = self::get_effective_available_question_count(
                    $courseid,
                    $skillkey,
                    $existingdirectquestionids,
                    $bankcontextid
                );
                $available -= (int)($existingrandomslots[$skillkey] ?? 0);
            }

            if ($available < $count) {
                $a = (object)[
                    'skill' => format_string($pool['definition']->displayname),
                    'requested' => $count,
                    'available' => max(0, $available),
                ];
                $errors[$skillkey] = get_string('randomskill_error_shortage', 'local_skillradar', $a);
            }
        }

        return $errors;
    }

    /**
     * Add true Moodle random slots grouped by skill.
     *
     * @param int $cmid
     * @param array<string, int> $skillcounts skill_key => count
     * @param int $addonpage
     * @return array{totalslots:int, usedskills:int}
     */
    public static function add_random_questions_to_quiz(
        int $cmid,
        array $skillcounts,
        int $addonpage = 0,
        int $bankcontextid = 0
    ): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $structure = quiz_settings::create($quiz->id)->get_structure();

        $errors = self::validate_skill_quotas_for_quiz((int)$course->id, (int)$quiz->id, $skillcounts, $bankcontextid);
        if ($errors !== []) {
            throw new \moodle_exception(implode("\n", array_values($errors)));
        }

        $pools = self::get_available_skill_pools((int)$course->id, $bankcontextid);
        $bykey = [];
        foreach ($pools as $pool) {
            $definition = $pool['definition'];
            $bykey[(string)$definition->skill_key] = $pool;
        }

        $totalslots = 0;
        $usedskills = 0;
        foreach ($skillcounts as $skillkey => $count) {
            $count = (int)$count;
            if ($count < 1 || empty($bykey[$skillkey])) {
                continue;
            }
            $pool = $bykey[$skillkey];
            $filtercondition = self::build_skill_filter_condition(
                (int)$course->id,
                $skillkey,
                $pool['categoryids'],
                $bankcontextid
            );
            $structure->add_random_questions($addonpage, $count, $filtercondition);
            $totalslots += $count;
            $usedskills++;
        }

        if ($totalslots > 0) {
            quiz_delete_previews($quiz);
            quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            manager::invalidate_course_cache((int)$course->id);
        }

        return [
            'totalslots' => $totalslots,
            'usedskills' => $usedskills,
        ];
    }

    /**
     * @param int $quizid
     * @return array{0:int[],1:array<string,int>}
     */
    private static function get_existing_quiz_skill_usage(int $quizid): array {
        global $DB;

        $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, MUST_EXIST);
        $quizcontext = \context_module::instance((int)$cm->id);
        $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $quizcontext);

        $directquestionids = [];
        $randomslots = [];
        foreach ($slots as $slot) {
            if (($slot->qtype ?? '') === 'random') {
                $skillkey = $slot->filtercondition['filter']['skillradar']['values'][0] ?? null;
                if (is_string($skillkey) && $skillkey !== '') {
                    $randomslots[$skillkey] = ($randomslots[$skillkey] ?? 0) + 1;
                }
                continue;
            }
            $questionid = (int)($slot->questionid ?? 0);
            if ($questionid > 0) {
                $directquestionids[$questionid] = $questionid;
            }
        }

        return [array_values($directquestionids), $randomslots];
    }

    /**
     * Count questions still available to new random slots after excluding fixed questions already in the quiz.
     *
     * @param int $courseid
     * @param string $skillkey
     * @param int[] $excludedquestionids
     * @return int
     */
    private static function get_effective_available_question_count(
        int $courseid,
        string $skillkey,
        array $excludedquestionids,
        int $bankcontextid = 0
    ): int {
        global $DB;

        if (!class_exists(\qbank_skillradar\skill_condition::class)) {
            return 0;
        }

        [$where, $params] = \qbank_skillradar\skill_condition::build_query_from_filter([
            'values' => [$skillkey],
            'jointype' => \core\output\datafilter::JOINTYPE_ANY,
            'filteroptions' => [
                'courseid' => $courseid,
                'bankcontextid' => $bankcontextid,
            ],
        ]);
        if ($where === '') {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT q.id)
                  FROM {question} q
                 WHERE {$where}";
        if ($excludedquestionids !== []) {
            [$notsql, $notparams] = $DB->get_in_or_equal(array_values(array_map('intval', $excludedquestionids)), SQL_PARAMS_NAMED, 'srex', false);
            $sql .= " AND q.id {$notsql}";
            $params += $notparams;
        }

        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Get question bank categories that belong to one bank context.
     *
     * @param int $bankcontextid
     * @return int[]
     */
    public static function get_bank_category_ids(int $bankcontextid): array {
        global $DB;

        if ($bankcontextid < 1) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_select(
            'question_categories',
            'id',
            'contextid = :contextid AND parent <> 0',
            ['contextid' => $bankcontextid]
        ));
    }

    /**
     * Get banks available for authoring random-by-skill questions in this course.
     *
     * @param int $courseid
     * @return array<int, \stdClass>
     */
    public static function get_available_banks(int $courseid): array {
        $shared = \core_question\local\bank\question_bank_helper::get_activity_instances_with_shareable_questions(
            [$courseid],
            [],
            ['moodle/question:useall'],
            true
        );
        $private = \core_question\local\bank\question_bank_helper::get_activity_instances_with_private_questions(
            [$courseid],
            [],
            ['moodle/question:useall'],
            true
        );

        $banks = [];
        foreach (array_merge($shared, $private) as $bank) {
            $banks[(int)$bank->contextid] = $bank;
        }
        ksort($banks);
        return $banks;
    }
}
