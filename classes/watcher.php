<?php
// This file is part of Moodle - http://moodle.org/
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
class tool_datewatch_watcher {
    protected $component;
    protected $tablename;
    protected $fieldname;
    protected $callback;
    protected $watchcallback;
    protected $query;
    protected $params;
    protected $id;

    public function __construct($component, $tablename, $fieldname, $callback,
            $watchcallback = null, $query = null, $params = null) {
        $this->component = $component;
        $this->tablename = $tablename;
        $this->fieldname = $fieldname;
        $this->callback = $callback;
        $this->watchcallback = $watchcallback;
        $this->query = $query;
        $this->params = $params;
    }

    public final function validate($component) {
        return $this->get_component() === $component && $this->get_component()
                && $this->get_table_name() && $this->get_field_name();
    }

    public final function get_component() {
        return clean_param($this->component, PARAM_COMPONENT);
    }

    public final function get_table_name() {
        return clean_param($this->tablename, PARAM_ALPHANUMEXT);
    }

    public final function get_field_name() {
        return clean_param($this->fieldname, PARAM_ALPHANUMEXT);
    }

    public function set_id($id) {
        $this->id = $id;
    }

    public final function get_id() {
        return (int)$this->id;
    }

    public function get_query() {
        return $this->query;
    }

    public function get_params() {
        return $this->params ?: [];
    }

    public function watch_callback($record) {
        if ($this->watchcallback && is_callable($this->watchcallback)) {
            return call_user_func_array($this->watchcallback, [$record]);
        }
        return true;
    }

    public function notify($tableid, $timestamp) {
        if ($this->callback && is_callable($this->callback)) {
            call_user_func_array($this->callback, [$tableid, $timestamp]);
        }
    }
}
