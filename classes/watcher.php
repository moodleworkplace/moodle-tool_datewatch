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
 * @property-read string $shortname
 * @property-read string $query
 * @property-read array $params
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
    /** @var string */
    protected $query;
    /** @var array */
    protected $params = [];
    /** @var string */
    protected $shortname;

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
        return null;
    }

    /**
     * Allows to set a unique shortname to the watcher, this is required if there are several watchers for the same field
     *
     * Every time the condition is changed, the shortname has to be updated, this will result in the re-index of the
     *
     * @param string $shortname
     * @return $this
     */
    public function set_shortname(string $shortname): self {
        $this->shortname = $shortname;
        return $this;
    }

    /**
     * Register callback that will be called when event occurs
     *
     * @param callable $callback accepts parameters (int $objectid, int $timestamp)
     * @return $this
     */
    public function set_callback(callable $callback): self {
        $this->callback = $callback;
        return $this;
    }

    /**
     * Adds a condition for the table records that need to be watched
     *
     * Adding condition allows to improve performance on how we scan the table initially and how
     * we monitor individual dates
     *
     * Examples of how datewatch retrieves records:
     *     $DB->get_records('SELECT * FROM {'.$this->tablename.'} WHERE ".$this->select, $this->params);
     *
     *     $DB->get_records('SELECT * FROM {'.$this->tablename.'} WHERE id=:objectid AND ".$this->select,
     *         $this->params + ['objectid' => $objectid]);
     *
     * @param string $select SQL expression to be used in a query
     * @param array $params named parameters for the select
     * @return $this
     */
    public function set_condition(string $select, array $params = []): self {
        $this->query = $select;
        $this->params = $params;
        return $this;
    }

    /**
     * Convert to string, normally used in error messages about broken watcher definitions
     *
     * @return string
     */
    public function __toString() {
        if ($this->shortname) {
            return $this->component . ' / ' . $this->shortname;
        } else {
            return $this->component . ' / ' . $this->tablename . ' / ' . $this->fieldname;
        }
    }
}
