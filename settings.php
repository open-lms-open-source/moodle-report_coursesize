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
 * Version info
 *
 * @package    report
 * @subpackage coursesize
 * @author     Kirill Astashov <kirill.astashov@gmail.com>
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$ADMIN->add('reports', new admin_externalpage('reportcoursesizepage', new lang_string('pluginname', 'report_coursesize'),
    "$CFG->wwwroot/report/coursesize/index.php", 'report/coursesize:view'));

$settings->add(new admin_setting_configselect('report_coursesize/calcmethod',
    new lang_string('calcmethod', 'report_coursesize'),
    new lang_string('calcmethodhelp', 'report_coursesize'),
    'cron', array('cron' => new lang_string('calcmethodcron', 'report_coursesize'),
        'live' => new lang_string('calcmethodlive', 'report_coursesize'))));

$name = new lang_string('showgranular', 'report_coursesize');
$description = new lang_string('showgranularhelp', 'report_coursesize');
$settings->add(new admin_setting_configcheckbox('report_coursesize/showgranular',
                                                $name,
                                                $description,
                                                0));

$name = new lang_string('excludebackups', 'report_coursesize');
$description = new lang_string('excludebackupshelp', 'report_coursesize');
$settings->add(new admin_setting_configcheckbox('report_coursesize/excludebackups',
                                                $name,
                                                $description,
                                                0));

$name = new lang_string('showcoursecomponents', 'report_coursesize');
$description = new lang_string('showcoursecomponentshelp', 'report_coursesize');
$settings->add(new admin_setting_configcheckbox('report_coursesize/showcoursecomponents',
                                                $name,
                                                $description,
                                                0));

$name = new lang_string('alwaysdisplaymb', 'report_coursesize');
$description = new lang_string('alwaysdisplaymbhelp', 'report_coursesize');
$settings->add(new admin_setting_configcheckbox('report_coursesize/alwaysdisplaymb',
                                                $name,
                                                $description,
                                                0));

$name = new lang_string('emailrecipients', 'report_coursesize');
$description = new lang_string('emailrecipientshelp', 'report_coursesize');
$settings->add(new admin_setting_configtext('report_coursesize/emailrecipients',
                                                $name,
                                                $description,
                                                ''));