<?php
/**
 * Unit tests for report/coursesize/locallib.php.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package report_coursesize
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); //  It must be included from a Moodle page
}

global $CFG;

// Make sure the code being tested is accessible.
require_once($CFG->dirroot . '/report/coursesize/locallib.php'); // Include the code to test

/** This class contains the test cases for the functions in locallib.php. */
class report_coursesize_locallib_test extends advanced_testcase {

    function test_cron() {
        $this->resetAfterTest(true);
        $cronres = report_coursesize_crontask();
        $this->assertTrue($cronres);
    }

    function test_catsize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_catcalc(0)));
    }

    function test_coursesize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_coursecalc(1)));
    }

    function test_usersize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_usercalc()));
    }

    function test_uniquesize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_uniquetotalcalc()));
    }
}
