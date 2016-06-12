<?php

function tool_datewatch_datewatch() {
    return [
        new tool_datewatch_watcher(
            'tool_datewatch',
            'course',
            'startdate',
            'tool_datewatch_course_started',
            function($course) {
                return $course->format === 'topics';
            },
            'format = :topicsformat',
            ['topicsformat' => 'topics']
        ),
        new tool_datewatch_watcher(
            'tool_datewatch',
            'user_enrolments',
            'timeend',
            'tool_datewatch_user_enrolment_ended'
        ),
    ];

}

function tool_datewatch_course_started($recordid, $datevalue) {
    
}

function tool_datewatch_user_enrolment_ended($recordid, $datevalue) {
    mtrace('YEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEESSSSSSSSSSSS '.$recordid.' = '.$datevalue);
}