<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(grade_provider::class)]
final class grade_provider_test extends \advanced_testcase {
    public function test_compute_overall_excludes_empty_axes(): void {
        $this->resetAfterTest(true);
        $config = (object)['overallmode' => 'average'];
        $ref = new \ReflectionMethod(grade_provider::class, 'compute_overall');
        $ref->setAccessible(true);
        $detail = [
            ['value' => 100.0, 'empty' => false, 'earned' => 100.0, 'maxearned' => 100.0],
            ['value' => 0.0, 'empty' => true, 'earned' => 0.0, 'maxearned' => 0.0],
        ];
        $result = $ref->invoke(null, $config, $detail);
        $this->assertSame(100.0, $result['percent']);
    }

    public function test_axis_label_uses_skill_key_when_displayname_equals_definition_id(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'axis_label_from_definition');
        $ref->setAccessible(true);
        $def = (object)[
            'displayname' => '5',
            'skill_key' => 'buxgalteriya',
        ];
        $label = $ref->invoke(null, $def, 5);
        $this->assertSame('buxgalteriya', $label);
    }

    public function test_axis_label_fallback_when_displayname_is_id_digits_and_no_key(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'axis_label_from_definition');
        $ref->setAccessible(true);
        $def = (object)[
            'displayname' => '5',
            'skill_key' => '',
        ];
        $label = $ref->invoke(null, $def, 5);
        $expected = get_string('skill_label_fallback', 'local_skillradar', ['id' => 5]);
        $this->assertSame($expected, $label);
    }

    public function test_axis_label_uses_skill_key_when_numeric_displayname_mismatches_definition_id(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'axis_label_from_definition');
        $ref->setAccessible(true);
        $def = (object)[
            'displayname' => '5',
            'skill_key' => 'k1',
        ];
        $label = $ref->invoke(null, $def, 8);
        $this->assertSame('k1', $label);
    }

    public function test_filter_ghost_tagged_axes_drops_empty_zero_items(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'filter_ghost_tagged_axes');
        $ref->setAccessible(true);
        $detail = [
            ['key' => '8', 'label' => 'Ghost', 'empty' => true, 'items' => 0],
            ['key' => '1', 'label' => 'Real', 'empty' => false, 'items' => 1],
        ];
        $out = $ref->invoke(null, $detail);
        $this->assertCount(1, $out);
        $this->assertSame('Real', $out[0]['label']);
    }

    public function test_filter_ghost_tagged_axes_keeps_empty_when_questions_tagged(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'filter_ghost_tagged_axes');
        $ref->setAccessible(true);
        $detail = [
            ['key' => '2', 'label' => 'Pending', 'empty' => true, 'items' => 2, 'maxearned' => 0.0],
        ];
        $out = $ref->invoke(null, $detail);
        $this->assertCount(1, $out);
    }

    public function test_gradebook_course_average_fallback_skips_synthetic_empty_rows(): void {
        $this->resetAfterTest(true);
        $ref = new \ReflectionMethod(grade_provider::class, 'apply_gradebook_course_average_fallback');
        $ref->setAccessible(true);
        $detail = [[
            'key' => '6',
            'label' => 't1',
            'placeholder' => false,
            'synthetic_empty' => true,
        ]];
        $courseavg = [
            'label' => 'Course average',
            'values' => [null],
        ];
        $result = $ref->invoke(null, 999, $detail, $courseavg);
        $this->assertNull($result['values'][0]);
    }
}
