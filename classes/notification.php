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
 * tool_datewatch\notification
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

    public function __construct(\tool_datewatch\task\watch $task) {
        $this->task = $task;
    }

    public function init(\tool_datewatch_watcher $watcher, int $objectid, int $fieldvalue): void {
        $this->watcher = $watcher;
        $this->objectid = $objectid;
        $this->fieldvalue = $fieldvalue;
    }

    public function get_task(): \tool_datewatch\task\watch {
        return $this->task;
    }

    public function get_watcher(): \tool_datewatch_watcher {
        return $this->watcher;
    }

    public function get_objectid(): int {
        return $this->objectid;
    }

    public function get_field_value(): int {
        return $this->fieldvalue;
    }

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
        return $this->records[$tablename][$objectid];
    }

    public function get_record($strictness = IGNORE_MISSING): ?\stdClass {
        return $this->get_snapshot($this->watcher->tablename, $this->objectid, $strictness);
    }
}