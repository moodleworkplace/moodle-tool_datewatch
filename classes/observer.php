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

defined('MOODLE_INTERNAL') || die();

/**
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_datewatch_observer {
    /**
     * Cache dates if they are watched.
     *
     * @param \core\event\base $event
     */
    public static function cachedates(\core\event\base $event) {
        if ($event->crud !== 'c' && $event->crud !== 'u' && $event->crud !== 'd') {
            return;
        }
        $tablename = $event->objecttable ?? '';
        $tableid = $event->objectid ?? 0;
        self::process_event($event, $tablename, $tableid);

        if ($event instanceof \core\event\course_module_created ||
                $event instanceof \core\event\course_module_updated ||
                $event instanceof \core\event\course_module_deleted) {
            $tablename = (string)$event->other['modulename'];
            $tableid = (int)$event->other['instanceid'];
            self::process_event($event, $tablename, $tableid);
        }
    }

    protected static function process_event(\core\event\base $event, ?string $tablename, ?int $tableid) {
        if (!$tablename || !$tableid || !in_array($event->crud, ['d', 'u', 'c'])) {
            return;
        }
        if (tool_datewatch_manager::has_watchers($tablename)) {
            if ($event->crud === 'd') {
                tool_datewatch_manager::delete_upcoming($tablename, $tableid);
            } else {
                $record = $event->get_record_snapshot($tablename, $tableid);
                if ($event->crud === 'u') {
                    tool_datewatch_manager::update_upcoming($tablename, $record);
                } else {
                    tool_datewatch_manager::create_upcoming($tablename, $record);
                }
            }
        }
    }
}
