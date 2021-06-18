# moodle-tool_datewatch

The tool_datewatch plugin allows other plugins to execute a callback every time some date passes.

For example, plugins can monitor such things as "course enrolment ended", "due date happened", etc.

There are two requirements:
- The watched date has to be a field in a database table (no calculations are supported such as "7 days after" 
  or table joins)
- There are events triggered every time a record is added or updated in the respective database table
  and this event has correct `objecttable` and `objectid` attributes.
  
There is also a special case for the module tables - the `course_module_updated` event is known to update two
tables - 'course_modules' and the module table ('assign', 'quiz', etc).

To use plugins need to define the callback `PLUGINNAME_datewtach()` in the `lib.php` that returns an array
of instances of the `tool_datewatch_watcher` class.

Each time cron runs the tool_datewatch plugin will make re-read the list of the watched dates and then
analyse which ones have now happened and execute a callback for them. The datewatch plugin defines its own
database tables with the necessary indexes. Even if the watched date field is not indexed in the original
table, the cron analysis will be fast (however the initial index may take some time).
