<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

/**
 * @covers \local_skillradar\radar_helper
 */
final class radar_helper_test extends \advanced_testcase {
    public function test_dedupe_duplicate_axis_labels_appends_key_when_same_label(): void {
        $this->resetAfterTest(true);
        $rows = [
            ['key' => '10', 'label' => 'Algebra', 'placeholder' => false],
            ['key' => '11', 'label' => 'Algebra', 'placeholder' => false],
        ];
        $out = radar_helper::dedupe_duplicate_axis_labels($rows);
        $this->assertSame('Algebra · 10', $out[0]['label']);
        $this->assertSame('Algebra · 11', $out[1]['label']);
    }

    public function test_percent_to_letter_boundaries(): void {
        $this->resetAfterTest(true);
        $this->assertSame('S+', radar_helper::percent_to_letter(95.0));
        $this->assertSame('E-', radar_helper::percent_to_letter(0.0));
        $this->assertNull(radar_helper::percent_to_letter(null));
    }
}
