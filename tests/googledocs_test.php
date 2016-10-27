<?php

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/course/tests/fixtures/format_theunittest.php');
require_once($CFG->dirroot . '/repository/googledrive/classes/observer.php');
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
        $googledriverepo = $DB->get_record('repository', array ('type'=>'googledrive'));
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
        $filerecord->timemodified= time();
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
    private function setProtectedProperty($object, $property, $value)
    {
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
        $this->setProtectedProperty($mockrepo, 'service', $mockgoogleservice);
        return $mockrepo;
    }
    /**
      * @test
      * Sanity check test
      * TO DO: refactor with better assertion
      */
    public function googledrive_course_updated() {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $course->visible = 1;
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[8]));
    }
    /**
      * @test
      * Sanity check test
      * TO DO: refactor with better assertion
      *
      */
    public function googledrive_course_updated_hidden() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $course->visible = 0;
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[8]));
    }

    /**
      * @test
      * Sanity check test
      * TO DO: refactor with better assertion
      *
      */
    public function googledrive_role_assigned() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
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
    public function googledrive_role_unassigned() {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $sink = $this->redirectEvents();
        $users = $this->create_google_user_and_enrol($course);
        update_course($course);
        $events = $sink->get_events();

        $mockrepo = $this->get_googledrive_mock_repo();

        $mockrepo->manage_resources($events[8]);
        $coursecontext = context_course::instance($course->id);
        $this->create_resources($coursecontext->id);
        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
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
    public function googledrive_module_created() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $sink = $this->redirectEvents();
        $users = $this->create_google_user_and_enrol($course);

        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[8]));
    }

    /**
      * @test
      * Sanity check test
      * TO DO: refactor with better assertion
      *
      */
    public function googledrive_module_updated() {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');

        $sink = $this->redirectEvents();
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        $formdata = $DB->get_record('course_modules', array('id' => $drivemodule->cmid));
        $formdata->modulename = 'Update url name';
        $formdata->display = 0;
        $formdata->externalurl = $drivemodule->externalurl;
        $formdata->coursemodule = $drivemodule->cmid;
        $draftid_editor = 0;
        file_prepare_draft_area($draftid_editor, null, null, null, null);
        $formdata->introeditor = array('text' => 'This is a module', 'format' => FORMAT_HTML, 'itemid' => $draftid_editor);
        update_module($formdata);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[1]));
    }

    /**
      * @test
      * Sanity check test
      * TO DO: refactor with better assertion
      *
      */
    public function googledrive_module_deleted() {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $users = $this->create_google_user_and_enrol($course);
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');

        $sink = $this->redirectEvents();
        $params = array('course' => $course->id, 'name' => 'Another url');
        $drivemodule = $this->getDataGenerator()->create_module('url', $params);
        $modulecontext = context_module::instance($drivemodule->cmid);
        $this->create_resources($modulecontext->id);
        course_delete_module($drivemodule->cmid);
        $events = $sink->get_events();
        $sink->close();
        $mockrepo = $this->get_googledrive_mock_repo();
        $this->assertNotNull($mockrepo->manage_resources($events[1]));
    }
}
