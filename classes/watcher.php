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
 * Class tool_datewatch_watcher
 *
 * @property-read string $component
 * @property-read string $tablename
 * @property-read string $fieldname
 * @property-read int $offset
 * @property-read callable $callback
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_datewatch_watcher {
    /** @var string */
    protected $component;
    /** @var string */
    protected $tablename;
    /** @var string */
    protected $fieldname;
    /** @var int */
    protected $offset = 0;
    /** @var callable */
    protected $callback;

    /**
     * Constructor
     *
     * @param string $component
     * @param string $tablename
     * @param string $fieldname
     * @param int $offset
     */
    public function __construct(string $component, string $tablename, string $fieldname, int $offset = 0) {
        $this->component = $component;
        $this->tablename = $tablename;
        $this->fieldname = $fieldname;
        $this->offset = $offset;
    }

    /**
     * Magic property getter
     *
     * @param string $name
     * @return null
     */
    public function __get($name) {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        debugging('Property '.$name.' does not exist', DEBUG_DEVELOPER);
        return null;
    }

    /**
     * Register callback that will be called when event occurs
     *
     * @param callable $callback accepts single parameter (\tool_datewatch\notification $notification)
     * @return $this
     */
    public function set_callback(callable $callback): self {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Convert to string, normally used in error messages about broken watcher definitions
     *
     * @return string
     */
    public function __toString() {
        return $this->component . ' / ' . $this->tablename . ' / ' . $this->fieldname;
    }
}
