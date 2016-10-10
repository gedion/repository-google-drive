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
 * Strings for component 'repository_googledrive', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   repository_googledrive
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['clientid'] = 'Client ID';
$string['configplugin'] = 'Configure Google Drive plugin';
$string['googledrive:view'] = 'View Google Drive repository';
$string['oauthinfo'] = '<p>To use this plugin, you must register your site with Google, as described in the documentation <a href="{$a->driveurl}">Google OAuth 2.0 setup</a>.</p><p>As part of the registration process, you will need to enter the following URL as \'Authorized Redirect URIs\':</p><p>{$a->callbackurl}</p><p>Once registered, you will be provided with a client ID and secret which can be used to configure all Google Drive and Picasa plugins.</p><p>Please also note that you will have to enable the service \'Drive API\'.</p>';
$string['oauth2upgrade_message_subject'] = 'Important information regarding Google Drive repository plugin';
$string['oauth2upgrade_message_content'] = 'As part of the upgrade to Moodle 2.3, the Google Drive portfolio plugin has been disabled. To re-enable it, your Moodle site needs to be registered with Google, as described in the documentation {$a->driveurl}, in order to obtain a client ID and secret. The client ID and secret can then be used to configure all Google Drive and Picasa plugins.';
$string['oauth2upgrade_message_small'] = 'This plugin has been disabled, as it requires configuration as described in the documentation Google OAuth 2.0 setup.';
$string['pluginname'] = 'Google Drive Extended';
$string['secret'] = 'Secret';
$string['driveconnection'] = 'Google Drive connection';
$string['servicenotenabled'] = 'Access not configured. Make sure the service \'Drive API\' is enabled';
$string['syncyourgoogleaccount'] = 'Manage your Google account';
$string['connectyourgoogleaccount'] = 'Connect your Google account with Moodle';
$string['revokeyourgoogleaccount'] = 'Disconnect your Google account from Moodle';
$string['googledrivedetails'] = 'Google Drive settings';
$string['connected'] = 'Connected';
$string['notconnected'] = 'Not connected';
$string['googledriveconnection'] = 'Sign in with your email';
$string['googledriveconnection_help'] = 'Once you authorize us to see your Google Drive you\'ll be able to submit your assignments directly from Google Drive, and create and share documents with members of your classes.';
$string['googleemail'] = 'Email address';
$string['googleemail_help'] = 'The email address associated with your Google account.';

