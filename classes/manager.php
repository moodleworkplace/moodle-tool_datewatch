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
    /** @var stdClass[] Watchers present in the database */
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
     * Get all watchers defined in plugins
     */
    public static function fetch_watchers() {
        global $DB;
        self::$watchers = [];
        self::$dbwatchers = [];
        $plugins = get_plugins_with_function('datewatch');

        foreach ($plugins as $plugintype => $funcs) {
            foreach ($funcs as $pluginname => $functionname) {
                $manager = new self($plugintype . '_' . $pluginname);
                call_user_func_array($functionname, [$manager]);
                foreach ($manager->componentwatchers as $watcher) {
                    self::$watchers[] = $watcher;
                }
            }
        }

        if (!empty(self::$watchers)) {
            self::$dbwatchers = $DB->get_records('tool_datewatch');
        }
    }

    protected static function initial_index_of_watchers() {
        global $DB;
        $fields = [];
        self::fetch_watchers();
        foreach (self::$watchers as $watcher) {
            $key = $watcher->tablename . '/' . $watcher->fieldname;
            if (!isset($fields[$key])) {
                $fields[$key] = (object)[
                    'tablename' => $watcher->tablename,
                    'fieldname' => $watcher->fieldname,
                    'maxoffset' => $watcher->offset,
                ];
            } else if ($fields[$key]->maxoffset < $watcher->offset) {
                $fields[$key]->maxoffset = $watcher->offset;
            }
        }
        $dbwatchers = [];
        $records = $DB->get_records('tool_datewatch');
        foreach ($records as $record) {
            $key = $record->tablename . '/' . $record->fieldname;
            $dbwatchers[$key] = $record;
        }

        foreach ($dbwatchers as $key => $record) {
            if (!array_key_exists($key, $fields)) {
                self::unregister_watcher($record->id);
            }
        }

        foreach ($fields as $key => $record) {
            if (!array_key_exists($key, $dbwatchers)) {
                $dbwatchers[$key] = self::register_watcher($record);
            } else if ($record->maxoffset > $dbwatchers[$key]->maxoffset) {
                // TODO only update.
                self::unregister_watcher($record->id);
                $dbwatchers[$key] = self::register_watcher($record);
            }
        }

        self::$dbwatchers = $DB->get_records('tool_datewatch');
        return $dbwatchers;
    }

    /**
     * Register watcher in the db, re-index the field
     *
     * @param tool_datewatch_watcher $watcher
     * @return stdClass
     */
    protected static function register_watcher(stdClass $record): stdClass {
        global $DB;
        $record->lastcheck = time();
        $record->id = $DB->insert_record('tool_datewatch', $record);
        $sql = "INSERT INTO {tool_datewatch_upcoming} (datewatchid, objectid, value)
                SELECT :datewatchid, id, ".$record->fieldname."
                FROM {".$record->tablename."}
                WHERE ".$record->fieldname." >= :minvalue";
        $params = ['datewatchid' => $record->id, 'minvalue' => time() - $record->maxoffset];
        try {
            $DB->execute($sql, $params);
        } catch (Exception $ex) {
            debugging('Invalid watcher definition ' . $record->tablename . ' / ' . $record->fieldname,
                DEBUG_DEVELOPER);
        }
        return $record;
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
     * Get watchers for a given table / record (only the ones that are already indexed in the DB)
     *
     * @param string $tablename
     * @return stdClass[] list of records from {tool_datewatch} table indexed by id
     */
    public static function get_db_watchers_for_table(string $tablename): array {
        $watchers = [];
        if (self::$watchers === null) {
            self::fetch_watchers();
        }
        foreach (self::$dbwatchers as $record) {
            if ($record->tablename === $tablename) {
                $watchers[$record->id] = $record;
            }
        }
        return $watchers;
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
            if (!$dbwatchers = self::get_db_watchers_for_table($tablename)) {
                return;
            }
            list($sql, $params) = $DB->get_in_or_equal(array_keys($dbwatchers), SQL_PARAMS_NAMED);
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
        if (($dbwatchers = self::get_db_watchers_for_table($tablename)) &&
                ($record = $event->get_record_snapshot($tablename, $tableid))) {
            foreach ($dbwatchers as $id => $dbwatcher) {
                $value = (int)($record->{$dbwatcher->fieldname} ?? 0);
                if ($value + $dbwatcher->maxoffset >= time()) {
                    $upcoming[] = [
                        'datewatchid' => $id,
                        'objectid' => $record->id,
                        'value' => $value,
                    ];
                }
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
                    if ($u['value'] != $c->value) {
                        $toupdate[] = [
                            'id' => $c->id,
                            'value' => $u['value'],
                            'notified' => ($u['value'] > time() - MINSECS) ? 0 : 1,
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
                return $element['value'] > time() - MINSECS;
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
     * Checks if any watched date has happened, execute callback and mark as notified (called from the scheduled task)
     */
    public static function monitor_upcoming() {
        global $DB;

        $dbwatchers = self::initial_index_of_watchers();

        if (!self::$dbwatchers) {
            return;
        }

        $toupdate = [];
        $now = time();
        sleep(1); // To prevent race conditions when some record was updated/inserted at the same second by another process.
        foreach (self::$watchers as $watcher) {
            if ($callback = $watcher->callback) {
                $key = $watcher->tablename . '/' . $watcher->fieldname;
                $record = $dbwatchers[$key] ?? null;
                if ($record) {
                    $sql = "SELECT *
                    FROM {tool_datewatch_upcoming}
                    WHERE value > :lastcheck AND value <= :date";
                    $params = ['date' => $now - $watcher->offset, 'lastcheck' => $record->lastcheck - $watcher->offset];
                    $tonotifys = $DB->get_records_sql($sql, $params);
                    foreach ($tonotifys as $tonotify) {
                        try {
                            $callback($tonotify->objectid, $tonotify->value);
                        } catch (Throwable $t) {
                            debugging('Exception calling callback in the date watcher ' . $watcher,
                                DEBUG_DEVELOPER);
                        }
                    }
                    $toupdate[$dbwatchers[$key]->id] = ['id' => $dbwatchers[$key]->id, 'lastcheck' => $now];
                }
            }
        }

        foreach ($toupdate as $dataobject) {
            $DB->update_record('tool_datewatch', $dataobject);
        }
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
