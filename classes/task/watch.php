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
 * Contains class tool_datewatch\task\watch
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_datewatch\task;

/**
 * Scheduled task for tool_datewatch
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 */
class watch extends \core\task\scheduled_task {

    /** @var string */
    protected $idnumber;

    /**
     * Constructor
     */
    public function __construct() {
        $this->idnumber = random_string(64);
    }

    /**
     * Unique idnumber for this tas
     *
     * @return string
     */
    public function get_task_idnumber(): string {
        return $this->idnumber;
    }

    /**
     * Get name.
     * @return string
     */
    public function get_name() {
        // Shown in admin screens.
        return get_string('taskname', 'tool_datewatch');
    }

    /**
     * Execute.
     */
    public function execute() {
        // Monitor dates.
        \tool_datewatch\manager::singleton()->monitor_upcoming($this);
    }
}
