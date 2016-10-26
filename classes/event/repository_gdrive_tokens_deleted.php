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
 * Google refresh token deleted event.
 *
 * @package    repository_googledrive
 * @copyright  2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace repository_googledrive\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Google refresh token deleted event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - string refreshtokenid: id of refresh token.
 *      - string gmail: user gmail.
 * }
 *
 * @package    repository_googledrive
 * @since      Moodle 3.1
 * @copyright  2016
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_gdrive_tokens_deleted extends \core\event\base {

    /**
     * Initialise required event data properties.
     */
    protected function init() {
        $this->data['objecttable'] = 'repository_gdrive_tokens';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtokendeleted', 'repository_googledrive');
    }

    /**
     * Returns non-localised event description with id's for admin use only.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' deleted the Google refresh token with id '$this->objectid'.";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            debugging('The \'relateduserid\' value must be specified in the event.', DEBUG_DEVELOPER);
            $this->relateduserid = $this->objectid;
        }

        //if (!isset($this->other['refreshtokenid'])) {
        //    throw new \coding_exception('The \'refreshtokenid\' value must be set in other.');
        //}

        //if (!isset($this->other['gmail'])) {
        //    throw new \coding_exception('The \'gmail\' value must be set in other.');
        //}
    }

    /**
     * Create instance of event.
     *
     * @since Moodle 2.6.4, 2.7.1
     *
     * @param int $userid id of user
     * @return user_created
     */
    public static function create_from_userid($userid) {
        $data = array(
            'objectid' => $userid,
            'relateduserid' => $userid,
            'context' => \context_user::instance($userid)
        );
    
        // Create user_created event.
        $event = self::create($data);
        return $event;
    }
}