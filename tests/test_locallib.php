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
 * Unit tests for report/coursesize/locallib.php.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package report_coursesize
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

// Make sure the code being tested is accessible.
require_once($CFG->dirroot . '/report/coursesize/locallib.php');

/** This class contains the test cases for the functions in locallib.php. */
class report_coursesize_locallib_test extends advanced_testcase {

    public function test_cron() {
        $this->resetAfterTest(true);
        $cronres = report_coursesize_crontask();
        $this->assertTrue($cronres);
    }

    public function test_catsize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_catcalc(0)));
    }

    public function test_coursesize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_coursecalc(1)));
    }

    public function test_usersize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_usercalc()));
    }

    public function test_uniquesize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_uniquetotalcalc()));
    }
}
