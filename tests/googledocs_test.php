<?php

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/course/tests/fixtures/format_theunittest.php');
require_once($CFG->dirroot . '/repository/googledrive/classes/observer.php');

class test_repository_googledrive extends advanced_testcase {

    const VARIABLE = 1;
/**
  *  +-----------------------------------------------+----------------------+
  *  | refreshtokenid                                | gmail                |
  *  +-----------------------------------------------+----------------------+
  *  | 1/o-Mck56v32V60Q3n_vYjyBVPFP9sbot78isjI4O6lTs | behattest2@gmail.com |
  *  | 1/TlIMN0hGHjmEzjsN7yafYtgbMi0tOB9kwznfTIW0Y84 | behattest3@gmail.com |
  *  | 1/WPBke6hgdnljQnxe7ixiDUp02M2Pkkwgf3EeElQVj0o | behattest4@gmail.com |
  *  | 1/dlLnDwW7RuQ_p45GyJJk4HMm1cZGKJ1HUGvU1lVW1YY | behattest5@gmail.com |
  *  +-----------------------------------------------+----------------------+
  */
    private function enable_google_drive_repository() {
        global $CFG, $DB;

        $type = $this->getDataGenerator()->create_repository_type('googledrive');
        $repo = $this->getDataGenerator()->create_repository('googledrive');
        set_config('clientid', '734778225255-olpah4nhjef22mc0j9st9gh2fq0p5eu3.apps.googleusercontent.com', 'googledrive');
        set_config('secret', 'xz5EJ6VIwG-CT4yQ4_FbYkuW', 'googledrive');

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
        $users = [];
        $user = $this->getDataGenerator()->create_user();
        $userdata = new stdClass();
        $userdata->refreshtokenid = '1/o-Mck56v32V60Q3n_vYjyBVPFP9sbot78isjI4O6lTs';
        $userdata->gmail = 'behattest2@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $USER = $user;
        $users [] = $user;
        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '1/TlIMN0hGHjmEzjsN7yafYtgbMi0tOB9kwznfTIW0Y84';
        $userdata->gmail = 'behattest3@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $users [] = $user;

        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '1/WPBke6hgdnljQnxe7ixiDUp02M2Pkkwgf3EeElQVj0o';
        $userdata->gmail = 'behattest4@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $users [] = $user;

        $user = $this->getDataGenerator()->create_user();
        $userdata->refreshtokenid = '1/dlLnDwW7RuQ_p45GyJJk4HMm1cZGKJ1HUGvU1lVW1YY';
        $userdata->gmail = 'behattest5@gmail.com';
        $userdata->userid = $user->id;
        $DB->insert_record('repository_gdrive_tokens', $userdata);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $users [] = $user;
        return $users;
    }

    public function create_resources($course) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type'=>'googledrive'));
        $repoid = $googledriverepo->id;
        $refrecord = new stdClass();
        $refrecord->repositoryid = $repoid;
        $refrecord->reference = '1ehL1CUPsYClYw6GRn9ZIT15qfA46JK8-499WEMM7d9M';
        $filesreferenceid = $DB->insert_record('files_reference', $refrecord);

        $coursecontext = context_course::instance($course->id);
        $filerecord = new stdClass();
        $filerecord->contextid = $coursecontext->id;
        $filerecord->component = 'mod_url';
        $filerecord->filearea = 'phpunit';
        $filerecord->contenthash = 'testf1';
        $filerecord->filepath = '/'; 
        $filerecord->filename = 'googledoctest.txt';
        $filerecord->itemid = 0; 
        $filerecord->filesize = 0; 
        $filerecord->referencefileid = $filesreferenceid;
        $filerecord->timecreated = time();
        $filerecord->timemodified= time();
        $DB->insert_record('files', $filerecord);
    }

    /**
      * @test
      *
      */
    public function googledrive_course_updated() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $course->visible = 1;
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $this->create_resources($course);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $this->asserttrue(repository_googledrive_observer::manage_resources($events[8]));
    }

    /**
      * @test
      *
      */
    public function googledrive_course_updated_hidden() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $course->visible = 0;
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $this->create_resources($course);
        update_course($course);
        $events = $sink->get_events();
        $sink->close();
        $this->asserttrue(repository_googledrive_observer::manage_resources($events[8]));
    }

    /**
      * @test
      *
      */
    public function googledrive_role_assigned() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $repo_instance = $this->enable_google_drive_repository();
        $sink = $this->redirectEvents();
        $this->create_google_user_and_enrol($course);
        $this->create_resources($course);
        $events = $sink->get_events();
        $sink->close();
        $this->asserttrue(repository_googledrive_observer::manage_resources($events[7]));
    }

    /**
      * @test
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

        repository_googledrive_observer::manage_resources($events[8]);
        $this->create_resources($course);
        $studentrole = $DB->get_record('role', array('shortname'=>'student'));
        role_unassign($studentrole->id, $users[3]->id, context_course::instance($course->id)->id);
        $events = $sink->get_events();
        $sink->close();
        $this->asserttrue(repository_googledrive_observer::manage_resources($events[9]));
    }

    /**
      * @test
      *
      */
    public function googledrive_module_created() {
        global $DB;
        $this->asserttrue(false);
    }

    /**
      * @test
      *
      */
    public function googledrive_module_updated() {
        global $DB;
        $this->asserttrue(false);
    }

    /**
      * @test
      *
      */
    public function googledrive_module_deleted() {
        global $DB;
        $this->asserttrue(false);
    }
}
