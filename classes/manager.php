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
class tool_datewatch_manager {
    /** @var tool_datewatch_watcher[] Watchers defined in the plugins callbacks, indexed by hash */
    protected static $watchers;
    /** @var int[] Watchers present in the database, hash=>id */
    protected static $dbwatchers = [];
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

    /**
     * Reset caches (to be called from unittests)
     */
    public static function reset_caches() {
        self::$watchers = null;
        self::$dbwatchers = [];
    }

    /**
     * Calculates an unique key for either an exported watcher or a record from the watcher table
     *
     * @param tool_datewatch_watcher $watcher
     * @return string
     */
    protected static function get_watcher_hash(tool_datewatch_watcher $watcher) {
        return md5($watcher->component .
            '|' . $watcher->tablename .
            '|' . $watcher->fieldname .
            '|' . $watcher->offset .
            '|' . $watcher->query .
            '|' . json_encode($watcher->params) .
            '|' . $watcher->shortname);
    }

    /**
     * Get all watchers defined in plugins
     *
     * @param bool $reconcile if executed from cron and we also need to reconcile with DB and re-index
     */
    public static function fetch_watchers(bool $reconcile = false) {
        global $DB;
        self::$watchers = [];
        $plugins = get_plugins_with_function('datewatch');

        foreach ($plugins as $plugintype => $funcs) {
            foreach ($funcs as $pluginname => $functionname) {
                $manager = new self($plugintype . '_' . $pluginname);
                call_user_func_array($functionname, [$manager]);
                foreach ($manager->componentwatchers as $watcher) {
                    $hash = self::get_watcher_hash($watcher);
                    self::$watchers[$hash] = $watcher;
                }
            }
        }

        if (!$reconcile && empty(self::$watchers)) {
            // Do not bother querying DB if there are no watchers in callbacks.
            self::$dbwatchers = [];
            return;
        }

        self::$dbwatchers = $DB->get_records_menu('tool_datewatch', [], '', 'hash, id');

        if (!$reconcile) {
            return;
        }

        foreach (self::$watchers as $hash => $watcher) {
            if (!array_key_exists($hash, self::$dbwatchers)) {
                self::$dbwatchers[$hash] = self::register_watcher($watcher);
            }
        }

        foreach (self::$dbwatchers as $hash => $id) {
            if (!array_key_exists($hash, self::$watchers)) {
                self::unregister_watcher($id);
            }
        }
    }

    /**
     * Register watcher in the db, re-index the field
     *
     * @param tool_datewatch_watcher $watcher
     * @return int
     */
    protected static function register_watcher(tool_datewatch_watcher $watcher): int {
        global $DB;
        $id = $DB->insert_record('tool_datewatch',
            [
                'hash' => self::get_watcher_hash($watcher),
                'component' => $watcher->component,
                'tablename' => $watcher->tablename,
                'fieldname' => $watcher->fieldname,
            ]);
        $sql = "INSERT INTO {tool_datewatch_upcoming} (datewatchid, objectid, timestamp)
                SELECT :datewatchid, id, ".$watcher->fieldname." + ".$watcher->offset."
                FROM {".$watcher->tablename."}
                WHERE ".$watcher->fieldname." + ".$watcher->offset.">=:now";
        $params = $watcher->params + ['datewatchid' => $id, 'now' => time() - MINSECS];
        if ($query = $watcher->query) {
            $sql .= ' AND '.$query;
        }
        try {
            $DB->execute($sql, $params);
        } catch (Exception $ex) {
            debugging('Invalid watcher definition ' . $watcher,
                DEBUG_DEVELOPER);
        }
        return $id;
    }

    /**
     * Unregister watcher from the db
     *
     * @param int $dbwatcherid
     */
    protected static function unregister_watcher(int $dbwatcherid) {
        global $DB;
        $DB->execute('DELETE FROM {tool_datewatch_upcoming} WHERE datewatchid = ?', [$dbwatcherid]);
        $DB->execute('DELETE FROM {tool_datewatch} WHERE id = ?', [$dbwatcherid]);
    }

    /**
     * Get watchers for a given table / record
     *
     * @param string $tablename
     * @param int $tableid id of the record in the table (optional)
     * @return tool_datewatch_watcher[]
     */
    public static function get_watchers(string $tablename, ?int $tableid = null): array {
        $watchers = [];
        if (self::$watchers === null) {
            self::fetch_watchers();
        }
        foreach (self::$watchers as $hash => $watcher) {
            if (array_key_exists($hash, self::$dbwatchers) &&
                    $watcher->tablename === $tablename &&
                    (!$tableid || self::is_table_record_watchable($watcher, $tableid))) {
                $watchers[self::$dbwatchers[$hash]] = $watcher;
            }
        }
        return $watchers;
    }

    /**
     * Checks if a record in the table should be watched (using "query" and "params" from the watcher)
     *
     * @param tool_datewatch_watcher $watcher
     * @param int $tableid
     * @return bool
     */
    protected static function is_table_record_watchable(tool_datewatch_watcher $watcher, int $tableid): bool {
        global $DB;
        if ($watcher->query) {
            try {
                if (!$DB->get_record_select($watcher->tablename, "id = :objectid AND " . $watcher->query,
                    $watcher->params + ['objectid' => $tableid])) {
                    return false;
                }
            } catch (dml_exception $e) {
                debugging('Invalid condition query defined in the date watcher ' . $watcher,
                    DEBUG_DEVELOPER);
                return false;
            }
        }
        return true;
    }

    /**
     * Caches the upcoming dates modified in the event
     *
     * @param \core\event\base $event
     * @param string|null $tablename
     * @param int|null $tableid
     */
    public static function process_event(\core\event\base $event, ?string $tablename, ?int $tableid) {
        global $DB;
        if ($tablename && $tableid) {
            if (!$watchers = self::get_watchers($tablename)) {
                return;
            }
            list($sql, $params) = $DB->get_in_or_equal(array_keys($watchers), SQL_PARAMS_NAMED);
            $select = 'datewatchid ' . $sql . ' AND objectid = :objectid';
            $params += ['objectid' => $tableid];
            if ($event->crud === 'd') {
                if (!$DB->record_exists($tablename, ['id' => $tableid])) {
                    // Check the record was actually deleted, some events like 'course_content_deleted'
                    // may be triggered when the record is still present.
                    $DB->delete_records_select('tool_datewatch_upcoming', $select, $params);
                }
            } else if ($event->crud === 'u' || $event->crud === 'c') {
                $currentupcoming = $DB->get_records_select('tool_datewatch_upcoming', $select, $params);
                self::sync_upcoming($currentupcoming, self::prepare_upcoming($tablename, $tableid, $event));
            }
        }
    }

    /**
     * Prepare records to insert into the upcoming table
     *
     * @param string $tablename
     * @param int $tableid
     * @param \core\event\base $event
     * @return array[]
     */
    protected static function prepare_upcoming(string $tablename, int $tableid, \core\event\base $event) {
        $upcoming = [];
        if (($watchers = self::get_watchers($tablename, $tableid)) &&
                ($record = $event->get_record_snapshot($tablename, $tableid))) {
            foreach ($watchers as $id => $watcher) {
                $timestamp = (int)$record->{$watcher->fieldname} + $watcher->offset;
                $upcoming[] = [
                    'datewatchid' => $id,
                    'objectid' => $record->id,
                    'timestamp' => $timestamp,
                ];
            }
        }
        return $upcoming;
    }

    /**
     * Synchronises the upcoming dates records that are currently in the DB with the newly calculated ones
     *
     * @param stdClass[] $currentupcoming
     * @param array[] $upcoming
     */
    protected static function sync_upcoming(array $currentupcoming, array $upcoming) {
        global $DB;
        // Find which records need to be deleted or inserted or updated.
        $toinsert = $toupdate = [];
        $todelete = $currentupcoming;
        foreach ($upcoming as $u) {
            foreach ($currentupcoming as $c) {
                if ($u['objectid'] == $c->objectid && $u['datewatchid'] == $c->datewatchid) {
                    if ($u['timestamp'] != $c->timestamp) {
                        $toupdate[] = [
                            'id' => $c->id,
                            'timestamp' => $u['timestamp'],
                            'notified' => ($u['timestamp'] > time() - MINSECS) ? 0 : 1,
                        ];
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
                return $element['timestamp'] > time() - MINSECS;
            });
            if ($toinsert) {
                $DB->insert_records('tool_datewatch_upcoming', $toinsert);
            }
        }
        foreach ($toupdate as $u) {
            $DB->update_record('tool_datewatch_upcoming', $u);
        }
    }

    /**
     * Get watcher definition from the id in the database table
     *
     * @param int $datewatchid
     * @return tool_datewatch_watcher|null
     */
    protected static function get_watcher_from_id(int $datewatchid): ?tool_datewatch_watcher {
        $hashes = array_flip(self::$dbwatchers);
        if (!array_key_exists($datewatchid, $hashes)) {
            return null;
        }
        return self::$watchers[$hashes[$datewatchid]] ?? null;
    }

    /**
     * Checks if any watched date has happened, execute callback and mark as notified (called from the scheduled task)
     */
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
            if (($watcher = self::get_watcher_from_id($tonotify->datewatchid)) &&
                    ($callback = $watcher->callback)) {
                try {
                    $callback($tonotify->objectid, $tonotify->timestamp);
                } catch (Throwable $t) {
                    debugging('Exception calling callback in the date watcher ' . $watcher,
                     DEBUG_DEVELOPER);
                }
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
     * @param int $offset
     * @return tool_datewatch_watcher
     */
    public function watch(string $tablename, string $fieldname, int $offset = 0): tool_datewatch_watcher {
        $watcher = new tool_datewatch_watcher($this->component, $tablename, $fieldname, $offset);
        $this->componentwatchers[] = $watcher;
        return $watcher;
    }
}
