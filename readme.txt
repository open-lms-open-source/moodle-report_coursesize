Course size report
==================

Developed by NetSpot Pty Ltd, Australia
Website: http://netspot.com.au
Author: Kirill Astashov

Developed and tested on Moodle 2.2

This admin report calculates size of file storage space occupied by categories
and courses. Calculations are based on information stored in table 'files'.


Features
========
1. The report displays size of category (bolded) and course files.
2. Categories can be drilled down with AJAX.
3. User files size is also calculated separately, and grand total is displayed.
4. The following display options are available:
- 'Sort by' (Size, A-Z, or Moodle sortorder),
- 'Sort direction' (Ascending, Descending),
- 'Display sizes as' (bytes, KB, MB, GB or Auto).


External data retrieval
=======================
Results are stored in 'filesize' field against contextlevel-instanceid couple,
where contextlevel=0 is reserved for totals as follows:

1. instanceid=0 stores grand total for all files (course files + user files).
2. instanceid=1 stores total for user files in Moodle.
3. instanceid=2 stores grand total for unique file records in Moodle (unique hashes).

Install instructions
====================
1. Copy code to <dirroot>/reports/coursesize, so that index.php
is available at http://<wwwroot>/report/coursesize/index.php

2. Install the plugin.

3. On New Settings page, set the required parameters.

4. Settings for the report are located in
Site administrations - Plugins - Reports - Course size

5. The report itself is located in
Site administrations - Reports - Course size


Additional information
======================
The report creates a DB table 'report_coursesize' to cache results.
It is strongly recommended to use cron task to update cached results when Moodle
is not busy (i.e. nightly), please refer to plugin settings for details.


