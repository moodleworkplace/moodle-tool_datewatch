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
 * tool_datewatch_manager
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_datewatch_manager {
    /** @var tool_datewatch_watcher[] */
    protected static $watchers;
    /** @var string */
    protected $component;
    /** @var tool_datewatch_watcher[] */
    protected $componentwatchers = [];

    /**
     * Constructor
     *
     * @param string $component
     */
    private function __construct(string $component) {
        $this->component = $component;
    }

    protected static function get_unique_key(stdClass $data) {
        if (!empty($data->shortname)) {
            return $data->component . '/' . $data->shortname;
        }
        return $data->component . '/' . $data->tablename . '/' . $data->fieldname;
    }

    public static function fetch_watchers(bool $unregisteroldwatchers = false) {
        global $DB;
        $watchers = [];
        $plugins = get_plugins_with_function('datewatch');

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            // For unittests also read the tests definitions.
            $pluginstest = get_plugins_with_function('test_datewatch', 'tests/generator/datewatch.php');
            $plugins = array_merge_recursive($plugins, $pluginstest);
        }

        foreach ($plugins as $plugintype => $funcs) {
            foreach ($funcs as $pluginname => $functionname) {
                $manager = new self($plugintype . '_' . $pluginname);
                call_user_func_array($functionname, [$manager]);
                foreach ($manager->componentwatchers as $watcher) {
                    $uniquekey = self::get_unique_key($watcher->to_object());
                    $watchers[$uniquekey] = $watcher;
                }
            }
        }

        self::$watchers = [];

        if (!$unregisteroldwatchers && empty($watchers)) {
            return self::$watchers;
        }

        $dbwatchers = $DB->get_records('tool_datewatch');
        $existing = [];
        foreach ($dbwatchers as $id => $dbwatcher) {
            $uniquekey = self::get_unique_key($dbwatcher);
            if (isset($watchers[$uniquekey])) {
                $existing[] = $uniquekey;
                self::$watchers[$id] = $watchers[$uniquekey];
                unset($dbwatchers[$id]);
            }
        }

        foreach ($watchers as $watcher) {
            $uniquekey = self::get_unique_key($watcher->to_object());
            if (!in_array($uniquekey, $existing)) {
                $id = self::register_watcher($watcher);
                self::$watchers[$id] = $watcher;
            }
        }

        if ($unregisteroldwatchers) {
            foreach ($dbwatchers as $id => $dbwatcher) {
                self::unregister_watcher($dbwatcher);
            }
        }

        return self::$watchers;
    }

    protected static function register_watcher(tool_datewatch_watcher $watcher): int {
        global $DB;
        $record = $watcher->to_object();
        $id = $DB->insert_record('tool_datewatch',
            ['component' => $record->component,
                'tablename' => $record->tablename,
                'fieldname' => $record->fieldname]);
        $sql = "INSERT INTO {tool_datewatch_upcoming} (datewatchid, tableid, timestamp)
                SELECT :datewatchid, id, ".$record->fieldname."
                FROM {".$record->tablename."}
                WHERE ".$record->fieldname.">=:now";
        $params = $record->params + ['datewatchid' => $id, 'now' => time() - MINSECS];
        if ($query = $record->query) {
            $sql .= ' AND '.$query;
        }
        try {
            $DB->execute($sql, $params);
        } catch (Exception $ex) {
            // TODO increase fail count for the watcher or mark it somehow as the faulty one.
            debugging('ERROR: unable to execute query to retrieve date field from the table: '.print_r($watcher, true),
                DEBUG_DEVELOPER);
        }
        return $id;
    }

    protected static function unregister_watcher($dbwatcher) {
        global $DB;
        $DB->execute('DELETE FROM {tool_datewatch_upcoming} WHERE datewatchid = ?', [$dbwatcher->id]);
        $DB->execute('DELETE FROM {tool_datewatch} WHERE id = ?', [$dbwatcher->id]);
    }

    public static function has_watchers(string $tablename): bool {
        return (bool)self::get_watchers($tablename, null);
    }

    /**
     *
     * @param string $tablename
     * @param stdClass $record
     * @return tool_datewatch_watcher[]
     */
    public static function get_watchers(string $tablename, ?stdClass $record = null): array {
        $watchers = [];
        if (self::$watchers === null) {
            self::fetch_watchers();
        }
        foreach (self::$watchers as $id => $watcher) {
            $watcherrecord = $watcher->to_object();
            if ($watcherrecord->tablename === $tablename &&
                    (!$record || $watcher->watch_callback($record))) {
                $watchers[$id] = $watcher;
            }
        }
        return $watchers;
    }

    public static function delete_upcoming(string $tablename, int $tableid): void {
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
     * @param string $tablename
     * @param stdClass $record
     */
    public static function create_upcoming(string $tablename, stdClass $record) {
        self::sync_upcoming([], self::prepare_upcoming($tablename, $record));
    }

    /**
     *
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
        foreach ($watchers as $id => $watcher) {
            $watcherexported = $watcher->to_object();
            $timestamp = $record->{$watcherexported->fieldname};
            $upcoming[] = ['datewatchid' => $id,
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
        foreach ($tonotifys as $tonotify) {
            if (array_key_exists($tonotify->datewatchid, self::$watchers)) {
                self::$watchers[$tonotify->datewatchid]->notify($tonotify->tableid, $tonotify->timestamp);
            }
        }
        list($sqlu, $paramsu) = $DB->get_in_or_equal(array_keys($tonotifys));
        $DB->execute("UPDATE {tool_datewatch_upcoming} SET notified = 1 WHERE id ".$sqlu, $paramsu);
    }

    /**
     * Allows plugins to start watching a date field. See README.md for examples
     *
     * @param string $tablename
     * @param string $fieldname
     * @return tool_datewatch_watcher
     */
    public function watch(string $tablename, string $fieldname): tool_datewatch_watcher {
        $watcher = new tool_datewatch_watcher($this->component, $tablename, $fieldname);
        $this->componentwatchers[] = $watcher;
        return $watcher;
    }
}
