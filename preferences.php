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
 * User Google Drive settings page.
 *
 * @package    repository
 * @subpackage googledrive
 * @copyright  2012
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Gedion Woldeselassie <gedion@umn.edu>
 */
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/repository/googledrive/preferences_form.php');

require_login();

$context = context_user::instance($USER->id);

$PAGE->set_url(new moodle_url('/repository/googledrive/preference.php'));
$PAGE->set_context($context);

$title = get_string('googledrivedetails', 'repository_googledrive');
$PAGE->set_title($title);
$PAGE->set_heading(fullname($USER));
$PAGE->set_pagelayout('mydashboard');

$form = new edit_repository_googledrive_form();

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
