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
class generator_test extends advanced_testcase {

    /**
     * Get dynamic rule generator
     *
     * @return tool_datewatch_generator
     */
    protected function get_generator(): tool_datewatch_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_datewatch');
    }

    public function tearDown(): void {
        $this->get_generator()->remove_watchers();
    }

    /**
     * Testing resistering and unregistering watchers.
     */
    public function test_register_watchers() {
        global $DB;
        $this->resetAfterTest();
        (new \tool_datewatch\task\watch())->execute();
        $this->get_generator()->register_watcher('course');
        (new \tool_datewatch\task\watch())->execute();
        $this->assertCount(1, $DB->get_records('tool_datewatch', ['tablename' => 'course', 'fieldname' => 'startdate']));
        $this->get_generator()->register_watcher('user_enrolments');
        (new \tool_datewatch\task\watch())->execute();
        $this->assertCount(1, $DB->get_records('tool_datewatch', ['tablename' => 'course', 'fieldname' => 'startdate']));
        $this->assertCount(1, $DB->get_records('tool_datewatch', ['tablename' => 'user_enrolments', 'fieldname' => 'timeend']));
    }
}
