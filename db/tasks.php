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
 * Scheduled maintenance tasks.
 *
 * @package report_coursesize
 * @author Adam Olley <adam.olley@openlms.net>
 * @copyright Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'report_coursesize\task\calculate',
        'blocking'  => 0,
        'minute'    => 'R',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*'
    ],
    [
        'classname' => 'report_coursesize\task\send_report',
        'blocking'  => 0,
        'minute'    => 'R',
        'hour'      => '7',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*'
    ],
];
