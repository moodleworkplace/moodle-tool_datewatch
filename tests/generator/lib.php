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
 * tool_datewatch data generator class.
 *
 * @package    tool_datewatch
 * @copyright  2021 Marina Glancy
 */
class tool_datewatch_generator extends component_generator_base {
    /** @var array */
    public static $watchers = [];

    /**
     * Mock some watchers
     *
     * This function is called from {@see tool_datewatch_datewatch()} in lib.php
     *
     * @param tool_datewatch_manager $manager
     */
    public static function register_watchers(tool_datewatch_manager $manager) {
        if (in_array('course', self::$watchers)) {
            $manager->watch('course', 'startdate')
                ->set_callback(function() {
                    null;
                })
                ->set_condition('format = :topicsformat', ['topicsformat' => 'topics']);
        }

        if (in_array('user_enrolments', self::$watchers)) {
            $manager->watch('user_enrolments', 'timeend')
                ->set_callback(function ($recordid, $datevalue) {
                    null;
                });
        }

        if (in_array('enrolnotification', self::$watchers)) {
            $manager->watch('user_enrolments', 'timeend', - 3 * DAYSECS)
                ->set_callback(function ($recordid, $datevalue) {
                    global $DB;
                    $uenrol = $DB->get_record('user_enrolments', ['id' => $recordid]);
                    self::send_message($uenrol->userid);
                });
        }

        if (in_array('course_broken_condition', self::$watchers)) {
            $manager->watch('course', 'startdate')
                ->set_callback(function() {
                    null;
                })
                ->set_condition('nonexistingfield = :topicsformat', ['topicsformat' => 'topics']);
        }

        if (in_array('enrol_broken_callback', self::$watchers)) {
            $manager->watch('user_enrolments', 'timeend')
                ->set_callback(function ($recordid, $datevalue) {
                    throw new \coding_exception('Oops');
                });
        }

        if (in_array('assign', self::$watchers)) {
            $manager->watch('assign', 'duedate')
                ->set_shortname('assignduedate')
                ->set_callback(function() {
                    null;
                });
        }
    }

    /**
     * Register a watcher for this unittest
     *
     * @param string $name specify name of the watcher - {@see self::register_watchers()}
     */
    public function register_watcher(string $name) {
        self::$watchers[] = $name;
        tool_datewatch_manager::reset_caches();
    }

    /**
     * Remove all watchers added in this unittest and clear all caches (to be called from tearDown())
     */
    public function remove_watchers() {
        self::$watchers = [];
        tool_datewatch_manager::reset_caches();
        (new tool_datewatch\task\watch())->execute();
        tool_datewatch_manager::reset_caches();
    }

    /**
     * Shift dates in both watched table and upcoming table by $delta seconds
     *
     * This function is used if uupz is not installed and we can't "time travel" for testing.
     *
     * @param string $component
     * @param string $table
     * @param int $objectid
     * @param string $field
     * @param int $delta
     */
    public function shift_dates(string $component, string $table, int $objectid, string $field, int $delta) {
        global $DB;
        $datewatchid = $DB->get_field_sql('SELECT id FROM {tool_datewatch} WHERE component = ? AND tablename = ? AND fieldname = ?',
            [$component, $table, $field]);
        $DB->execute("UPDATE {".$table."} SET $field = $field + ? WHERE id = ?", [$delta, $objectid]);
        $DB->execute('UPDATE {tool_datewatch_upcoming} SET timestamp = timestamp + ? WHERE tableid = ? AND datewatchid = ?',
            [$delta, $objectid, $datewatchid]);
    }

    /**
     * Send a message to a user
     *
     * @param int $userid
     */
    protected static function send_message(int $userid) {
        // Any core message will do here.
        $message = new \core\message\message();
        $message->component         = 'moodle';
        $message->name              = 'instantmessage';
        $message->userfrom          = core_user::get_noreply_user();
        $message->userto            = core_user::get_user($userid);
        $message->subject           = 'Your enrolment will end soon';
        $message->fullmessage       = 'Hello there';
        $message->fullmessageformat = FORMAT_MARKDOWN;
        $message->fullmessagehtml   = 'Hello there';
        $message->notification      = 0;

        message_send($message);
    }
}
