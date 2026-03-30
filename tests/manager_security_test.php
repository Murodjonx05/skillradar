<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(manager::class)]
final class manager_security_test extends \advanced_testcase {
    public function test_question_belongs_to_course_scope_accepts_course_and_course_bank_questions_only(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $generator = $this->getDataGenerator();
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $qbankgenerator = $generator->get_plugin_generator('mod_qbank');

        $course = $generator->create_course();
        $coursecontext = \context_course::instance((int)$course->id);
        $bank = $qbankgenerator->create_instance(['course' => (int)$course->id]);
        $bankcontext = \context_module::instance((int)$bank->cmid);

        $othercourse = $generator->create_course();
        $otherbank = $qbankgenerator->create_instance(['course' => (int)$othercourse->id]);
        $otherbankcontext = \context_module::instance((int)$otherbank->cmid);

        $coursecategory = $questiongenerator->create_question_category(['contextid' => (int)$coursecontext->id]);
        $bankcategory = $questiongenerator->create_question_category(['contextid' => (int)$bankcontext->id]);
        $othercategory = $questiongenerator->create_question_category(['contextid' => (int)$otherbankcontext->id]);

        $coursequestion = $questiongenerator->create_question('truefalse', null, ['category' => $coursecategory->id]);
        $bankquestion = $questiongenerator->create_question('truefalse', null, ['category' => $bankcategory->id]);
        $foreignquestion = $questiongenerator->create_question('truefalse', null, ['category' => $othercategory->id]);

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

        $this->assertTrue(manager::question_belongs_to_course_scope((int)$course->id, (int)$coursequestion->id));
        $this->assertTrue(manager::question_belongs_to_course_scope((int)$course->id, (int)$bankquestion->id));
        $this->assertFalse(manager::question_belongs_to_course_scope((int)$course->id, (int)$foreignquestion->id));
    }
}
