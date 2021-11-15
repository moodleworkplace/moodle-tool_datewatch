# Datewatch plugin

The **Datewatch plugin** allows other Moodle plugins to execute a callback every time some date occurs.

For example, plugins can monitor such things as "course enrolment ended", "something is due", etc.

Quick example: to execute callback every time the course starts put it in your plugin's lib.php:

```
function YOURPLUGINNAME_datewatch() {
    $watchers = [];
    $watchers[] = \tool_datewatch\watcher::instance('course', 'startdate')
        ->set_callback(function(\tool_datewatch\notification $notification) {
            // This callback will be executed from cron on each course start.
        });
    return $watchers;
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
that returns an array of `\tool_datewatch\watcher` instances.

### Examples:

Watch when course enrolment has ended for a user and send them a notification:
```
\tool_datewatch\watcher::instance('user_enrolments', 'timeend')
    ->set_callback(function(\tool_datewatch\notification $notification) {
        if ($userenrolment = $notification->get_record()) {
            $enrol = $notification->get_snapshot('enrol', $userenrolment->enrolid);
            YOURPLUGINNAME_send_notification_enrollment_ended($userenrolment->userid, $enrol->courseid);
        }
    });
```

It is also possible to add an offset to the watched dates. Send notification 3 days before due date:

```
\tool_datewatch\watcher::instance('assign', 'duedate', - 3 * DAYSECS)
    ->set_callback(function(\tool_datewatch\notification $notification) {
        // ...
    });
```
