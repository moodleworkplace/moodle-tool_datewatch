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

defined('MOODLE_INTERNAL') || die();

/**
 * Executed on tool_datewatch upgrade
 *
 * @param string $oldversion
 * @return bool
 */
function xmldb_tool_datewatch_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2021092400) {

        // Database and code have changed significantly. Force re-indexing of everything.
        $DB->delete_records('tool_datewatch');
        $DB->delete_records('tool_datewatch_upcoming');

        // Define field hash to be added to tool_datewatch.
        $table = new xmldb_table('tool_datewatch');
        $field = new xmldb_field('hash', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null, 'id');

        // Conditionally launch add field hash.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index hash (unique) to be added to tool_datewatch.
        $table = new xmldb_table('tool_datewatch');
        $index = new xmldb_index('hash', XMLDB_INDEX_UNIQUE, ['hash']);

        // Conditionally launch add index hash.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Datewatch savepoint reached.
        upgrade_plugin_savepoint(true, 2021092400, 'tool', 'datewatch');
    }

    if ($oldversion < 2021092401) {

        // Define index datewatchid-tableid (unique) to be dropped form tool_datewatch_upcoming.
        $table = new xmldb_table('tool_datewatch_upcoming');
        $index = new xmldb_index('datewatchid-tableid', XMLDB_INDEX_UNIQUE, ['datewatchid', 'tableid']);

        // Conditionally launch drop index datewatchid-tableid.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Rename field tableid on table tool_datewatch_upcoming to objectid.
        $table = new xmldb_table('tool_datewatch_upcoming');
        $field = new xmldb_field('tableid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'datewatchid');

        // Launch rename field objectid.
        $dbman->rename_field($table, $field, 'objectid');

        // Define index datewatchid-objectid (unique) to be added to tool_datewatch_upcoming.
        $table = new xmldb_table('tool_datewatch_upcoming');
        $index = new xmldb_index('datewatchid-objectid', XMLDB_INDEX_UNIQUE, ['datewatchid', 'objectid']);

        // Conditionally launch add index datewatchid-objectid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Datewatch savepoint reached.
        upgrade_plugin_savepoint(true, 2021092401, 'tool', 'datewatch');
    }

    return true;
}
