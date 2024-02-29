<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrades for coursesize
 *
 * @package    report_coursesize
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_report_coursesize_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2015081100) {
        // Define table report_coursesize_no_backups to be created.
        $table = new xmldb_table('report_coursesize_no_backups');

        // Adding fields to table report_coursesize_no_backups.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('contextlevel', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('instanceid', XMLDB_TYPE_INTEGER, '11', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '15', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table report_coursesize_no_backups.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        // Conditionally launch create table for report_coursesize_no_backups.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2015081100, 'report', 'coursesize');
    }

    if ($oldversion < 2015081400) {
        $dbman->install_one_table_from_xmldb_file(__DIR__.'/install.xml', 'report_coursesize_components');

        upgrade_plugin_savepoint(true, 2015081400, 'report', 'coursesize');
    }

    if ($oldversion < 2021102000) {
        $table = new xmldb_table('report_coursesize_no_backups');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        $table = new xmldb_table('report_coursesize');
        $field = new xmldb_field('backupsize', XMLDB_TYPE_INTEGER, '15', null, XMLDB_NOTNULL, null, 0);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('autobackupsize', XMLDB_TYPE_INTEGER, '15', null, XMLDB_NOTNULL, null, 0);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2021102000, 'report', 'coursesize');
    }

    return true;
}
