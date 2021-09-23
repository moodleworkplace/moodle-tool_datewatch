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
 * Contains class local_ma_watcher
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class tool_datewatch_watcher
 *
 * @package   tool_datewatch
 * @copyright 2016 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_datewatch_watcher {
    protected $component;
    protected $tablename;
    protected $fieldname;
    protected $callback;
    protected $conditioncallback;
    protected $query;
    protected $params = [];
    protected $shortname;

    public function __construct($component, $tablename, $fieldname, $callback = null,
            $conditioncallback = null, $query = null, $params = []) {
        $this->component = $component;
        $this->tablename = $tablename;
        $this->fieldname = $fieldname;
        $this->callback = $callback;
        $this->conditioncallback = $conditioncallback;
        $this->query = $query;
        $this->params = $params;
    }

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

    public function set_condition(callable $conditioncallback, string $select, array $params = []): self {
        $this->conditioncallback = $conditioncallback;
        $this->query = $select;
        $this->params = $params;
        return $this;
    }

    public function watch_callback(stdClass $record) {
        if ($this->conditioncallback && is_callable($this->conditioncallback)) {
            return call_user_func_array($this->conditioncallback, [$record]);
        }
        return true;
    }

    /**
     * Notify callback that the date happened
     *
     * @param int $objectid
     * @param int $timestamp
     */
    public function notify(int $objectid, int $timestamp) {
        if ($this->callback && is_callable($this->callback)) {
            call_user_func_array($this->callback, [$objectid, $timestamp]);
        }
    }

    public function to_object(): stdClass {
        return (object)[
            'component' => $this->component,
            'tablename' => $this->tablename,
            'fieldname' => $this->fieldname,
            'callback' => $this->callback,
            'conditioncallback' => $this->conditioncallback,
            'query' => $this->query,
            'params' => $this->params,
            'shortname' => $this->shortname,
        ];
    }
//
//    public function __unserialize(array $data): void {
//        $this->component = $data['component'];
//        $this->tablename = $data['tablename'];
//        $this->fieldname = $data['fieldname'];
//        //$this->callback = $data['callback'];
//        //$this->conditioncallback = $data['conditioncallback'];
//        $this->query = $data['query'];
//        $this->params = $data['params'];
//        $this->shortname = $data['shortname'];
//    }
}
