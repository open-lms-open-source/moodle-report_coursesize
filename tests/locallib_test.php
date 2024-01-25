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
        $this->setAdminUser();

        $count = 3;

        $courseids = [];

        while ($count > 0) {
            $course = $this->getDataGenerator()->create_course();
            $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

            $context = context_course::instance($course->id);
            $modcontext = context_module::instance($assign->cmid);

            // Add some random files to the course.
            $content = $this->createfile($context->id, 'course', 'intro');
            $content += $this->createfile($modcontext->id, 'mod_assign', 'data');
            $content += $this->createfile($modcontext->id, 'mod_assign', 'data');
            $backupdata = $this->createfile($context->id, 'backup', 'course');
            $backupdata += $this->createfile($context->id, 'backup', 'course');
            $autobackupdata = $this->createfile($context->id, 'backup', 'automated');

            $courseids[] = $course->id;
            $expected[] = [
                $content,
                $backupdata,
                $autobackupdata,
            ];

            $count--;
        }


        $cronres = report_coursesize_crontask();
        $this->assertTrue($cronres);

        foreach ($courseids as $index => $courseid) {
            // Check calculation of ALL files.
            $uniquesize = report_coursesize_getcachevalue(CONTEXT_COURSE, $courseid, false);
            $totalsize = array_sum($expected[$index]);
            $this->assertEquals($totalsize, $uniquesize);

            // Check calculation of all non-backup files.
            $uniquesize = report_coursesize_getcachevalue(CONTEXT_COURSE, $courseid, true);
            $totalsize = $expected[$index][0];
            $this->assertEquals($totalsize, $uniquesize);

            // Check calculation of all non-automated-backup files.
            $uniquesize = report_coursesize_getcachevalue(CONTEXT_COURSE, $courseid, false, true);
            $totalsize = $expected[$index][0] + $expected[$index][1];
            $this->assertEquals($totalsize, $uniquesize);
        }
    }

    public function test_report_coursesize_uniquetotalcalc() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        $uniqueinit = report_coursesize_uniquetotalcalc(false);
        $uniquebackupinit = report_coursesize_uniquetotalcalc(true);

        // Add some random files to the course.
        $content = $this->createfile($context->id, 'course', 'intro');
        $backupdata = $this->createfile($context->id, 'backup', 'course');
        $autobackupdata = $this->createfile($context->id, 'backup', 'automated');

        // Add a backup to both a backup area and a normal area.
        $fs = get_file_storage();
        $data = "somedata";
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'backup',
            'filearea'  => 'data',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'somedata',
        ];
        $fs->create_file_from_string($filerecord, $data);
        $filerecord['component'] = 'course';
        $fs->create_file_from_string($filerecord, $data);

        // All other data, plus the duplicate file.
        $total = $content + $backupdata + $autobackupdata + strlen($data);
        $compare = report_coursesize_uniquetotalcalc(false) - $uniqueinit;
        $this->assertEquals($total, $compare);

        // All the data, but not even the duplicate - its a backup and we're excluding those.
        $compare = report_coursesize_uniquetotalcalc(true) - $uniquebackupinit;
        $this->assertEquals($content, $compare);
    }

    public function test_report_coursesize_coursecalc() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = context_course::instance($course->id);

        // Add some random files to the course.
        $content = $this->createfile($context->id, 'course', 'intro');
        $backupdata = $this->createfile($context->id, 'backup', 'course');
        $autobackupdata = $this->createfile($context->id, 'backup', 'automated');

        $totalsize = report_coursesize_coursecalc($course->id, false);
        $this->assertEquals($content + $backupdata + $autobackupdata, $totalsize);

        $totalsizenobackups = report_coursesize_coursecalc($course->id, true);
        $this->assertEquals($content, $totalsizenobackups);
    }

    public function createfile($contextid, $component, $filearea) {
        static $unique = 0;
        $unique++;
        $fs = get_file_storage();
        $filename = "somefile.{$unique}";
        $content = "somecontent.{$unique}";
        $filerecord = [
            'contextid' => $contextid,
            'component' => $component,
            'filearea'  => $filearea,
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
        ];
        $fs->create_file_from_string($filerecord, $content);
        return strlen($content);
    }

    public function test_catsize() {
        $this->resetAfterTest(true);
        $this->assertTrue(is_int(report_coursesize_catcalc(0)));

        $coursecat = $this->getDataGenerator()->create_category();

        // Create some data.
        $course = $this->getDataGenerator()->create_course(['category' => $coursecat->id]);
        $context = context_course::instance($course->id);

        $initsize = report_coursesize_catcalc($coursecat->id, false);
        $initbackupsize = report_coursesize_catcalc($coursecat->id, true);

        // Add some random files to the course.
        $content = $this->createfile($context->id, 'course', 'intro');
        $backupdata = $this->createfile($context->id, 'backup', 'course');
        $autobackupdata = $this->createfile($context->id, 'backup', 'automated');

        // Add a backup to both a backup area and a normal area.
        $fs = get_file_storage();
        $data = "somedata";
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'backup',
            'filearea'  => 'data',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'somedata',
        ];
        $fs->create_file_from_string($filerecord, $data);
        $filerecord['component'] = 'course';
        $fs->create_file_from_string($filerecord, $data);

        // All other data, plus the duplicate file (cat calc is not unique size).
        $total = $content + $backupdata + $autobackupdata + strlen($data) * 2;
        $compare = report_coursesize_catcalc($coursecat->id, false) - $initsize;
        $this->assertEquals($total, $compare);

        // All the data, including the duplicate - its a backup and we're excluding those, but catcalc isn't unique.
        $compare = report_coursesize_catcalc($coursecat->id, true) - $initbackupsize;
        $this->assertEquals($content + strlen($data), $compare);

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

    public function test_deleting_content_components() {
        global $DB;
        $this->resetAfterTest(true);

        set_config('showcoursecomponents', 1, 'report_coursesize');

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $modcontext = context_module::instance($assign->cmid);

        // Add some random file to the mod.
        $content = $this->createfile($modcontext->id, 'mod_assign', 'data');

        report_coursesize_modulecalc();
        $record = $DB->get_record('report_coursesize_components', ['component' => 'mod_assign']);
        $this->assertEquals($content, $record->filesize);

        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id, 'mod_assign');

        // With the area now empty, verify component cache size is empty.
        report_coursesize_modulecalc();
        $record = $DB->get_record('report_coursesize_components', ['component' => 'mod_assign']);
        $this->assertFalse($record);

        $contenta = $this->createfile($modcontext->id, 'mod_assign', 'data');
        $contentf = $this->createfile($modcontext->id, 'mod_forum', 'data');

        report_coursesize_modulecalc();
        $total = 0;
        foreach ($DB->get_records('report_coursesize_components') as $row) {
            $total += $row->filesize;
        }
        $this->assertEquals($contenta + $contentf, $total);

        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id, 'mod_assign');

        // With the area now empty, verify component cache size is empty.
        report_coursesize_modulecalc();
        $total = 0;
        foreach ($DB->get_records('report_coursesize_components') as $row) {
            $total += $row->filesize;
        }
        $this->assertEquals($contentf, $total);
    }

    public function test_deleting_content_courses() {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $modcontext = context_module::instance($assign->cmid);
        $this->createfile($modcontext->id, 'mod_assign', 'data');

        report_coursesize_crontask();
        $records = $DB->get_records('report_coursesize_components');
        $this->assertNotEmpty($records);

        delete_course($course->id, false);
        report_coursesize_crontask();
        $records = $DB->get_records('report_coursesize_components');
        $this->assertEmpty($records);
    }
}
