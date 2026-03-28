<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression tests for question->skill resolution.
 *
 * @covers \local_skillradar\skill_service
 * @covers \local_skillradar\manager
 */
final class skill_service_test extends \advanced_testcase {
    public function test_replace_question_skill_mappings_resets_in_request_resolution_cache(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $course = $this->getDataGenerator()->create_course();
        $courseid = (int)$course->id;
        $this->create_definition($courseid, 'skill_a', 'Skill A', 0);
        $defb = $this->create_definition($courseid, 'skill_b', 'Skill B', 1);

        $question = $this->create_course_question($courseid, 'numerical');
        $DB->insert_record(manager::TABLE_QMAP, (object)[
            'courseid' => $courseid,
            'questionid' => (int)$question->id,
            'skill_key' => 'skill_a',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $first = skill_service::get_question_skill((int)$question->id, $courseid);
        $this->assertSame('Skill A', $first->skillname);

        manager::replace_question_skill_mappings($courseid, [[
            'questionid' => (int)$question->id,
            'skill_key' => 'skill_b',
        ]]);

        $second = skill_service::get_question_skill((int)$question->id, $courseid);
        $this->assertNotNull($second);
        $this->assertSame((int)$defb, (int)$second->skillid);
        $this->assertSame('Skill B', $second->skillname);
    }

    public function test_question_bank_entry_override_applies_to_other_versions_of_same_question(): void {
        global $DB;

        $this->resetAfterTest(true);
        manager::reset_static_caches();
        skill_service::reset_caches();

        $course = $this->getDataGenerator()->create_course();
        $courseid = (int)$course->id;
        $defa = $this->create_definition($courseid, 'skill_a', 'Skill A', 0);

        $oldquestion = $this->create_course_question($courseid, 'numerical');
        $newquestion = $this->create_course_question($courseid, 'numerical');

        $oldversion = $DB->get_record('question_versions', ['questionid' => (int)$oldquestion->id], '*', MUST_EXIST);
        $newversion = $DB->get_record('question_versions', ['questionid' => (int)$newquestion->id], '*', MUST_EXIST);

        $DB->set_field('question_versions', 'questionbankentryid', (int)$oldversion->questionbankentryid, ['id' => (int)$newversion->id]);
        $DB->set_field('question_versions', 'version', 2, ['id' => (int)$newversion->id]);

        $DB->insert_record(manager::TABLE_QMAP, (object)[
            'courseid' => $courseid,
            'questionid' => (int)$newquestion->id,
            'skill_key' => 'skill_a',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        manager::reset_static_caches();
        $resolved = skill_service::get_question_skills($courseid, [(int)$oldquestion->id, (int)$newquestion->id]);

        $this->assertSame((int)$defa, (int)$resolved[(int)$oldquestion->id]->skillid);
        $this->assertSame('Skill A', $resolved[(int)$oldquestion->id]->skillname);
        $this->assertSame((int)$defa, (int)$resolved[(int)$newquestion->id]->skillid);
        $this->assertSame('Skill A', $resolved[(int)$newquestion->id]->skillname);
    }

    private function create_definition(int $courseid, string $skillkey, string $displayname, int $sortorder): int {
        global $DB;

        return (int)$DB->insert_record(manager::TABLE_DEF, (object)[
            'courseid' => $courseid,
            'skill_key' => $skillkey,
            'displayname' => $displayname,
            'color' => '#3B82F6',
            'sortorder' => $sortorder,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    private function create_course_question(int $courseid, string $qtype): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($courseid)->id,
        ]);

        return $questiongenerator->create_question($qtype, null, ['category' => $category->id]);
    }
}
