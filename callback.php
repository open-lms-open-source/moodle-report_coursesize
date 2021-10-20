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
 *
 * @package    report_coursesize
 * @author     Kirill Astashov <kirill.astashov@gmail.com>
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once(dirname(__file__) . '/locallib.php');
require_login();
require_once($CFG->libdir.'/filelib.php');
$context = context_system::instance();
$PAGE->set_context($context);

require_capability('report/coursesize:view', $context);

extract(\report_coursesize\local\helper::get_options());

// The column where to insert the granular report icon links if enabled.
define('REPORT_COURSESIZE_GRANULAR_COL', 2);

$id = optional_param('id', 0, PARAM_INT); // Category id.
$course = optional_param('course', 0, PARAM_INT); // Course based data.

$config = get_config('report_coursesize');

$out = '';
// Get categories.
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

$sql = '
SELECT
        ct.id AS catid,
        ct.name AS catname,
        ct.parent AS catparent,
        ct.sortorder AS sortorder,
        rc.filesize AS filesize,
        rc.backupsize AS backupsize
FROM
        {course_categories} ct
        LEFT JOIN {report_coursesize} rc ON ct.id = rc.instanceid AND rc.contextlevel = :ctxcc
WHERE
        ct.parent = :id';
$sql .= ' ORDER BY ' . $orderby;

$tout = false;
$totalsize = 0;
$params = array('ctxcc' => CONTEXT_COURSECAT, 'id' => $id);
if ($cats = $DB->get_records_sql($sql, $params)) {

    if ($config->calcmethod == 'live') {
        // Re-calculate.
        $dosort = false;
        foreach ($cats as $cat) {
            $newsize = report_coursesize_catcalc($cat->catid, $excludebackups);
            if (!$dosort && $cat->filesize != $newsize) {
                $dosort = true;
            }
            $cat->filesize = $newsize;
        }

        // Sort by size manually as we cannot rely on DB sorting with live calculation.
        if ($dosort && $sortorder == 'ssize') {
            usort($cats, 'report_coursesize_cmp' . $sortdir);
        }
    }

    foreach ($cats as $cat) {

        $table = new html_table();
        $table->align = array('left', 'left', 'right');
        $table->width = '100%';
        $table->size = array('22px', '', '130px');
        if (!empty($config->showgranular)) {
            // Insert a column into the table.
            array_splice($table->align, REPORT_COURSESIZE_GRANULAR_COL, 0, 'center');
            array_splice($table->size, REPORT_COURSESIZE_GRANULAR_COL, 0, '22px');
        }
        $table->attributes = array('style' => 'margin-bottom: 0;');
        if (!$id && !$tout) {
            $table->head = array('', get_string('ttitle', 'report_coursesize'), get_string('tsize', 'report_coursesize'));
            if (!empty($config->showgranular)) {
                array_splice($table->head, REPORT_COURSESIZE_GRANULAR_COL, 0, '');
            }
            $tout = true;
        }
        // Check if category has anything in it.
        if ($DB->record_exists('course_categories', array('parent' => $cat->catid))) {
            $hascontent = true;
        } else {
            if ($DB->record_exists('course', array('category' => $cat->catid))) {
                $hascontent = true;
            } else {
                $hascontent = false;
            }
        }
        $expandstr = get_string('tdtoggle', 'report_coursesize');
        if ($hascontent) {
            $pix = new \pix_icon('t/switch_plus', $expandstr, 'moodle', ['role' => 'button']);
            $options = [
                'class' => 'cattoggle',
                'data-id' => $cat->catid,
                'aria-label' => $expandstr,
                'role' => 'button',
                'aria-expanded' => false,
            ];
            $icon = html_writer::link('', $OUTPUT->render($pix), $options);
        } else {
            $pix = new \pix_icon('empty', $expandstr, 'report_coursesize', ['role' => 'button']);
            $icon = $OUTPUT->render($pix);
        }
        $divicon = html_writer::tag('div', $icon, array('id' => 'icon'.$cat->catid));
        $title = html_writer::tag('strong', $cat->catname);

        $rawsize = $excludebackups ? $cat->filesize - $cat->backupsize : $cat->filesize;

        $filesize = report_coursesize_displaysize($rawsize, $displaysize);
        $size = html_writer::tag('strong', $filesize);
        $table->data[] = array($divicon, $title, $size);
        if (!empty($config->showgranular)) {
            array_splice($table->data[key($table->data)], REPORT_COURSESIZE_GRANULAR_COL, 0, '');
        }
        $out .= html_writer::table($table);
        $out .= html_writer::tag('div', '', array('style' => "display:none", 'id' => 'cat'.$cat->catid));
        $totalsize += $rawsize;
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

$sql = "
SELECT
        c.id AS courseid,
        c.fullname AS coursename,
        c.shortname AS courseshortname,
        c.sortorder AS sortorder,
        c.category AS coursecategory,
        rc.filesize as filesize,
        rc.backupsize AS backupsize
FROM
        {course} c
        LEFT JOIN {report_coursesize} rc ON c.id = rc.instanceid AND rc.contextlevel = :ctxc
WHERE
        c.category = :id";
$sql .= " ORDER BY " . $orderby;

$params = array('ctxc' => CONTEXT_COURSE, 'id' => $id);
if ($courses = $DB->get_records_sql($sql, $params)) {

    if ($config->calcmethod == 'live') {
        report_coursesize_modulecalc();
        $dosort = false;
        foreach ($courses as $course) {
            $newsize = report_coursesize_coursecalc($course->courseid, $excludebackups);
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
        $table = new html_table();
        $table->align = array('left', 'left', 'right');
        $table->width = '100%';
        $table->size = array('22px', '', '130px');
        $table->attributes = array('style' => 'margin-bottom: 0;');
        $title = html_writer::tag('a', $course->coursename . " ({$course->courseshortname})", array(
            'href' => $CFG->wwwroot . '/course/view.php?id=' . $course->courseid,
        ));
        $rawsize = $excludebackups ? $course->filesize - $course->backupsize : $course->filesize;
        $size = report_coursesize_displaysize($rawsize, $displaysize);
        $data = report_coursesize_modulestats($course->courseid, $displaysize, $excludebackups);
        $expandstr = get_string('tdtoggle', 'report_coursesize');
        if (!empty($data)) {
            $pix = new \pix_icon('t/switch_plus', $expandstr, 'moodle', ['role' => 'button']);
            $options = [
                'class' => 'coursetoggle',
                'data-id' => $course->courseid,
                'aria-label' => $expandstr,
                'role' => 'button',
                'aria-expanded' => false,
            ];
            $icon = html_writer::link('', $OUTPUT->render($pix), $options);
        } else {
            $pix = new \pix_icon('empty', $expandstr, 'report_coursesize', ['role' => 'button']);
            $icon = $OUTPUT->render($pix);
        }
        $divicon = html_writer::tag('div', $icon, array('id' => 'iconcourse'.$course->courseid));
        $table->data[] = array($divicon, $title, $size);
        if (!empty($config->showgranular)) {
            $granularicon = $OUTPUT->pix_icon('i/report', '', 'moodle', array(
                'alt' => get_string('granularlink', 'report_coursesize'),
                'title' => get_string('granularlink', 'report_coursesize'),
            ));
            $granular = html_writer::tag('a', $granularicon, array(
                'href' => 'granular.php?courseid=' . $course->courseid,
            ));
            array_splice($table->data[key($table->data)], REPORT_COURSESIZE_GRANULAR_COL, 0, $granular);
            array_splice($table->align, REPORT_COURSESIZE_GRANULAR_COL, 0, 'center');
            array_splice($table->size, REPORT_COURSESIZE_GRANULAR_COL, 0, '22px');
        }
        $out .= html_writer::table($table);
        if (!empty($data)) {
            $out .= html_writer::start_tag('div', array('style' => "display:none", 'id' => 'course'.$course->courseid));
            $table = new html_table();
            $table->align = array('left', 'left', 'right');
            $table->width = '100%';
            $table->size = array('22px', '', '130px');
            $table->attributes = array('style' => 'margin-bottom: 0;');
            foreach ($data as $row) {
                $table->data[] = $row;
            }
            $out .= html_writer::table($table);
            $out .= html_writer::end_tag('div');
        }

        $totalsize += $rawsize;
    }
}

$out = html_writer::tag('div', $out, array('style' => 'margin-left: 25px;'));

// We are displaying the main table (Moodle root), so let's print some additional info.
if (!$id) {
    // Get user files.
    if ($DB->record_exists('report_coursesize', array('contextlevel' => 0, 'instanceid' => 1))) {
        $row = $DB->get_record('report_coursesize', array('contextlevel' => 0, 'instanceid' => 1));
        $totalsize += $row->filesize;
        $usersize = $row->filesize;
    } else {
        if ($config->calcmethod == 'live') {
            $usersize = report_coursesize_usercalc($excludebackups);
            $totalsize += $usersize;
        } else {
            $usersize = 0;
        }
    }

    // Store grand total.
    if ($config->calcmethod == 'live') {
        report_coursesize_storecacherow(0, 0, $totalsize);
    }

    // Output totals.
    $out .= html_writer::empty_tag('br') . html_writer::empty_tag('br');
    $out .= get_string('userfilesize', 'report_coursesize') . ': ' . report_coursesize_displaysize($usersize, $displaysize);
    $out .= html_writer::empty_tag('br');
    $out .= get_string('totalfilesize', 'report_coursesize') . ': ' . report_coursesize_displaysize($totalsize, $displaysize);

    // Get and output total unique file size (may differ from $totalsize since
    // Moodle file storage does not duplicate identical files).
    if ($DB->record_exists('report_coursesize', array('contextlevel' => 0, 'instanceid' => 2))) {
        $row = $DB->get_record('report_coursesize', array('contextlevel' => 0, 'instanceid' => 2));
        $uniquefilesize = $row->filesize;
    } else {
        if ($config->calcmethod == 'live') {
            $uniquefilesize = report_coursesize_uniquetotalcalc($excludebackups);
        } else {
            $uniquefilesize = 0;
        }
    }
    $out .= html_writer::empty_tag('br');
    $out .= get_string('uniquefilesize', 'report_coursesize') . ': ' . report_coursesize_displaysize($uniquefilesize, $displaysize);

}

echo json_encode($out);

