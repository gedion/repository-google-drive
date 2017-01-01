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

defined('MOODLE_INTERNAL') || die();

$callback = 'repository_googledrive_observer::manage_resources';

$observers = array (

    array (
        'eventname'   => '\core\event\course_category_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_restored',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_content_deleted',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\tool_recyclebin\event\course_bin_item_restored',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\course_section_updated',
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
        'eventname'   => '\core\event\course_module_deleted',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\role_assigned',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\role_unassigned',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\role_capabilities_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\group_member_added',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\group_member_removed',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\grouping_group_assigned',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\grouping_group_unassigned',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\user_enrolment_created',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\user_enrolment_updated',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\user_enrolment_deleted',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\core\event\user_deleted',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\repository_googledrive\event\repository_gdrive_tokens_created',
        'callback'    => $callback
    ),
    array (
        'eventname'   => '\repository_googledrive\event\repository_gdrive_tokens_deleted',
        'callback'    => $callback
    )
);
