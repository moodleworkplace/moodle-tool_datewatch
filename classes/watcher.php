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
 * Class tool_datewatch\watcher
 *
 * @property string $component
 * @property-read string $tablename
 * @property-read string $fieldname
 * @property-read int $offset
 * @property-read callable $callback
 * @property-read mixed $identifier
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class watcher {
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
    /** @var mixed */
    protected $identifier;

    /**
     * Constructor, use {@link self::instance()} instead
     */
    private function __construct() {
    }

    /**
     * Create a new instance of the watcher
     *
     * @param string $tablename
     * @param string $fieldname
     * @param int $offset
     * @return static
     */
    public static function instance(string $tablename, string $fieldname, int $offset = 0): self {
        $instance = new self();
        $instance->tablename = clean_param($tablename, PARAM_ALPHANUMEXT);
        $instance->fieldname = clean_param($fieldname, PARAM_ALPHANUMEXT);
        $instance->offset = $offset;
        return $instance;
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
     * Sets the property value (only used by the datewatch manager to set the component)
     *
     * @param string $name
     * @param mixed $value
     * @throws \coding_exception
     */
    public function __set($name, $value) {
        if ($name === 'component') {
            $this->component = clean_param($value, PARAM_COMPONENT);
        } else {
            debugging('Property '.$name.' does not exist or is not writable', DEBUG_DEVELOPER);
        }
    }

    /**
     * Register callback that will be called when event occurs
     *
     * @param callable $callback accepts single parameter (\tool_datewatch\notification $notification)
     * @return self
     */
    public function set_callback(callable $callback): self {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Any identifier that the plugin wants to assign to this watcher
     *
     * This can be useful if multiple watchers have the same callback, then inside the callback
     * the watcher identifier can be accessed as:
     *
     *     $notification->get_watcher()->identifier
     *
     * @param mixed $identifier
     * @return self
     */
    public function set_identifier($identifier): self {
        $this->identifier = $identifier;
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
