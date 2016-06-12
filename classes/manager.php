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
 * tool_datewatch_manager
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
class tool_datewatch_manager {
    /** @var tool_datewatch_watcher[] */
    protected static $watchers;

    public static function fetch_watchers($registernewwatchers = false, $verbose = false) {
        global $DB;
        self::$watchers = [];
        $plugins = get_plugins_with_function('datewatch');
        foreach ($plugins as $plugintype => $funcs) {
            foreach ($funcs as $pluginname => $functionname) {
                $pluginwatchers = call_user_func_array($functionname, []);
                foreach ($pluginwatchers as $watcher) {
                    self::add_watcher($plugintype . '_' . $pluginname, $watcher, $verbose);
                }
            }
        }

        if (!$registernewwatchers && !self::$watchers) {
            return;
        }

        $dbwatchers = $DB->get_records('tool_datewatch');
        foreach ($dbwatchers as $id => $dbwatcher) {
            $uniquekey = $dbwatcher->component . '/' . $dbwatcher->tablename . '/' . $dbwatcher->fieldname;
            if (isset(self::$watchers[$uniquekey])) {
                self::$watchers[$uniquekey]->set_id($id);
                unset($dbwatchers[$id]);
            }
        }

        if ($registernewwatchers) {
            foreach (self::$watchers as $watcher) {
                if (!$watcher->get_id()) {
                    self::register_watcher($watcher, $verbose);
                }
            }
        }

        foreach ($dbwatchers as $id => $dbwatcher) {
            self::unregister_watcher($dbwatcher);
        }
    }

    protected static function add_watcher($component, $watcher, $verbose = false) {
        if (!$watcher) {
            return;
        }
        if (!($watcher instanceof tool_datewatch_watcher)) {
            if ($verbose) {
                mtrace('ERROR: Invalid watcher for component '.$component.', must be an instance of tool_datewatch_watcher: '.print_r($watcher,true));
            }
            return;
        }
        if (!$watcher->validate($component)) {
            if ($verbose) {
                mtrace('ERROR: Invalid watcher for component '.$component.', verify component, tablename and fieldname: '.print_r($watcher,true));
            }
            return;
        }

        $uniquekey = $watcher->get_component() . '/' . $watcher->get_table_name() . '/' . $watcher->get_field_name();
        $watcher->set_id(null);
        if (array_key_exists($uniquekey, self::$watchers)) {
            if ($verbose) {
                mtrace('ERROR: Duplicate watcher ignored: '.print_r($watcher, true));
            }
        } else {
            self::$watchers[$uniquekey] = $watcher;
        }
    }

    protected static function register_watcher(tool_datewatch_watcher $watcher, $verbose = false) {
        global $DB;
        $id = $DB->insert_record('tool_datewatch',
            ['component' => $watcher->get_component(),
                'tablename' => $watcher->get_table_name(),
                'fieldname' => $watcher->get_field_name()]);
        $watcher->set_id($id);
        $sql = "INSERT INTO {tool_datewatch_upcoming} (datewatchid, tableid, timestamp)
                SELECT :datewatchid, id, ".$watcher->get_field_name()."
                FROM {".$watcher->get_table_name()."}
                WHERE ".$watcher->get_field_name().">=:now";
        $params = $watcher->get_params() + ['datewatchid' => $id, 'now' => time() - MINSECS];
        if ($query = $watcher->get_query()) {
            $sql .= ' AND '.$query;
        }
        try {
            $DB->execute($sql, $params);
        } catch (Exception $ex) {
            if ($verbose) {
                mtrace('ERROR: unable to execute query to retrieve date field from the table: '.print_r($watcher, true));
            }
        }
    }

    protected static function unregister_watcher($dbwatcher) {
        global $DB;
        $DB->execute('DELETE FROM {tool_datewatch_upcoming} WHERE datewatchid = ?', [$dbwatcher->id]);
        $DB->execute('DELETE FROM {tool_datewatch} WHERE id = ?', [$dbwatcher->id]);
    }

    /**
     *
     * @param string $tablename
     * @param stdClass $record
     * @return tool_datewatch_watcher[]
     */
    public static function get_watchers($tablename, $record = null) {
        $watchers = [];
        if (self::$watchers === null) {
            self::fetch_watchers();
        }
        foreach (self::$watchers as $watcher) {
            if ($watcher->get_table_name() === $tablename &&
                    (!$record || $watcher->watch_callback($record))) {
                $watchers[$watcher->get_id()] = $watcher;
            }
        }
        return $watchers;
    }

    public static function delete_upcoming($tablename, $tableid) {
        global $DB;
        if (!$watchers = self::get_watchers($tablename)) {
            return;
        }
        list($sql, $params) = $DB->get_in_or_equal(array_keys($watchers));
        $params[] = $tableid;
        $DB->delete_records_select('tool_datewatch_upcoming',
                'datewatchid ' . $sql . ' AND tableid = ?', $params);
    }

    /**
     *
     * @global moodle_database $DB
     * @param type $tablename
     * @param type $crud
     * @param type $record
     */
    public static function create_upcoming($tablename, $record) {
        self::sync_upcoming([], self::prepare_upcoming($tablename, $record));
    }

    /**
     *
     * @global moodle_database $DB
     * @param string $tablename
     * @param stdClass $record
     */
    public static function update_upcoming($tablename, $record) {
        global $DB;
        if (!$watchers = self::get_watchers($tablename)) {
            return;
        }
        list($sql, $params) = $DB->get_in_or_equal(array_keys($watchers));
        $params[] = $record->id;
        $currentupcoming = $DB->get_records_select('tool_datewatch_upcoming',
                'datewatchid ' . $sql . ' AND tableid = ?', $params);
        self::sync_upcoming($currentupcoming, self::prepare_upcoming($tablename, $record));
    }

    /**
     *
     * @param string $tablename
     * @param stdClass $record
     * @return array[]
     */
    protected static function prepare_upcoming($tablename, $record) {
        $watchers = self::get_watchers($tablename, $record);
        $upcoming = [];
        foreach ($watchers as $watcher) {
            $timestamp = $record->{$watcher->get_field_name()};
            $upcoming[] = ['datewatchid' => $watcher->get_id(),
                            'tableid' => $record->id,
                            'timestamp' => $timestamp];
        }
        return $upcoming;
    }

    protected static function is_future_date($timestamp) {
        return $timestamp > time() - MINSECS;
    }


    /**
     *
     * @global moodle_database $DB
     * @param stdClass[] $currentupcoming
     * @param array[] $upcoming
     */
    protected static function sync_upcoming($currentupcoming, $upcoming) {
        global $DB;
        // Find which records ned to be deleted or inserted or updated.
        $toinsert = $toupdate = [];
        $todelete = $currentupcoming;
        foreach ($upcoming as $u) {
            foreach ($currentupcoming as $c) {
                if ($u['tableid'] == $c->tableid && $u['datewatchid'] == $c->datewatchid) {
                    if ($u['timestamp'] != $c->timestamp) {
                        $toupdate[] = ['id' => $c->id, 'timestamp' => $u['timestamp'],
                            'notified' => self::is_future_date($u['timestamp']) ? 0 : 1];
                    }
                    unset($todelete[$c->id]);
                    continue 2;
                }
            }
            $toinsert[] = $u;
        }
        // Perform delete/insert/update.
        if ($todelete) {
            $DB->delete_records_list('tool_datewatch_upcoming', 'id', array_keys($todelete));
        }
        if ($toinsert) {
            $toinsert = array_filter($toinsert, function($element) {
                return tool_datewatch_manager::is_future_date($element['timestamp']);
            });
            if ($toinsert) {
                $DB->insert_records('tool_datewatch_upcoming', $toinsert);
            }
        }
        foreach ($toupdate as $u) {
            $DB->update_record('tool_datewatch_upcoming', $u);
        }
    }

    public static function monitor_upcoming() {
        global $DB;
        $sql = "SELECT *
                FROM {tool_datewatch_upcoming}
                WHERE notified = 0 AND timestamp <= ".time();
        $tonotifys = $DB->get_records_sql($sql, ['now' => time()]);
        if (!$tonotifys) {
            return;
        }
        $watchersbyid = [];
        foreach (self::$watchers as $watcher) {
            $watchersbyid[$watcher->get_id()] = $watcher;
        }
        foreach ($tonotifys as $tonotify) {
            if (array_key_exists($tonotify->datewatchid, $watchersbyid)) {
                $watchersbyid[$tonotify->datewatchid]->notify($tonotify->tableid, $tonotify->timestamp);
            }
        }
        list($sqlu, $paramsu) = $DB->get_in_or_equal(array_keys($tonotifys));
        $DB->execute("UPDATE {tool_datewatch_upcoming} SET notified = 1 WHERE id ".$sqlu, $paramsu);
    }
}
