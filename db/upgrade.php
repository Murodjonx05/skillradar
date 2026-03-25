<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function xmldb_local_skillradar_upgrade($oldversion) {
    if ($oldversion < 2026032500) {
        upgrade_plugin_savepoint(true, 2026032500, 'local', 'skillradar');
    }
    return true;
}
