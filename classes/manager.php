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

use stdClass;

/**
 * tool_datewatch_manager
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var self */
    static private $manager = null;
    /** @var string */
    static private $key = null;
    /** @var watcher[] Watchers defined in the plugins callbacks, indexed by hash */
    protected $watchers;
    /** @var \stdClass[] Watchers present in the database */
    protected $dbwatchers = [];

    /**
     * Returns a single instance of this class that is constant within the request and is reset for each unittest
     *
     * @return manager|null
     * @throws \coding_exception
     */
    public static function singleton() {
        // Use the identifier stored in the request cache so that each run of unittests has a different instance
        // of the singleton (caches are reset between the unittests).
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'tool_datewatch', 'manager');
        if (self::$manager === null || self::$key === null || $cache->get('key') !== self::$key) {
            // Create new instance of the singleton.
            self::$key = random_string(32);
            $cache->set('key', self::$key);
            self::$manager = new self();
        }
        return self::$manager;
    }

    /**
     * Constructor
     */
    private function __construct() {
    }

    /**
     * Reset caches (to be called from unittests)
     */
    public static function reset_caches() {
        self::$key = null;
    }

    /**
     * Get all watchers defined in plugins
     */
    protected function fetch_watchers() {
        global $DB;
        $this->watchers = [];
        $this->dbwatchers = [];
        $plugins = get_plugins_with_function('datewatch');

        foreach ($plugins as $plugintype => $funcs) {
            foreach ($funcs as $pluginname => $functionname) {
                $compwatchers = call_user_func_array($functionname, []);
                if (!$compwatchers) {
                    continue;
                }
                if (!is_array($compwatchers)) {
                    debugging('Function '.$functionname.' must return an array of \tool_datewatch\watcher', DEBUG_DEVELOPER);
                    continue;
                }
                foreach ($compwatchers as $idx => $watcher) {
                    if (!$watcher || !is_object($watcher) || !($watcher instanceof watcher)) {
                        debugging('Function '.$functionname.' returned invalid object at array index '.$idx, DEBUG_DEVELOPER);
                        continue;
                    }
                    $watcher->component = $plugintype . '_' . $pluginname;
                    $this->watchers[] = $watcher;
                }
            }
        }

        if (!empty($this->watchers)) {
            $this->dbwatchers = $DB->get_records('tool_datewatch');
        }
    }

    /**
     * Create index of the database field the first time a watcher is added
     */
    protected function initial_index_of_watchers(): void {
        global $DB;
        $fields = [];
        $this->fetch_watchers();
        foreach ($this->watchers as $watcher) {
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
            if (!array_key_exists($key, $fields)) {
                $this->unregister_watcher($record->id);
            } else {
                $dbwatchers[$key] = $record;
            }
        }

        foreach ($fields as $key => $record) {
            if (!array_key_exists($key, $dbwatchers)) {
                $dbwatchers[$key] = $this->register_watcher($record);
            } else if ($record->maxoffset > $dbwatchers[$key]->maxoffset) {
                // TODO only update.
                $this->unregister_watcher($dbwatchers[$key]->id);
                $dbwatchers[$key] = $this->register_watcher($record);
            }
        }

        $this->dbwatchers = $DB->get_records('tool_datewatch');
    }

    /**
     * Register watcher in the db, re-index the field
     *
     * @param stdClass $record
     * @return stdClass
     */
    protected function register_watcher(stdClass $record): stdClass {
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
        } catch (\Exception $ex) {
            debugging('Invalid watcher definition ' . $record->tablename . ' / ' . $record->fieldname . ': ' .
                $ex->getMessage(),
                DEBUG_DEVELOPER);
        }
        return $record;
    }

    /**
     * Unregister watcher from the db
     *
     * @param int $dbwatcherid
     */
    protected function unregister_watcher(int $dbwatcherid) {
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
    private function get_db_watchers_for_table(string $tablename): array {
        $watchers = [];
        if ($this->watchers === null) {
            $this->fetch_watchers();
        }
        foreach ($this->dbwatchers as $record) {
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
    public function process_event(\core\event\base $event, ?string $tablename, ?int $tableid) {
        global $DB;
        if ($tablename && $tableid) {
            if (!$dbwatchers = $this->get_db_watchers_for_table($tablename)) {
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
                $this->sync_upcoming($currentupcoming, $this->prepare_upcoming($tablename, $tableid, $event));
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
    protected function prepare_upcoming(string $tablename, int $tableid, \core\event\base $event) {
        $upcoming = [];
        if (($dbwatchers = $this->get_db_watchers_for_table($tablename)) &&
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
     * @param \stdClass[] $currentupcoming
     * @param array[] $upcoming
     */
    protected function sync_upcoming(array $currentupcoming, array $upcoming) {
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
     *
     * @param \tool_datewatch\task\watch $task
     */
    public function monitor_upcoming(\tool_datewatch\task\watch $task) {
        global $DB;

        $this->initial_index_of_watchers();

        if (!$this->dbwatchers) {
            return;
        }

        $now = time();
        sleep(1); // To prevent race conditions when some record was updated/inserted at the same second by another process.

        $notification = new \tool_datewatch\notification($task);
        foreach ($this->dbwatchers as $dbwatcher) {
            // For each watcher registered in the DB find all callbacks and offsets.
            $watchers = array_filter($this->watchers, function($watcher) use ($dbwatcher) {
                return $watcher->callback &&
                    $watcher->fieldname === $dbwatcher->fieldname && $watcher->tablename === $dbwatcher->tablename;
            });
            if ($watchers) {
                $offsets = array_map(function($watcher) {
                    return $watcher->offset;
                }, $watchers);

                $sql = "SELECT *
                    FROM {tool_datewatch_upcoming}
                    WHERE value + :maxoffset > :lastcheck
                      AND value + :minoffset <= :date";
                $params = [
                    'date' => $now,
                    'lastcheck' => $dbwatcher->lastcheck,
                    'minoffset' => min($offsets),
                    'maxoffset' => max($offsets),
                ];
                $upcomingrecords = $DB->get_records_sql($sql, $params);

                foreach ($upcomingrecords as $tonotify) {
                    foreach ($watchers as $watcher) {
                        $notifytime = $tonotify->value + $watcher->offset;
                        if ($notifytime > $dbwatcher->lastcheck && $notifytime <= $now) {
                            try {
                                $notification->init($watcher, $tonotify->objectid, $tonotify->value);
                                $callback = $watcher->callback;
                                $callback($notification);
                            } catch (\Throwable $t) {
                                debugging('Exception calling callback in the date watcher ' . $watcher .
                                    ": ".$t->getMessage(),
                                    DEBUG_DEVELOPER);
                            }
                        }
                    }
                }
            }

            $DB->update_record('tool_datewatch', ['id' => $dbwatcher->id, 'lastcheck' => $now]);
        }
    }
}
