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
 * Library functions
 *
 * @package    report
 * @subpackage coursesize
 * @author     Kirill Astashov <kirill.astashov@gmail.com>
 * @copyright  2012 NetSpot Pty Ltd {@link http://netspot.com.au}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Calls local function to calculate and cache course sizes.
 * This will utilize heavy database queries, highly recommended to be launched somewhere in the night
 */
function report_coursesize_cron($verbose = false, $force = false) {

    $config = get_config('report_coursesize');

    if (!$config->calcmethod == 'live'){
        mtrace("Cron calculations are disabled for report_coursesize, see plugin settings. Aborting.");
        return;
    }

    require_once(dirname(__file__) . '/locallib.php');

    $now = time();
    $schtime = $config->start_hour * 3600 + $config->start_minute * 60;
    $runtime = strtotime(date('Y-m-d')) + $schtime;

    if (isset($config->lastruntime) && $config->lastruntime == $runtime) {
        // we've already ran today - return
        return;
    } elseif ($now > $runtime+7200) {
        // missed the window... skip today.
        return;
    }

    set_config('lastruntime', $now, 'report_coursesize');

    mtrace("Starting report_coursesize tasks...");

    $result = report_coursesize_crontask();

    if ($result === true) {
        mtrace("Task complete");
    } else {
        mtrace("Task failed");
    }
}
