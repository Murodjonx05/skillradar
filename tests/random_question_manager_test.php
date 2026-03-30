<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(random_question_manager::class)]
final class random_question_manager_test extends \advanced_testcase {
    public function test_available_skill_pools_count_distinct_candidates(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $pools = random_question_manager::get_available_skill_pools((int)$ctx['course']->id);
        $bykey = $this->index_pools_by_key($pools);

        $this->assertSame(2, (int)$bykey['alpha']['questioncount']);
        $this->assertSame(1, (int)$bykey['beta']['questioncount']);
        $this->assertNotEmpty($bykey['alpha']['categoryids']);
        $this->assertNotEmpty($bykey['beta']['categoryids']);
    }

    public function test_validate_skill_quotas_blocks_shortage(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $errors = random_question_manager::validate_skill_quotas((int)$ctx['course']->id, [
            'alpha' => 3,
            'beta' => 1,
        ]);

        $this->assertArrayHasKey('alpha', $errors);
        $this->assertArrayNotHasKey('beta', $errors);
    }

    public function test_validate_skill_quotas_allows_exact_pool_size(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $errors = random_question_manager::validate_skill_quotas((int)$ctx['course']->id, [
            'alpha' => 2,
            'beta' => 1,
        ]);

        $this->assertSame([], $errors);
    }

    public function test_validate_skill_quotas_for_quiz_excludes_fixed_questions_already_in_quiz(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        quiz_add_quiz_question((int)$ctx['alphaq1']->id, $ctx['quiz']);

        $errors = random_question_manager::validate_skill_quotas_for_quiz((int)$ctx['course']->id, (int)$ctx['quiz']->id, [
            'alpha' => 2,
        ]);

        $this->assertArrayHasKey('alpha', $errors);
    }

    public function test_available_skill_pools_can_be_scoped_to_one_bank_context(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_split_bank_contexts();
        $pools = random_question_manager::get_available_skill_pools((int)$ctx['course']->id, (int)$ctx['bankcontextid']);
        $bykey = $this->index_pools_by_key($pools);

        $this->assertSame(1, (int)$bykey['alpha']['questioncount']);
        $this->assertSame(0, (int)$bykey['beta']['questioncount']);
    }

    public function test_validate_skill_quotas_rejects_bank_context_outside_course_allowlist(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_split_bank_contexts();
        $other = $this->create_external_bank_context();
        $errors = random_question_manager::validate_skill_quotas(
            (int)$ctx['course']->id,
            ['alpha' => 1],
            (int)$other['bankcontextid']
        );

        $this->assertArrayHasKey('_form', $errors);
    }

    public function test_add_random_questions_to_quiz_rejects_bank_context_outside_course_allowlist(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $other = $this->create_external_bank_context();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('randomskill_error_invalidbank', 'local_skillradar'));
        random_question_manager::add_random_questions_to_quiz(
            (int)$ctx['cm']->id,
            ['alpha' => 1],
            0,
            (int)$other['bankcontextid']
        );
    }

    public function test_add_random_questions_to_quiz_creates_expected_slots_and_filters(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $this->setAdminUser();
        $result = random_question_manager::add_random_questions_to_quiz((int)$ctx['cm']->id, [
            'alpha' => 2,
            'beta' => 1,
        ]);

        $this->assertSame(3, (int)$result['totalslots']);
        $this->assertSame(2, (int)$result['usedskills']);

        $refs = $DB->get_records_sql(
            "SELECT qsr.id, qsr.filtercondition
               FROM {question_set_references} qsr
               JOIN {quiz_slots} qs ON qs.id = qsr.itemid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot ASC",
            ['quizid' => (int)$ctx['quiz']->id]
        );

        $this->assertCount(3, $refs);
        $skills = [];
        foreach ($refs as $ref) {
            $filtercondition = json_decode((string)$ref->filtercondition, true);
            $this->assertNotEmpty($filtercondition['filter']['category']['values'] ?? []);
            $skillvalues = $filtercondition['filter']['skillradar']['values'] ?? [];
            $this->assertCount(1, $skillvalues);
            $skills[] = $skillvalues[0];
        }
        sort($skills);
        $this->assertSame(['alpha', 'alpha', 'beta'], $skills);
    }

    public function test_zero_count_skills_are_ignored(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $this->setAdminUser();
        $result = random_question_manager::add_random_questions_to_quiz((int)$ctx['cm']->id, [
            'alpha' => 0,
            'beta' => 1,
        ]);

        $this->assertSame(1, (int)$result['totalslots']);
        $this->assertSame(1, (int)$result['usedskills']);
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => (int)$ctx['quiz']->id]));
    }

    public function test_random_skill_slots_expose_multiple_candidates_for_same_skill(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $this->setAdminUser();
        random_question_manager::add_random_questions_to_quiz((int)$ctx['cm']->id, [
            'alpha' => 1,
            'beta' => 1,
        ]);

        $quizcontext = \context_module::instance((int)$ctx['cm']->id);
        $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure((int)$ctx['quiz']->id, $quizcontext);
        $this->assertCount(2, $slots);

        $loader = new \core_question\local\bank\random_question_loader(new \qubaid_list([]));
        $alphafilter = $slots[1]->filtercondition['filter'];
        $betafilter = $slots[2]->filtercondition['filter'];

        $alphacandidates = array_keys($loader->get_filtered_questions($alphafilter));
        sort($alphacandidates);
        $betacandidates = array_keys($loader->get_filtered_questions($betafilter));
        sort($betacandidates);

        $this->assertSame([(int)$ctx['alphaq1']->id, (int)$ctx['alphaq2']->id], array_map('intval', $alphacandidates));
        $this->assertSame([(int)$ctx['betaq1']->id], array_map('intval', $betacandidates));

        $firstdraw = $loader->get_next_filtered_question_id($alphafilter);
        $seconddraw = $loader->get_next_filtered_question_id($alphafilter);
        $this->assertNotNull($firstdraw);
        $this->assertNotNull($seconddraw);
        $this->assertNotSame((int)$firstdraw, (int)$seconddraw);
    }

    public function test_started_attempt_uses_questions_from_matching_skill_pool(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_skill_pools();
        $this->setAdminUser();
        random_question_manager::add_random_questions_to_quiz((int)$ctx['cm']->id, [
            'alpha' => 1,
            'beta' => 1,
        ]);

        $selected = $this->start_attempt_and_get_slot_question_ids($ctx['quiz'], $ctx['student']);

        $this->assertContains((int)$selected[1], [(int)$ctx['alphaq1']->id, (int)$ctx['alphaq2']->id]);
        $this->assertSame((int)$ctx['betaq1']->id, (int)$selected[2]);
    }

    public function test_add_random_questions_to_quiz_creates_large_mixed_quota_configuration(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_quiz_with_many_skill_pools([
            'skill1' => 10,
            'skill2' => 20,
            'skill3' => 10,
            'skill4' => 10,
        ]);

        $this->setAdminUser();
        $result = random_question_manager::add_random_questions_to_quiz((int)$ctx['cm']->id, [
            'skill1' => 10,
            'skill2' => 20,
            'skill3' => 10,
            'skill4' => 10,
        ]);

        $this->assertSame(50, (int)$result['totalslots']);
        $this->assertSame(4, (int)$result['usedskills']);
        $quizcontext = \context_module::instance((int)$ctx['cm']->id);
        $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure((int)$ctx['quiz']->id, $quizcontext);

        $this->assertCount(50, $slots);
        $counts = [];
        foreach ($slots as $slot) {
            $skillkey = $slot->filtercondition['filter']['skillradar']['values'][0] ?? '';
            $counts[$skillkey] = ($counts[$skillkey] ?? 0) + 1;
        }

        ksort($counts);
        $this->assertSame([
            'skill1' => 10,
            'skill2' => 20,
            'skill3' => 10,
            'skill4' => 10,
        ], $counts);
    }

    /**
     * @param array<int, array{definition:\stdClass, questioncount:int, categoryids:int[]}> $pools
     * @return array<string, array{definition:\stdClass, questioncount:int, categoryids:int[]}>
     */
    private function index_pools_by_key(array $pools): array {
        $bykey = [];
        foreach ($pools as $pool) {
            $bykey[(string)$pool['definition']->skill_key] = $pool;
        }
        return $bykey;
    }

    /**
     * @return array<string, mixed>
     */
    private function create_quiz_with_skill_pools(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $questiongenerator = $generator->get_plugin_generator('core_question');
        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');

        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user((int)$student->id, (int)$course->id, 'student');

        $quiz = $quizgenerator->create_instance([
            'course' => (int)$course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 2,
        ]);
        $cm = get_coursemodule_from_instance('quiz', (int)$quiz->id, (int)$course->id, false, MUST_EXIST);
        $coursecontext = \context_course::instance((int)$course->id);

        $alphacat = $questiongenerator->create_question_category(['contextid' => (int)$coursecontext->id]);
        $betacat = $questiongenerator->create_question_category(['contextid' => (int)$coursecontext->id]);

        $alphaq1 = $questiongenerator->create_question('shortanswer', null, ['category' => $alphacat->id]);
        $alphaq2 = $questiongenerator->create_question('numerical', null, ['category' => $alphacat->id]);
        $betaq1 = $questiongenerator->create_question('truefalse', null, ['category' => $betacat->id]);

        $now = time();
        $defalphaid = (int)$DB->insert_record(manager::TABLE_DEF, (object)[
            'courseid' => (int)$course->id,
            'skill_key' => 'alpha',
            'displayname' => 'Alpha',
            'color' => '#ff0000',
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $defbetaid = (int)$DB->insert_record(manager::TABLE_DEF, (object)[
            'courseid' => (int)$course->id,
            'skill_key' => 'beta',
            'displayname' => 'Beta',
            'color' => '#00ff00',
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        manager::replace_question_skill_mappings((int)$course->id, [
            ['questionid' => (int)$alphaq1->id, 'skill_key' => 'alpha'],
            ['questionid' => (int)$alphaq2->id, 'skill_key' => 'alpha'],
            ['questionid' => (int)$betaq1->id, 'skill_key' => 'beta'],
        ]);

        return [
            'course' => $course,
            'cm' => $cm,
            'quiz' => $quiz,
            'student' => $student,
            'defalpha' => $DB->get_record(manager::TABLE_DEF, ['id' => $defalphaid], '*', MUST_EXIST),
            'defbeta' => $DB->get_record(manager::TABLE_DEF, ['id' => $defbetaid], '*', MUST_EXIST),
            'alphaq1' => $alphaq1,
            'alphaq2' => $alphaq2,
            'betaq1' => $betaq1,
        ];
    }

    /**
     * @param array<string, int> $skillcounts
     * @return array<string, mixed>
     */
    private function create_quiz_with_many_skill_pools(array $skillcounts): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $questiongenerator = $generator->get_plugin_generator('core_question');
        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');

        $course = $generator->create_course();
        $quiz = $quizgenerator->create_instance([
            'course' => (int)$course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => array_sum($skillcounts),
        ]);
        $cm = get_coursemodule_from_instance('quiz', (int)$quiz->id, (int)$course->id, false, MUST_EXIST);
        $coursecontext = \context_course::instance((int)$course->id);
        $now = time();

        $mappings = [];
        foreach ($skillcounts as $index => $count) {
            $definition = (object)[
                'courseid' => (int)$course->id,
                'skill_key' => (string)$index,
                'displayname' => ucfirst((string)$index),
                'color' => '#3366cc',
                'sortorder' => count($mappings) + 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record(manager::TABLE_DEF, $definition);

            $category = $questiongenerator->create_question_category(['contextid' => (int)$coursecontext->id]);
            for ($i = 0; $i < $count; $i++) {
                $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
                $mappings[] = [
                    'questionid' => (int)$question->id,
                    'skill_key' => (string)$index,
                ];
            }
        }

        manager::replace_question_skill_mappings((int)$course->id, $mappings);

        return [
            'course' => $course,
            'quiz' => $quiz,
            'cm' => $cm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function create_course_with_split_bank_contexts(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $qbankgenerator = $generator->get_plugin_generator('mod_qbank');

        $course = $generator->create_course();
        $coursecontext = \context_course::instance((int)$course->id);
        $bank = $qbankgenerator->create_instance(['course' => (int)$course->id]);
        $bankcontext = \context_module::instance((int)$bank->cmid);
        $quizcategory = $questiongenerator->create_question_category(['contextid' => (int)$coursecontext->id]);
        $bankcategory = $questiongenerator->create_question_category(['contextid' => (int)$bankcontext->id]);

        $quizalpha = $questiongenerator->create_question('truefalse', null, ['category' => $quizcategory->id]);
        $bankalpha = $questiongenerator->create_question('truefalse', null, ['category' => $bankcategory->id]);
        $quizbeta = $questiongenerator->create_question('truefalse', null, ['category' => $quizcategory->id]);

        $now = time();
        $DB->insert_record(manager::TABLE_DEF, (object)[
            'courseid' => (int)$course->id,
            'skill_key' => 'alpha',
            'displayname' => 'Alpha',
            'color' => '#ff0000',
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record(manager::TABLE_DEF, (object)[
            'courseid' => (int)$course->id,
            'skill_key' => 'beta',
            'displayname' => 'Beta',
            'color' => '#00ff00',
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        manager::replace_question_skill_mappings((int)$course->id, [
            ['questionid' => (int)$quizalpha->id, 'skill_key' => 'alpha'],
            ['questionid' => (int)$bankalpha->id, 'skill_key' => 'alpha'],
            ['questionid' => (int)$quizbeta->id, 'skill_key' => 'beta'],
        ]);

        return [
            'course' => $course,
            'bankcontextid' => (int)$bankcontext->id,
            'quizalpha' => $quizalpha,
            'bankalpha' => $bankalpha,
            'quizbeta' => $quizbeta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function create_external_bank_context(): array {
        $generator = $this->getDataGenerator();
        $qbankgenerator = $generator->get_plugin_generator('mod_qbank');

        $course = $generator->create_course();
        $bank = $qbankgenerator->create_instance(['course' => (int)$course->id]);
        $bankcontext = \context_module::instance((int)$bank->cmid);

        return [
            'course' => $course,
            'bankcontextid' => (int)$bankcontext->id,
        ];
    }

    /**
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @return array<int, int>
     */
    private function start_attempt_and_get_slot_question_ids(\stdClass $quiz, \stdClass $user): array {
        global $DB;

        $this->setUser($user);
        $attemptnumber = (int)$DB->count_records('quiz_attempts', [
            'quiz' => (int)$quiz->id,
            'userid' => (int)$user->id,
        ]) + 1;
        $timenow = time();
        $quizobj = \mod_quiz\quiz_settings::create((int)$quiz->id, (int)$user->id);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $attempt = \quiz_create_attempt($quizobj, $attemptnumber, null, $timenow, false, (int)$user->id);
        \quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow);
        \quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
        $selected = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $selected[(int)$slot] = (int)$attemptobj->get_question_attempt((int)$slot)->get_question_id();
        }

        $this->setUser();
        return $selected;
    }
}
