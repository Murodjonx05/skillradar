<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;
use question_engine;

#[CoversClass(cache_manager::class)]
#[CoversClass(attempt_analyzer::class)]
#[CoversClass(attempt_skill_snapshot::class)]
#[CoversClass(grade_provider::class)]
#[CoversClass(hybrid_provider::class)]
final class course_full_test extends \advanced_testcase {
    /**
     * Two quizzes in one course, tagged skills, finished attempts → DB rows + API payload.
     */
    public function test_two_quizzes_course_aggregates_skills_and_radar_payload(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $data = $this->create_course_two_quizzes_with_skill_tags();
        $student = $data['student'];
        $course = $data['course'];
        $defid = $data['defid'];
        $quiz1 = $data['quiz1'];
        $quiz2 = $data['quiz2'];
        $q1 = $data['question1'];
        $q2 = $data['question2'];

        $aid1 = $this->finish_quiz_attempt_with_correct_answer($quiz1, $student, $q1);
        $aid2 = $this->finish_quiz_attempt_with_correct_answer($quiz2, $student, $q2);

        $this->assertSame(\mod_quiz\quiz_attempt::FINISHED, $DB->get_field('quiz_attempts', 'state', ['id' => $aid1]));
        $this->assertSame(\mod_quiz\quiz_attempt::FINISHED, $DB->get_field('quiz_attempts', 'state', ['id' => $aid2]));

        cache_manager::recompute_attempt($aid1);
        cache_manager::recompute_attempt($aid2);

        $this->assertTrue($DB->record_exists(cache_manager::TABLE_ATTEMPT, [
            'attemptid' => $aid1,
            'skillid' => $defid,
        ]));
        $this->assertTrue($DB->record_exists(cache_manager::TABLE_ATTEMPT, [
            'attemptid' => $aid2,
            'skillid' => $defid,
        ]));

        $this->assertTrue($DB->record_exists(attempt_skill_snapshot::TABLE, [
            'attemptid' => $aid1,
            'questionid' => $q1->id,
        ]));
        $this->assertTrue($DB->record_exists(attempt_skill_snapshot::TABLE, [
            'attemptid' => $aid2,
            'questionid' => $q2->id,
        ]));

        $this->assertTrue($DB->record_exists(cache_manager::TABLE_USER, [
            'quizid' => $quiz1->id,
            'userid' => $student->id,
            'skillid' => $defid,
        ]));
        $this->assertTrue($DB->record_exists(cache_manager::TABLE_USER, [
            'quizid' => $quiz2->id,
            'userid' => $student->id,
            'skillid' => $defid,
        ]));

        $payload = grade_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $this->assertArrayHasKey('skills_detail', $payload);
        $this->assertNotEmpty($payload['skills_detail']);
        $found = false;
        foreach ($payload['skills_detail'] as $row) {
            if ((int)($row['key'] ?? 0) !== $defid || !empty($row['placeholder']) || !empty($row['empty'])) {
                continue;
            }
            $found = true;
            $this->assertGreaterThanOrEqual(90.0, (float)$row['value']);
        }
        $this->assertTrue($found, 'Expected materialized skill row for definition in radar payload');

        $hybrid = hybrid_provider::get_course_skill_radar((int)$student->id, (int)$course->id, false);
        $this->assertArrayHasKey('question_skills_radar', $hybrid);
        $this->assertNotEmpty($hybrid['question_skills_radar']['skills_detail'] ?? []);
    }

    /**
     * After first materialization, changing qmap must not change per-attempt skill (snapshot).
     */
    public function test_attempt_skill_snapshot_overrides_later_qmap_change(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $data = $this->create_course_two_quizzes_with_skill_tags();
        $student = $data['student'];
        $course = $data['course'];
        $defida = $data['defid'];
        $defidb = $data['defid_b'];
        $quiz1 = $data['quiz1'];
        $q1 = $data['question1'];

        $aid = $this->finish_quiz_attempt_with_correct_answer($quiz1, $student, $q1);
        cache_manager::recompute_attempt($aid);

        $firstskill = (int)$DB->get_field(cache_manager::TABLE_ATTEMPT, 'skillid', [
            'attemptid' => $aid,
        ], IGNORE_MISSING);
        $this->assertSame($defida, $firstskill);

        // Point qmap at a different skill key (would change live resolution).
        $conds = ['courseid' => $course->id, 'questionid' => $q1->id];
        $DB->set_field('local_skillradar_qmap', 'skill_key', 'skill_b', $conds);
        $DB->set_field('local_skillradar_qmap', 'timemodified', time(), $conds);
        manager::reset_static_caches();

        cache_manager::recompute_attempt($aid);

        $afterskill = (int)$DB->get_field(cache_manager::TABLE_ATTEMPT, 'skillid', [
            'attemptid' => $aid,
        ], IGNORE_MISSING);
        $this->assertSame($defida, $afterskill);
        $this->assertNotSame($defidb, $afterskill);
    }

    /**
     * @return array{
     *   course: \stdClass,
     *   student: \stdClass,
     *   defid: int,
     *   defid_b: int,
     *   quiz1: \stdClass,
     *   quiz2: \stdClass,
     *   question1: \stdClass,
     *   question2: \stdClass
     * }
     */
    private function create_course_two_quizzes_with_skill_tags(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $defida = (int)$DB->insert_record('local_skillradar_def', (object)[
            'courseid' => $course->id,
            'skill_key' => 'skill_a',
            'displayname' => 'Skill A',
            'color' => '#3B82F6',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $defidb = (int)$DB->insert_record('local_skillradar_def', (object)[
            'courseid' => $course->id,
            'skill_key' => 'skill_b',
            'displayname' => 'Skill B',
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

        foreach ([[$q1, 'skill_a'], [$q2, 'skill_a']] as [$q, $key]) {
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
            'defid' => $defida,
            'defid_b' => $defidb,
            'quiz1' => $quiz1,
            'quiz2' => $quiz2,
            'question1' => $q1,
            'question2' => $q2,
        ];
    }

    /**
     * Submit the correct response for slot 1 using the question bank row (works for numerical and similar types).
     *
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @param \stdClass $question Question record from the generator.
     * @return int attempt id
     */
    private function finish_quiz_attempt_with_correct_answer(\stdClass $quiz, \stdClass $user, \stdClass $question): int {
        global $DB;

        $correct = $DB->get_field_sql(
            "SELECT answer FROM {question_answers}
              WHERE question = ? AND fraction > 0.999
           ORDER BY id ASC",
            [$question->id],
            IGNORE_MULTIPLE
        );
        $this->assertNotFalse($correct);

        $this->setUser($user);
        $timenow = time();
        $quizobj = quiz_settings::create($quiz->id, $user->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $attempt = quiz_create_attempt($quizobj, 1, null, $timenow, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [1 => ['answer' => trim((string)$correct)]]);
        $attemptobj->process_submit($timenow, false);
        $attemptobj->process_grade_submission($timenow);

        $this->setUser();
        return (int)$attempt->id;
    }
}
