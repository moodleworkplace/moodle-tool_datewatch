<?php
// This file is part of the tool_datewatch plugin for Moodle - http://moodle.org/
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
 * Date watcher upgrades
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executed on tool_datewatch upgrade
 *
 * @param string $oldversion
 * @return bool
 */
function xmldb_tool_datewatch_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2021100100) {

        // Define table tool_datewatch_upcoming to be dropped.
        $table = new xmldb_table('tool_datewatch_upcoming');

        // Conditionally launch drop table for tool_datewatch_upcoming.
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Define table tool_datewatch to be dropped.
        $table = new xmldb_table('tool_datewatch');

        // Conditionally launch drop table for tool_datewatch.
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Define table tool_datewatch to be created.
        $table = new xmldb_table('tool_datewatch');

        // Adding fields to table tool_datewatch.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tablename', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fieldname', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('maxoffset', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastcheck', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table tool_datewatch.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table tool_datewatch.
        $table->add_index('tablename', XMLDB_INDEX_NOTUNIQUE, ['tablename', 'fieldname']);

        // Conditionally launch create table for tool_datewatch.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table tool_datewatch_upcoming to be created.
        $table = new xmldb_table('tool_datewatch_upcoming');

        // Adding fields to table tool_datewatch_upcoming.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datewatchid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('objectid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Adding keys to table tool_datewatch_upcoming.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('datewatchid', XMLDB_KEY_FOREIGN, ['datewatchid'], 'tool_datewatch', ['id']);

        // Adding indexes to table tool_datewatch_upcoming.
        $table->add_index('value', XMLDB_INDEX_NOTUNIQUE, ['datewatchid', 'value']);
        $table->add_index('datewatchid-objectid', XMLDB_INDEX_UNIQUE, ['datewatchid', 'objectid']);

        // Conditionally launch create table for tool_datewatch_upcoming.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Datewatch savepoint reached.
        upgrade_plugin_savepoint(true, 2021100100, 'tool', 'datewatch');
    }

    return true;
}
