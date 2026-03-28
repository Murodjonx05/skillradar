<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy metadata for stored analytics tables.
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * @param \core_privacy\local\metadata\collection $collection
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_skill_attempt_result', [
            'userid' => 'privacy:metadata:local_skill_attempt_result:userid',
        ], 'privacy:metadata:local_skill_attempt_result');

        $collection->add_database_table('local_skill_attempt_qskill', [
            'attemptid' => 'privacy:metadata:local_skill_attempt_qskill:attemptid',
        ], 'privacy:metadata:local_skill_attempt_qskill');

        $collection->add_database_table('local_skill_user_result', [
            'userid' => 'privacy:metadata:local_skill_user_result:userid',
        ], 'privacy:metadata:local_skill_user_result');

        $collection->add_database_table('local_skillradar_def', [
            'courseid' => 'privacy:metadata:local_skillradar_def:courseid',
        ], 'privacy:metadata:local_skillradar_def');

        $collection->add_database_table('local_skillradar_map', [
            'courseid' => 'privacy:metadata:local_skillradar_map:courseid',
        ], 'privacy:metadata:local_skillradar_map');

        $collection->add_database_table('local_skillradar_qmap', [
            'courseid' => 'privacy:metadata:local_skillradar_qmap:courseid',
        ], 'privacy:metadata:local_skillradar_qmap');

        return $collection;
    }
}
