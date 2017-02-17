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
 * Observer class containing methods monitoring various events.
 *
 * @package tool_monitor
 * @copyright 2014 onwards Ankit Agarwal <ankit.agrr@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/repository/googledrive/lib.php');

/**
 * Observer class containing methods monitoring various events.
 *
 * @since Moodle 3.0
 * @package repository_googledrive
 * @copyright 2016 Gedion Woldeselassie <gedion@umn.edu>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class repository_googledrive_observer {
    /**
     * Sync google resource permissions based on various events.
     *
     * @param \core\event\* $event The event fired.
     * @return true
     */
    public static function manage_resources($event) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'));
        $repo = new repository_googledrive($googledriverepo->id);
        $repo->manage_resources($event);
    }
}
