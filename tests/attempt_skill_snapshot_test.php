<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(attempt_skill_snapshot::class)]
final class attempt_skill_snapshot_test extends \advanced_testcase {
    public function test_insert_new_is_idempotent_for_same_attempt_and_question(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
        ]);
        $question = $questiongenerator->create_question('numerical', null, ['category' => $category->id]);

        /** @var \mod_quiz_generator $quizgen */
        $quizgen = $generator->get_plugin_generator('mod_quiz');
        $quiz = $quizgen->create_instance([
            'course' => $course->id,
            'sumgrades' => 1,
            'grade' => 100,
            'questionsperpage' => 0,
        ]);
        quiz_add_quiz_question($question->id, $quiz);

        $attempt = $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempt' => 1,
            'uniqueid' => 123456,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => time(),
            'timefinish' => time(),
            'timemodified' => time(),
        ]);

        $row = [
            'questionid' => (int)$question->id,
            'skillid' => 7,
            'skillname' => 'Skill',
        ];

        attempt_skill_snapshot::insert_new((int)$attempt, [$row]);
        attempt_skill_snapshot::insert_new((int)$attempt, [$row]);

        $records = $DB->get_records(attempt_skill_snapshot::TABLE, [
            'attemptid' => (int)$attempt,
            'questionid' => (int)$question->id,
        ]);
        $this->assertCount(1, $records);
    }
}
