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
 * Form class for preference.php
 *
 * @package    repository
 * @subpackage googledrive
 * @copyright  2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Geidion Woldeselassie <gwy321@gmail.com>
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/repository/googledrive/lib.php');

/*
 * URL of Google drive.
 */
define('GOOGLE_DRIVE_URL', 'https://drive.google.com');

/** Form to edit repository google drive initial details.
 *
 */
class edit_repository_googledrive_form extends moodleform {

    /**
     * Defines the form
     */
    public function definition() {

        $mform = $this->_form;

        $mform->addElement('html', html_writer::tag('span', '', array('class' => 'notconnected', 'id' => 'connection-error')));
        $mform->addElement('header', 'googledriveheader', get_string('driveconnection', 'repository_googledrive'));
        $mform->addHelpButton('googledriveheader', 'googledriveconnection', 'repository_googledrive');
        $mform->addElement('static', 'url', get_string('url'), GOOGLE_DRIVE_URL);

        list($redirecturl, $status, $email) = $this->get_redirect_url_and_connection_status();
        if ($status == 'connected') {
            $statuselement = html_writer::tag('span', get_string('connected', 'repository_googledrive'),
                array('class' => 'connected', 'id' => 'connection-status'));
        } else {
            $statuselement = html_writer::tag('span', get_string('notconnected', 'repository_googledrive'),
                array('class' => 'notconnected', 'id' => 'connection-status'));
        }
        $mform->addElement('static', 'status', get_string('status'), $statuselement);
        if ($email) {
            $mform->addElement('static', 'email', get_string('email'), $email);
            $mform->addHelpButton('email', 'googleemail', 'repository_googledrive');
        }
        $mform->addElement('static', 'googledrive', '', $redirecturl);
        if ($status != 'connected') {
            $mform->addHelpButton('googledrive', 'googledriveconnection', 'repository_googledrive');
        }
    }

    /**
     * Returns google redirect url(which can be either
     * a login to google url or a revoke token url) and
     * a login status
     */
    private function get_redirect_url_and_connection_status() {
        global $DB, $USER;

        $context = context_user::instance($USER->id);
        $repoinstances = repository::get_instances(array('currentcontext' => $context, 'type' => 'googledrive'));
        if (count($repoinstances) == 0) {
            throw new repository_exception('servicenotenabled', 'repository_googledrive');
        }
        $googlerepoinstance = array_values($repoinstances)[0];
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'));
        $googlerefreshtoken = $DB->get_record('repository_gdrive_tokens', array ('userid' => $USER->id));
        $repooptions = array(
            'ajax' => false,
            'mimetypes' => array('.mp3')
        );

        $repo = new repository_googledrive($googlerepoinstance->id, $context, $repooptions);
        $code = optional_param('oauth2code', null, PARAM_RAW);
        if (!$googlerefreshtoken || (is_null($googlerefreshtoken->refreshtokenid) && empty($code))) {
            $redirecturl = $repo->get_login_url();
            $email = null;
            $status = "notconnected";
        } else {
            if ($code) {
                $repo->callback();
            }
            $status = "connected";
            $redirecturl = $repo->get_revoke_url();
            $email = $repo->get_user_info()->email;
        }
        return array($redirecturl, $status, $email);
    }
}
