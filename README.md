# Course size report

Developed by Open LMS

Website: http://openlms.net

Original Author: Kirill Astashov

This admin report calculates size of file storage space occupied by categories
and courses. Calculations are based on information stored in table 'files'.


## Features
1. The report displays size of category (bolded) and course files.
2. Categories can be drilled down with AJAX.
3. User files size is also calculated separately, and grand total is displayed.
4. The following display options are available:
    - 'Sort by' (Size, A-Z, or Moodle™ sortorder),
    - 'Sort direction' (Ascending, Descending),
    - 'Display sizes as' (bytes, KB, MB, GB or Auto).


## External data retrieval
Results are stored in 'filesize' field against contextlevel-instanceid couple,
where contextlevel=0 is reserved for totals as follows:

1. instanceid=0 stores grand total for all files (course files + user files).
2. instanceid=1 stores total for user files in Moodle™.
3. instanceid=2 stores grand total for unique file records in Moodle™ (unique hashes).

## Install

### Using Moodle
You can install the plugin from the Moodle™ plugin repository from within your Moodle™ installation.

### Using a downloaded zip file
You can download a zip of this plugin from: https://github.com/open-lms-open-source/moodle-report_coursesize/zipball/master
Unzip it to your report/ folder and rename the extracted folder to 'coursesize'.

### Using Git
To install using git, run the following command from the root of your moodle installation:  
git clone git://github.com/open-lms-open-source/moodle-report_coursesize.git report/coursesize

Then add report/coursesize to your gitignore.


## Additional information
The report creates a DB table 'report_coursesize' to cache results. It is strongly recommended to use use the cron method for calculation to update cached results when Moodle™ is not busy (i.e. nightly), please refer to plugin settings for details.
