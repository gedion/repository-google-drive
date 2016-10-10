<?php

$callback = 'repository_googledrive::manage_resources';

$observers = array (

    array (
        'eventname'   => '\core\event\course_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_module_created',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_module_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_module_delted',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\role_assigned',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\role_unassigned',
        'callback'    => $callback
    )

);

