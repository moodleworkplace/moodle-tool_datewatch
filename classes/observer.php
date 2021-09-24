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
 * Event observer.
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_datewatch_observer {
    /**
     * Checks if the event affects one of the watched dates and stores them.
     *
     * Executed on every event.
     *
     * @param \core\event\base $event
     */
    public static function date_changed(\core\event\base $event) {
        if ($event->crud !== 'c' && $event->crud !== 'u' && $event->crud !== 'd') {
            return;
        }
        $tablename = $event->objecttable ?? '';
        $tableid = $event->objectid ?? 0;
        tool_datewatch_manager::process_event($event, $tablename, $tableid);

        if ($event instanceof \core\event\course_module_created ||
                $event instanceof \core\event\course_module_updated ||
                $event instanceof \core\event\course_module_deleted) {
            $tablename = (string)$event->other['modulename'];
            $tableid = (int)$event->other['instanceid'];
            tool_datewatch_manager::process_event($event, $tablename, $tableid);
        }
    }
}
