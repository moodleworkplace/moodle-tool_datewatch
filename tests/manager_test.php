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

use advanced_testcase;
use tool_datewatch_generator;

/**
 * Class generator_test
 *
 * @package     tool_datewatch
 * @copyright   2021 Marina Glancy
 */
class manager_test extends advanced_testcase {

    /**
     * After each test
     */
    public function tearDown(): void {
        $this->get_generator()->remove_watchers();
        if (extension_loaded('uopz')) {
            // Revert function overrides.
            uopz_unset_return('time');
        }
    }

    /**
     * Get dynamic rule generator
     *
     * @return tool_datewatch_generator
     */
    protected function get_generator(): tool_datewatch_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_datewatch');
    }

    /**
     * Test initial re-indexing when watchers are registered.
     */
    public function test_watchers_reindex() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        // Create courses and enrolments.
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course(['startdate' => $now + 2 * DAYSECS]);
        $course3 = $this->getDataGenerator()->create_course(['startdate' => $now - 2 * DAYSECS]);
        $course4 = $this->getDataGenerator()->create_course(['startdate' => $now + 10 * DAYSECS]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student', 'manual', 0, $now + 21 * DAYSECS);
        // Enrolment without an enddate.
        $this->getDataGenerator()->enrol_user($user1->id, $course2->id, 'student', 'manual', 0, 0);
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student', 'manual', 0, $now + 22 * DAYSECS);
        // Enrolment with a date in the past.
        $this->getDataGenerator()->enrol_user($user2->id, $course2->id, 'student', 'manual', 0, $now - 5 * DAYSECS);
        // Enrolment that ends soon (no notification since it's less than 3 days in advance).
        $this->getDataGenerator()->enrol_user($user3->id, $course2->id, 'student', 'manual', 0, $now + 2 * DAYSECS);

        // First execution of the watch task will remember the current date and will not index anything before it.
        (new \tool_datewatch\task\watch())->execute();

        // Register watcher and run scheduled task, the courses and enrolments should be indexed.
        $this->get_generator()->register_watcher('course');
        $this->get_generator()->register_watcher('user_enrolments');
        $this->get_generator()->register_watcher('enrolnotification');
        (new \tool_datewatch\task\watch())->execute();

        // Get the watchers ids from the db so we can query the upcoming table.
        $records = $DB->get_records('tool_datewatch', null, 'id DESC', 'id', 0, 2);
        [$enrolwatcher, $coursewatcher] = array_keys($records);

        // Assert the records in the upcoming table correspond to the courses and enrolments we have created.
        $this->assertEqualsCanonicalizing([$now + 2 * DAYSECS, $now + 10 * DAYSECS],
            $DB->get_fieldset_select('tool_datewatch_upcoming', 'value', 'datewatchid=?', [$coursewatcher]));
        $this->assertEqualsCanonicalizing([$now + 21 * DAYSECS, $now + 22 * DAYSECS, $now + 2 * DAYSECS],
            $DB->get_fieldset_select('tool_datewatch_upcoming', 'value', 'datewatchid=?', [$enrolwatcher]));
    }

    /**
     * Test that event listener creates, updates and deletes records from upcoming table
     *
     * Watcher without offset
     */
    public function test_update_upcoming() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        $this->get_generator()->register_watcher('course');
        (new \tool_datewatch\task\watch())->execute();

        // Make sure the watcher for the course start date is created.
        $datewatch = $DB->get_record('tool_datewatch',
            ['tablename' => 'course', 'fieldname' => 'startdate']);
        $this->assertNotEmpty($datewatch);

        // Create three courses. Make sure only course that has start date in the future is
        // registered in the 'upcoming' table.
        $course2 = $this->getDataGenerator()->create_course(['format' => 'topics', 'startdate' => $now + 2 * DAYSECS]);
        $course3 = $this->getDataGenerator()->create_course(['format' => 'topics', 'startdate' => $now - 2 * DAYSECS]);
        $course4 = $this->getDataGenerator()->create_course(['format' => 'topics', 'startdate' => $now + 10 * DAYSECS]);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $expected2 = ['objectid' => $course2->id, 'value' => $now + 2 * DAYSECS];
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[0], $expected2));
        $expected4 = ['objectid' => $course4->id, 'value' => $now + 10 * DAYSECS];
        $this->assertEquals($expected4, array_intersect_key((array)$upcoming[1], $expected4));

        // Update course. The upcoming table should be updated respectfully.
        update_course((object)['id' => $course2->id, 'startdate' => $now + 5 * DAYSECS]);
        $expected2['value'] = $now + 5 * DAYSECS;
        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[0], $expected2));
        $this->assertEquals($expected4, array_intersect_key((array)$upcoming[1], $expected4));

        // Update course and set the startdate in the past. The respective upcoming will be removed.
        update_course((object)['id' => $course2->id, 'startdate' => $now - 5 * DAYSECS]);
        $expected2['value'] = $now - 5 * DAYSECS;
        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(1, count($upcoming));
        $this->assertEquals($expected4, array_intersect_key((array)$upcoming[0], $expected4));

        // Delete a course. The respective upcoming will be deleted.
        delete_course($course4->id, false);
        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(0, count($upcoming));
    }

    /**
     * Test that event listener creates, updates and deletes records from upcoming table
     *
     * Watcher without offset
     */
    public function test_update_upcoming_with_offset() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        // Register the watcher that will notify us 3 days before any enrolment ends.
        $this->get_generator()->register_watcher('enrolnotification');
        (new \tool_datewatch\task\watch())->execute();

        // Make sure the watcher for the course start date is created, there is nothing yet in upcoming table.
        $datewatch = $DB->get_record('tool_datewatch',
            ['tablename' => 'user_enrolments']);
        $this->assertNotEmpty($datewatch);
        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEmpty($upcoming);

        // Create course and two users.
        $course1 = $this->getDataGenerator()->create_course();
        $manual1 = $DB->get_record('enrol', ['courseid' => $course1->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $roleid = $DB->get_field('role', 'id', array('shortname' => 'student'), MUST_EXIST);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $manplugin = enrol_get_plugin('manual');

        // Enrol one user with the notification date in the future and another one in the past, make sure one record is created.
        $manplugin->enrol_user($manual1, $user1->id, $roleid, 0, $now + 21 * DAYSECS);
        $enrol1 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user1->id], IGNORE_MULTIPLE);
        $manplugin->enrol_user($manual1, $user2->id, $roleid, 0, $now + 2 * DAYSECS);
        $enrol2 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user2->id], IGNORE_MULTIPLE);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(1, count($upcoming));
        $expected1 = ['objectid' => $enrol1->id, 'value' => $now + 21 * DAYSECS];
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));

        // Update second enrolment to end in the future.
        $manplugin->update_user_enrol($manual1, $user2->id, null, null, $now + 22 * DAYSECS);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));
        $expected2 = ['objectid' => $enrol2->id, 'value' => $now + 22 * DAYSECS];
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[1], $expected2));

        // Update enrolment to end in the past, it will be removed from the 'upcoming' table.
        $manplugin->update_user_enrol($manual1, $user2->id, null, null, $now + 1 * DAYSECS);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(1, count($upcoming));
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));

        // Delete enrolment, the record in 'upcoming' will be deleted too.
        $manplugin->unenrol_user($manual1, $user1->id);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(0, count($upcoming));
    }

    /**
     * Test that callback is executed when event occurs.
     */
    public function test_datewatch_notifications() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        $this->get_generator()->register_watcher('enrolnotification');
        (new \tool_datewatch\task\watch())->execute();

        // Make sure the watcher for the course start date is created.
        $datewatch = $DB->get_record('tool_datewatch',
            ['tablename' => 'user_enrolments', 'fieldname' => 'timeend']);
        $this->assertNotEmpty($datewatch);

        // Create a course and enrolment, run cron, nothing should change.
        $course1 = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student', 'manual', 0, $now + 5 * DAYSECS);
        $enrol1 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user1->id], IGNORE_MULTIPLE);
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student', 'manual', 0, $now + 10 * DAYSECS);
        $enrol2 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user2->id], IGNORE_MULTIPLE);
        // User 3 should not be notified because the notification date is in the past, even though the timeend is in the future.
        $this->getDataGenerator()->enrol_user($user3->id, $course1->id, 'student', 'manual', 0, $now + 2 * DAYSECS);
        $enrol3 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user3->id], IGNORE_MULTIPLE);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $expected1 = ['objectid' => $enrol1->id, 'value' => $now + 5 * DAYSECS];
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));
        $expected2 = ['objectid' => $enrol2->id, 'value' => $now + 10 * DAYSECS];
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[1], $expected2));

        // Run cron - no messages, no changes to the upcoming table.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(0, $messages);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[1], $expected2));

        // Timetravel by 2 days.
        $delta = 2 * DAYSECS + MINSECS;
        if (extension_loaded('uopz')) {
            uopz_set_return('time', $now + $delta);
        } else {
            $this->get_generator()->shift_dates('user_enrolments', 'timeend', -$delta);
        }

        // Running cron will send a message to the user.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($user1->id, $messages[0]->useridto);
        $this->assertEquals('Your enrolment will end in 3 days', $messages[0]->subject);

        // Run cron again, no messages will be sent.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(0, $messages);
    }

    public function test_broken_watcher() {
        $this->resetAfterTest();

        // Catching exception when reindexing.
        $this->get_generator()->register_watcher('broken');
        (new \tool_datewatch\task\watch())->execute();
        $debugging = $this->getDebuggingMessages();
        $this->assertCount(1, $debugging);
        $debug = reset($debugging);
        $this->assertStringStartsWith('Invalid watcher definition course / nonexistingfield', $debug->message);
        $this->resetDebugging();
    }

    public function test_watcher_broken_callback() {
        global $DB;
        $this->resetAfterTest();

        $this->get_generator()->register_watcher('enrol_broken_callback');
        (new \tool_datewatch\task\watch())->execute();

        // Create a course and enrolment.
        $course1 = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student', 'manual', 0, time() + 5 * DAYSECS);
        $enrol1 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user1->id], IGNORE_MULTIPLE);

        // Timetravel by 5 days.
        $delta = 5 * DAYSECS + MINSECS;
        if (extension_loaded('uopz')) {
            uopz_set_return('time', time() + $delta);
        } else {
            $this->get_generator()->shift_dates('user_enrolments', 'timeend', -$delta);
        }

        (new \tool_datewatch\task\watch())->execute();
        $this->assertDebuggingCalled('Exception calling callback in the date watcher tool_datewatch / user_enrolments / timeend: '.
            'Coding error detected, it must be fixed by a programmer: Oops');
        $this->resetDebugging();
    }

    /**
     * Test watching a date in the course module table 'assign' that does not have its own events
     */
    public function test_watch_course_module() {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();

        $this->get_generator()->register_watcher('assign');
        (new \tool_datewatch\task\watch())->execute();
        $datewatch = $DB->get_record('tool_datewatch', ['tablename' => 'assign']);
        $this->assertNotEmpty($datewatch);

        // Create a course and module, make sure the date is watched.
        $course1 = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course1->id, 'duedate' => $now + 3 * DAYSECS]);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id]));
        $this->assertCount(1, $upcoming);
        $this->assertEquals($now + 3 * DAYSECS, $upcoming[0]->value);

        // Test update module.
        $formdata = $DB->get_record('course_modules', ['id' => $assign->cmid]);
        $formdata = (object)((array)$assign + (array)$formdata);
        $formdata->modulename = 'assign';
        $formdata->coursemodule = $assign->cmid;
        $formdata->cmidnumber = $formdata->idnumber;
        $formdata->introeditor = ['itemid' => 0, 'text' => '', 'format' => FORMAT_HTML];

        $formdata->duedate = $now + 4 * DAYSECS;
        update_module($formdata);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id]));
        $this->assertCount(1, $upcoming);
        $this->assertEquals($now + 4 * DAYSECS, $upcoming[0]->value);

        // Test delete module.
        course_delete_module($assign->cmid);
        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id]));
        $this->assertCount(0, $upcoming);
    }

    /**
     * Datewatch ignores events triggered before first reindexing
     */
    public function test_event_before_index() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        $this->get_generator()->register_watcher('course');
        $count = $DB->count_records('tool_datewatch_upcoming');

        // Create course.
        $this->getDataGenerator()->create_course(['format' => 'topics', 'startdate' => $now + 2 * DAYSECS]);
        // No new records in 'upcoming' table were created.
        $this->assertEquals($count, $DB->count_records('tool_datewatch_upcoming'));

        // Run index, one new record will be created.
        (new \tool_datewatch\task\watch())->execute();
        $this->assertEquals($count + 1, $DB->count_records('tool_datewatch_upcoming'));

        // Next course we create will be added to the upcoming table.
        $this->getDataGenerator()->create_course(['format' => 'topics', 'startdate' => $now + 4 * DAYSECS]);
        $this->assertEquals($count + 2, $DB->count_records('tool_datewatch_upcoming'));
    }

    public function test_multiple_watchers() {
        global $DB;
        $this->resetAfterTest();
        $now = time();

        // Register two watchers on the same table with different offsets.
        $this->get_generator()->register_watcher('enrolnotification');
        $this->get_generator()->register_watcher('enrolnotification5');
        (new \tool_datewatch\task\watch())->execute();

        // Make sure the watcher for the course start date is created.
        $datewatch = $DB->get_record('tool_datewatch',
            ['tablename' => 'user_enrolments', 'fieldname' => 'timeend']);
        $this->assertNotEmpty($datewatch);

        // Create a course and enrolment, run cron, nothing should change.
        $course1 = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        // First user will receive notification only "3 days before".
        $this->getDataGenerator()->enrol_user($user1->id, $course1->id, 'student', 'manual', 0, $now + 4 * DAYSECS);
        $enrol1 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user1->id], IGNORE_MULTIPLE);
        // Second user will receive notifications "3 days before" and "5 days before".
        $this->getDataGenerator()->enrol_user($user2->id, $course1->id, 'student', 'manual', 0, $now + 10 * DAYSECS);
        $enrol2 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user2->id], IGNORE_MULTIPLE);
        // User 3 should not be notified because the notification date is in the past, even though the timeend is in the future.
        $this->getDataGenerator()->enrol_user($user3->id, $course1->id, 'student', 'manual', 0, $now + 2 * DAYSECS);
        $enrol3 = $DB->get_record_sql('SELECT * FROM {user_enrolments} WHERE userid=? ORDER BY id DESC',
            [$user3->id], IGNORE_MULTIPLE);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $expected1 = ['objectid' => $enrol1->id, 'value' => $now + 4 * DAYSECS];
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));
        $expected2 = ['objectid' => $enrol2->id, 'value' => $now + 10 * DAYSECS];
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[1], $expected2));

        // Run cron - no messages, no changes to the upcoming table.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(0, $messages);

        $upcoming = array_values($DB->get_records('tool_datewatch_upcoming', ['datewatchid' => $datewatch->id], 'id'));
        $this->assertEquals(2, count($upcoming));
        $this->assertEquals($expected1, array_intersect_key((array)$upcoming[0], $expected1));
        $this->assertEquals($expected2, array_intersect_key((array)$upcoming[1], $expected2));

        // Timetravel by 2 days.
        $delta1 = 2 * DAYSECS + MINSECS;
        if (extension_loaded('uopz')) {
            uopz_set_return('time', $now + $delta1);
        } else {
            $this->get_generator()->shift_dates('user_enrolments', 'timeend', -$delta1);
        }

        // Running cron will send a message to the user 1.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($user1->id, $messages[0]->useridto);
        $this->assertEquals('Your enrolment will end in 3 days', $messages[0]->subject);

        // Timetravel by 4 more days.
        $delta2 = 4 * DAYSECS + MINSECS;
        if (extension_loaded('uopz')) {
            uopz_set_return('time', $now + $delta1 + $delta2);
        } else {
            $this->get_generator()->shift_dates('user_enrolments', 'timeend', - $delta2);
        }

        // Run cron again, message will be sent to user2.
        $sink = $this->redirectMessages();
        (new \tool_datewatch\task\watch())->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($user2->id, $messages[0]->useridto);
        $this->assertEquals('Your enrolment will end in 5 days', $messages[0]->subject);
    }

}
