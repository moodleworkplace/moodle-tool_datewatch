# Datewatch plugin

The **Datewatch plugin** allows other Moodle plugins to execute a callback every time some date occurs.

For example, plugins can monitor such things as "course enrolment ended", "something is due", etc.

There are two requirements:
- The watched date has to be a field in a database table (no calculations are supported such as "7 days after" 
  or table joins)
- There are events triggered every time a record is added or updated in the respective database table
  and this event has correct `objecttable` and `objectid` attributes.
  
There is also a special case for the module tables - the `course_module_updated` event is known to update two
tables - 'course_modules' and the module table ('assign', 'quiz', etc).

To use **Datewatch** your plugin needs to define the callback `PLUGINNAME_datewtach()` in the `lib.php` 
that accepts an argument `tool_datewatch_manager $manager`. Your plugin can register as many watchers 
as it needs by calling `$manager->watch()`. If your plugin needs to register several watchers for the same field,
you need to assign them different short names (see example below).

```
function YOURPLUGINNAME_datewatch(tool_datewatch_manager $manager) {
    $manager->watch('TABLENAME', 'FIELDNAME')->set_callback(...);
}
```

Each time cron runs the **Datewatch plugin** will read the list of the watched dates and then
analyse which ones have now happened and execute a callback for them. The datewatch plugin defines its own
database tables with the necessary indexes. Even if the watched date field is not indexed in the original
table, the cron analysis will be fast (however the initial index may take some time).

### Examples:

Watch when course enrolment has ended for a user and send them a notification:
```
$manager->watch('user_enrolments', 'timeend')
    ->set_callback(function($recordid) {
        global $DB;
        if ($record = $DB->get_record('user_enrolments', ['id' => $recordid])) {
            YOURPLUGINNAME_send_notification_enrollment_ended($record->userid, $record->courseid);
        }
    });
```

Watch start date of any course in 'MYFORMAT' format and trigger custom event.

```
$manager->watch('course', 'startdate')
    ->set_callback(function($courseid, $timestamp) {
        $event = \format_MYFORMAT\event\course_started::create([
            'objectid' => $courseid,
            'context' => context_course::instance($courseid),
            'other' => ['startdate' => $timestamp],
        ])->trigger();
    })
    ->set_condition('format = :format', ['format' => 'MYFORMAT']);
```
        
If the plugin needs to register several watchers for the same field it can assign a unique
shortname to the watcher:

```
$manager->watch('course', 'startdate')
    ->set_shortname('anothercoursewatcher')
    ->set_callback(function() {
        // ...
    });    
```
