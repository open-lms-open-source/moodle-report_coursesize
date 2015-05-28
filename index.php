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
 * Course size report
 *
 * @package    report
 * @subpackage coursesize
 * @author     Kirill Astashov <kirill.astashov@gmail.com>
 * @copyright  2012 NetSpot Pty Ltd {@link http://netspot.com.au}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once(dirname(__file__) . '/locallib.php');
require_login();
require_once(dirname(__file__) . '/getoptions.php');
admin_externalpage_setup('reportcoursesizepage', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

$config = get_config('report_coursesize');
$lastruntime = (!isset($config->lastruntime)) ? get_string('nevercap', 'report_coursesize') : date('r', $config->lastruntime);
$livecalcenabled = (isset($config->calcmethod) && $config->calcmethod == 'live') ? get_string('enabledcap', 'report_coursesize') : get_string('disabledcap', 'report_coursesize');

echo html_writer::tag('div', get_string('lastcalculated', 'report_coursesize') . $lastruntime, array('style' => 'margin-bottom:10px;'));
echo html_writer::tag('div', get_string('livecalc', 'report_coursesize') . $livecalcenabled, array('style' => 'margin-bottom:10px;'));

// for catsize.js to be able to pass these parameters to callback script
echo "
<script type=\"text/javascript\">
//<![CDATA[
    var csize_sortorder = '{$sortorder}';
    var csize_sortdir = '{$sortdir}';
    var csize_displaysize = '{$displaysize}';
//]]>
</script>
";

// output form
$forminputs = array();
$forminputs[] = get_string('sortby', 'report_coursesize') . html_writer::select($orderoptions, 'sorder', $sortorder, array());
$forminputs[] = get_string('sortdir', 'report_coursesize') . html_writer::select($diroptions, 'sdir', $sortdir, array());
$forminputs[] = get_string('displaysize', 'report_coursesize') . html_writer::select($sizeoptions, 'display', $displaysize, array());
$forminputs[] = html_writer::empty_tag('input', array('type' => 'submit', 'name' => 'go', 'value' => get_string('refresh')));
echo html_writer::start_tag('div', array('style' => 'text-align:center;margin-bottom:10px;'));
echo html_writer::start_tag('form', array('name' => 'sortoptions', 'method' => 'POST', 'action' => new moodle_url('/report/coursesize/index.php')));
echo implode('&nbsp;&nbsp;&nbsp;', $forminputs);
echo html_writer::end_tag('form');
echo html_writer::end_tag('div');

$PAGE->requires->js(new moodle_url('/report/coursesize/catsize.js'));
echo html_writer::tag('div', '', array('id' => 'cat0', 'style' => 'display:none;'));

echo $OUTPUT->footer();
