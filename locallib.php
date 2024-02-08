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
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Calculates and caches course and category sizes
 */
function report_coursesize_crontask() {
    global $DB;

    set_time_limit(0);

    $totalsize = 0;
    $totalsizeexcludingbackups = 0;
    $totalsizeexcludingautobackups = 0;

    // Delete orphaned COURSE rows from cache table.
    $sql = "DELETE rc
              FROM {report_coursesize} rc
         LEFT JOIN {course} c ON instanceid = c.id
             WHERE (rc.contextlevel = :ctxc)
               AND c.id IS NULL";
    if ($DB->get_dbfamily() == 'postgres') {
        $sql = "DELETE FROM {report_coursesize}
                USING {course} c
                WHERE instanceid = c.id
                AND   (contextlevel = :ctxc)
                AND   c.id IS NULL";
    }
    $params = array('ctxc' => CONTEXT_COURSE);
    if (!$DB->execute($sql, $params)) {
        return false;
    }

    $fileconcat = $DB->sql_concat('x.id', "':'", 'x.component', "':'", 'x.filearea');
    // Get COURSE sizes and populate db.
    $sql = "SELECT $fileconcat AS concat, id, category, component, filearea, SUM(filesize) AS filesize
            FROM (
                SELECT c.id, c.category, f.component, f.filearea, f.filesize
                FROM {course} c
                JOIN {context} cx ON cx.contextlevel = :ctxc1 AND cx.instanceid = c.id
                JOIN {files} f ON f.contextid = cx.id
                UNION ALL
                    SELECT c.id, c.category, f.component, f.filearea, f.filesize
                    FROM {block_instances} bi
                    JOIN {context} cx1 ON cx1.contextlevel = :ctxb AND cx1.instanceid = bi.id
                    JOIN {context} cx2 ON cx2.contextlevel = :ctxc2 AND cx2.id = bi.parentcontextid
                    JOIN {course} c ON c.id = cx2.instanceid
                    JOIN {files} f ON f.contextid = cx1.id
                UNION ALL
                    SELECT c.id, c.category, f.component, f.filearea, f.filesize
                    FROM {course_modules} cm
                    JOIN {context} cx ON cx.contextlevel = :ctxm AND cx.instanceid = cm.id
                    JOIN {course} c ON c.id = cm.course
                    JOIN {files} f ON f.contextid = cx.id
            ) x
            GROUP BY concat, id, category, component, filearea
            ORDER BY id ASC";
    $params = array('ctxc1' => CONTEXT_COURSE, 'ctxc2' => CONTEXT_COURSE, 'ctxm' => CONTEXT_MODULE, 'ctxb' => CONTEXT_BLOCK);
    $courses = $DB->get_recordset_sql($sql, $params);

    $coursesizecache = [];
    $componentsizecache = [];
    foreach ($courses as $course) {
        if (!isset($coursesizecache[$course->id])) {
            $coursesizecache[$course->id] = [0, 0, 0];
        }
        if (!isset($componentsizecache[$course->id][$course->component])) {
            $componentsizecache[$course->id][$course->component] = 0;
        }

        $coursesizecache[$course->id][0] += $course->filesize;
        $componentsizecache[$course->id][$course->component] += $course->filesize;

        if ($course->component == 'backup') {
            $coursesizecache[$course->id][1] += $course->filesize;
        }
        if ($course->component == 'backup' && $course->filearea == 'automated') {
            $coursesizecache[$course->id][2] += $course->filesize;
        }
    }
    $courses->close();

    foreach ($coursesizecache as $courseid => $filesizes) {
        if (!report_coursesize_storecacherow(CONTEXT_COURSE, $courseid, $filesizes[0], $filesizes[1], $filesizes[2])) {
            return false;
        }
        $totalsize += $filesizes[0];
        $totalsizeexcludingbackups += $filesizes[1];
        $totalsizeexcludingautobackups += $filesizes[2];
    }

    foreach ($componentsizecache as $courseid => $data) {
        foreach ($data as $component => $filesize) {
            report_coursesize_storecomponentcacherow($component, $courseid, $filesize);
        }
    }

    // Delete orphaned CATEGORY rows from cache table.
    $sql = "DELETE rc
              FROM {report_coursesize} rc
         LEFT JOIN {course_categories} cc ON instanceid = cc.id
             WHERE (rc.contextlevel = :ctxcc)
               AND cc.id IS NULL";
    if ($DB->get_dbfamily() == 'postgres') {
        $sql = "DELETE FROM {report_coursesize}
                USING {course_categories} c
                WHERE instanceid = c.id AND contextlevel = :ctxcc AND c.id IS NULL";
    }
    $params = array('ctxcc' => CONTEXT_COURSECAT);
    if (!$DB->execute($sql, $params)) {
        return false;
    }

    // Get CATEGORY sizes and populate db
    // first, get courses under each category (no matter how deeply nested)
    // We have to have the first column unique.
    $sql = "
SELECT
        " . $DB->sql_concat('ct.id', "'_'", 'cx.instanceid') . " AS blah, ct.id AS catid, cx.instanceid AS courseid
FROM
        {course_categories} ct
        JOIN {context} ctx ON ctx.instanceid = ct.id
        LEFT JOIN {context} cx ON ( cx.path LIKE " . $DB->sql_concat('ctx.path', "'/%'") . " )
WHERE
        ctx.contextlevel = :ctxcc
    AND cx.contextlevel =  :ctxc
";

    $params = array('ctxc' => CONTEXT_COURSE, 'ctxcc' => CONTEXT_COURSECAT);
    if (($cats = $DB->get_records_sql($sql, $params)) === false) {
        mtrace('Failed to query categories. Aborting...');
        return false;
    }

    // Second, add up course sizes (which we already have) to their categories.
    $catsizecache = [];
    foreach ($cats as $cat) {
        if (!isset($catsizecache[$cat->catid])) {
            $catsizecache[$cat->catid] = [0, 0, 0];
        }
        if (isset($coursesizecache[$cat->courseid])) {
            $catsizecache[$cat->catid][0] += $coursesizecache[$cat->courseid][0];
            $catsizecache[$cat->catid][1] += $coursesizecache[$cat->courseid][1];
            $catsizecache[$cat->catid][2] += $coursesizecache[$cat->courseid][2];
        }
    }

    // Populate db.
    foreach ($cats as $cat) {
        if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $cat->catid, $catsizecache[$cat->catid][0], $catsizecache[$cat->catid][1], $catsizecache[$cat->catid][2])) {
            return false;
        }
    }

    // Calculate and update user total, add results to totalsize.
    $usercalc = report_coursesize_usercalc();
    if ($usercalc !== false) {
        $totalsize += $usercalc;
    }

    // Calculate and update user total, add results to totalsize.
    $usercalc = report_coursesize_usercalc(false);
    if ($usercalc !== false) {
        $totalsizeexcludingbackups += $usercalc;
    }

    // Update grand total.
    if (!report_coursesize_storecacherow(0, 0, $totalsize, $totalsizeexcludingbackups, $totalsizeexcludingautobackups)) {
        return false;
    }

    // Calculate and update unique grand total.
    if (report_coursesize_uniquetotalcalc() === false) {
        return false;
    }

    return true;
}

/**
 * Calculates size of a single category.
 */
function report_coursesize_catcalc($catid, $excludebackups = false) {
    global $DB;

    // First, get the list of courses nested under the category
    // we have to make the first column unique.
    $pathconcat = $DB->sql_concat('ctx.path', "'/%'");
    $coursesql = "SELECT cx.instanceid AS courseid, ct.id AS catid
            FROM {course_categories} ct
            JOIN {context} ctx ON ctx.instanceid = ct.id
            LEFT JOIN {context} cx ON ( cx.path LIKE {$pathconcat} )
            WHERE ctx.contextlevel = ? AND cx.contextlevel = ? AND ct.id = ?";
    $params = [
        CONTEXT_COURSECAT,
        CONTEXT_COURSE,
        $catid,
    ];
    if ($DB->count_records_sql("SELECT count(1) FROM ($coursesql) x", $params) == 0) {
        // The category has no courses - record 0 for filesizes.
        if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $catid, 0, 0, 0)) {
            return false;
        }
        if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $catid, 0, 0, 0)) {
            return false;
        }
        return 0;
    }

    // Second, get total size of those courses.
    $params = array_merge($params, $params, $params);
    $fileconcat = $DB->sql_concat('id', "':'", 'component', "':'", 'filearea');
    $sql = "SELECT $fileconcat AS concat, component, filearea, SUM(filesize) AS filesize
            FROM (
                SELECT c.id, f.component, f.filearea, f.filesize AS filesize
                FROM {course} c
                JOIN {context} cx ON cx.contextlevel = ".CONTEXT_COURSE." AND cx.instanceid = c.id
                JOIN {files} f ON f.contextid = cx.id
                WHERE c.id IN (SELECT courseid FROM ($coursesql) x)
                UNION ALL
                    SELECT c.id, f.component, f.filearea, f.filesize AS filesize
                    FROM {block_instances} bi
                    JOIN {context} cx1 ON cx1.contextlevel = ".CONTEXT_BLOCK." AND cx1.instanceid = bi.id
                    JOIN {context} cx2 ON cx2.contextlevel = ".CONTEXT_COURSE." AND cx2.id = bi.parentcontextid
                    JOIN {course} c ON c.id = cx2.instanceid
                    JOIN {files} f ON f.contextid = cx1.id
                    WHERE c.id IN (SELECT courseid FROM ($coursesql) x)
                UNION ALL
                    SELECT c.id, f.component, f.filearea, f.filesize AS filesize
                    FROM {course_modules} cm
                    JOIN {context} cx ON cx.contextlevel = ".CONTEXT_MODULE." AND cx.instanceid = cm.id
                    JOIN {course} c ON c.id = cm.course
                    JOIN {files} f ON f.contextid = cx.id
                    WHERE c.id IN (SELECT courseid FROM ($coursesql) x)
            ) x
            GROUP BY concat, component, filearea";
    $cats = $DB->get_recordset_sql($sql, $params);

    $sizecache = [0, 0, 0];
    foreach ($cats as $cat) {
        $sizecache[0] += $cat->filesize;
        if ($cat->component == 'backup') {
            $sizecache[1] += $cat->filesize;
        }
        if ($cat->component == 'backup' && $cat->filearea == 'automated') {
            $sizecache[2] += $cat->filesize;
        }
    }
    $cats->close();

    if (!report_coursesize_storecacherow(CONTEXT_COURSECAT, $catid, $sizecache[0], $sizecache[1], $sizecache[2])) {
        return false;
    }

    if ($excludebackups) {
        return $sizecache[0] - $sizecache[1];
    }
    return $sizecache[0];
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

/**
 * Calculates size of a single course.
 */
function report_coursesize_coursecalc($courseid, $excludebackups = false): int {
    global $DB;

    $fileconcat = $DB->sql_concat('component', "':'", 'filearea');
    $sql = "SELECT $fileconcat AS concat, component, filearea, SUM(filesize) AS filesize
            FROM (
                SELECT f.component, f.filearea, f.filesize AS filesize
                FROM {course} c
                JOIN {context} cx ON cx.contextlevel = ".CONTEXT_COURSE." AND cx.instanceid = c.id
                JOIN {files} f ON f.contextid = cx.id
                WHERE c.id = :id1
            UNION ALL
                SELECT f.component, f.filearea, f.filesize AS filesize
                FROM {block_instances} bi
                JOIN {context} cx1 ON cx1.contextlevel = ".CONTEXT_BLOCK." AND cx1.instanceid = bi.id
                JOIN {context} cx2 ON cx2.contextlevel = ".CONTEXT_COURSE." AND cx2.id = bi.parentcontextid
                JOIN {course} c ON c.id = cx2.instanceid
                JOIN {files} f ON f.contextid = cx1.id
                WHERE c.id = :id2
            UNION ALL
                SELECT f.component, f.filearea, f.filesize AS filesize
                FROM {course_modules} cm
                JOIN {context} cx ON cx.contextlevel = ".CONTEXT_MODULE." AND cx.instanceid = cm.id
                JOIN {course} c ON c.id = cm.course
                JOIN {files} f ON f.contextid = cx.id
                WHERE c.id = :id3
            ) x
            GROUP BY concat, component, filearea";

    $course = $DB->get_records_sql($sql, array('id1' => $courseid, 'id2' => $courseid, 'id3' => $courseid));
    if (!$course) {
        return false;
    }

    $sizecache = [0, 0, 0];
    foreach ($course as $record) {
        $sizecache[0] += $record->filesize;
        if ($record->component == 'backup') {
            $sizecache[1] += $record->filesize;
        }
        if ($record->component == 'backup' && $record->filearea == 'automated') {
            $sizecache[2] += $record->filesize;
        }
    }

    if (!report_coursesize_storecacherow(CONTEXT_COURSE, $courseid, $sizecache[0], $sizecache[1], $sizecache[2])) {
        return false;
    }
    if ($excludebackups) {
        return $sizecache[0] - $sizecache[1];
    }
    return $sizecache[0];
}

/**
 * Calculates size of user files.
 */
function report_coursesize_usercalc($excludebackups = false) {
    global $DB;

    $sql = "SELECT fs.filearea, SUM(fs.filesize) as filesize
            FROM {context} cx
            LEFT JOIN {files} fs ON fs.contextid = cx.id
            WHERE cx.contextlevel = ".CONTEXT_USER."
            GROUP BY fs.filearea";
    $rows = $DB->get_records_sql($sql);
    if (empty($rows)) {
        report_coursesize_storecacherow(0, 1, 0, 0);
        return 0;
    }

    $filesize = 0;
    $backupsize = 0;
    foreach ($rows as $row) {
        $filesize += $row->filesize;
        if ($row->filearea == 'backup') {
            $backupsize += $row->filesize;
        }
    }

    if (!report_coursesize_storecacherow(0, 1, $filesize, $backupsize, 0)) {
        return false;
    }

    if ($excludebackups) {
        return (int)($filesize - $backupsize);
    }
    return (int)$filesize;
}

/**
 * Calculates grand total for unique files records in Moodle (unique hashes).
 */
function report_coursesize_uniquetotalcalc($excludebackups = false) {
    global $DB;

    $sql = "SELECT SUM(fs.filesize) AS filesize
            FROM (
                SELECT DISTINCT(f.contenthash), f.filesize AS filesize
                FROM {files} f
            ) fs";
    $row = $DB->get_record_sql($sql);
    if ($row === false) {
        return false;
    }

    $filesize = $row->filesize;
    if (strval($filesize) == '') {
        $filesize = 0;
    }

    // Backups - that aren't also normal files.
    $sql = "SELECT SUM(fs.filesize) AS filesize
    FROM (
        SELECT DISTINCT(f.contenthash), f.filesize AS filesize
        FROM {files} f
        WHERE f.id IN (
            SELECT f.id
            FROM {files}
            WHERE f.component = 'backup'
        )
    ) fs";
    $row = $DB->get_record_sql($sql);
    $backupsize = $row->filesize;
    if (strval($backupsize) == '') {
        $backupsize = 0;
    }

    // Auto backups - that aren't also normal files.
    $sql = "SELECT SUM(fs.filesize) AS filesize
    FROM (
        SELECT DISTINCT(f.contenthash), f.filesize AS filesize
        FROM {files} f
        WHERE f.id IN (
            SELECT f.id
            FROM {files}
            WHERE f.component = 'backup' AND f.filearea = 'automated'
        )
    ) fs";
    $row = $DB->get_record_sql($sql);
    $autobackupsize = $row->filesize;
    if (strval($autobackupsize) == '') {
        $autobackupsize = 0;
    }

    if (!report_coursesize_storecacherow(0, 2, $filesize, $backupsize, $autobackupsize)) {
        return false;
    }
    return $excludebackups ? (int)$filesize - (int)$backupsize : (int)$filesize;
}

function report_coursesize_getcachevalue($contextlevel, $instanceid, $excludebackups = false, $excludeautobackups = false) {
    global $DB;

    if ($record = $DB->get_record('report_coursesize', ['contextlevel' => $contextlevel, 'instanceid' => $instanceid])) {
        $value = $record->filesize;
        if ($excludebackups) {
            $value -= $record->backupsize;
        } else if ($excludeautobackups) {
            $value -= $record->autobackupsize;
        }
        return $value;
    }
    return false;
}

/**
 * Checks if record exists in cache table and then inserts or updates.
 */
function report_coursesize_storecacherow($contextlevel, $instanceid, $filesize = 0, $backupsize = 0, $autobackupsize = 0) {
    global $DB;
    $r = new stdClass();
    $r->contextlevel = $contextlevel;
    $r->instanceid = $instanceid;
    $r->filesize = $filesize;
    $r->backupsize = $backupsize;
    $r->autobackupsize = $autobackupsize;
    if (strval($r->filesize) == '') {
        $r->filesize = 0;
    }

    if ($er = $DB->get_record('report_coursesize', array('contextlevel' => $r->contextlevel, 'instanceid' => $r->instanceid))) {
        if ($er->filesize != $r->filesize || $er->backupsize != $r->backupsize || $er->autobackupsize != $r->autobackupsize) {
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

/**
 * Checks if component record exists in cache table and then inserts or updates.
 */
function report_coursesize_storecomponentcacherow($component, $courseid, $filesize) {
    global $DB;
    $r = new stdClass();
    $r->component = $component;
    $r->courseid = $courseid;
    $r->filesize = $filesize;
    if (strval($r->filesize) == '') {
        $r->filesize = 0;
    }
    $table = 'report_coursesize_components';
    if ($er = $DB->get_record($table, array('component' => $r->component, 'courseid' => $r->courseid))) {
        if (strval($er->filesize) != $r->filesize) {
            $r->id = $er->id;
            if (!($DB->update_record($table, $r))) {
                return false;
            }
        }
    } else if (!($DB->insert_record($table, $r))) {
        return false;
    }
    return true;
}

/**
 * Formats file size for display.
 */
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

/**
 * These sort array of objects by filesize property.
 */
function report_coursesize_cmpasc($a, $b) {
    if ($a->filesize == $b->filesize) {
        return 0;
    }
    return ($a->filesize < $b->filesize) ? -1 : 1;
}

function report_coursesize_cmpdesc($a, $b) {
    if ($a->filesize == $b->filesize) {
        return 0;
    }
    return ($a->filesize > $b->filesize) ? -1 : 1;
}

/**
 * Generate export data for a csv based on display size, sorting and if backups are excluded
 * @param string $displaysize Whether size is shown in formats auto, bytes, Mb or Mb
 * @param string $sortorder Order to sort by
 * @param string $sortdir Order direction
 * @return array An array of category and course data sorted by input paramaters
 */
function report_coursesize_export($displaysize, $sortorder, $sortdir) {
    global $CFG, $DB;

    $config = get_config('report_coursesize');
    $data = array();
    $output = array();

    switch($sortorder) {
        case 'salphan':
        case 'salphas':
            $orderby = 'catname';
            break;

        case 'sorder':
            $orderby = 'sortorder';
            break;

        case 'ssize':
        default:
            $orderby = 'filesize';
            break;
    }

    switch($sortdir) {
        case 'asc':
            $orderby .= ' ASC';
            break;

        case 'desc':
        default:
            $orderby .= ' DESC';
            break;
    }

    $params = array('ctxcc' => CONTEXT_COURSECAT);

    $sql = '
    SELECT
            ct.id AS catid,
            ct.name AS catname,
            ct.parent AS catparent,
            ct.sortorder AS sortorder,
            rc.filesize as filesize,
            rc.backupsize AS backupsize
    FROM
            {course_categories} ct
            LEFT JOIN {report_coursesize} rc ON ct.id = rc.instanceid AND rc.contextlevel = :ctxcc';
    $sql .= ' ORDER BY ' . $orderby;

    if ($cats = $DB->get_records_sql($sql, $params)) {

        // Recalculate.
        $dosort = false;
        foreach ($cats as $cat) {
            $newsize = report_coursesize_catcalc($cat->catid);
            if (!$dosort && $cat->filesize != $newsize) {
                $dosort = true;
            }
            $cat->filesize = $newsize;
        }

        // Sort by size manually as we cannot
        // rely on DB sorting with live calculation.
        if ($dosort && $sortorder == 'ssize') {
            usort($cats, 'report_coursesize_cmp' . $sortdir);
        }

        foreach ($cats as $cat) {
            $url = '=hyperlink("' . $CFG->wwwroot . '/course/category.php?id=' . $cat->catid . '", "' . $cat->catname . '")';
            $totalfilesize = report_coursesize_displaysize($cat->filesize, $displaysize);
            if (!empty($config->excludebackups)) {
                $coursefilesize = report_coursesize_displaysize($cat->filesize - $cat->backupsize, $displaysize);
                $backupfilesize = report_coursesize_displaysize($cat->backupsize, $displaysize);
                $data['category'][$cat->catid] = array($url, $totalfilesize, $coursefilesize, $backupfilesize);
            } else {
                $data['category'][$cat->catid] = array($url, $totalfilesize);
            }
        }
    }

    switch($sortorder) {
        case 'salphan':
            $orderby = 'coursename';
            break;

        case 'salphas':
            $orderby = 'courseshortname';
            break;

        case 'sorder':
            $orderby = 'sortorder';
            break;

        case 'ssize':
        default:
            $orderby = 'filesize';
            break;
    }

    switch($sortdir) {
        case 'asc':
            $orderby .= ' ASC';
            break;

        case 'desc':
        default:
            $orderby .= ' DESC';
            break;
    }

    $params = array('ctxc' => CONTEXT_COURSE);
    $sql .= " ORDER BY " . $orderby;

    $sql = "
    SELECT
            c.id AS courseid,
            c.fullname AS coursename,
            c.shortname AS courseshortname,
            c.sortorder AS sortorder,
            c.category AS coursecategory,
            rc.filesize AS filesize,
            rc.backupsize AS backupsize
    FROM
            {course} c
            LEFT JOIN {report_coursesize} rc ON c.id = rc.instanceid AND rc.contextlevel = :ctxc";
    $sql .= " ORDER BY " . $orderby;
    $categories = core_course_category::make_categories_list('', 0);
    $categories[0] = '/';
    if ($courses = $DB->get_records_sql($sql, $params)) {

        if ($config->calcmethod == 'live') {
            // Recalculate.
            $dosort = false;
            foreach ($courses as $course) {
                $newsize = report_coursesize_coursecalc($course->courseid);
                if (!$dosort && $course->filesize != $newsize) {
                    $dosort = true;
                }
                $course->filesize = $newsize;
            }

            // Sort by size manually as we cannot
            // rely on DB sorting with live calculation.
            if ($dosort && $sortorder == 'ssize') {
                usort($courses, 'report_coursesize_cmp' . $sortdir);
            }
        }

        foreach ($courses as $course) {
            $url = '=hyperlink("' . $CFG->wwwroot . '/course/view.php?id=' . $course->courseid .
                '", "' . $course->coursename . '")';
            $totalfilesize = report_coursesize_displaysize($course->filesize, $displaysize);
            if (!empty($config->excludebackups)) {
                $coursefilesize = report_coursesize_displaysize($course->filesize - $course->backupsize, $displaysize);
                $backupfilesize = report_coursesize_displaysize($course->backupsize, $displaysize);
                $data['course'][$course->coursecategory][$course->courseid] = array($categories[$course->coursecategory], $url,
                    $totalfilesize, $coursefilesize, $backupfilesize);
            } else {
                $data['course'][$course->coursecategory][$course->courseid] = array($categories[$course->coursecategory], $url,
                    $totalfilesize);
            }
        }
    }

    // Convert data into category based flat layout.
    foreach ($data['category'] as $categoryid => $category) {
        if (!empty($data['course'][$categoryid])) {
            foreach ($data['course'][$categoryid] as $course) {
                $output[] = $course;
            }
        }
    }

    return $output;
}

function report_coursesize_modulecalc() {
    global $DB;

    $config = get_config('report_coursesize');
    if (!$config->showcoursecomponents) {
        return false;
    }

    $concat = $DB->sql_concat('c.id', "'_'", 'f.component');
    $pathconcat = $DB->sql_concat('cx1.path', "'/'", "'%'");
    $sql = "SELECT $concat AS concat, c.id, f.component, SUM(filesize) as filesize
            FROM {course} c
            JOIN {context} cx1 ON cx1.contextlevel = :ctxc1 AND cx1.instanceid = c.id
            JOIN {context} cx2 ON cx2.contextlevel >= :ctxc2 AND (cx2.path = cx1.path OR cx2.path LIKE $pathconcat)
            JOIN {files} f ON f.contextid = cx2.id
            GROUP BY concat, c.id, f.component";
    $params = array('ctxc1' => CONTEXT_COURSE, 'ctxc2' => CONTEXT_COURSE);

    $blah = $DB->sql_concat('cm.course', "'_'", 'f.component AS blah');
    $sql = "SELECT {$blah}, cm.course as id, f.component, sum(f.filesize) as filesize
              FROM {course_modules} cm
              JOIN {context} cx ON cx.contextlevel = :ctxm AND cx.instanceid = cm.id
              JOIN {files} f ON f.contextid = cx.id
             GROUP BY cm.course, f.component";
    $params = array('ctxm' => CONTEXT_MODULE);

    $data = $DB->get_records_sql($sql, $params);
    foreach ($data as $row) {
        report_coursesize_storecomponentcacherow($row->component, $row->id, $row->filesize);
    }

    return true;
}

function report_coursesize_modulestats($id, $displaysize, $excludebackups) {
    global $DB;

    $data = array();

    $config = get_config('report_coursesize');
    if (!$config->showcoursecomponents) {
        return $data;
    }

    $sql = 'SELECT *
              FROM {report_coursesize_components} rcc
             WHERE courseid = :id';
    if ($excludebackups) {
        $sql .= " AND component != 'backup'";
    }
    $params = array('id' => $id);
    if ($modules = $DB->get_records_sql($sql, $params)) {
        foreach ($modules as $module) {
            $size = report_coursesize_displaysize($module->filesize, $displaysize);
            $data[] = array('', $module->component, $size);
        }
    }
    return $data;
}
