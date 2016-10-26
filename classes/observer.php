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
        $repo = self::get_google_drive_repo();
        switch($event->eventname) {
            case '\core\event\course_category_updated':
                $categoryid = $event->objectid;
                $courses = $DB->get_records('course', array('category' => $categoryid), 'id', 'id, visible');
                foreach ($courses as $course) {
                    $courseid = $course->id;
                    $coursecontext = context_course::instance($courseid);
                    $userids = self::get_google_authenticated_userids($courseid);
                    $coursemodinfo = get_fast_modinfo($courseid, -1);
                    $coursemods = $coursemodinfo->get_cms();
                    $cms = array();
                    $cmids = array();
                    foreach ($coursemods as $cm) {
                        if ($cm->modname == 'resource') {
                            $cmids[] = $cm->id;
                            $cms[] = $cm;
                        }
                    }
                    if ($course->visible == 1) {
                        foreach ($cms as $cm) {
                            $cmid = $cm->id;
                            if ($cm->visible == 1) {
                                rebuild_course_cache($courseid, true);
                                foreach ($userids as $userid) {
                                    $email = self::get_google_authenticated_users_email($userid);
                                    $modinfo = get_fast_modinfo($courseid, $userid);
                                    $cminfo = $modinfo->get_cm($cmid);
                                    $sectionnumber = self::get_cm_sectionnum($cmid);
                                    $secinfo = $modinfo->get_section_info($sectionnumber);
                                    if ($cminfo->uservisible
                                        && $secinfo->available
                                        && is_enrolled($coursecontext, $userid, '', true)) {
                                            self::insert_cm_permission($cmid, $email, $repo);
                                    } else {
                                        self::remove_cm_permission($cmid, $email, $repo);
                                    }
                                }
                            } else {
                                foreach ($userids as $userid) {
                                    $email = self::get_google_authenticated_users_email($userid);
                                    self::remove_cm_permission($cmid, $email, $repo);
                                }
                            }
                        }
                    } else {
                        foreach ($cmids as $cmid) {
                            foreach ($userids as $userid) {
                                $email = self::get_google_authenticated_users_email($userid);
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    }
                }
                break;
            case '\core\event\course_updated':
                $courseid = $event->courseid;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $userids = self::get_google_authenticated_userids($courseid);
                $coursemodinfo = get_fast_modinfo($courseid, -1);
                $cms = $coursemodinfo->get_cms();
                $cmids = array();
                foreach ($cms as $cm) {
                    $cmids[] = $cm->id;
                }
                if ($course->visible == 1) {
                    foreach ($cms as $cm) {
                        $cmid = $cm->id;
                        if ($cm->visible == 1) {
                            rebuild_course_cache($courseid, true);
                            foreach ($userids as $userid) {
                                $email = self::get_google_authenticated_users_email($userid);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = self::get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                    self::insert_cm_permission($cmid, $email, $repo);
                                } else {
                                    self::remove_cm_permission($cmid, $email, $repo);
                                }
                            }
                        } else {
                            foreach ($userids as $userid) {
                                $email = self::get_google_authenticated_users_email($userid);
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    }
                } else {
                    foreach ($cmids as $cmid) {
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                }
                break;
            case '\core\event\course_content_deleted':
                $courseid = $event->courseid;
                $userids = self::get_google_authenticated_userids($courseid);
                $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                foreach ($cms as $cm) {
                    foreach ($userids as $userid) {
                        $email = self::get_google_authenticated_users_email($userid);
                        self::remove_cm_permission($cm->cmid, $email, $repo);
                    }
                    $DB->delete_records('repository_gdrive_references', array('cmid' => $cm->cmid));
                }
                break;
            case '\core\event\course_section_updated':
                $courseid = $event->courseid;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $userids = self::get_google_authenticated_userids($courseid);
                $sectionnumber = $event->other['sectionnum'];
                $cms = self::get_section_course_modules($sectionnumber);
                if ($course->visible == 1) {
                    foreach ($cms as $cm) {
                        $cmid = $cm->cmid;
                        if ($cm->cmvisible == 1) {
                            rebuild_course_cache($courseid, true);
                            foreach ($userids as $userid) {
                                $email = self::get_google_authenticated_users_email($userid);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = self::get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                    self::insert_cm_permission($cmid, $email, $repo);
                                } else {
                                    self::remove_cm_permission($cmid, $email, $repo);
                                }
                            }
                        } else {
                            foreach ($userids as $userid) {
                                $email = self::get_google_authenticated_users_email($userid);
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    }
                } else {
                    foreach ($cms as $cm) {
                        $cmid = $cm->id;
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                }
                break;
            case '\core\event\course_module_created':
                // Deal with file permissions.
                $courseid = $event->courseid;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $userids = self::get_google_authenticated_userids($courseid);
                $cmid = $event->contextinstanceid;
                if ($course->visible == 1) {
                    $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
                    if ($cm->visible == 1) {
                        rebuild_course_cache($courseid, true);
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $sectionnumber = self::get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);
                            if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                self::insert_cm_permission($cmid, $email, $repo);
                            } else {
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    } else {
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                } else {
                    foreach ($userids as $userid) {
                        $email = self::get_google_authenticated_users_email($userid);
                        self::remove_cm_permission($cmid, $email, $repo);
                    }
                }

                // Store cmid and reference.
                $newdata = new stdClass();
                $newdata->courseid = $courseid;
                $newdata->cmid = $cmid;
                $newdata->reference = self::get_resource($cmid);
                if ($newdata->reference) {
                    $DB->insert_record('repository_gdrive_references', $newdata);
                }
                break;
            case '\core\event\course_module_updated':
                // Deal with file permissions.
                $courseid = $event->courseid;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $userids = self::get_google_authenticated_userids($courseid);
                $cmid = $event->contextinstanceid;
                if ($course->visible == 1) {
                    $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
                    if ($cm->visible == 1) {
                        rebuild_course_cache($courseid, true);
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $sectionnumber = self::get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);
                            if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                self::insert_cm_permission($cmid, $email, $repo);
                            } else {
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    } else {
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                } else {
                    foreach ($userids as $userid) {
                        $email = self::get_google_authenticated_users_email($userid);
                        self::remove_cm_permission($cmid, $email, $repo);
                    }
                }

                // Update course module reference.
                $newdata = new stdClass();
                $newdata->cmid = $cmid;
                $newdata->reference = self::get_resource($cmid);

                if (!is_null($newdata->cmid) && $newdata->reference) {
                    $reference = $DB->get_record('repository_gdrive_references', array ('cmid' => $cmid), 'id, reference');
                    if ($reference) {
                        $newdata->id = $reference->id;
                        if ($newdata->reference != $reference->reference) {
                            $DB->update_record('repository_gdrive_references', $newdata);
                        }
                    }
                }
                break;
            case '\core\event\course_module_deleted':
                if ($event->other['modulename'] == 'resource') {
                    $courseid = $event->courseid;
                    $userids = self::get_google_authenticated_userids($courseid);
                    $cmid = $event->contextinstanceid;
                    $gcmid = $DB->get_record('repository_gdrive_references', array('cmid' => $cmid), 'id');
                    if ($gcmid) {
                        foreach ($userids as $userid) {
                            $email = self::get_google_authenticated_users_email($userid);
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                        $DB->delete_records('repository_gdrive_references', array('cmid' => $cmid));
                    }
                }
                break;
            case '\repository_googledrive\event\repository_gdrive_tokens_created':
                $userid = $event->relateduserid;
                $email = self::get_google_authenticated_users_email($userid);
                $usercourses = self::get_user_courseids($userid);
                foreach ($usercourses as $usercourse) {
                    $courseid = $usercourse->courseid;
                    $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                    $coursecontext = context_course::instance($courseid);
                    $coursemodinfo = get_fast_modinfo($courseid, -1);
                    $coursemods = $coursemodinfo->get_cms();
                    $cms = array();
                    $cmids = array();
                    foreach ($coursemods as $cm) {
                        if ($cm->modname == 'resource') {
                            $cmids[] = $cm->id;
                            $cms[] = $cm;
                        }
                    }
                    if ($course->visible == 1) {
                        foreach ($cms as $cm) {
                            $cmid = $cm->id;
                            if ($cm->visible == 1) {
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = self::get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible
                                    && $secinfo->available
                                    && is_enrolled($coursecontext, $userid, '', true)) {
                                        self::insert_cm_permission($cmid, $email, $repo);
                                    } else {
                                        self::remove_cm_permission($cmid, $email, $repo);
                                    }
                            } else {
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    } else {
                        foreach ($cmids as $cmid) {
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                }
                break;
            case '\repository_googledrive\event\repository_gdrive_tokens_deleted':
                $eventdata = $event->get_record_snapshot('repository_gdrive_tokens', $event->objectid);
                $email = $eventdata->gmail;
                $userid = $event->relateduserid;
                $usercourses = self::get_user_courseids($userid);
                foreach ($usercourses as $usercourse) {
                    $courseid = $usercourse->courseid;
                    $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                    $coursecontext = context_course::instance($courseid);
                    $coursemodinfo = get_fast_modinfo($courseid, -1);
                    $coursemods = $coursemodinfo->get_cms();
                    $cmids = array();
                    foreach ($coursemods as $cm) {
                        if ($cm->modname == 'resource') {
                            self::remove_cm_permission($cm->id, $email, $repo);
                        }
                    }
                }
                break;
            case '\core\event\user_enrolment_created':
            case '\core\event\user_enrolment_updated':
                $courseid = $event->courseid;
                $userid = $event->relateduserid;
                $email = self::get_google_authenticated_users_email($userid);
                if ($email) {
                    $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                    $coursecontext = context_course::instance($courseid);
                    $coursemodinfo = get_fast_modinfo($courseid, -1);
                    $coursemods = $coursemodinfo->get_cms();
                    $cms = array();
                    $cmids = array();
                    foreach ($coursemods as $cm) {
                        if ($cm->modname == 'resource') {
                            $cmids[] = $cm->id;
                            $cms[] = $cm;
                        }
                    }
                    if ($course->visible == 1) {
                        foreach ($cms as $cm) {
                            $cmid = $cm->id;
                            if ($cm->visible == 1) {
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = self::get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible
                                    && $secinfo->available
                                    && is_enrolled($coursecontext, $userid, '', true)) {
                                        self::insert_cm_permission($cmid, $email, $repo);
                                    } else {
                                        self::remove_cm_permission($cmid, $email, $repo);
                                    }
                            } else {
                                self::remove_cm_permission($cmid, $email, $repo);
                            }
                        }
                    } else {
                        foreach ($cmids as $cmid) {
                            self::remove_cm_permission($cmid, $email, $repo);
                        }
                    }
                }
                break;
            case '\core\event\user_enrolment_deleted':
                $courseid = $event->courseid;
                $userid = $event->relateduserid;
                $email = self::get_google_authenticated_users_email($userid);
                if ($email) {
                    $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                    foreach ($cms as $cm) {
                        $cmid = $cm->cmid;
                        self::remove_cm_permission($cmid, $email, $repo);
                    }
                }
                break;
            /**
            case '\core\event\role_assigned':
                $userid = $event->relateduserid;
                $contextlevel = $event->contextlevel;
                switch ($contextlevel) {
                    case 10:
                        $systemcontext = context_system::instance();
                        if (has_capability('mod/resource:addinstance', $systemcontext, $userid)) {
                            $categories = $DB->get_records('course_categories', array(), 'id', 'id');
                            foreach ($categories as $category) {
                                $categoryid = $category->id;
                                $courses = $DB->get_records('course', array('category' => $categoryid), 'id', 'id');
                                foreach ($courses as $course) {
                                    $courseid = $course->id;
                                    $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                                    foreach ($cms as $cm) {
                                        $cmid = $cm->id;
                                        self::insert_cm_permission($cmid, $userid, $repo, 'writer');
                                    }
                                }
                            }
                        }
                        break;
                    case 40:
                        $categoryid = $event->contextinstanceid;
                        $categorycontext = context_coursecat::instance($categoryid);
                        if (has_capability('mod/resource:addinstance', $categorycontext, $userid)) {
                            $courses = $DB->get_records('course', array('category' => $categoryid), 'id', 'id');
                            foreach ($courses as $course) {
                                $courseid = $course->id;
                                $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                                foreach ($cms as $cm) {
                                    $cmid = $cm->id;
                                    self::insert_cm_permission($cmid, $userid, $repo, 'writer');
                                }
                            }
                        }
                        break;
                    case 50:
                        $courseid = $event->courseid;
                        $coursecontext = context_course::instance($courseid);
                        if (has_capability('mod/resource:addinstance', $coursecontext, $userid)) {
                            $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                            foreach ($cms as $cm) {
                                $cmid = $cm->id;
                                self::insert_cm_permission($cmid, $userid, $repo, 'writer');
                            }
            
                        }
                        break;
                    case 70:
                        $cmid = $event->contextinstanceid;
                        $contextmodule = context_module::instance($cmid);
                        if (has_capability('mod/resource:addinstance', $contextmodule, $userid)) {
                            self::insert_cm_permission($cmid, $userid, $repo, 'writer');
                        }
                        break;
                }
                break;
            case '\core\event\role_unassigned':
                $userid = $event->relateduserid;
                $contextlevel = $event->contextlevel;
                switch ($contextlevel) {
                    case 10:
                        $systemcontext = context_system::instance();
                        if (!has_capability('mod/resource:addinstance', $systemcontext, $userid)) {
                            $categories = $DB->get_records('course_categories', array(), 'id', 'id');
                            foreach ($categories as $category) {
                                $categoryid = $category->id;
                                $courses = $DB->get_records('course', array('category' => $categoryid), 'id', 'id');
                                foreach ($courses as $course) {
                                    $courseid = $course->id;
                                    $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                                    foreach ($cms as $cm) {
                                        $cmid = $cm->id;
                                        self::remove_cm_permission($cmid, $userid, $repo, 'writer');
                                    }
                                }
                            }
                        }
                        break;
                    case 40:
                        $categoryid = $event->contextinstanceid;
                        $categorycontext = context_coursecat::instance($categoryid);
                        if (!has_capability('mod/resource:addinstance', $categorycontext, $userid)) {
                            $courses = $DB->get_records('course', array('category' => $categoryid, 'visible' => 1));
                            foreach ($courses as $course) {
                                $courseid = $course->id;
                                $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                                foreach ($cms as $cm) {
                                    $cmid = $cm->id;
                                    self::remove_cm_permission($cmid, $userid, $repo, 'writer');
                                }
                            }
                        }
                        break;
                    case 50:
                        $courseid = $event->courseid;
                        $coursecontext = context_course::instance($courseid);
                        if (has_capability('mod/resource:addinstance', $coursecontext, $userid)) {
                            $cms = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), 'id', 'cmid');
                            foreach ($cms as $cm) {
                                $cmid = $cm->id;
                                self::remove_cm_permission($cmid, $userid, $repo, 'writer');
                            }
            
                        }
                        break;
                    case 70:
                        $cmid = $event->contextinstanceid;
                        $contextmodule = context_module::instance($cmid);
                        if (has_capability('mod/resource:addinstance', $contextmodule, $userid)) {
                            self::remove_cm_permission($cmid, $userid, $repo, 'writer');
                        }
                        break;
                }
                break;
            case '\core\event\role_capabilities_updated':
                break;
                */
        }
        return true;
    }

    /**
     * Get the section number for a course module.
     *
     * @param course module id $cmid
     * @return section number
     */
    private static function get_cm_sectionnum($cmid) {
        global $DB;
        $sql = "SELECT cs.section
                FROM {course_sections} cs
                LEFT JOIN {course_modules} cm
                ON cm.section = cs.id
                WHERE cm.id = :cmid";
        $section = $DB->get_record_sql($sql, array('cmid' => $cmid));
        return $section->section;
    }

    /**
     * Get course module records for specified section.
     *
     * @param section number $sectionnumber
     * @return array of course module records
     */
    private static function get_section_course_modules($sectionnumber) {
        global $DB;
        $sql = "SELECT cm.id as cmid, cm.visible as cmvisible, cs.id as csid, cs.visible as csvisible
                FROM {course_modules} cm
                LEFT JOIN {course_sections} cs
                ON cm.section = cs.id
                WHERE cs.section = :sectionnum;";
        $cms = $DB->get_records_sql($sql, array('sectionnum' => $sectionnumber));
        return $cms;
    }

    /**
     * Insert permission for specified user for specified module.
     * Assumes all visibility and availability checks have been done before calling.
     *
     * @param course module id $cmid
     * @param user id $userid
     * @param repo $repo
     */
    private static function insert_cm_permission($cmid, $email, $repo) {
        global $DB;
        //$email = self::get_google_authenticated_users_email($userid);
        $fileid = self::get_resource($cmid);
        if ($fileid) {
            $existing = $DB->get_record('repository_gdrive_references', array('cmid' => $cmid), 'reference');
            if ($existing && ($existing->reference != $fileid)) {
                try {
                    $permissionid = $repo->print_permission_id_for_email($email);
                    $repo->remove_permission($existing->reference, $permissionid);
                    $repo->insert_permission($fileid, $email,  'user', 'reader');
                } catch (Exception $e) {
                    print "An error occurred: " . $e->getMessage();
                }
            } else {
                try {
                    $repo->insert_permission($fileid, $email,  'user', 'reader');
                } catch (Exception $e) {
                    print "An error occurred: " . $e->getMessage();
                }
            }
        }
    }

    /**
     * Delete permission for specified user for specified module.
     *
     * @param course module id $cmid
     * @param user id $userid
     * @param repo $repo
     */
    private static function remove_cm_permission($cmid, $email, $repo) {
        global $DB;
        //$email = self::get_google_authenticated_users_email($userid);
        $filerec = $DB->get_record('repository_gdrive_references', array('cmid' => $cmid), 'reference');
        if ($filerec) {
            $fileid = $filerec->reference;
            try {
                $permissionid = $repo->print_permission_id_for_email($email);
                $repo->remove_permission($fileid, $permissionid);
            } catch (Exception $e) {
                print "An error occurred: " . $e->getMessage();
            }
        }
    }

    /**
     * Get the fileid for specified course module.
     *
     * @param course module id $cmid
     * @return mixed fileid if files_reference record exists, false if not
     */
    private static function get_resource($cmid) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'), 'id');
        $id = $googledriverepo->id;
        if (empty($id)) {
            // We did not find any instance of googledrive.
            mtrace('Could not find any instance of the repository');
            return;
        }

        $sql = "SELECT DISTINCT r.reference
                FROM {repository_gdrive_references} r
                LEFT JOIN {files} f
                ON r.id = f.referencefileid
                LEFT JOIN {context} c
                ON f.contextid = c.id
                LEFT JOIN {course_modules} cm
                ON c.instanceid = cm.id
                WHERE cm.id = :cmid
                AND r.repositoryid = :repoid
                AND f.referencefileid IS NOT NULL
                AND not (f.component = :component and f.filearea = :filearea)";
        $filerecord = $DB->get_record_sql($sql,
            array('component' => 'user', 'filearea' => 'draft', 'repoid' => $id, 'cmid' => $cmid));
        if ($filerecord) {
            return $filerecord->reference;
        } else {
            return false;
        }
    }

    /**
     * Get the gmail address for a specified user.
     *
     * @param user id $userid
     * @return mixed gmail address if record exists, false if not
     */
    private static function get_google_authenticated_users_email($userid) {
        global $DB;
        $googlerefreshtoken = $DB->get_record('repository_gdrive_tokens', array ('userid' => $userid), 'gmail');
        if ($googlerefreshtoken) {
            return $googlerefreshtoken->gmail;
        } else {
            return false;
        }
    }

    /**
     * Get userids for users in specified course.
     *
     * @param courseid $courseid
     * @return array of userids
     */
    private static function get_google_authenticated_userids($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT grt.userid
                FROM {user} eu1_u
                JOIN {repository_gdrive_tokens} grt
                ON eu1_u.id = grt.userid
                JOIN {user_enrolments} eu1_ue
                ON eu1_ue.userid = eu1_u.id
                JOIN {enrol} eu1_e
                ON (eu1_e.id = eu1_ue.enrolid AND eu1_e.courseid = :courseid)
                WHERE eu1_u.deleted = 0 AND eu1_u.id <> :guestid AND eu1_ue.status = 0";
        $users = $DB->get_recordset_sql($sql, array('courseid' => $courseid, 'guestid' => '1'));
        $usersarray = array();
        foreach ($users as $user) {
            $usersarray[] = $user->userid;
        }
        return $usersarray;
    }
    
    /**
     * Get course records for specified user
     * 
     * @param user id $userid
     * @return course records
     */
    private static function get_user_courseids($userid) {
        global $DB;
        $sql = "SELECT e.courseid
                FROM {enrol} e
                LEFT JOIN {user_enrolments} ue
                ON e.id = ue.enrolid 
                WHERE ue.userid = :userid;";
        $courses = $DB->get_recordset_sql($sql, array('userid' => $userid));
        return $courses;
    }

    /**
     * Get Google Drive repo id.
     *
     * @return repository_googledrive
     */
    private static function get_google_drive_repo() {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'), 'id');
        return new repository_googledrive($googledriverepo->id);
    }
}
