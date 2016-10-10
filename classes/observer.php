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
 * @package    tool_monitor
 * @copyright  2014 onwards Ankit Agarwal <ankit.agrr@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/repository/googledrive/lib.php');

/**
 * Observer class containing methods monitoring various events.
 *
 * @since      Moodle 3.0
 * @package    repository_googledrive
 * @copyright  2016 Gedion Woldeselassie <gedion@umn.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_googledrive_observer {

    /**
     * Sync google resource permissions based on various events.
     *
     * @param \core\event\* $event The event fired.
     */
    public static function manage_resources($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid));
        $repo = self::get_google_drive_repo();
        switch($event->eventname) {
            case '\core\event\course_updated':
                $usersemails = self::get_google_authenticated_users($courseid);
                $resources  = self::get_resources($courseid);
                foreach ($resources as $fileid) {
                    foreach($usersemails as $email) {
                        if($course->visible == 1) {
                                $repo->insert_permission($fileid, $email, 'user', 'reader');
                        } else {
                                $permissionid = $repo->print_permission_id_for_email($email);
                                $repo->remove_permission($fileid, $permissionid);
                        }
                    }
                }
                break;
            case '\core\event\course_module_created':
            case '\core\event\course_module_updated':
                $fileid = self::get_resource_id($courseid, $event->contextinstanceid);
                $usersemails = self::get_google_authenticated_users($courseid);
                foreach($usersemails as $email) {
                    if($course->visible == 1) {
                        $repo->insert_permission($fileid, $email, 'user', 'reader');
                    }
                }
                break;
            case '\core\event\role_assigned':
                $email = self::get_google_authenticated_users_email($event->relateduserid);
                $resources  = self::get_resources($courseid);
                foreach ($resources as $fileid) {
                    $repo->insert_permission($fileid, $email, 'user', 'reader');
                }
                break;
            case '\core\event\role_unassigned':
                $email = self::get_google_authenticated_users_email($event->relateduserid);
                $resources  = self::get_resources($courseid);
                foreach ($resources as $fileid) {
                    self::remove_permission($repo, $fileid, $email);
                }
                break;
            case '\core\event\course_module_deleted':
                $fileid = self::get_resource_id($courseid, $event->contextinstanceid);
                $usersemails = self::get_google_authenticated_users($courseid);
                foreach($usersemails as $email) {
                    self::remove_permission($repo, $fileid, $email);
                }
                break;
        }
        return true;
    }

    private static function remove_permission($repo, $fileid, $email) {
        $permissionid = $repo->print_permission_id_for_email($email);
        $repo->remove_permission($fileid, $permissionid);
    }


    private static function get_resources($courseid, $contextinstanceid=null) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type'=>'googledrive'));
        $id = $googledrive->id;
        if (empty($id)) {
            // We did not find any instance of googledrive.
            mtrace('Could not find any instance of the repository');
            return;
        }

        $sql = "SELECT f.contextid, r.reference
                  FROM {files_reference} r
                  LEFT JOIN {files} f
                       ON f.referencefileid = r.id
                 WHERE r.repositoryid = :repoid
                   AND f.referencefileid IS NOT NULL
                   AND NOT (f.component = :component
                            AND f.filearea = :filearea)";
       $resources = array();
       $filerecords = $DB->get_recordset_sql($sql, array('component' => 'user', 'filearea' => 'draft', 'repoid' => $id));
       foreach ($filerecords as $filerecord) {
           $docid = $filerecord->reference;
           list($context, $course, $cm) = get_context_info_array($filerecord->contextid);
           if($course->id == $courseid && is_null($contextinstanceid) or
              $course->id == $courseid && $cm->id == $contextinstanceid) {
               $resources[] = $docid;
           }
       }
       return $resources;
    }

    private static function get_resource_id($courseid, $contextinstanceid) {
        $resources  = self::get_resources($courseid, $contextinstanceid);
        return current($resources);
    }

    private static function get_google_authenticated_users_email($userid) {
        global $DB;
        $googlerefreshtoken = $DB->get_record('repository_gdrive_tokens', array ('userid'=> $userid));
        return $googlerefreshtoken->gmail;
    }

    private static function get_google_authenticated_users($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT grt.gmail
                  FROM {user} eu1_u
                  JOIN {repository_gdrive_tokens} grt
                        ON eu1_u.id = grt.userid
                  JOIN {user_enrolments} eu1_ue
                       ON eu1_ue.userid = eu1_u.id
                  JOIN {enrol} eu1_e
                       ON (eu1_e.id = eu1_ue.enrolid AND eu1_e.courseid = :courseid)
                WHERE eu1_u.deleted = 0 AND eu1_u.id <> :guestid ";
        $users = $DB->get_recordset_sql($sql, array('courseid' => $courseid, 'guestid' => '1'));
        $usersarray = array();
        foreach($users as $user) {
            $usersarray[] = $user->gmail;
        }
        return $usersarray;
    }

    private static function get_google_drive_repo() {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type'=>'googledrive'));
        return new repository_googledrive($googledrivesrepo->id);
    }

}
