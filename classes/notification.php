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

namespace tool_datewatch;

/**
 * An instance of the notification class is passed to all datewatch callbacks when the date occurs
 *
 * @package    tool_datewatch
 * @copyright  2021 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification {
    /** @var \tool_datewatch\task\watch */
    private $task;
    /** @var \tool_datewatch_watcher */
    private $watcher;
    /** @var int */
    private $objectid;
    /** @var int */
    private $fieldvalue;
    /** @var array */
    private $records = [];

    /**
     * Constructor
     *
     * @param task\watch $task
     */
    public function __construct(\tool_datewatch\task\watch $task) {
        $this->task = $task;
    }

    /**
     * Initialiser (not to be used by the watchers)
     *
     * @param \tool_datewatch_watcher $watcher
     * @param int $objectid
     * @param int $fieldvalue
     */
    public function init(\tool_datewatch_watcher $watcher, int $objectid, int $fieldvalue): void {
        $this->watcher = $watcher;
        $this->objectid = $objectid;
        $this->fieldvalue = $fieldvalue;
    }

    /**
     * Returns the instance of scheduled task that produced this notification
     *
     * The watchers may call $notification->get_task()->get_task_idnumber() to identify
     * the same/different calling task.
     *
     * @return task\watch
     */
    public function get_task(): \tool_datewatch\task\watch {
        return $this->task;
    }

    /**
     * Returns the instance of the watcher registered by the plugin
     *
     * Can be useful if the plugin defines several watchers that use the same callback function
     *
     * @return \tool_datewatch_watcher
     */
    public function get_watcher(): \tool_datewatch_watcher {
        return $this->watcher;
    }

    /**
     * Row id in the table that is being watched
     *
     * @return int
     */
    public function get_objectid(): int {
        return $this->objectid;
    }

    /**
     * Actual value of the watched field
     *
     * This is the value stored in the index table, you can retrieve the whole row from the
     * table by calling {@see self::get_record()}
     *
     * @return int
     */
    public function get_field_value(): int {
        return $this->fieldvalue;
    }

    /**
     * Returns the record from a table at the moment when the notification was triggered
     *
     * When multiple watchers watch the same/similar data, this method will help to reduce the number
     * of DB queries because the records are cached.
     *
     * @param string $tablename
     * @param int $objectid
     * @param int $strictness
     * @return \stdClass|null
     */
    public function get_snapshot(string $tablename, int $objectid, $strictness = IGNORE_MISSING): ?\stdClass {
        global $DB;
        if (!array_key_exists($tablename, $this->records)) {
            $this->records[$tablename] = [];
        }
        if (!array_key_exists($objectid, $this->records[$tablename])) {
            $this->records[$tablename][$objectid] = $DB->get_record($tablename, ['id' => $objectid]) ?: null;
        }
        if ($strictness == MUST_EXIST && !$this->records[$tablename][$objectid]) {
            return $DB->get_record($tablename, ['id' => $objectid], '*', $strictness);
        }
        if (empty($this->records[$tablename][$objectid])) {
            return null;
        }
        // Return a copy of the record so watchers don't accidentally manipulate it and affect other watchers.
        return (object)(array)$this->records[$tablename][$objectid];
    }

    /**
     * Return the record from the watched table that triggered the notification
     *
     * @param int $strictness
     * @return \stdClass|null
     */
    public function get_record($strictness = IGNORE_MISSING): ?\stdClass {
        return $this->get_snapshot($this->watcher->tablename, $this->objectid, $strictness);
    }
}
