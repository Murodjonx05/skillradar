<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;
use question_engine;

#[CoversClass(grade_provider::class)]
#[CoversClass(cache_manager::class)]
#[CoversClass(manager::class)]
final class course_skill_flow_test extends \advanced_testcase {
    /**
     * One quiz, two questions → two skills at 100%; course + single-quiz payloads agree on that quiz’s row.
     */
    public function test_multi_skill_quiz_materialized_percent_and_quiz_scope(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $defalpha = $ctx['def_alpha'];
        $defbeta = $ctx['def_beta'];
        $q1 = $ctx['q1'];
        $q2 = $ctx['q2'];

        $aid = $this->finish_attempt_answer_slots($quiz, $student, [
            1 => $this->correct_answer_for_question($q1->id),
            2 => $this->correct_answer_for_question($q2->id),
        ]);
        cache_manager::recompute_attempt($aid);

        $rowa = $DB->get_record(cache_manager::TABLE_USER, [
            'userid' => $student->id,
            'quizid' => $quiz->id,
            'skillid' => $defalpha,
            'aggregation_strategy' => cache_manager::STRATEGY_LATEST,
        ], '*', MUST_EXIST);
        $rowb = $DB->get_record(cache_manager::TABLE_USER, [
            'userid' => $student->id,
            'quizid' => $quiz->id,
            'skillid' => $defbeta,
            'aggregation_strategy' => cache_manager::STRATEGY_LATEST,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(100.0, (float)$rowa->percent, 0.01);
        $this->assertEqualsWithDelta(100.0, (float)$rowb->percent, 0.01);

        $coursepayload = grade_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $bykey = $this->index_detail_by_skill_key($coursepayload['skills_detail'] ?? []);
        $this->assertArrayHasKey('alpha', $bykey);
        $this->assertArrayHasKey('beta', $bykey);
        $this->assertEqualsWithDelta(100.0, (float)$bykey['alpha']['value'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float)$bykey['beta']['value'], 0.05);

        $quizpayload = grade_provider::get_quiz_skill_radar((int)$student->id, (int)$course->id, (int)$quiz->id, false);
        $qz = $this->index_detail_by_skill_key($quizpayload['skills_detail'] ?? []);
        $this->assertEqualsWithDelta(100.0, (float)$qz['alpha']['value'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float)$qz['beta']['value'], 0.05);

        $this->assertSame(2, count($quizpayload['chart']['labels'] ?? []));
    }

    /**
     * Grading method «last attempt»: first attempt wrong, second correct → stored % follows last attempt.
     */
    public function test_quiz_grademethod_last_attempt_uses_final_attempt_only(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $defalpha = $ctx['def_alpha'];
        $q1 = $ctx['q1'];

        $DB->set_field('quiz', 'grademethod', (string)QUIZ_ATTEMPTLAST, ['id' => $quiz->id]);

        $wrong = $this->wrong_answer_for_numerical($q1->id);
        $right = $this->correct_answer_for_question($q1->id);

        $aid1 = $this->finish_attempt_answer_slots($quiz, $student, [1 => $wrong]);
        cache_manager::recompute_attempt($aid1);

        $pct1 = (float)$DB->get_field(cache_manager::TABLE_USER, 'percent', [
            'userid' => $student->id,
            'quizid' => $quiz->id,
            'skillid' => $defalpha,
        ], MUST_EXIST);
        $this->assertLessThan(50.0, $pct1, 'First attempt should not be full credit');

        $aid2 = $this->finish_attempt_answer_slots($quiz, $student, [1 => $right]);
        cache_manager::recompute_attempt($aid2);

        $pct2 = (float)$DB->get_field(cache_manager::TABLE_USER, 'percent', [
            'userid' => $student->id,
            'quizid' => $quiz->id,
            'skillid' => $defalpha,
        ], MUST_EXIST);
        $this->assertGreaterThanOrEqual(90.0, $pct2, 'Last attempt correct should dominate');

        $payload = grade_provider::get_quiz_skill_radar((int)$student->id, (int)$course->id, (int)$quiz->id, false);
        $bykey = $this->index_detail_by_skill_key($payload['skills_detail'] ?? []);
        $this->assertGreaterThanOrEqual(90.0, (float)$bykey['alpha']['value'], 'Radar row should match last attempt');
    }

    /**
     * Grading method «average attempt»: one wrong and one correct attempt -> stored % is the mean.
     */
    public function test_quiz_grademethod_average_attempt_uses_mean_percent(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $defalpha = $ctx['def_alpha'];
        $q1 = $ctx['q1'];

        $DB->set_field('quiz', 'grademethod', (string)QUIZ_GRADEAVERAGE, ['id' => $quiz->id]);

        $aid1 = $this->finish_attempt_answer_slots($quiz, $student, [1 => $this->wrong_answer_for_numerical($q1->id)]);
        cache_manager::recompute_attempt($aid1);

        $aid2 = $this->finish_attempt_answer_slots($quiz, $student, [1 => $this->correct_answer_for_question($q1->id)]);
        cache_manager::recompute_attempt($aid2);

        $row = $DB->get_record(cache_manager::TABLE_USER, [
            'userid' => $student->id,
            'quizid' => $quiz->id,
            'skillid' => $defalpha,
            'aggregation_strategy' => cache_manager::STRATEGY_LATEST,
        ], '*', MUST_EXIST);

        $this->assertSame(2, (int)$row->attempts_count);
        $this->assertEqualsWithDelta(50.0, (float)$row->percent, 0.05);

        $payload = grade_provider::get_quiz_skill_radar((int)$student->id, (int)$course->id, (int)$quiz->id, false);
        $bykey = $this->index_detail_by_skill_key($payload['skills_detail'] ?? []);
        $this->assertEqualsWithDelta(50.0, (float)$bykey['alpha']['value'], 0.05);
    }

    /**
     * Grading method «highest»: when total grades tie, the later best attempt should win.
     */
    public function test_quiz_grademethod_highest_attempt_prefers_later_tied_attempt(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $q1 = $ctx['q1'];
        $q2 = $ctx['q2'];

        $DB->set_field('quiz', 'grademethod', (string)QUIZ_GRADEHIGHEST, ['id' => $quiz->id]);

        $aid1 = $this->finish_attempt_answer_slots($quiz, $student, [
            1 => $this->correct_answer_for_question($q1->id),
            2 => $this->wrong_answer_for_numerical($q2->id),
        ]);
        cache_manager::recompute_attempt($aid1);

        $aid2 = $this->finish_attempt_answer_slots($quiz, $student, [
            1 => $this->wrong_answer_for_numerical($q1->id),
            2 => $this->correct_answer_for_question($q2->id),
        ]);
        cache_manager::recompute_attempt($aid2);

        $payload = grade_provider::get_quiz_skill_radar((int)$student->id, (int)$course->id, (int)$quiz->id, false);
        $bykey = $this->index_detail_by_skill_key($payload['skills_detail'] ?? []);

        $this->assertEqualsWithDelta(0.0, (float)$bykey['alpha']['value'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float)$bykey['beta']['value'], 0.05);
    }

    /**
     * Replace question→skill mapping before any attempt: materialized skill follows new mapping.
     */
    public function test_replace_question_skill_mapping_before_attempt_uses_new_skill(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $defbeta = $ctx['def_beta'];
        $q1 = $ctx['q1'];
        $q2 = $ctx['q2'];

        manager::replace_question_skill_mappings((int)$course->id, [
            ['questionid' => (int)$q1->id, 'skill_key' => 'beta'],
            ['questionid' => (int)$q2->id, 'skill_key' => 'beta'],
        ]);

        $aid = $this->finish_attempt_answer_slots($quiz, $student, [
            1 => $this->correct_answer_for_question($q1->id),
            2 => $this->correct_answer_for_question($q2->id),
        ]);
        cache_manager::recompute_attempt($aid);

        $this->assertFalse($DB->record_exists(cache_manager::TABLE_ATTEMPT, [
            'attemptid' => $aid,
            'skillid' => $ctx['def_alpha'],
        ]));
        $att = $DB->get_record(cache_manager::TABLE_ATTEMPT, [
            'attemptid' => $aid,
            'skillid' => $defbeta,
        ], '*', MUST_EXIST);
        $this->assertSame(2, (int)$att->questions_count, 'Both slots merged under one skill');
        $this->assertEqualsWithDelta(100.0, (float)$att->percent, 0.01);
    }

    /**
     * Tagged skills should already appear as empty axes before the learner has any finalized attempt.
     */
    public function test_course_radar_shows_tagged_empty_axes_before_attempts(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];

        $payload = grade_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $bykey = $this->index_detail_by_skill_key($payload['skills_detail'] ?? []);

        $this->assertArrayHasKey('alpha', $bykey);
        $this->assertArrayHasKey('beta', $bykey);
        $this->assertTrue((bool)$bykey['alpha']['empty']);
        $this->assertTrue((bool)$bykey['beta']['empty']);
        $this->assertSame(1, (int)$bykey['alpha']['items']);
        $this->assertSame(1, (int)$bykey['beta']['items']);
        $this->assertNull($bykey['alpha']['value']);
        $this->assertNull($bykey['beta']['value']);
        $this->assertNull($payload['overall']['percent']);
    }

    /**
     * Single-quiz radar should mirror course radar and expose tagged empty axes before attempts exist.
     */
    public function test_single_quiz_radar_shows_tagged_empty_axes_before_attempts(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];

        $payload = grade_provider::get_quiz_skill_radar((int)$student->id, (int)$course->id, (int)$quiz->id, false);
        $bykey = $this->index_detail_by_skill_key($payload['skills_detail'] ?? []);

        $this->assertArrayHasKey('alpha', $bykey);
        $this->assertArrayHasKey('beta', $bykey);
        $this->assertTrue((bool)$bykey['alpha']['empty']);
        $this->assertTrue((bool)$bykey['beta']['empty']);
        $this->assertNull($bykey['alpha']['value']);
        $this->assertNull($bykey['beta']['value']);
        $this->assertSame(2, count($payload['chart']['labels'] ?? []));
        $this->assertNull($payload['overall']['percent']);
    }

    /**
     * Same skill on two quizzes: course-level row sums earned/max across quizzes → stable %.
     */
    public function test_course_payload_aggregates_same_skill_across_two_quizzes(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $defgamma = (int)$DB->insert_record('local_skillradar_def', (object)[
            'courseid' => $course->id,
            'skill_key' => 'gamma',
            'displayname' => 'Gamma',
            'color' => '#9333EA',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
        ]);
        $q1 = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);
        $q2 = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);

        /** @var \mod_quiz_generator $quizgen */
        $quizgen = $generator->get_plugin_generator('mod_quiz');
        $quiz1 = $quizgen->create_instance([
            'course' => $course->id,
            'sumgrades' => 1,
            'grade' => 100,
            'questionsperpage' => 0,
        ]);
        $quiz2 = $quizgen->create_instance([
            'course' => $course->id,
            'sumgrades' => 1,
            'grade' => 100,
            'questionsperpage' => 0,
        ]);
        quiz_add_quiz_question($q1->id, $quiz1);
        quiz_add_quiz_question($q2->id, $quiz2);

        foreach ([$q1, $q2] as $q) {
            $DB->insert_record('local_skillradar_qmap', (object)[
                'courseid' => $course->id,
                'questionid' => $q->id,
                'skill_key' => 'gamma',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $aid1 = $this->finish_attempt_answer_slots($quiz1, $student, [
            1 => $this->correct_answer_for_question($q1->id),
        ]);
        $aid2 = $this->finish_attempt_answer_slots($quiz2, $student, [
            1 => $this->correct_answer_for_question($q2->id),
        ]);
        cache_manager::recompute_attempt($aid1);
        cache_manager::recompute_attempt($aid2);

        $payload = grade_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $gamma = null;
        foreach ($payload['skills_detail'] ?? [] as $row) {
            if ((int)($row['key'] ?? 0) === $defgamma) {
                $gamma = $row;
                break;
            }
        }
        $this->assertNotNull($gamma);
        $this->assertEqualsWithDelta(100.0, (float)$gamma['value'], 0.05);

        $hybrid = hybrid_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $this->assertArrayHasKey('course_skills_radar', $hybrid);
        $this->assertArrayHasKey('question_skills_radar', $hybrid);
    }

    /**
     * Average overall: two skills at 100% and 0% (one wrong) → ~50% when both axes non-empty.
     */
    public function test_overall_average_across_two_skills(): void {
        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $ctx = $this->create_course_with_two_skills_one_quiz();
        $student = $ctx['student'];
        $course = $ctx['course'];
        $quiz = $ctx['quiz'];
        $q1 = $ctx['q1'];
        $q2 = $ctx['q2'];

        $aid = $this->finish_attempt_answer_slots($quiz, $student, [
            1 => $this->correct_answer_for_question($q1->id),
            2 => $this->wrong_answer_for_numerical($q2->id),
        ]);
        cache_manager::recompute_attempt($aid);

        $payload = grade_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $this->assertArrayHasKey('overall', $payload);
        $this->assertNotNull($payload['overall']['percent']);
        $overall = (float)$payload['overall']['percent'];
        $this->assertGreaterThan(40.0, $overall);
        $this->assertLessThan(60.0, $overall);
    }

    /**
     * @return array<string, mixed>
     */
    private function create_course_with_two_skills_one_quiz(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $defalpha = (int)$DB->insert_record('local_skillradar_def', (object)[
            'courseid' => $course->id,
            'skill_key' => 'alpha',
            'displayname' => 'Alpha',
            'color' => '#3B82F6',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $defbeta = (int)$DB->insert_record('local_skillradar_def', (object)[
            'courseid' => $course->id,
            'skill_key' => 'beta',
            'displayname' => 'Beta',
            'color' => '#059669',
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
        ]);
        $q1 = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);
        $q2 = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);

        /** @var \mod_quiz_generator $quizgen */
        $quizgen = $generator->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance([
            'course' => $course->id,
            'sumgrades' => 2,
            'grade' => 100,
            'questionsperpage' => 0,
            'attempts' => 10,
        ]);
        quiz_add_quiz_question($q1->id, $quiz);
        quiz_add_quiz_question($q2->id, $quiz);

        foreach ([[$q1, 'alpha'], [$q2, 'beta']] as [$q, $key]) {
            $DB->insert_record('local_skillradar_qmap', (object)[
                'courseid' => $course->id,
                'questionid' => $q->id,
                'skill_key' => $key,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return [
            'course' => $course,
            'student' => $student,
            'quiz' => $quiz,
            'def_alpha' => $defalpha,
            'def_beta' => $defbeta,
            'q1' => $q1,
            'q2' => $q2,
        ];
    }

    /**
     * @param array<int, string> $slotanswers slot => raw answer text (wrapped for question_engine like course_full_test).
     */
    private function finish_attempt_answer_slots(\stdClass $quiz, \stdClass $user, array $slotanswers): int {
        global $DB;

        $this->setUser($user);
        $timenow = time();
        $quizobj = quiz_settings::create($quiz->id, $user->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $prevcount = (int)$DB->count_records('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'preview' => 0,
        ]);
        $attemptnum = $prevcount + 1;

        $attempt = quiz_create_attempt($quizobj, $attemptnum, null, $timenow, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $responses = [];
        foreach ($slotanswers as $slot => $answer) {
            $responses[(int)$slot] = (string)$answer;
        }

        $attemptobj = quiz_attempt::create($attempt->id);
        $postdata = $questiongenerator->get_simulated_post_data_for_questions_in_usage(
            $attemptobj->get_question_usage(),
            $responses,
            false
        );
        $attemptobj->process_submitted_actions($timenow, false, $postdata);
        $attemptobj->process_submit($timenow, false);
        $attemptobj->process_grade_submission($timenow);

        $this->setUser();
        return (int)$attempt->id;
    }

    private function correct_answer_for_question(int $questionid): string {
        global $DB;

        $correct = $DB->get_field_sql(
            "SELECT answer FROM {question_answers}
              WHERE question = ? AND fraction > 0.999
           ORDER BY id ASC",
            [$questionid],
            IGNORE_MULTIPLE
        );
        $this->assertNotFalse($correct);

        return trim((string)$correct);
    }

    private function wrong_answer_for_numerical(int $questionid): string {
        $right = $this->correct_answer_for_question($questionid);
        if (is_numeric($right)) {
            return (string)((float)$right + 999.0);
        }

        return '0';
    }

    /**
     * @param array<int, array<string, mixed>> $detail
     * @return array<string, array<string, mixed>> skill_key => row
     */
    private function index_detail_by_skill_key(array $detail): array {
        global $DB;

        $out = [];
        foreach ($detail as $row) {
            $kid = (int)($row['key'] ?? 0);
            if ($kid < 1) {
                continue;
            }
            $def = $DB->get_record('local_skillradar_def', ['id' => $kid], 'skill_key', IGNORE_MISSING);
            if ($def && $def->skill_key !== '') {
                $out[(string)$def->skill_key] = $row;
            }
        }

        return $out;
    }
}
