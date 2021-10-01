# Datewatch plugin

The **Datewatch plugin** allows other Moodle plugins to execute a callback every time some date occurs.

For example, plugins can monitor such things as "course enrolment ended", "something is due", etc.

Quick example: to execute callback every time the course starts put it in your plugin's lib.php:

```
function YOURPLUGINNAME_datewatch(tool_datewatch_manager $manager) {
    $manager->watch('course', 'startdate')
        ->set_callback(function(int $courseid, int $startdate) {
            // This callback will be executed from cron on each course start.
        });
}
```

## How it works

When the watcher is added for the first time, the watched table is scanned and all upcoming dates are added
to the table 'tool_datewatch_upcoming'. After that the **Datewatch plugin** listens to all create and update
events on the watched table and updates the upcoming dates.

Datewatch plugin defines a scheduled task that is executed from cron (by default every two minutes).
Every time the scheduled task runs it queries the "upcoming" table and executes the callback if some date is
reached. The "upcoming" table has the database index on the date field and the queries are very fast. Initial
indexing may take some time, it is also performed in cron.

There are two requirements to the watched dates:
- The watched date has to be a field in a database table plus/minus a fixed offset (i.e. "7 days before the due date");
- There must be events triggered every time a record is added, updated or deleted in the respective database table
  and this event must have correct `objecttable` and `objectid` properties.

Plugin takes care of the special case for the module tables - the `course_module_updated` event is known to update two
tables - 'course_modules' and the module table ('assign', 'quiz', etc).

## How to use

To use **Datewatch** your plugin needs to define the callback `PLUGINNAME_datewtach()` in the `lib.php` 
that accepts an argument `tool_datewatch_manager $manager`. Your plugin can register as many watchers 
as it needs by calling `$manager->watch()`. If your plugin needs to register several watchers for the same field,
you need to assign them different short names.

```
function YOURPLUGINNAME_datewatch(tool_datewatch_manager $manager) {
    $manager->watch('TABLENAME', 'FIELDNAME')->set_callback(...);
}
```

### Examples:

Watch when course enrolment has ended for a user and send them a notification:
```
$manager->watch('user_enrolments', 'timeend')
    ->set_callback(function($recordid) {
        global $DB;
        if ($record = $DB->get_record('user_enrolments', ['id' => $recordid])) {
            $enrol = $DB->get_record('enrol', ['id' => $record->enrolid]);
            YOURPLUGINNAME_send_notification_enrollment_ended($record->userid, $enrol->courseid);
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

It is also possible to add an offset to the watched dates. Send notification 3 days before due date:

```
$manager->watch('assign', 'duedate', - 3 * DAYSECS)
    ->set_callback(function($assignid) {
        // ...
    });
```
