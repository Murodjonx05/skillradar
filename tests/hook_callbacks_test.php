<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_skillradar\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    public function test_quiz_structure_modified_callback_is_defined(): void {
        $this->resetAfterTest(true);
        $this->assertTrue(method_exists(hook_callbacks::class, 'quiz_structure_modified'));
    }
}
