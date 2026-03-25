<?php
// This file is part of Moodle - http://moodle.org/

namespace local_skillradar;

defined('MOODLE_INTERNAL') || die();

use cache;
use stdClass;

class manager {
    public const TABLE_MAP = 'local_skillradar_map';
    public const TABLE_DEF = 'local_skillradar_def';
    public const TABLE_CFG = 'local_skillradar_cfg';

    /** @var string Application cache key prefix for per-course payload revision (invalidates course without purging all sites). */
    private const CACHE_REV_PREFIX = 'skillradar_rev_';

    public static function get_course_config(int $courseid): stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE_CFG, ['courseid' => $courseid]);
        if ($record) {
            return $record;
        }
        return (object)[
            'courseid' => $courseid,
            'overallmode' => 'average',
            'courseavg' => 0,
            'primarycolor' => '#3B82F6',
        ];
    }

    public static function save_course_config(stdClass $data): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record(self::TABLE_CFG, ['courseid' => $data->courseid]);
        if ($existing) {
            $data->id = $existing->id;
            $data->timemodified = $now;
            $DB->update_record(self::TABLE_CFG, $data);
            return;
        }
        $data->timecreated = $now;
        $data->timemodified = $now;
        $DB->insert_record(self::TABLE_CFG, $data);
    }

    public static function get_definitions(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE_DEF, ['courseid' => $courseid], 'sortorder ASC, id ASC');
    }

    public static function get_mappings(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE_MAP, ['courseid' => $courseid], 'id ASC');
    }

    public static function replace_mappings(int $courseid, array $rows): void {
        global $DB;
        $DB->delete_records(self::TABLE_MAP, ['courseid' => $courseid]);
        $now = time();
        foreach ($rows as $row) {
            $row->courseid = $courseid;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record(self::TABLE_MAP, $row);
        }
    }

    public static function purge_stale_mappings(int $courseid): void {
        global $DB;
        $sql = "SELECT m.id
                  FROM {" . self::TABLE_MAP . "} m
             LEFT JOIN {grade_items} gi ON gi.id = m.gradeitemid
                 WHERE m.courseid = :courseid
                   AND gi.id IS NULL";
        $ids = $DB->get_fieldset_sql($sql, ['courseid' => $courseid]);
        foreach ($ids as $id) {
            $DB->delete_records(self::TABLE_MAP, ['id' => $id]);
        }
    }

    public static function count_items_per_skill(int $courseid): array {
        global $DB;
        $sql = "SELECT skill_key, COUNT(*) AS itemcount
                  FROM {" . self::TABLE_MAP . "}
                 WHERE courseid = :courseid
              GROUP BY skill_key";
        return $DB->get_records_sql_menu($sql, ['courseid' => $courseid]);
    }

    public static function find_preview_userid(int $courseid): int {
        global $DB, $USER;
        $sql = "SELECT DISTINCT gg.userid
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.courseid = :courseid
                   AND gg.finalgrade IS NOT NULL
              ORDER BY gg.userid ASC";
        $userid = (int)$DB->get_field_sql($sql, ['courseid' => $courseid], IGNORE_MULTIPLE);
        return $userid > 0 ? $userid : (int)$USER->id;
    }

    public static function invalidate_course_cache(int $courseid): void {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $cache->set(self::CACHE_REV_PREFIX . $courseid, time());
    }

    public static function invalidate_user_cache(int $courseid, int $userid): void {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $base = self::cache_key($courseid, $userid);
        $cache->delete($base);
        $cache->delete($base . '_avg');
    }

    /**
     * Revision token so mapping/config changes for one course do not require purging the whole store.
     */
    protected static function course_payload_revision(int $courseid): int {
        $cache = cache::make('local_skillradar', 'skillpayload');
        $rev = $cache->get(self::CACHE_REV_PREFIX . $courseid);
        if ($rev === false) {
            return 0;
        }
        return (int) $rev;
    }

    public static function cache_key(int $courseid, int $userid): string {
        $r = self::course_payload_revision($courseid);
        return 'c' . $courseid . '_r' . $r . '_u' . $userid;
    }

    /**
     * True when the course has at least one skill (axis) defined — then the block is shown to all viewers.
     * With zero skills the course is treated as unset and only managers see the panel.
     */
    public static function is_course_skillradar_ready(int $courseid): bool {
        global $DB;
        if ($courseid < 1) {
            return false;
        }
        return $DB->record_exists(self::TABLE_DEF, ['courseid' => $courseid]);
    }
}
