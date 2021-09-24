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
 * Class generator_test
 *
 * @package     tool_datewatch
 * @group       tool_datewatch
 * @covers      \tool_datewatch_generator
 * @copyright   2021 Marina Glancy
 */
class tool_datewatch_generator_testcase extends advanced_testcase {

    /**
     * Get dynamic rule generator
     *
     * @return tool_datewatch_generator
     */
    protected function get_generator(): tool_datewatch_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_datewatch');
    }


    public function test_watchers() {
        $this->resetAfterTest();

        tool_datewatch_manager::fetch_watchers();
        $a = new \tool_datewatch\task\watch();
        $a->execute();
    }
}