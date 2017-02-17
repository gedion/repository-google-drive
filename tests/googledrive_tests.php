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
global $CFG;
require_once($CFG->dirroot . '/course/tests/fixtures/format_theunittest.php');
require_once($CFG->dirroot . '/repository/googledrive/classes/observer.php');
require_once($CFG->dirroot . '/repository/googledrive/classes/event/repository_gdrive_tokens_created.php');
require_once($CFG->dirroot . '/repository/googledrive/lib.php');

class test_repository_googledrive extends advanced_testcase {

    private function enable_google_drive_repository() {
        global $CFG, $DB;
        $type = $this->getDataGenerator()->create_repository_type('googledrive');
        $repo = $this->getDataGenerator()->create_repository('googledrive');
        set_config('clientid', 'clientid1', 'googledrive');
        set_config('secret', 'secret1', 'googledrive');
        if (!$repoid = $repo->id) {
            error_log('Cannot create Googledrive repo');
        }
        return $repo;
    }

    /**
     * create_google_user_and_enrol
     *
     * @param mixed $course
     * @return void
     */
    private function create_google_user_and_enrol($course) {
        global $DB, $USER;
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $users = [];
        $user = $this->getDataGenerator()->create_user();
        $userdata = new stdClass();
        $userdata->refreshtokenid = '1';
        $userdata->gmail = 'testuser2@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleids['student']);
        $USER = $user;
        $this->setAdminUser();
        $users [] = $user;
        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '2';
        $userdata->gmail = 'testuser3@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleids['student']);
        $users [] = $user;
        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '3';
        $userdata->gmail = 'testuser4@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleids['student']);
        $users [] = $user;
        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '4';
        $userdata->gmail = 'testuser5@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleids['student']);
        $users [] = $user;
        return $users;
    }

    private function create_resources($contextid) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'));
        $repoid = $googledriverepo->id;
        $refrecord = new stdClass();
        $refrecord->repositoryid = $repoid;
        $refrecord->reference = 'dummygooglefileid';
        $filesreferenceid = $DB->insert_record('files_reference', $refrecord);
        $filerecord = new stdClass();
        $filerecord->contextid = $contextid;
        $filerecord->component = 'mod_url';
        $filerecord->filearea = 'phpunit';
        $filerecord->contenthash = 'testf1';
        $filerecord->filepath = '/';
        $filerecord->filename = 'googledrivedoctest.txt';
        $filerecord->itemid = 0;
        $filerecord->filesize = 0;
        $filerecord->referencefileid = $filesreferenceid;
        $filerecord->timecreated = time();
        $filerecord->timemodified = time();
        $DB->insert_record('files', $filerecord);
    }

    /**
     * Sets a protected property on a given object via reflection
     *
     * @param $object - instance in which protected value is being modified
     * @param $property - property on instance being modified
     * @param $value - new value of the property being modified
     *
     * @return void
     */
    private function set_protected_property($object, $property, $value) {
        $property = new ReflectionProperty('repository_googledrive', $property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function get_googlelib_mock_permissions() {
        $mockgooglepermissions = $this->getMockBuilder('Permissions')->setMethods(
            array('insert', 'getIdForEmail', 'get', 'delete'))->getMock();
        $mockgooglelibpermissionid = $this->getMockBuilder('Google_Service_Drive_PermissionId')->setMethods(array('getId', 'getRole'))->getMock();
        $mockgooglelibpermissionid->method('getId')->willReturn('dummypermissionid');
        $mockgooglelibpermissionid->method('getRole')->willReturn('reader');
        $mockgooglepermissions->method('insert')->willReturn(array());
        $mockgooglepermissions->method('getIdForEmail')->willReturn($mockgooglelibpermissionid);
        $mockgooglepermissions->method('get')->willReturn($mockgooglelibpermissionid);
        return $mockgooglepermissions;
    }

    private function get_googledrive_mock_repo() {
        $mockgoogleservice = $this->getMockBuilder('Service')->setMethods(array('setPermisssion'))->getMock();
        $mockgoogleservice->permissions = $this->get_googlelib_mock_permissions();
        $mockrepo = $this->getMockbuilder(
                'repository_googledrive')->disableoriginalconstructor()->setmethods(
                array('noop'))->getmock();
        $this->set_protected_property($mockrepo, 'service', $mockgoogleservice);
        return $mockrepo;
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     */
    public function fire_googledrive_course_updated_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $course->visible = 1;
        $this->create_google_user_and_enrol($course);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[12]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_course_updated_hidden_event() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();
        $repoinstance = $this->enable_google_drive_repository();
        $course->visible = 0;
        $this->create_google_user_and_enrol($course);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[8]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_role_assigned_event() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[7]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_role_unassigned_event() {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $sink = $this->redirectEvents();
        $users = $this->create_google_user_and_enrol($course);
        update_course($course);
        $events = $sink->get_events();
        $mockrepo = $this->get_googledrive_mock_repo();
        $mockrepo->manage_resources($events[8]);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        role_unassign($studentrole->id, $users[3]->id, context_course::instance($course->id)->id);
        $events = $sink->get_events();
        $sink->close();
        $this->assertNotNull($mockrepo->manage_resources($events[9]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_module_created_event() {
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[12]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_module_updated_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $formdata = $DB->get_record('course_modules', array('id' => $drivemodule->cmid));
        $formdata->modulename = 'Update url name';
        $formdata->display = 0;
        $formdata->externalurl = $drivemodule->externalurl;
        $formdata->coursemodule = $drivemodule->cmid;
        $draftideditor = 0;
        file_prepare_draft_area($draftideditor, null, null, null, null);
        $formdata->introeditor = array('text' => 'This is a module', 'format' => FORMAT_HTML, 'itemid' => $draftideditor);
        update_module($formdata);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[13]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_module_deleted_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        course_delete_module($drivemodule->cmid);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[14]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_course_category_updated_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $category = $this->getDataGenerator()->create_category();
        $course->category = $category->id;
        update_course($course);
        $data = new stdClass();
        $data->name = 'Category name change';
        $data = new stdClass();
        $category->update($data);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[15]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_course_content_deleted_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $coursecontext = context_course::instance($course->id);
        remove_course_contents($course->id, false);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[24]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_googledrive_course_section_updated_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $sectioncreatedevent = $this->create_course_section_updated_event($sections, $course);
        $sectioncreatedevent->trigger();
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[13]));
    }

    private function create_course_section_updated_event($sections, $course) {
        global $DB;
        $section = array_pop($sections);
        $section->name = 'Test section';
        $section->summary = 'Test section summary';
        $DB->update_record('course_sections', $section);

        // Trigger an event for course section update.
        $event = \core\event\course_section_updated::create(
                array(
                    'objectid' => $section->id,
                    'courseid' => $course->id,
                    'context' => context_course::instance($course->id),
                    'other' => array(
                        'sectionnum' => $section->section
                    )
                )
            );
        $event->add_record_snapshot('course_sections', $section);
        return $event;
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_repository_gdrive_tokens_created_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $this->create_repository_gdrive_tokens_created_event($users[0], $course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[13]));
    }

    private function create_repository_gdrive_tokens_created_event($user , $course) {
        global $DB;
        $userdata = new stdClass();
        $userdata->refreshtokenid = '1';
        $userdata->gmail = 'testuser2@gmail.com';
        $userdata->userid = $user->id;
        $userdata->id = $user->id;
        $event = \repository_googledrive\event\repository_gdrive_tokens_created::create(
                array(
                    'objectid' => $userdata->id,
                    'courseid' => $course->id,
                    'context' => context_course::instance($course->id),
                    'relateduserid' => $userdata->userid
                )
            );
        $event->add_record_snapshot('repository_gdrive_tokens', $userdata);
        $event->trigger();
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_repository_gdrive_tokens_deleted_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $this->create_repository_gdrive_tokens_deleted_event($users[0], $course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[13]));
    }

    private function create_repository_gdrive_tokens_deleted_event($user , $course) {
        global $DB;
        $userdata = new stdClass();
        $userdata->refreshtokenid = '1';
        $userdata->gmail = 'testuser2@gmail.com';
        $userdata->userid = $user->id;
        $userdata->id = $user->id;
        $event = \repository_googledrive\event\repository_gdrive_tokens_deleted::create(
                array(
                    'objectid' => $userdata->id,
                    'courseid' => $course->id,
                    'context' => context_course::instance($course->id),
                    'relateduserid' => $userdata->userid
                )
            );
        $event->add_record_snapshot('repository_gdrive_tokens', $userdata);
        $event->trigger();
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_user_enrolment_created_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[10]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_user_enrolment_update_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $instances = enrol_get_instances($course->id, false);
        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->update_user_enrol(current($instances), $users[0]->id, ENROL_USER_SUSPENDED);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[10]));
    }

    /**
     * @test
     * Sanity check test
     * TO DO: refactor with better assertion
     *
     */
    public function fire_user_enrolment_delete_event() {
        global $DB;
        $this->resetAfterTest(true);
        $sink = $this->redirectEvents();
        $course = $this->getDataGenerator()->create_course();
        $repoinstance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('resource', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $sections = $DB->get_records('course_sections', array('course' => $course->id));
        $coursecontext = context_course::instance($course->id);
        $instances = enrol_get_instances($course->id, false);
        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->unenrol_user(current($instances), $users[0]->id);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertEquals(true, $mockrepo->manage_resources($events[14]));
    }
}
