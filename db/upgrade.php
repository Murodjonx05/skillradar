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

    return true;
}
