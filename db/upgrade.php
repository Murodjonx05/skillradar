<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function xmldb_local_skillradar_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026032500) {
        upgrade_plugin_savepoint(true, 2026032500, 'local', 'skillradar');
    }

    if ($oldversion < 2026032600) {
        $table = new xmldb_table('local_skillradar_cfg');
        $field = new xmldb_field('primarycolor', XMLDB_TYPE_CHAR, '7', null, XMLDB_NOTNULL, null, '#3B82F6', 'courseavg');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->execute(
            "UPDATE {local_skillradar_cfg} SET primarycolor = ? WHERE primarycolor IS NULL OR primarycolor = ''",
            ['#3B82F6']
        );
        upgrade_plugin_savepoint(true, 2026032600, 'local', 'skillradar');
    }

    if ($oldversion < 2026032700) {
        $attempttable = new xmldb_table('local_skill_attempt_result');
        if (!$dbman->table_exists($attempttable)) {
            $attempttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $attempttable->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('skillid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('skillname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $attempttable->add_field('earned', XMLDB_TYPE_NUMBER, '12,7', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('maxearned', XMLDB_TYPE_NUMBER, '12,7', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('percent', XMLDB_TYPE_NUMBER, '7,2', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('questions_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('calculation_version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $attempttable->add_field('debugmeta', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $attempttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $attempttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $attempttable->add_index('attempt_skill_uix', XMLDB_INDEX_UNIQUE, ['attemptid', 'skillid']);
            $attempttable->add_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $attempttable->add_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $attempttable->add_index('quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['quizid']);
            $attempttable->add_index('skillid_ix', XMLDB_INDEX_NOTUNIQUE, ['skillid']);
            $attempttable->add_index('quiz_user_ix', XMLDB_INDEX_NOTUNIQUE, ['quizid', 'userid']);
            $dbman->create_table($attempttable);
        }

        $usertable = new xmldb_table('local_skill_user_result');
        if (!$dbman->table_exists($usertable)) {
            $usertable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $usertable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('skillid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('skillname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $usertable->add_field('source_attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('aggregation_strategy', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'latest_finalized_per_quiz');
            $usertable->add_field('earned', XMLDB_TYPE_NUMBER, '12,7', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('maxearned', XMLDB_TYPE_NUMBER, '12,7', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('percent', XMLDB_TYPE_NUMBER, '7,2', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('questions_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_field('attempts_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $usertable->add_field('calculation_version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $usertable->add_field('debugmeta', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $usertable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $usertable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $usertable->add_index('quiz_user_skill_strategy_uix', XMLDB_INDEX_UNIQUE,
                ['quizid', 'userid', 'skillid', 'aggregation_strategy']);
            $usertable->add_index('course_user_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
            $usertable->add_index('skillid_ix', XMLDB_INDEX_NOTUNIQUE, ['skillid']);
            $usertable->add_index('user_strategy_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'aggregation_strategy']);
            $dbman->create_table($usertable);
        }

        upgrade_plugin_savepoint(true, 2026032700, 'local', 'skillradar');
    }

    if ($oldversion < 2026032800) {
        upgrade_plugin_savepoint(true, 2026032800, 'local', 'skillradar');
    }

    if ($oldversion < 2026032900) {
        $maptable = new xmldb_table('local_skillradar_map');
        if (!$dbman->table_exists($maptable)) {
            $maptable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $maptable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $maptable->add_field('gradeitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $maptable->add_field('skill_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $maptable->add_field('weight', XMLDB_TYPE_NUMBER, '12,5', null, XMLDB_NOTNULL, null, '1');
            $maptable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $maptable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $maptable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $maptable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $maptable->add_key('gradeitem_fk', XMLDB_KEY_FOREIGN, ['gradeitemid'], 'grade_items', ['id']);
            $maptable->add_index('course_gradeitem_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'gradeitemid']);
            $maptable->add_index('course_skill_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'skill_key']);
            $dbman->create_table($maptable);
        }

        $deftable = new xmldb_table('local_skillradar_def');
        if (!$dbman->table_exists($deftable)) {
            $deftable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $deftable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $deftable->add_field('skill_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $deftable->add_field('displayname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $deftable->add_field('color', XMLDB_TYPE_CHAR, '7', null, XMLDB_NOTNULL, null, '#3B82F6');
            $deftable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $deftable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $deftable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $deftable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $deftable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $deftable->add_index('course_skill_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'skill_key']);
            $dbman->create_table($deftable);
        }

        upgrade_plugin_savepoint(true, 2026032900, 'local', 'skillradar');
    }

    if ($oldversion < 2026033010) {
        // Drop cached API payloads so dual-radar (quiz modules + skills) is always fresh.
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033010, 'local', 'skillradar');
    }

    if ($oldversion < 2026033020) {
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033020, 'local', 'skillradar');
    }

    if ($oldversion < 2026033100) {
        $table = new xmldb_table('local_skillradar_qmap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('skill_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('course_question_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'questionid']);
            $table->add_index('question_ix', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($table);
        }
        // Historical analytics used question_categories.id as skillid (positive). Category-based skills are now stored as negative ids so local_skillradar_def.id stays positive.
        $DB->execute("UPDATE {local_skill_attempt_result} SET skillid = -skillid WHERE skillid > 0");
        $DB->execute("UPDATE {local_skill_user_result} SET skillid = -skillid WHERE skillid > 0");
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033100, 'local', 'skillradar');
    }

    if ($oldversion < 2026033125) {
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033125, 'local', 'skillradar');
    }

    if ($oldversion < 2026033126) {
        upgrade_plugin_savepoint(true, 2026033126, 'local', 'skillradar');
    }

    if ($oldversion < 2026033127) {
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033127, 'local', 'skillradar');
    }

    if ($oldversion < 2026033128) {
        upgrade_plugin_savepoint(true, 2026033128, 'local', 'skillradar');
    }

    if ($oldversion < 2026033138) {
        \cache::make('local_skillradar', 'skillpayload')->purge();
        upgrade_plugin_savepoint(true, 2026033138, 'local', 'skillradar');
    }

    return true;
}
