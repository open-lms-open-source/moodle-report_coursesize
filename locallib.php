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
 * Local functions
 *
 * @package    report
 * @subpackage coursesize
 * @author     Kirill Astashov <kirill.astashov@gmail.com>
 * @copyright  2012 NetSpot Pty Ltd {@link http://netspot.com.au}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Calculates and caches course and category sizes
 */
function report_coursesize_crontask() {
    global $CFG, $DB;

    set_time_limit(0);

    $totalsize = 0;

    // delete orphaned COURSE rows from cache table
    $sql = "
DELETE FROM
        {report_coursesize}
  USING {course} c
  WHERE instanceid = c.id
  AND   contextlevel = " . CONTEXT_COURSE . "
  AND   c.id IS NULL
    ";
    if (!$DB->execute($sql)) {
        return false;
    }

    // get COURSE sizes and populate db
    $sql = "
SELECT id AS id, category AS category, SUM(filesize) AS filesize
FROM (
        SELECT c.id, c.category, f.filesize
        FROM {course} c
        JOIN {context} cx ON cx.contextlevel = 50 AND cx.instanceid = c.id
        JOIN {files} f ON f.contextid = cx.id
    UNION ALL
        SELECT c.id, c.category, f.filesize
        FROM {block_instances} bi
        JOIN {context} cx1 ON cx1.contextlevel = 80 AND cx1.instanceid = bi.id
        JOIN {context} cx2 ON cx2.contextlevel = 50 AND cx2.id = bi.parentcontextid
        JOIN {course} c ON c.id = cx2.instanceid
        JOIN {files} f ON f.contextid = cx1.id
    UNION ALL
        SELECT c.id, c.category, f.filesize
        FROM {course_modules} cm
        JOIN {context} cx ON cx.contextlevel = 70 AND cx.instanceid = cm.id
        JOIN {course} c ON c.id = cm.course
        JOIN {files} f ON f.contextid = cx.id
) x
GROUP BY id, category;
";

    if (($courses = $DB->get_records_sql($sql)) === false) {
        mtrace('Failed to query course file sizes. Aborting...');
        return false;
    }

    $coursesizecache = array();
    foreach ($courses as $course) {
        if (!report_coursesize_storecacherow(CONTEXT_COURSE, $course->id, $course->filesize)) {
            return false;
        }
        $totalsize += $course->filesize;
        $coursesizecache[$course->id] = $course->filesize;
    }

    // delete orphaned CATEGORY rows from cache table
    $sql = "
DELETE FROM
    {report_coursesize}
USING
    {course_categories} c
WHERE
        instanceid = c.id
    AND contextlevel = " . CONTEXT_COURSECAT . "
    AND c.id IS NULL
    ";
    if (!$DB->execute($sql)) {
        return false;
    }

    // get CATEGORY sizes and populate db
    // first, get courses under each category (no matter how deeply nested)
    // We have to have the first column unique ;-(
    $sql = "
SELECT
        " . $DB->sql_concat('ct.id', "'_'", 'cx.instanceid') . " AS blah, ct.id AS catid, cx.instanceid AS courseid
FROM
        {course_categories} ct
        JOIN {context} ctx ON ctx.instanceid = ct.id
        LEFT JOIN {context} cx ON ( cx.path LIKE " . $DB->sql_concat('ctx.path', "'/%'") . " )
WHERE
        ctx.contextlevel = " . CONTEXT_COURSECAT . "
    AND cx.contextlevel =  " . CONTEXT_COURSE . "
";

   if (($cats = $DB->get_records_sql($sql)) === false) {
        mtrace('Failed to query categories. Aborting...');
        return false;
   }

    // second, add up course sizes (which we already have) to their categories
    $catsizecache = array();
    foreach ($cats as $cat) {
        if (!isset($catsizecache[$cat->catid])) {
            $catsizecache[$cat->catid] = 0;
        }
        if (isset($coursesizecache[$cat->courseid])) {
            $catsizecache[$cat->catid] += $coursesizecache[$cat->courseid];
        }
    }

    // populate db
    foreach ($cats as $cat) {
        if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $cat->catid, $catsizecache[$cat->catid])) {
            return false;
        }
    }

    // calculate and update user total, add results to totalsize
    $usercalc = report_coursesize_usercalc();
    if ($usercalc === false) {
        return false;
    }
    $totalsize += $usercalc;

    // update grand total
    if (!report_coursesize_storecacherow(0, 0, $totalsize)) {
        return false;
    }

    // calculate and update unique grand total
    if (report_coursesize_uniquetotalcalc() === false) {
        return false;
    }

    return true;
}

/**
 * Calculates size of a single category.
 */
function report_coursesize_catcalc($catid) {
    global $DB;

    // first, get the list of courses nested under the category
    // we have to make the first column unique
    $sql = "
SELECT
        " . $DB->sql_concat('ct.id', "'_'", 'cx.instanceid') . " AS blah, ct.id AS catid, cx.instanceid AS courseid
FROM
        {course_categories} ct
        JOIN {context} ctx ON ctx.instanceid = ct.id
        LEFT JOIN {context} cx ON ( cx.path LIKE " . $DB->sql_concat('ctx.path', "'/%'") . " )
WHERE
        ctx.contextlevel = " . CONTEXT_COURSECAT . "
    AND cx.contextlevel =  " . CONTEXT_COURSE . "
    AND ct.id = :id
";
    $rows = $DB->get_records_sql($sql, array('id' => $catid));
    if ($rows === false OR sizeof($rows) == 0) {
        if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $catid, 0)) {
            return false;
        }
        return 0;
    }

    // get couseids as array
    $courseids = array();
    foreach ($rows AS $row) {
        $courseids[] = $row->courseid;
    }

    // second, get total size of those courses
    list($insql, $params) = $DB->get_in_or_equal($courseids);
    $params = array_merge($params, $params, $params);
    $sql = "
SELECT SUM(filesize) AS filesize
FROM (
        SELECT f.filesize AS filesize
        FROM {course} c
        JOIN {context} cx ON cx.contextlevel = 50 AND cx.instanceid = c.id
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id $insql
    UNION ALL
        SELECT f.filesize AS filesize
        FROM {block_instances} bi
        JOIN {context} cx1 ON cx1.contextlevel = 80 AND cx1.instanceid = bi.id
        JOIN {context} cx2 ON cx2.contextlevel = 50 AND cx2.id = bi.parentcontextid
        JOIN {course} c ON c.id = cx2.instanceid
        JOIN {files} f ON f.contextid = cx1.id
        WHERE c.id $insql
    UNION ALL
        SELECT f.filesize AS filesize
        FROM {course_modules} cm
        JOIN {context} cx ON cx.contextlevel = 70 AND cx.instanceid = cm.id
        JOIN {course} c ON c.id = cm.course
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id $insql
) x
";
    $row = $DB->get_record_sql($sql, $params);
    if ($row === false) {
        return false;
    }
    if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $catid, $row->filesize)) {
        return false;
    }
    if (strval($row->filesize) == '') {
        $row->filesize = 0;
    }
    return $row->filesize;
}

/**
 * Calculates granular size of a single course broken down
 * per each file.
 */
function report_coursesize_coursecalc_granular($courseid) {
    global $DB;

    $sql = "
SELECT x.id, x.filesize, x.filename, x.component, x.filearea, x.userid FROM (
    SELECT f.id, f.filesize, f.filename, f.component, f.filearea, f.userid
        FROM {course} c
        JOIN {context} cx ON cx.contextlevel = :contextcourse1 AND cx.instanceid = c.id
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id = :courseid1
            AND filesize > 0
    UNION ALL
        SELECT f.id, f.filesize, f.filename, f.component, f.filearea, f.userid
        FROM {block_instances} bi
        JOIN {context} cx1 ON cx1.contextlevel = :contextblock AND cx1.instanceid = bi.id
        JOIN {context} cx2 ON cx2.contextlevel = :contextcourse2 AND cx2.id = bi.parentcontextid
        JOIN {course} c ON c.id = cx2.instanceid
        JOIN {files} f ON f.contextid = cx1.id
        WHERE c.id = :courseid2
            AND filesize > 0
    UNION ALL
        SELECT f.id, f.filesize, f.filename, f.component, f.filearea, f.userid
        FROM {course_modules} cm
        JOIN {context} cx ON cx.contextlevel = :contextmodule AND cx.instanceid = cm.id
        JOIN {course} c ON c.id = cm.course
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id = :courseid3
            AND filesize > 0
) x
ORDER BY x.filesize DESC
";

    $params = array(
        'contextcourse1' => CONTEXT_COURSE,
        'courseid1' => $courseid,
        'contextblock' => CONTEXT_BLOCK,
        'contextcourse2' => CONTEXT_COURSE,
        'courseid2' => $courseid,
        'contextmodule' => CONTEXT_MODULE,
        'courseid3' => $courseid,
    );
    $filelist = $DB->get_records_sql($sql, $params);
    if (!$filelist) {
        return false;
    }
    return $filelist;
}

//
// calculates size of a single course
//
function report_coursesize_coursecalc($courseid) {
    global $DB;

    $sql = "
SELECT SUM(filesize) AS filesize
FROM (
        SELECT f.filesize AS filesize
        FROM {course} c
        JOIN {context} cx ON cx.contextlevel = 50 AND cx.instanceid = c.id
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id = :id1
    UNION ALL
        SELECT f.filesize AS filesize
        FROM {block_instances} bi
        JOIN {context} cx1 ON cx1.contextlevel = 80 AND cx1.instanceid = bi.id
        JOIN {context} cx2 ON cx2.contextlevel = 50 AND cx2.id = bi.parentcontextid
        JOIN {course} c ON c.id = cx2.instanceid
        JOIN {files} f ON f.contextid = cx1.id
        WHERE c.id = :id2
    UNION ALL
        SELECT f.filesize AS filesize
        FROM {course_modules} cm
        JOIN {context} cx ON cx.contextlevel = 70 AND cx.instanceid = cm.id
        JOIN {course} c ON c.id = cm.course
        JOIN {files} f ON f.contextid = cx.id
        WHERE c.id = :id3
) x
";

    $course = $DB->get_record_sql($sql, array('id1' => $courseid, 'id2' => $courseid, 'id3' => $courseid));
    if (!$course) {
        return false;
    }
    if (!report_coursesize_storecacherow(CONTEXT_COURSE, $courseid, $course->filesize)) {
        return false;
    }
    if (strval($course->filesize) == '') {
        $course->filesize = 0;
    }
    return $course->filesize;
}

//
// calculates size of user files
//
function report_coursesize_usercalc() {
    global $DB;

    $sql = "
SELECT
        SUM(fs.filesize) as filesize
FROM
        {context} cx 
        LEFT JOIN {files} fs ON fs.contextid = cx.id
WHERE
        cx.contextlevel = " . CONTEXT_USER . "
    ";
    $row = $DB->get_record_sql($sql);
    if ($row === false) {
        return false;
    }
    $filesize = $row->filesize;
    if (strval($filesize) == '') {
        $filesize = 0;
    }
    if (!report_coursesize_storecacherow(0, 1, $filesize)) {
        return false;
    }
    return $filesize;
}

//
// calculates grand total for unique files records in Moodle (unique hashes)
//
function report_coursesize_uniquetotalcalc() {
    global $DB;

    $sql = "SELECT SUM(filesize) AS filesize FROM (SELECT DISTINCT(f.contenthash), f.filesize AS filesize FROM {files} f) fs";
    $row = $DB->get_record_sql($sql, array());
    if ($row === false) {
        return false;
    }
    $filesize = $row->filesize;
    if (strval($filesize) == '') {
        $filesize = 0;
    }
    if (!report_coursesize_storecacherow(0, 2, $filesize)) {
        return false;
    }
    return $filesize;
}

//
// checks if record exists in cache table and then inserts or updates
//
function report_coursesize_storecacherow($contextlevel, $instanceid, $filesize) {
    global $DB;
    $r = new stdClass();
    $r->contextlevel = $contextlevel;
    $r->instanceid = $instanceid;
    $r->filesize = $filesize;
    if (strval($r->filesize) == '') {
        $r->filesize = 0;
    }
    if ($er = $DB->get_record('report_coursesize', array('contextlevel' => $r->contextlevel, 'instanceid' => $r->instanceid))) {
        if (strval($er->filesize) != $r->filesize) {
            $r->id = $er->id;
            if (!($DB->update_record('report_coursesize', $r))) {
                return false;
            }
        }
    } else {
        if (!($DB->insert_record('report_coursesize', $r))) {
            return false;
        }
    }
    return true;
}

//
// formats file size for display
//
function report_coursesize_displaysize($size, $type='auto') {

    static $gb, $mb, $kb, $b;
    if (empty($gb)) {
        $gb = ' ' . get_string('sizegb');
        $mb = ' ' . get_string('sizemb');
        $kb = ' ' . get_string('sizekb');
        $b  = ' ' . get_string('sizeb');
    }

    if ($size == '') {
        $size = 0;
    }

    switch ($type) {
        case 'gb':
            $size = number_format(round($size / 1073741824 * 10, 1) / 10, 1) . $gb;
            break;
        case 'mb':
            $size = number_format(round($size / 1048576 * 10) / 10) . $mb;
            break;
        case 'kb':
            $size = number_format(round($size / 1024 * 10) / 10) . $kb;
            break;
        case 'b':
            $size = number_format($size) . $b;
            break;
        case 'auto':
        default:
            if ($size >= 1073741824) {
                $size = number_format(round($size / 1073741824 * 10, 1) / 10, 1) . $gb;
            } else if ($size >= 1048576) {
                $size = number_format(round($size / 1048576 * 10) / 10) . $mb;
            } else if ($size >= 1024) {
                $size = number_format(round($size / 1024 * 10) / 10) . $kb;
            } else {
                $size = number_format($size) . $b;
            }
    }

    return $size;
}

//
// These sort array of objects by filesize property
// 
function report_coursesize_cmpasc($a, $b)
{ 
    if ($a->filesize == $b->filesize) {
        return 0;
    }
    return ($a->filesize < $b->filesize) ? -1 : 1;
}

function report_coursesize_cmpdesc($a, $b)
{ 
    if ($a->filesize == $b->filesize) {
        return 0;
    }
    return ($a->filesize > $b->filesize) ? -1 : 1;
}
