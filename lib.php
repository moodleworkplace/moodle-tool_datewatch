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
 * tool_datewatch callbacks
 *
 * @package    tool_datewatch
 * @copyright  2016 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Callback for tool_datewatch (for unittests only)
 *
 * @param tool_datewatch_manager $manager
 */
function tool_datewatch_datewatch(tool_datewatch_manager $manager) {
    if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
        // For unittests only. BE CAREFUL WHEN COPYING.
        $manager->watch('course', 'startdate')
            ->set_callback(function() {
                null;
            })
            ->set_condition('format = :topicsformat', ['topicsformat' => 'topics']);

        $manager->watch('user_enrolments', 'timeend')
            ->set_callback(function ($recordid, $datevalue) {
                null;
            });
    }
}
