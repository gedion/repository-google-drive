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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Behat\Context\Step\Given as Given;
use Behat\Gherkin\Node\TableNode as TableNode;
use Behat\Mink\Exception\SkippedException as SkippedException;


/**
 * Steps definitions to deal with the Google repository.
 *
 * @package    repository_googledrive
 * @category   test
 * @copyright  2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_googledrive extends behat_base {

    /**
     * Used to determine if we need to login to Google Drive again.
     * @var string
     */
    private static $googlerefreshtoken = null;

    /**
     * Enables the Google Drive repo.
     *
     * Make sure that repo clientid and secret is set in config.php.
     *
     * @Given /^Google Drive repository is enabled$/
     */
    public function google_drive_repository_is_enabled() {
        global $CFG;
        require_once($CFG->dirroot . '/repository/lib.php');

        $fromform = array();
        $fromform['clientid'] = get_config('googledrive', 'clientid');
        $fromform['secret'] = get_config('googledrive', 'secret');
        $fromform['pluginname'] = 'googledrive';
        if (empty($fromform['clientid']) || empty($fromform['secret'])) {
            debugging('Googledrive clientid/secret not set in config');
            throw new SkippedException;
        }

        $type = new repository_type('googledrive', $fromform, true);
        if (!$typeid = $type->create()) {
            debugging('Cannot create Googledrive repo');
            throw new SkippedException;
        }
    }

    /**
     * Create the gdoc files.
     *
     */
    public function create_test_files() {
        error_log("create_test_files");
        global $USER;
        $context = context_user::instance($USER->id);
        $repoinstances = repository::get_instances(array('currentcontext'=>$context, 'type'=>'googledrive'));
        $googlerepoinstance =  array_values($repoinstances)[0];
        $fileMetadata = new Google_Service_Drive_DriveFile(array(
          'title' => 'My Document',
          'mimeType' => 'application/vnd.google-apps.document'));
        $file = $googlerepoinstance->service->files->create($fileMetadata);
        error_log(json_encode($file));

    }

    /**
     * Save token.
     * @AfterScenario
     *
     */
    public function tear_down() {
       global $DB, $USER;
       $googlerefreshtokens = $DB->get_record('repository_gdrive_tokens', array ('userid'=>$USER->id));
       if($googlerefreshtokens && $googlerefreshtokens->refreshtokenid) {
           self::$googlerefreshtoken = $googlerefreshtokens->refreshtokenid;
       }
       //$this->create_test_files();
       return true;
    }

    /**
     * @Given /^I wait until allow button is available$/
     * @param int $sectionnumber
     * @return void
     */
    public function i_wait_until_allow_button_is_available() {

        $this->ensure_element_does_not_exist('.modal-dialog-buttons button[disabled]', 'css_element');
    }
    /**
     *
     * @Given /^I rename window name$/
     */
    public function I_rename_window_name() {
        $this->getSession()->evaluateScript('window.name="behat_repo_auth"');
    }
    /**
     * Connect to Google Drive.
     *
     * Make sure that Google Drive user/password is set in config.php.
     *
     * @Given /^I connect to Google Drive$/
     */
    public function i_connect_to_google_drive() {
        global $DB, $USER;

        $config = get_config('googledrive');
        if (empty($config->behatuser) || empty($config->behatpassword)) {
            debugging('Googledrive behat user/password not set in config');
            throw new SkippedException;
        }

        $login = new TableNode();
        $login->addRow(array('email', $config->behatuser));
        $password = new TableNode();
        $password->addRow(array('Passwd', $config->behatpassword));

        // If running other Behat tests in which user already logged into
        // Google Drive before, then bypass the login process
        if (self::$googlerefreshtoken) {
            $DB->insert_record('repository_gdrive_tokens', array( 'userid' => $USER->id, 'refreshtokenid' => self::$googlerefreshtoken));
        } else {
            // Go through entire login process.
            return array(
                new Given('I follow "Manage your Google account"'),
                new Given('I follow "Connect your Google account with Moodle"'),
                new Given('I switch to "repo_auth" window'),
                new Given('I rename window name'),
                new Given('I set the following fields to these values:', $login),
                new Given('I press "next"'),
                new Given('I set the following fields to these values:', $password),
                new Given('I press "Sign in"'),
                new Given('I wait until allow button is available'),
                new Given('I press "Allow"')
            );
        }
    }
}
