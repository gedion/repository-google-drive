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

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/google/lib.php');

/**
 * Google Drive Plugin
 *
 * @since Moodle 2.0
 * @package    repository_googledrive
 * @copyright  2009 Dan Poltawski <talktodan@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_googledrive extends repository {

    /**
     * Google Client.
     * @var Google_Client
     */
    private $client = null;

    /**
     * Google Drive Service.
     * @var Google_Drive_Service
     */
    private $service = null;

    /**
     * Session key to store the accesstoken.
     * @var string
     */
    const SESSIONKEY = 'googledrive_accesstoken';

    /**
     * URI to the callback file for OAuth.
     * @var string
     */
    const CALLBACKURL = '/admin/oauth2callback.php';

    private static $googlelivedrivetypes = array('document', 'presentation', 'spreadsheet');
    /**
     * Constructor.
     *
     * @param int $repositoryid repository instance id.
     * @param int|stdClass $context a context id or context object.
     * @param array $options repository options.
     * @param int $readonly indicate this repo is readonly or not.
     * @return void
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array(), $readonly = 0) {
        parent::__construct($repositoryid, $context, $options, $readonly = 0);

        $callbackurl = new moodle_url(self::CALLBACKURL);
        $this->client = get_google_client();
        $this->client->setAccessType("offline");
        $this->client->setClientId(get_config('googledrive', 'clientid'));
        $this->client->setClientSecret(get_config('googledrive', 'secret'));
        $this->client->setScopes(array(Google_Service_Drive::DRIVE_FILE, Google_Service_Drive::DRIVE, 'email'));
        $this->client->setRedirectUri($callbackurl->out(false));
        $this->service = new Google_Service_Drive($this->client);

        $this->check_login();
    }

    /**
     * Returns the access token if any.
     *
     * @return string|null access token.
     */
    protected function get_access_token() {
        global $SESSION;
        if (isset($SESSION->{self::SESSIONKEY})) {
            return $SESSION->{self::SESSIONKEY};
        }
        return null;
    }

    /**
     * Store the access token in the session.
     *
     * @param string $token token to store.
     * @return void
     */
    protected function store_access_token($token) {
        global $SESSION;
        $SESSION->{self::SESSIONKEY} = $token;
    }

    /**
     * Callback method during authentication.
     *
     * @return void
     */
    public function callback() {
        if ($code = optional_param('oauth2code', null, PARAM_RAW)) {
            $this->client->authenticate($code);
            $this->store_access_token($this->client->getAccessToken());
            $this->save_refresh_token();
        } else if ($revoke = optional_param('revoke', null, PARAM_RAW)) {
            $this->revoke_token();
        }
        if (optional_param('reloadparentpage', null, PARAM_RAW)) {
            $url = new moodle_url('/repository/googledrive/callback.php');
            redirect($url);
        }
    }

    /**
     * Checks whether the user is authenticated or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        global $USER, $DB;
        $googlerefreshtokens = $DB->get_record('repository_gdrive_tokens', array ('userid' => $USER->id));

        if ($googlerefreshtokens && !is_null($googlerefreshtokens->refreshtokenid)) {
            try {
                $this->client->refreshToken($googlerefreshtokens->refreshtokenid);
            } catch (Exception $e) {
                $this->revoke_token();
            }
            $token = $this->client->getAccessToken();
            $this->store_access_token($token);
            return true;
        }
        return false;
    }

    /**
     * Return the revoke form.
     *
     */
    public function get_revoke_url() {

        $url = new moodle_url('/repository/repository_callback.php');
        $url->param('callback', 'yes');
        $url->param('repo_id', $this->id);
        $url->param('revoke', 'yes');
        $url->param('reloadparentpage', true);
        $url->param('sesskey', sesskey());
        return '<a target="_blank" href="'.$url->out(false).'">'.get_string('revokeyourgoogleaccount', 'repository_googledrive').'</a>';
    }

    /**
     * Return the login form.
     *
     * @return void|array for ajax.
     */
    public function get_login_url() {

        $returnurl = new moodle_url('/repository/repository_callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('repo_id', $this->id);
        $returnurl->param('sesskey', sesskey());
        $returnurl->param('reloadparentpage', true);

        $url = new moodle_url($this->client->createAuthUrl());
        $url->param('state', $returnurl->out_as_local_url(false));
        return '<a target="repo_auth" href="'.$url->out(false).'">'.get_string('connectyourgoogleaccount', 'repository_googledrive').'</a>';
    }

    /**
     * Print or return the login form.
     *
     * @return void|array for ajax.
     */
    public function print_login() {
        $returnurl = new moodle_url('/repository/repository_callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('repo_id', $this->id);
        $returnurl->param('sesskey', sesskey());

        $url = new moodle_url($this->client->createAuthUrl());
        $url->param('state', $returnurl->out_as_local_url(false));
        if ($this->options['ajax']) {
            $popup = new stdClass();
            $popup->type = 'popup';
            $popup->url = $url->out(false);
            return array('login' => array($popup));
        } else {
            echo '<a target="_blank" href="'.$url->out(false).'">'.get_string('login', 'repository').'</a>';
        }
    }

    /**
     * Build the breadcrumb from a path.
     *
     * @param string $path to create a breadcrumb from.
     * @return array containing name and path of each crumb.
     */
    protected function build_breadcrumb($path) {
        $bread = explode('/', $path);
        $crumbtrail = '';
        foreach ($bread as $crumb) {
            list($id, $name) = $this->explode_node_path($crumb);
            $name = empty($name) ? $id : $name;
            $breadcrumb[] = array(
                'name' => $name,
                'path' => $this->build_node_path($id, $name, $crumbtrail)
            );
            $tmp = end($breadcrumb);
            $crumbtrail = $tmp['path'];
        }
        return $breadcrumb;
    }

    /**
     * Generates a safe path to a node.
     *
     * Typically, a node will be id|Name of the node.
     *
     * @param string $id of the node.
     * @param string $name of the node, will be URL encoded.
     * @param string $root to append the node on, must be a result of this function.
     * @return string path to the node.
     */
    protected function build_node_path($id, $name = '', $root = '') {
        $path = $id;
        if (!empty($name)) {
            $path .= '|' . urlencode($name);
        }
        if (!empty($root)) {
            $path = trim($root, '/') . '/' . $path;
        }
        return $path;
    }

    /**
     * Returns information about a node in a path.
     *
     * @see self::build_node_path()
     * @param string $node to extrat information from.
     * @return array about the node.
     */
    protected function explode_node_path($node) {
        if (strpos($node, '|') !== false) {
            list($id, $name) = explode('|', $node, 2);
            $name = urldecode($name);
        } else {
            $id = $node;
            $name = '';
        }
        $id = urldecode($id);
        return array(
            0 => $id,
            1 => $name,
            'id' => $id,
            'name' => $name
        );
    }


    /**
     * List the files and folders.
     *
     * @param  string $path path to browse.
     * @param  string $page page to browse.
     * @return array of result.
     */
    public function get_listing($path='', $page = '') {
        if (empty($path)) {
            $path = $this->build_node_path('root', get_string('pluginname', 'repository_googledrive'));
        }

        // We analyse the path to extract what to browse.
        $trail = explode('/', $path);
        $uri = array_pop($trail);
        list($id, $name) = $this->explode_node_path($uri);

        // Handle the special keyword 'search', which we defined in self::search() so that
        // we could set up a breadcrumb in the search results. In any other case ID would be
        // 'root' which is a special keyword set up by Google, or a parent (folder) ID.
        if ($id === 'search') {
            return $this->search($name);
        }

        // Query the Drive.
        $q = "'" . str_replace("'", "\'", $id) . "' in parents";
        $q .= ' AND trashed = false';
        $results = $this->query($q, $path);

        $ret = array();
        $ret['dynload'] = true;
        $ret['path'] = $this->build_breadcrumb($path);
        $ret['list'] = $results;
        return $ret;
    }

    /**
     * Search throughout the Google Drive.
     *
     * @param string $searchtext text to search for.
     * @param int $page search page.
     * @return array of results.
     */
    public function search($searchtext, $page = 0) {
        $path = $this->build_node_path('root', get_string('pluginname', 'repository_googledrive'));
        $path = $this->build_node_path('search', $searchtext, $path);

        // Query the Drive.
        $q = "fullText contains '" . str_replace("'", "\'", $searchtext) . "'";
        $q .= ' AND trashed = false';
        $results = $this->query($q, $path);

        $ret = array();
        $ret['dynload'] = true;
        $ret['path'] = $this->build_breadcrumb($path);
        $ret['list'] = $results;
        return $ret;
    }

    /**
     * Query Google Drive for files and folders using a search query.
     *
     * Documentation about the query format can be found here:
     *   https://developers.google.com/drive/search-parameters
     *
     * This returns a list of files and folders with their details as they should be
     * formatted and returned by functions such as get_listing() or search().
     *
     * @param string $q search query as expected by the Google API.
     * @param string $path parent path of the current files, will not be used for the query.
     * @param int $page page.
     * @return array of files and folders.
     */
    protected function query($q, $path = null, $page = 0) {
        global $OUTPUT;

        $files = array();
        $folders = array();
        $fields = "items(id,title,mimeType,downloadUrl,fileExtension,exportLinks,modifiedDate,fileSize,thumbnailLink,alternateLink)";
        $params = array('q' => $q, 'fields' => $fields);

        try {
            // Retrieving files and folders.
            $response = $this->service->files->listFiles($params);
        } catch (Google_Service_Exception $e) {
            if ($e->getCode() == 403 && strpos($e->getMessage(), 'Access Not Configured') !== false) {
                // This is raised when the service Drive API has not been enabled on Google APIs control panel.
                throw new repository_exception('servicenotenabled', 'repository_googledrive');
            } else {
                throw $e;
            }
        }

        $items = isset($response['items']) ? $response['items'] : array();
        foreach ($items as $item) {
            if ($item['mimeType'] == 'application/vnd.google-apps.folder') {
                // This is a folder.
                $folders[$item['title'] . $item['id']] = array(
                    'title' => $item['title'],
                    'path' => $this->build_node_path($item['id'], $item['title'], $path),
                    'date' => strtotime($item['modifiedDate']),
                    'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    'children' => array()
                );
            } else {
                // This is a file.
                if (isset($item['fileExtension'])) {
                    // The file has an extension, therefore there is a download link.
                    $title = $item['title'];
                    $source = $item['downloadurl'];
                } else {
                    // The file is probably a Google Doc file, we get the corresponding export link.
                    // This should be improved by allowing the user to select the type of export they'd like.
                    $type = str_replace('application/vnd.google-apps.', '', $item['mimeType']);
                    $title = '';
                    $exporttype = '';
                    switch ($type){
                        case 'document':
                            $title = $item['title'] . '.rtf';
                            $exporttype = 'application/rtf';
                            break;
                        case 'presentation':
                            $title = $item['title'] . '.pptx';
                            $exporttype = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                            break;
                        case 'spreadsheet':
                            $title = $item['title'] . '.csv';
                            $exporttype = 'text/csv';
                            break;
                    }
                    // Skips invalid/unknown types.
                    if (empty($title) || !isset($item['exportLinks'][$exporttype])) {
                        continue;
                    }
                    $source = $item['exportLinks'][$exporttype];
                }
                // Adds the file to the file list. Using the itemId along with the title as key
                // of the array because Google Drive allows files with identical names.
                $files[$title . $item['id']] = array(
                    'title' => $title,
                    'source' => $item['id'],
                    'date' => strtotime($item['modifiedDate']),
                    'size' => isset($item['fileSize']) ? $item['fileSize'] : null,
                    'thumbnail' => $OUTPUT->pix_url(file_extension_icon($title, 64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    // Do not use real thumbnails as they wouldn't work if the user disabled 3rd party
                    // plugins in his browser, or if they're not logged in their Google account.
                );

                // Sometimes the real thumbnails can't be displayed, for example if 3rd party cookies are disabled
                // or if the user is not logged in Google anymore. But this restriction does not seem to be applied
                // to a small subset of files.
                $extension = strtolower(pathinfo($title, PATHINFO_EXTENSION));
                if (isset($item['thumbnailLink']) && in_array($extension, array('jpg', 'png', 'txt', 'pdf'))) {
                    $files[$title . $item['id']]['realthumbnail'] = $item['thumbnailLink'];
                }
            }
        }

        // Filter and order the results.
        $files = array_filter($files, array($this, 'filter'));
        core_collator::ksort($files, core_collator::SORT_NATURAL);
        core_collator::ksort($folders, core_collator::SORT_NATURAL);
        return array_merge(array_values($folders), array_values($files));
    }

    /**
     * Logout.
     *
     * @return string
     */
    public function logout() {
        $this->store_access_token(null);
        return parent::logout();
    }

    /**
     * Get a file.
     *
     * @param string $reference reference of the file.
     * @param string $file name to save the file to.
     * @return string JSON encoded array of information about the file.
     */
    public function get_file($source, $filename = '') {
        global $USER, $CFG;
        $url = $this->get_doc_url_by_doc_id($source, $downloadurl = true);
        $auth = $this->client->getAuth();
        $request = $auth->authenticatedRequest(new Google_Http_Request($url));
        if ($request->getResponseHttpCode() == 200) {
            $path = $this->prepare_file($filename);
            $content = $request->getResponseBody();
            if (file_put_contents($path, $content) !== false) {
                @chmod($path, $CFG->filepermissions);
                return array(
                    'path' => $path,
                    'url' => $url
                );
            }
        }
        throw new repository_exception('cannotdownload', 'repository');
    }

    /**
     * Return external link.
     *
     * @param string $ref of the file.
     * @return string document url.
     */
    public function get_link($ref) {
        return $this->service->files->get($ref)->alternateLink;
    }

    /**
     * What kind of files will be in this repository?
     *
     * @return array return '*' means this repository support any files, otherwise
     *               return mimetypes of files, it can be an array
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     * Tells how the file can be picked from this repository.
     *
     * Maximum value is FILE_INTERNAL | FILE_EXTERNAL | FILE_REFERENCE.
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Repository method to serve the referenced file
     *
     * @param stored_file $storedfile the file that contains the reference
     * @param int $lifetime Number of seconds before the file should expire from caches (null means $CFG->filelifetime)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime=null , $filter=0, $forcedownload=false, array $options = null) {
        $id = $storedfile->get_reference();
        $token = json_decode($this->get_access_token());
        header('Authorization: Bearer ' . $token->access_token);
        if ($forcedownload) {
            $downloadurl = true;
            $url = $this->get_doc_url_by_doc_id($id, $downloadurl);
            header('Location: ' . $url);
            die;
        } else {
            $file = $this->service->files->get($id);
            $type = str_replace('application/vnd.google-apps.', '', $file['mimeType']);
            if (in_array($type, self::$googlelivedrivetypes)) {
                redirect($file->alternateLink);
            } else {
                header("Location: " . $file->downloadurl . '&access_token='. $token->access_token);
                die;
            }
        }
    }

    private function get_doc_url_by_doc_id($id, $downloadurl=false) {
        $file = $this->service->files->get($id);
        if (isset($file['fileExtension'])) {
            if ($downloadurl) {
                $token = json_decode($this->get_access_token());
                return $file['downloadurl']. '&access_token='. $token->access_token;
            } else {
                return $file['webContentLink'];
            }
        } else {
            // The file is probably a Google Doc file, we get the corresponding export link.
            // This should be improved by allowing the user to select the type of export they'd like.
            $type = str_replace('application/vnd.google-apps.', '', $file['mimeType']);
            $exporttype = '';
            switch ($type){
                case 'document':
                    $exporttype = 'application/rtf';
                    break;
                case 'presentation':
                    $exporttype = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    break;
                case 'spreadsheet':
                    $exporttype = 'text/csv';
                    break;
            }
            // Skips invalid/unknown types.
            if (!isset($file['exportLinks'][$exporttype])) {
                throw new repository_exception('repositoryerror', 'repository', '', 'Uknown file type');
            }
            return $file['exportLinks'][$exporttype];
        }
    }
    /**
     * Return names of the general options.
     * By default: no general option name.
     *
     * @return array
     */
    public static function get_type_option_names() {
        return array('clientid', 'secret', 'pluginname');
    }

    /**
     * Edit/Create Admin Settings Moodle form.
     *
     * @param moodleform $mform Moodle form (passed by reference).
     * @param string $classname repository class name.
     */
    public static function type_config_form($mform, $classname = 'repository') {

        $callbackurl = new moodle_url(self::CALLBACKURL);

        $a = new stdClass;
        $a->driveurl = get_docs_url('Google_OAuth_2.0_setup');
        $a->callbackurl = $callbackurl->out(false);

        $mform->addElement('static', null, '', get_string('oauthinfo', 'repository_googledrive', $a));

        parent::type_config_form($mform);
        $mform->addElement('text', 'clientid', get_string('clientid', 'repository_googledrive'));
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'secret', get_string('secret', 'repository_googledrive'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);

        $strrequired = get_string('required');
        $mform->addRule('clientid', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

    /**
     * Accessor to native revokeToken method
     *
     */
    private function revoke_token() {
        $this->delete_refresh_token();
        $this->client->revokeToken();
        $this->store_access_token(null);
    }

    /**
     *
     * @return stdclass user info.
     */
    public function get_user_info() {
        $serviceoauth2 = new Google_Service_Oauth2($this->client);
        return $serviceoauth2->userinfo_v2_me->get();
    }

    /**
     * Removes the refresh token from database.
     *
     */
    private function delete_refresh_token() {
        global $DB, $USER;
        $grt = $DB->get_record('repository_gdrive_tokens', array('userid' => $USER->id));
        $event = \repository_googledrive\event\repository_gdrive_tokens_deleted::create_from_userid($USER->id);
        $event->add_record_snapshot('repository_gdrive_tokens', $grt);
        $event->trigger();
        $DB->delete_records('repository_gdrive_tokens', array ('userid' => $USER->id));
    }

    /**
     * Saves the refresh token to database.
     *
     */
    private function save_refresh_token() {
        global $DB, $USER;

        $newdata = new stdClass();
        $newdata->refreshtokenid = $this->client->getRefreshToken();
        $newdata->gmail = $this->get_user_info()->email;

        if (!is_null($newdata->refreshtokenid) && !is_null($newdata->gmail)) {
            $rectoken = $DB->get_record('repository_gdrive_tokens', array ('userid' => $USER->id));
            if ($rectoken) {
                $newdata->id = $rectoken->id;
                if ($newdata->gmail === $rectoken->gmail) {
                    unset($newdata->gmail);
                }
                $DB->update_record('repository_gdrive_tokens', $newdata);
            } else {
                $newdata->userid = $USER->id;
                $newdata->gmail_active = 1;
                $DB->insert_record('repository_gdrive_tokens', $newdata);
            }
        }

        $event = \repository_googledrive\event\repository_gdrive_tokens_created::create_from_userid($USER->id);
        $event->trigger();
    }

    /**
     * Sync google resource permissions based on various events.
     *
     * @param \core\event\* $event The event fired.
     */
    public function manage_resources($event) {
        global $DB;
        switch($event->eventname) {
            case '\core\event\course_category_updated':
                break;
            case '\core\event\course_updated':
                break;
            case '\core\event\course_restored':
                break;
            case '\core\event\course_content_deleted':
                break;
            case '\core\event\course_section_updated':
                break;
            case '\core\event\course_module_created':
                $this->course_module_created($event);
                break;
            case '\core\event\course_module_updated':
                $this->course_module_updated($event);
                break;
            case '\core\event\course_module_deleted':
                $this->course_module_deleted($event);
                break;
            case '\core\event\role_assigned':
                break;
            case '\core\event\role_unassigned':
                break;
            case '\core\event\role_capabilities_updated':
                break;
            case '\core\event\group_member_added':
            case '\core\event\group_member_removed':
                break;
            case '\core\event\grouping_group_assigned':
            case '\core\event\grouping_group_unassigned':
                break;
            case '\core\event\user_enrolment_created':
            case '\core\event\user_enrolment_updated':
                break;
            case '\core\event\user_enrolment_deleted': 
                break;
            case '\core\event\user_deleted':
                break;
            case '\repository_googledrive\event\repository_gdrive_tokens_created':
                break;
            case '\repository_googledrive\event\repository_gdrive_tokens_deleted':
                break;
            default:
                return false;
        }
            return true;
    }

    private function course_module_created($event) {
        global $DB;
        // Deal with file permissions.
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);
        
        $cmid = $event->contextinstanceid;
        $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
        $cmcontext = context_module::instance($cmid);
        
        $userids = $this->get_google_authenticated_userids($courseid);
        $fileids = $this->get_fileids($cmid);
        $insertcalls = array();
        
        foreach ($fileids as $fileid) {
            foreach ($userids as $userid) {
                $gmail = $this->get_google_authenticated_users_email($userid);
                if ($this->edit_capability($cmcontext, $userid)) {
                    $call = new stdClass();
                    $call->fileid = $fileid;
                    $call->gmail = $gmail;
                    $call->role = 'writer';
                    $insertcalls[] = $call;
                    unset($call);
                    if (count($insertcalls) == 1000) {
                        $this->batch_insert_permissions($insertcalls);
                        unset($insertcalls);
                        $insertcalls = array();
                    }
                } else {
                    if ($course->visible == 1) {
                        // Course is visible, continue checks
                        if ($cm->visible == 1) {
                            // Course module is visible, continue checks
                            rebuild_course_cache($courseid, true);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);
        
                            // User can view course module, section, is enrolled in course, and cannot edit module
                            if ($cminfo->uservisible && $secinfo->uservisible && is_enrolled($coursecontext, $userid, '', true) && !$this->edit_capability($cmcontext, $userid)) {
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                unset($call);
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    unset($insertcalls);
                                    $insertcalls = array();
                                }
                            }
                            // else user cannot access course module, do nothing
                        }
                        // else course module not visible, do nothing
                    }
                    // else course not visible, do nothing
                }
            }
        
            // Store cmid and reference(s).
            $newdata = new stdClass();
            $newdata->courseid = $courseid;
            $newdata->cmid = $cmid;
            $newdata->reference = $fileid;
            $DB->insert_record('repository_gdrive_references', $newdata);
            unset($newdata);
        }
        
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
    }
    
    private function course_module_updated($event) {
        global $DB;
        // Deal with file permissions.
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);
        
        $cmid = $event->contextinstanceid;
        $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
        $cmcontext = context_module::instance($cmid);
        
        $userids = $this->get_google_authenticated_userids($courseid);
        
        // Get current file ids for module.
        $fileids = $this->get_fileids($cmid);
        
        $insertcalls = array();
        $deletecalls = array();
        
        // Check and ajust permissions for current file ids for module.
        foreach ($fileids as $fileid) {
            foreach ($userids as $userid) {
                $gmail = $this->get_google_authenticated_users_email($userid);
                if ($this->edit_capability($cmcontext, $userid)) {
                    $call = new stdClass();
                    $call->fileid = $fileid;
                    $call->gmail = $gmail;
                    $call->role = 'writer';
                    $insertcalls[] = $call;
                    unset($call);
                    if (count($insertcalls) == 1000) {
                        $this->batch_insert_permissions($insertcalls);
                        unset($insertcalls);
                        $insertcalls = array();
                    }
                } else {
                    if ($course->visible == 1) {
                        // Course is visible, continue checks
                        if ($cm->visible == 1) {
                            // Course module is visible, continue checks
                            rebuild_course_cache($courseid, true);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);
        
                            // User can view course module, section, is enrolled in course, and cannot edit module
                            if ($cminfo->uservisible && $secinfo->uservisible && is_enrolled($coursecontext, $userid, '', true) && !$this->edit_capability($cmcontext, $userid)) {
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                unset($call);
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    unset($insertcalls);
                                    $insertcalls = array();
                                }
                            } else {
                                // User cannot view course module, or section, or is not enrolled in course
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $deletecalls[] = $call;
                                unset($call);
                                if (count($deletecalls) == 1000) {
                                    $this->batch_delete_permissions($deletecalls);
                                    unset($deletecalls);
                                    $deletecalls = array();
                                }
                            }
                        } else {
                            // Course module is not visible
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $gmail;
                            $deletecalls[] = $call;
                            unset($call);
                            if (count($deletecalls) == 1000) {
                                $this->batch_delete_permissions($deletecalls);
                                unset($deletecalls);
                                $deletecalls = array();
                            }
                        }
                    } else {
                        // Course is not visible
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $gmail;
                        $deletecalls[] = $call;
                        unset($call);
                        if (count($deletecalls) == 1000) {
                            $this->batch_delete_permissions($deletecalls);
                            unset($deletecalls);
                            $deletecalls = array();
                        }
                    }
                }
            }
        }
        
        // Get previous file ids for module.
        $prevfilerecs = $DB->get_records('repository_gdrive_references', array('cmid' => $cmid), '', 'reference');
        $prevfileids = array();
        foreach ($prevfilerecs as $prevfilerec) {
            $prevfileids[] = $prevfilerec->reference;
        }
        
        // Get file ids that were added and deleted from module.
        $addfileids = array_diff($fileids, $prevfileids);
        $delfileids = array_diff($prevfileids, $fileids);
        
        // Delete permissions for removed file ids.
        foreach ($delfileids as $delfileid) {
            foreach ($userids as $userid) {
                $gmail = $this->get_google_authenticated_users_email($userid);
                $call = new stdClass();
                $call->fileid = $delfileid;
                $call->gmail = $gmail;
                $deletecalls[] = $call;
                unset($call);
                if (count($deletecalls) == 1000) {
                    $this->batch_delete_permissions($deletecalls);
                    unset($deletecalls);
                    $deletecalls = array();
                }
            }
            $DB->delete_records_select('repository_gdrive_references', 'cmid = :cmid AND reference = :reference', array('cmid' => $cmid, 'reference' => $delfileid));
        }
        
        // Call any remainting batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
        
        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
        
        // Add new file ids to database.
        $newdata = new stdClass();
        $newdata->courseid = $courseid;
        $newdata->cmid = $cmid;
        
        foreach ($addfileids as $addfileid) {
            $newdata->reference = $addfileid;
            $DB->insert_record('repository_gdrive_references', $newdata);
            unset($newdata);
        }
        
        // Delete removed file ids from database.

    }
    
    private function course_module_deleted($event) {
        global $DB;
        $courseid = $event->courseid;
        $cmid = $event->contextinstanceid;
        $userids = $this->get_google_authenticated_userids($courseid);
        $filerecs = $DB->get_records('repository_gdrive_references', array('cmid' => $cmid), '', 'reference');
        $deletecalls = array();
        foreach ($filerecs as $filerec) {
            foreach ($userids as $userid) {
                $gmail = $this->get_google_authenticated_users_email($userid);
                $call = new stdClass();
                $call->fileid = $filerec->reference;
                $call->gmail = $gmail;
                $deletecalls[] = $call;
        
                if (count($deletecalls) == 1000) {
                    $this->batch_delete_permissions($deletecalls);
                    unset($deletecalls);
                    $deletecalls = array();
                }
            }
        }
        
        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
        
        $DB->delete_records('repository_gdrive_references', array('cmid' => $cmid));
    }
    
    /**
     * Get userids for users in specified course.
     *
     * @param courseid $courseid
     * @return array of userids
     */
    private function get_google_authenticated_userids($courseid) {
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
     * Get the gmail address for a specified user.
     *
     * @param user id $userid
     * @return mixed gmail address if record exists, false if not
     */
    private function get_google_authenticated_users_email($userid) {
        global $DB;
        $googlerefreshtoken = $DB->get_record('repository_gdrive_tokens', array ('userid' => $userid), 'gmail');
        if ($googlerefreshtoken) {
            return $googlerefreshtoken->gmail;
        } else {
            return false;
        }
    }

    /**
     * Get the section number for a course module.
     *
     * @param course module id $cmid
     * @return section number
     */
    private function get_cm_sectionnum($cmid) {
        global $DB;
        $sql = "SELECT cs.section
                FROM {course_sections} cs
                LEFT JOIN {course_modules} cm
                ON cm.section = cs.id
                WHERE cm.id = :cmid";
        $section = $DB->get_record_sql($sql, array('cmid' => $cmid));
        return $section->section;
    }
    
    private function get_fileids($cmid) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'), 'id');
        $id = $googledriverepo->id;
        if (empty($id)) {
            debugging('Could not find any instance of the repository');
            return;
        }
    
        $sql = "SELECT DISTINCT r.reference
                FROM {files_reference} r
                LEFT JOIN {files} f
                ON r.id = f.referencefileid
                LEFT JOIN {context} c
                ON f.contextid = c.id
                LEFT JOIN {course_modules} cm
                ON c.instanceid = cm.id
                WHERE cm.id = :cmid
                AND r.repositoryid = :repoid
                AND f.referencefileid IS NOT NULL
                AND NOT (f.component = :component1 AND f.component = :component2 AND f.filearea = :filearea)";
    
        $filerecords = $DB->get_records_sql($sql,
                array('component1' => 'user', 'component2' => 'tool_recyclebin', 'filearea' => 'draft', 'repoid' => $id, 'cmid' => $cmid));
    
        if ($filerecords) {
            $fileids = array();
            foreach ($filerecords as $filerecord) {
                $fileids[] = $filerecord->reference;
            }
            return $fileids;
        } else {
            return false;
        }
    }
    
    // Currently only tests for mod/resource capabilities
    private function edit_capability($context, $user) {
        return has_capability('mod/resource:addinstance', $context, $user);
    }
    
    // Need to limit batches to 1000 calls - $calls should be checked before calling
    private function batch_insert_permissions($calls) {
        $type = 'user';
        $optparams = array('sendNotificationEmails' => false);
        $this->client->setUseBatch(true);
        try {
            $batch = $this->service->createBatch();
            
            foreach ($calls as $call) {
                $name = explode('@', $call->gmail);
                $newpermission = new Google_Service_Drive_Permission();
                $newpermission->setValue($call->gmail);
                $newpermission->setType($type);
                $newpermission->setRole($call->role);
                $newpermission->setEmailAddress($call->gmail);
                $newpermission->setDomain($name[1]);
                $newpermission->setName($name[0]);
                
                $request = $this->service->permissions->insert($call->fileid, $newpermission, $optparams);
                $batch->add($request);
            }
            
            $results = $batch->execute();
            
            foreach ($results as $result) {
                if ($result instanceof Google_Service_Exception) {
                    debugging($result);
                }
            }
        } finally {
            $this->client->setUseBatch(false);
        }
    }
    
    // Need to limit batches to 1000 calls - $calls should be checked before calling
    private function batch_delete_permissions($calls) {
        $this->client->setUseBatch(true);
        try {
            $batch = $this->service->createBatch();
            
            foreach ($calls as $call) {
                $request = $this->service->permissions->getIdForEmail($call->gmail);
                $batch->add($request);
                $results = $batch->execute();
                foreach ($results as $result) {
                    if ($result instanceof Google_Service_Exception) {
                        debugging($result);
                    } else {
                        $permissionid = $result->id;
                    } 
                }

                $request = $this->service->permissions->get($call->fileid, $permissionid);
                $batch->add($request);
                $results = $batch->execute();
                foreach ($results as $result) {
                    if ($result instanceof Google_Service_Exception) {
                        debugging($result);
                    } else {
                        if ($result->role != 'owner') {
                            $request = $this->service->permissions->delete($call->fileid, $permissionid);
                            $batch->add($request);
                        }
                    } 
                } 
            }
    
            $results = $batch->execute();
    
            foreach ($results as $result) {
                if ($result instanceof Google_Service_Exception) {
                    debugging($result);
                }
            }
        } finally {
            $this->client->setUseBatch(false);
        }
    }

    /**
     * Get course module records for specified section.
     *
     * @param section number $sectionnumber
     * @return array of course module records
     */
    private function get_section_course_modules($sectionnumber) {
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
     * Get course records for specified user
     *
     * @param user id $userid
     * @return course records
     */
    private function get_user_courseids($userid) {
        global $DB;
        $sql = "SELECT e.courseid
                FROM {enrol} e
                LEFT JOIN {user_enrolments} ue
                ON e.id = ue.enrolid
                WHERE ue.userid = :userid;";
        $courses = $DB->get_recordset_sql($sql, array('userid' => $userid));
        return $courses;
    }
}

    /**
     * This function extends the navigation with the google drive items for user settings node.
     *
     * @param navigation_node $navigation  The navigation node to extend
     * @param stdClass        $user        The user object
     * @param context         $usercontext The context of the user
     * @param stdClass        $course      The course to object for the tool
     * @param context         $coursecontext     The context of the course
     */
function repository_googledrive_extend_navigation_user_settings($navigation, $user, $usercontext, $course, $coursecontext) {
    $url = new moodle_url('/repository/googledrive/preferences.php');
    $subsnode = navigation_node::create(get_string('syncyourgoogleaccount', 'repository_googledrive'), $url,
                navigation_node::TYPE_SETTING, null, 'monitor', new pix_icon('i/navigationitem', ''));

    if (isset($subsnode) && !empty($navigation)) {
        $navigation->add_node($subsnode);
    }
}
// Icon from: http://www.iconspedia.com/icon/google-2706.html.
