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

    /**
     * Return file types for repository.
     *
     * @var array
     */
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
        global $PAGE;
        if ($PAGE->bodyid == 'page-mod-resource-mod') {
            return FILE_INTERNAL | FILE_REFERENCE;
        } else {
            return FILE_INTERNAL;
        }
    }

    /**
     * Repository method to serve the referenced file.
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

    /**
     * Repository method to get the document url.
     *
     * @param unknown $id
     * @param string $downloadurl
     * @throws repository_exception
     * @return string|unknown
     */
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
                $this->course_category_updated($event);
                break;
            case '\core\event\course_updated':
                $this->course_updated($event);
                break;
            case '\core\event\course_content_deleted':
                $this->course_content_deleted($event);
                break;
            case '\core\event\course_restored':
                $this->course_restored($event);
                break;
            case '\core\event\course_section_updated':
                $this->course_section_updated($event);
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
            case '\tool_recyclebin\event\course_bin_item_restored':
                $this->course_bin_item_restored($event);
                break;
            case '\core\event\role_assigned':
                $this->role_assigned($event);
                break;
            case '\core\event\role_unassigned':
                $this->role_unassigned($event);
                break;
            case '\core\event\role_capabilities_updated':
                $this->role_capabilities_updated($event);
                break;
            case '\core\event\group_member_added':
            case '\core\event\group_member_removed':
                $this->group_member_added($event);
                break;
            case '\core\event\grouping_group_assigned':
            case '\core\event\grouping_group_unassigned':
                $this->grouping_group_assigned($event);
                break;
            case '\core\event\user_enrolment_created':
                $this->user_enrolment_created($event);
                break;
            case '\core\event\user_enrolment_updated':
                $this->user_enrolment_updated($event);
                break;
            case '\core\event\user_enrolment_deleted':
                $this->user_enrolment_deleted($event);
                break;
            case '\core\event\user_deleted':
                $this->user_deleted($event);
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

    /**
     * Update Google Drive reader permissions if category (and therefore course) visibility changed.
     *
     * @param string $event
     */
    private function course_category_updated($event) {
        global $DB;
        $categoryid = $event->objectid;
        $courses = $DB->get_records('course', array('category' => $categoryid), '', 'id, visible');

        $insertcalls = array();
        $deletecalls = array();

        foreach ($courses as $course) {
            $courseid = $course->id;
            $coursecontext = context_course::instance($courseid);

            $users = $this->get_google_authenticated_users($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();

            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {
                        foreach ($users as $user) {
                            if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                                // Manager; do nothing.
                            } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                                // Teacher (enrolled) (active); do nothing.
                            } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                                // Student (enrolled) (active); continue checks.
                                if ($course->visible == 1) {
                                    // Course is visible, continue checks.
                                    rebuild_course_cache($courseid, true);
                                    $modinfo = get_fast_modinfo($courseid, $user->userid);
                                    $cminfo = $modinfo->get_cm($cmid);
                                    $sectionnumber = $this->get_cm_sectionnum($cmid);
                                    $secinfo = $modinfo->get_section_info($sectionnumber);
                                    if ($cminfo->uservisible && $secinfo->available) {
                                        // User can view and access course module and can access section; insert reader permission.
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->gmail = $user->gmail;
                                        $call->role = 'reader';
                                        $insertcalls[] = $call;
                                        if (count($insertcalls) == 1000) {
                                            $this->batch_insert_permissions($insertcalls);
                                            $insertcalls = array();
                                        }
                                    }
                                    // User cannot access course module, do nothing (course module availability won't change here).
                                } else {
                                    // Course not visible, delete permission.
                                    try {
                                        $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                                        $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        if ($permission->role != 'owner') {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->permissionid = $permissionid->id;
                                            $deletecalls[] = $call;
                                            if (count($deletecalls) == 1000) {
                                                $this->batch_delete_permissions($deletecalls);
                                                $deletecalls = array();
                                            }
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                }
                            } else {
                                // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                            }
                        }
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Update Google Drive reader permissions if course visibility changed.
     *
     * @param string $event
     */
    private function course_updated($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);
        $coursemodinfo = get_fast_modinfo($courseid, -1);
        $cms = $coursemodinfo->get_cms();

        $users = $this->get_google_authenticated_users($courseid);
        $insertcalls = array();
        $deletecalls = array();

        foreach ($cms as $cm) {
            $cmid = $cm->id;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    foreach ($users as $user) {
                        if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                            // Manager; do nothing.
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                            // Teacher (enrolled) (active); do nothing.
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                            // Student (enrolled); continue checks for reader permissions.
                            if ($course->visible == 1) {
                                // Course is visible, continue checks.
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $user->userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = $this->get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available) {
                                    // User can view and access course module and can access section; insert reader permission.
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->gmail = $user->gmail;
                                    $call->role = 'reader';
                                    $insertcalls[] = $call;
                                    if (count($insertcalls) == 1000) {
                                        $this->batch_insert_permissions($insertcalls);
                                        $insertcalls = array();
                                    }
                                }
                                // User cannot access course module, do nothing (course module availability won't change here).
                            } else {
                                // Course not visible, delete permission.
                                try {
                                    $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                                    $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                    if ($permission->role != 'owner') {
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->permissionid = $permissionid->id;
                                        $deletecalls[] = $call;
                                        if (count($deletecalls) == 1000) {
                                            $this->batch_delete_permissions($deletecalls);
                                            $deletecalls = array();
                                        }
                                    }
                                } catch (Exception $e) {
                                    debugging($e);
                                }
                            }
                        }
                        // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Delete repository database table records when course is deleted.
     * Permission deletes handled by user_enrolment_deleted.
     *
     * @param string $event
     */
    private function course_content_deleted($event) {
        global $DB;
        $courseid = $event->courseid;
        $DB->delete_records('repository_gdrive_references', array('courseid' => $courseid));
    }

    /**
     * Insert Google Drive permissions when course is restored.
     *
     * @param string $event
     */
    private function course_restored($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);
        $siteadmins = $this->get_siteadmins();
        $users = $this->get_google_authenticated_users($courseid);
        $coursemodinfo = get_fast_modinfo($courseid, -1);
        $cms = $coursemodinfo->get_cms();
        $insertcalls = array();
        $deletecalls = array();
        foreach ($cms as $cm) {
            $cmid = $cm->id;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    foreach ($users as $user) {
                        if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                            // Manager; insert writer permission.
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $user->gmail;
                            $call->role = 'writer';
                            $insertcalls[] = $call;
                            if (count($insertcalls) == 1000) {
                                $this->batch_insert_permissions($insertcalls);
                                $insertcalls = array();
                            }
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                            // Enrolled teacher; insert writer permission.
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $user->gmail;
                            $call->role = 'writer';
                            $insertcalls[] = $call;
                            if (count($insertcalls) == 1000) {
                                $this->batch_insert_permissions($insertcalls);
                                $insertcalls = array();
                            }
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                            // Enrolled student; continue checks for reader permission.
                            if ($course->visible == 1) {
                                // Course is visible, continue checks.
                                if ($cm->visible == 1) {
                                    // Course module is visible, continue checks.
                                    rebuild_course_cache($courseid, true);
                                    $modinfo = get_fast_modinfo($courseid, $user->userid);
                                    $cminfo = $modinfo->get_cm($cmid);
                                    $sectionnumber = $this->get_cm_sectionnum($cmid);
                                    $secinfo = $modinfo->get_section_info($sectionnumber);
                                    if ($cminfo->uservisible && $secinfo->available) {
                                        // Course module is visible and accessible, section is accessible; insert reader permission.
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->gmail = $user->gmail;
                                        $call->role = 'reader';
                                        $insertcalls[] = $call;
                                        if (count($insertcalls) == 1000) {
                                            $this->batch_insert_permissions($insertcalls);
                                            $insertcalls = array();
                                        }
                                    }
                                    // User cannot access course module or section, do nothing.
                                }
                                // Course module not visible, do nothing.
                            }
                            // Course not visible, do nothing.
                        } else {
                            // User is not enrolled; do nothing.
                        }
                    }

                    $newdata = new stdClass();
                    $newdata->courseid = $courseid;
                    $newdata->cmid = $cmid;
                    $newdata->reference = $fileid;
                    $DB->insert_record('repository_gdrive_references', $newdata);
                    
                    // Insert writer permissions for site admins.
                    foreach ($siteadmins as $siteadmin) {
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $siteadmin->gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    }
                }
            }

            // Call any remaining batch requests.
            if (count($insertcalls) > 0) {
                $this->batch_insert_permissions($insertcalls);
            }

            if (count($deletecalls) > 0) {
                $this->batch_delete_permissions($deletecalls);
            }
        }
    }

    /**
     * Update Google Drive reader permissions if section visibility or access changed.
     *
     * @param string $event
     */
    private function course_section_updated($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);

        $users = $this->get_google_authenticated_users($courseid);
        $sectionnumber = $event->other['sectionnum'];
        $cms = $this->get_section_course_modules($sectionnumber);

        $insertcalls = array();
        $deletecalls = array();

        foreach ($cms as $cm) {
            $cmid = $cm->id;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    foreach ($users as $user) {
                        if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                            // Manager; do nothing.
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                            // Teacher (enrolled) (active); do nothing.
                        } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                            // Student (enrolled) (active); continue checks.
                            if ($course->visible == 1) {
                                // Course is visible, continue checks.
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $user->userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = $this->get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available) {
                                    // Course module and section are visible and available; insert reader permission.
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->gmail = $user->gmail;
                                    $call->role = 'reader';
                                    $insertcalls[] = $call;
                                    if (count($insertcalls) == 1000) {
                                        $this->batch_insert_permissions($insertcalls);
                                        $insertcalls = array();
                                    }
                                } else {
                                    // User cannot access course module or section, delete permission.
                                    try {
                                        $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                                        $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        if ($permission->role != 'owner') {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->permissionid = $permissionid->id;
                                            $deletecalls[] = $call;
                                            if (count($deletecalls) == 1000) {
                                                $this->batch_delete_permissions($deletecalls);
                                                $deletecalls = array();
                                            }
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                }   
                            }
                            // Course is not visible; do nothing (course visibility would not have changed during this event).
                        }
                        // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Insert Google Drive permissions when file resource course module created.
     *
     * @param string $event
     */
    private function course_module_created($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);

        $cmid = $event->contextinstanceid;
        $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
        $cmcontext = context_module::instance($cmid);

        $siteadmins = $this->get_siteadmins();
        $users = $this->get_google_authenticated_users($courseid);

        $fileids = $this->get_fileids($cmid);
        $insertcalls = array();

        if ($fileids) {
            foreach ($fileids as $fileid) {
                // Insert writer permissions for site admins.
                foreach ($siteadmins as $siteadmin) {
                    $call = new stdClass();
                    $call->fileid = $fileid;
                    $call->gmail = $siteadmin->gmail;
                    $call->role = 'writer';
                    $insertcalls[] = $call;
                    if (count($insertcalls) == 1000) {
                        $this->batch_insert_permissions($insertcalls);
                        $insertcalls = array();
                    }
                }

                // Insert permissions for course users.
                foreach ($users as $user) {
                    if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                        // Manager; insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $user->gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                        // Teacher (enrolled) (active); insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $user->gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                        // Student (enrolled) (active); continue checks for reader permission.
                        if ($course->visible == 1) {
                            // Course is visible, continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $user->userid);

                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);

                            if ($cminfo->uservisible && $secinfo->available) {
                                // Course module is visible and available, section is available.
                                // Insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $user->gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            }
                            // Course module not visible, do nothing.
                        }
                        // Course not visible, do nothing.
                    } 
                    // User is not enrolled; do nothing.
                }

                // Store cmid and reference(s).
                $newdata = new stdClass();
                $newdata->courseid = $courseid;
                $newdata->cmid = $cmid;
                $newdata->reference = $fileid;
                $DB->insert_record('repository_gdrive_references', $newdata);
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
    }

    /**
     * Update Google Drive reader permissions if course module files, visibility or access changed.
     *
     * @param string $event
     */
    private function course_module_updated($event) {
        global $DB;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);

        $cmid = $event->contextinstanceid;
        $cm = $DB->get_record('course_modules', array('id' => $cmid), 'visible');
        $cmcontext = context_module::instance($cmid);

        $siteadmins = $this->get_siteadmins();
        $users = $this->get_google_authenticated_users($courseid);

        $insertcalls = array();
        $deletecalls = array();

        // Get current fileids for module.
        $fileids = $this->get_fileids($cmid);

        // Get previous fileids for module.
        $prevfilerecs = $DB->get_records('repository_gdrive_references', array('cmid' => $cmid), '', 'id, reference');
        $prevfileids = array();
        foreach ($prevfilerecs as $prevfilerec) {
            $prevfileids[] = $prevfilerec->reference;
        }

        // Determine fileids that were added, deleted, or didn't change from module.
        $addfileids = array_diff($fileids, $prevfileids);
        $delfileids = array_diff($prevfileids, $fileids);
        $eqfileids = array_diff($fileids, $addfileids);

        // Check unchanged module fileids for visibility and access changes.
        if ($eqfileids) {
            foreach ($eqfileids as $eqfileid) {
                foreach ($users as $user) {
                    if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                        // Manager; already has permission - do nothing.
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                        // Teacher (enrolled) (active); already has permission - do nothing.
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                        // Student (enrolled) (active); continue checks.
                        if ($course->visible == 1) {
                            // Course is visible; continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $user->userid);

                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            $cminfo = $modinfo->get_cm($cmid);

                            if ($cminfo->uservisible && $secinfo->available) {
                                // Course module is visible and available, section is available.
                                // Insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $eqfileid;
                                $call->gmail = $user->gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } else {
                                // Course module or section is not visible or available,
                                // Delete permission.
                                try {
                                    $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                                    $permission = $this->service->permissions->get($eqfileid, $permissionid->id);
                                    if ($permission->role != 'owner') {
                                        $call = new stdClass();
                                        $call->fileid = $eqfileid;
                                        $call->permissionid = $permissionid->id;
                                        $deletecalls[] = $call;
                                        if (count($deletecalls) == 1000) {
                                            $this->batch_delete_permissions($deletecalls);
                                            $deletecalls = array();
                                        }
                                    }
                                } catch (Exception $e) {
                                    debugging($e);
                                }
                            }
                        }
                        // Course is not visible; do nothing (course visibility would not have changed during this event).
                    }
                    // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                }
            }
        }

        // Insert permissions for added fileids.
        if ($addfileids) {
            foreach ($addfileids as $addfileid) {
                // Insert writer permission for site admins.
                foreach ($siteadmins as $siteadmin) {
                    $call = new stdClass();
                    $call->fileid = $addfileid;
                    $call->gmail = $siteadmin->gmail;
                    $call->role = 'writer';
                    $insertcalls[] = $call;
                    if (count($insertcalls) == 1000) {
                        $this->batch_insert_permissions($insertcalls);
                        $insertcalls = array();
                    }
                }
                
                // Insert permissions for course users.
                foreach ($users as $user) {
                    if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                        // Manager; insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $addfileid;
                        $call->gmail = $user->gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                        // Teacher (enrolled) (active); insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $addfileid;
                        $call->gmail = $user->gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                        // Student (enrolled) (active); continue access checks.
                        if ($course->visible == 1) {
                            // Course is visible; continue checks.
                            // Course module is visible, continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $user->userid);
                            $cminfo = $modinfo->get_cm($cmid);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            if ($cminfo->uservisible && $secinfo->available) {
                                // User can view and access course module and can access section; insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $addfileid;
                                $call->gmail = $user->gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            }
                            // Course module is not accessible; do nothing.
                        }
                        // Course is not visible; do nothing.
                    }
                    // Unenrolled user; do nothing.
                }

                // Add new fileids to database.
                $newdata = new stdClass();
                $newdata->courseid = $courseid;
                $newdata->cmid = $cmid;
                $newdata->reference = $addfileid;
                $DB->insert_record('repository_gdrive_references', $newdata);
            }
        }

        // Remove permissions for site admins and course users for deleted fileids.
        if ($delfileids) {
            foreach ($delfileids as $delfileid) {
                foreach ($siteadmins as $siteadmin) {
                    try {
                        $permissionid = $this->service->permissions->getIdForEmail($siteadmin->gmail);
                        $permission = $this->service->permissions->get($delfileid, $permissionid->id);
                        if ($permission->role != 'owner') {
                            $call = new stdClass();
                            $call->fileid = $delfileid;
                            $call->permissionid = $permissionid->id;
                            $deletecalls[] = $call;
                            if (count($deletecalls) == 1000) {
                                $this->batch_delete_permissions($deletecalls);
                                $deletecalls = array();
                            }
                        }
                    } catch (Exception $e) {
                        debugging($e);
                    }
                }

                foreach ($users as $user) {
                    try {
                        $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                        $permission = $this->service->permissions->get($delfileid, $permissionid->id);
                        if ($permission->role != 'owner') {
                            $call = new stdClass();
                            $call->fileid = $delfileid;
                            $call->permissionid = $permissionid->id;
                            $deletecalls[] = $call;
                            if (count($deletecalls) == 1000) {
                                $this->batch_delete_permissions($deletecalls);
                                $deletecalls = array();
                            }
                        }
                    } catch (Exception $e) {
                        debugging($e);
                    }
                }
                $DB->delete_records_select('repository_gdrive_references', 'cmid = :cmid AND reference = :reference', array('cmid' => $cmid, 'reference' => $delfileid));
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Delete Google Drive permissions when file resource course module is deleted.
     *
     * @param string $event
     */
    private function course_module_deleted($event) {
        global $DB;
        $courseid = $event->courseid;
        $coursecontext = context_course::instance($courseid);
        $cmid = $event->contextinstanceid;
        
        $users = $this->get_google_authenticated_users($courseid);
        $filerecs = $DB->get_records('repository_gdrive_references', array('cmid' => $cmid), '', 'id, reference');
        $deletecalls = array();

        foreach ($filerecs as $filerec) {
            foreach ($users as $user) {
                if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                    // Manager; do nothing (don't delete permission so they can restore if necessary).
                } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $coursecontext, $user->userid)) {
                    // Teacher (enrolled) (active); do nothing (don't delete permission so they can restore if necessary).
                } else {
                    // Student (enrolled) or unenrolled user; delete permission.
                    try {
                        $permissionid = $this->service->permissions->getIdForEmail($user->gmail);
                        $permission = $this->service->permissions->get($filerec->reference, $permissionid->id);
                        if ($permission->role != 'owner') {
                            $call = new stdClass();
                            $call->fileid = $filerec->reference;
                            $call->permissionid = $permissionid->id;
                            $deletecalls[] = $call;
                            if (count($deletecalls) == 1000) {
                                $this->batch_delete_permissions($deletecalls);
                                $deletecalls = array();
                            }
                        }
                    } catch (Exception $e) {
                        debugging($e);
                    }
                }   
            }
        }

        // Call any remaining batch requests.
        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
        $DB->delete_records('repository_gdrive_references', array('cmid' => $cmid));
    }

    /**
     * Insert Google Drive permissions when a file resource course module is restored from recycle bin.
     *
     * @param unknown $event
     */
    private function course_bin_item_restored($event) {
        global $DB;
        $objectid = $event->objectid;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);

        $restoreditem = $event->get_record_snapshot('tool_recyclebin_course', $objectid);
        $section = $restoreditem->section;
        $module = $restoreditem->module;
        $sql = 'SELECT id, visible
                FROM {course_modules}
                WHERE course = :courseid
                AND module = :module
                AND section = :section
                ORDER BY id DESC
                LIMIT 1';
        $cm = $DB->get_record_sql($sql, array('courseid' => $courseid, 'module' => $module, 'section' => $section));
        $cmid = $cm->id;
        $cmcontext = context_module::instance($cmid);

        $users = $this->get_google_authenticated_users($courseid);
        $fileids = $this->get_fileids($cmid);
        $insertcalls = array();

        if ($fileids) {
            foreach ($fileids as $fileid) {
                foreach ($users as $user) {
                    if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                        // Manager; do nothing (permission should already exist).
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                        // Teacher (enrolled) (active); do nothing (permission should already exist).
                    } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                        // Student (enrolled) (active); continue checks for reader permission.
                        if ($course->visible == 1) {
                            // Course is visible, continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $user->userid);
                            $cminfo = $modinfo->get_cm($cmid);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            if ($cminfo->uservisible && $secinfo->available) {
                                // Course module is visible and accessible, section is accessible; insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $user->gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            }
                            // Course module or section not visible or available; do nothing.
                        }
                        // Course not visible, do nothing.
                    } 
                    // User is not enrolled; do nothing.
                }

                // Store cmid and reference(s).
                $newdata = new stdClass();
                $newdata->courseid = $courseid;
                $newdata->cmid = $cmid;
                $newdata->reference = $fileid;
                $DB->insert_record('repository_gdrive_references', $newdata);
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
    }

    /**
     * Insert or delete Google Drive permissions when role assigned to a user.
     *
     * @param unknown $event
     */
    private function role_assigned($event) {
        global $DB;
        $contextlevel = $event->contextlevel;
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);
        $insertcalls = array();
        if ($contextlevel == 40) {
            // Category role assigned.
            $categoryid = $event->contextinstanceid;
            $courses = $DB->get_records('course', array('category' => $categoryid), '', 'id, visible');
            foreach ($courses as $course) {
                $courseid = $course->id;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $coursemodinfo = get_fast_modinfo($courseid, -1);
                $cms = $coursemodinfo->get_cms();
                foreach ($cms as $cm) {
                    $cmid = $cm->id;
                    $cmcontext = context_module::instance($cmid);
                    $fileids = $this->get_fileids($cmid);
                    if ($fileids) {
                        foreach ($fileids as $fileid) {
                            if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                                // Manager; insert writer permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'writer';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                                // Teacher (enrolled) (active); insert writer permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'writer';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                                // Student (enrolled) (active); do nothing (user_enrolment_created handles reader permission).
                            } else {
                                // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                            }
                        }
                    }
                }
            }
        } elseif ($contextlevel == 50) {
            // Course role assigned.
            $courseid = $event->courseid;
            $course = $DB->get_record('course', array('id' => $courseid), 'visible');
            $coursecontext = context_course::instance($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();
            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {
                        if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                            // Manager; insert writer permission.
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $gmail;
                            $call->role = 'writer';
                            $insertcalls[] = $call;
                            if (count($insertcalls) == 1000) {
                                $this->batch_insert_permissions($insertcalls);
                                $insertcalls = array();
                            }
                        } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                            // Teacher (enrolled) (active); insert writer permission.
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $gmail;
                            $call->role = 'writer';
                            $insertcalls[] = $call;
                            if (count($insertcalls) == 1000) {
                                $this->batch_insert_permissions($insertcalls);
                                $insertcalls = array();
                            }
                        } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                            // Student (enrolled) (active); do nothing (user_enrolment_created handles reader permission).
                        } else {
                            // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                        }
                    }
                }
            }
        } else if ($contextlevel == 70) {
            // Course module role assigned.
            $cmid = $event->contextinstanceid;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                        // Manager; insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                        // Teacher (enrolled) (active); insert writer permission.
                        $call = new stdClass();
                        $call->fileid = $fileid;
                        $call->gmail = $gmail;
                        $call->role = 'writer';
                        $insertcalls[] = $call;
                        if (count($insertcalls) == 1000) {
                            $this->batch_insert_permissions($insertcalls);
                            $insertcalls = array();
                        }
                    } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                        // Student (enrolled) (active); do nothing (user_enrolment_created handles reader permission).
                    } else {
                        // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                    }
                }
            }
            
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
    }

    /**
     * Patch Google Drive permissions when role is unassigned from user.
     * Also used during course_content_deleted event.
     *
     * @param unknown $event
     */
    private function role_unassigned($event) {
        global $DB;
        $contextlevel = $event->contextlevel;
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);
        $patchcalls = array();
        $deletecalls = array();
        if ($contextlevel == 40) {
            // Category role unassigned.
            $categoryid = $event->contextinstanceid;
            $courses = $DB->get_records('course', array('category' => $categoryid), '', 'id, visible');
            foreach ($courses as $course) {
                $courseid = $course->id;
                $course = $DB->get_record('course', array('id' => $courseid), 'visible');
                $coursecontext = context_course::instance($courseid);
                $coursemodinfo = get_fast_modinfo($courseid, -1);
                $cms = $coursemodinfo->get_cms();
                foreach ($cms as $cm) {
                    $cmid = $cm->id;
                    $cmcontext = context_module::instance($cmid);
                    $fileids = $this->get_fileids($cmid);
                    if ($fileids) {
                        foreach ($fileids as $fileid) {
                            if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                                // Manager; do nothing.
                            } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                                // Teacher (enrolled) (active); do nothing.
                            } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                                // Student (enrolled) (active); continue checks.
                                if ($course->visible == 1) {
                                    // Course is visible; continue checks.
                                    rebuild_course_cache($courseid, true);
                                    $modinfo = get_fast_modinfo($courseid, $userid);
                                    $cminfo = $modinfo->get_cm($cmid);
                                    $sectionnumber = $this->get_cm_sectionnum($cmid);
                                    $secinfo = $modinfo->get_section_info($sectionnumber);
                                    if ($cminfo->uservisible && $secinfo->available) {
                                        // User can view and access course module and can access section; patch to reader permission.
                                        try {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->gmail = $gmail;
                                            $call->role = 'reader';
                                            $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                            $call->permissionid = $permissionid->id;
                                            $patchcalls[] = $call;
                                            if (count($patchcalls) == 1000) {
                                                $this->batch_patch_permissions($patchcalls);
                                                $patchcalls = array();
                                            }
                                        } catch (Exception $e) {
                                            debugging($e);
                                        }
                                    } else {
                                        // Course module or section not available; delete permission.
                                        try {
                                            $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                            $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                            if ($permission->role != 'owner') {
                                                $call = new stdClass();
                                                $call->fileid = $fileid;
                                                $call->permissionid = $permissionid->id;
                                                $deletecalls[] = $call;
                                                if (count($deletecalls) == 1000) {
                                                    $this->batch_delete_permissions($deletecalls);
                                                    $deletecalls = array();
                                                }
                                            }
                                        } catch (Exception $e) {
                                            debugging($e);
                                        }
                                    }
                                }
                                // Course not visible; do nothing (course would not change here).
                            }
                            // Unenrolled user; delete permission.
                            try {
                                $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                if ($permission->role != 'owner') {
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->permissionid = $permissionid->id;
                                    $deletecalls[] = $call;
                                    if (count($deletecalls) == 1000) {
                                        $this->batch_delete_permissions($deletecalls);
                                        $deletecalls = array();
                                    }
                                }
                            } catch (Exception $e) {
                                debugging($e);
                            }
                        }
                    }
                }
            }
        } elseif ($contextlevel == 50) {
            // Course role assigned.
            $courseid = $event->courseid;
            $course = $DB->get_record('course', array('id' => $courseid), 'visible');
            $coursecontext = context_course::instance($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();
            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {
                        if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                            // Manager; do nothing.
                        } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                            // Teacher (enrolled) (active); do nothing.
                        } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                            // Student (enrolled) (active); continue checks.
                            if ($course->visible == 1) {
                                // Course is visible; continue checks.
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = $this->get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available) {
                                    // User can view and access course module and can access section; patch to reader permission.
                                    try {
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->gmail = $gmail;
                                        $call->role = 'reader';
                                        $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                        $call->permissionid = $permissionid->id;
                                        $patchcalls[] = $call;
                                        if (count($patchcalls) == 1000) {
                                            $this->batch_patch_permissions($patchcalls);
                                            $patchcalls = array();
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                } else {
                                    // Course module or section not available; delete permission.
                                    try {
                                        $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                        $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        if ($permission->role != 'owner') {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->permissionid = $permissionid->id;
                                            $deletecalls[] = $call;
                                            if (count($deletecalls) == 1000) {
                                                $this->batch_delete_permissions($deletecalls);
                                                $deletecalls = array();
                                            }
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                }
                            }
                            // Course not visible; do nothing (course would not change here).
                        }
                        // Unenrolled user; do nothing (enrolment would not change here).
                    }
                }
            }
        } else if ($contextlevel == 70) {
            // Course module role assigned.
            $cmid = $event->contextinstanceid;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                        // Manager; do nothing.
                    } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                        // Teacher (enrolled) (active); do nothing.
                    } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                        // Student (enrolled) (active); continue checks.
                        if ($course->visible == 1) {
                            // Course is visible; continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $cminfo = $modinfo->get_cm($cmid);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            if ($cminfo->uservisible && $secinfo->available) {
                                // User can view and access course module and can access section; patch to reader permission.
                                try {
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->gmail = $gmail;
                                    $call->role = 'reader';
                                    $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                    $call->permissionid = $permissionid->id;
                                    $patchcalls[] = $call;
                                    if (count($patchcalls) == 1000) {
                                        $this->batch_patch_permissions($patchcalls);
                                        $patchcalls = array();
                                    }
                                } catch (Exception $e) {
                                    debugging($e);
                                }
                            } else {
                                // Course module or section not available; delete permission.
                                try {
                                    $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                    $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                    if ($permission->role != 'owner') {
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->permissionid = $permissionid->id;
                                        $deletecalls[] = $call;
                                        if (count($deletecalls) == 1000) {
                                            $this->batch_delete_permissions($deletecalls);
                                            $deletecalls = array();
                                        }
                                    }
                                } catch (Exception $e) {
                                    debugging($e);
                                }
                            }
                        }
                        // Course not visible; do nothing (course would not change here).
                    }
                    // Unenrolled user; do nothing (enrolment would not change here).
                }
            }
    
        }
    
        // Call any remaining batch requests.
        if (count($patchcalls) > 0) {
            $this->batch_patch_permissions($patchcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Insert, delete or patch Google Drive permissions when role capabilities are updated.
     *
     * @param unknown $event
     */
    private function role_capabilities_updated($event) {
        global $DB;
        $roleid = $event->objectid;
        $sql = "SELECT DISTINCT c.id, c.visible
                FROM {course} c
                LEFT JOIN {context} ct
                ON c.id = ct.instanceid
                LEFT JOIN {role_assignments} ra
                ON ra.contextid = ct.id
                WHERE ra.roleid = :roleid";

        // Get courses affected by role capability update.
        $courses = $DB->get_records_sql($sql, array('roleid' => $roleid));

        $insertcalls = array();
        $deletecalls = array();
        $patchcalls = array();
        foreach ($courses as $course) {
            $courseid = $course->id;
            $coursecontext = context_course::instance($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();
            $userssql = "SELECT ra.userid
                         FROM {role_assignments} ra
                         LEFT JOIN {context} ct
                         ON ct.id = ra.contextid
                         LEFT JOIN {course} c
                         ON c.id = ct.instanceid
                         WHERE c.id = :courseid
                         AND ra.roleid = :roleid";

            // Get users affected by role capability update in course.
            $users = $DB->get_records_sql($userssql, array('courseid' => $courseid, 'roleid' => $roleid));
            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {
                        foreach ($users as $user) {
                            $gmail = $this->get_google_authenticated_users_gmail($user->userid);
                            if (has_capability('moodle/course:view', $coursecontext, $user->userid)) {
                                // Manager; insert writer permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'writer';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } elseif (is_enrolled($coursecontext, $user->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $user->userid)) {
                                // Teacher (enrolled) (active); insert writer permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'writer';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } elseif (is_enrolled($coursecontext, $user->userid, null, true)) {
                                // Student (enrolled) (active); patch permission to reader and continue checks.
                                if ($course->visible == 1) {
                                    // Course is visible; continue checks.
                                    rebuild_course_cache($courseid, true);
                                    $modinfo = get_fast_modinfo($courseid, $user->userid);
                                    $cminfo = $modinfo->get_cm($cmid);
                                    $sectionnumber = $this->get_cm_sectionnum($cmid);
                                    $secinfo = $modinfo->get_section_info($sectionnumber);
                                    if ($cminfo->uservisible && $secinfo->available) {
                                        // Course module and section are visible and available.
                                        // Try to patch permission.
                                        try {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->gmail = $gmail;
                                            $call->role = 'reader';
                                            $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                            $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        } catch (Exception $e) {
                                            debugging($e);
                                        } finally {
                                            if (is_a($permission, 'Google_Service_Drive_Permission')) {
                                                // Permission exists; patch to reader.
                                                if ($permission->role != 'owner') {
                                                    $call->permissionid = $permissionid->id;
                                                    $patchcalls[] = $call;
                                                    if (count($patchcalls) == 1000) {
                                                        $this->batch_patch_permissions($patchcalls);
                                                        $patchcalls = array();
                                                    }
                                                }
                                            } else {
                                                // Permission does not exist; insert reader permission.
                                                $insertcalls[] = $call;
                                                if (count($insertcalls) == 1000) {
                                                    $this->batch_insert_permissions($insertcalls);
                                                    $insertcalls = array();
                                                }
                                            }
                                        }
                                    } else {
                                        // Course module is not available; delete permission.
                                        try {
                                            $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                            $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                            if ($permission->role != 'owner') {
                                                $call = new stdClass();
                                                $call->fileid = $fileid;
                                                $call->permissionid = $permissionid->id;
                                                $deletecalls[] = $call;
                                                if (count($deletecalls) == 1000) {
                                                    $this->batch_delete_permissions($deletecalls);
                                                    $deletecalls = array();
                                                }
                                            }
                                        } catch (Exception $e) {
                                            debugging($e);
                                        }
                                    }
                                } else {
                                    // Course is not visible; delete permission.
                                    try {
                                        $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                        $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        if ($permission->role != 'owner') {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->permissionid = $permissionid->id;
                                            $deletecalls[] = $call;
                                            if (count($deletecalls) == 1000) {
                                                $this->batch_delete_permissions($deletecalls);
                                                $deletecalls = array();
                                            }
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                }
                            }
                            // Unenrolled user; do nothing (user enrolment would not have changed during this event).
                        }
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($patchcalls) > 0) {
            $this->batch_patch_permissions($patchcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Insert or delete Google Drive permissions when user is added to group.
     *
     * @param unknown $event
     */
    private function group_member_added($event) {
        global $DB;
        $groupid = $event->objectid;
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);

        $group = groups_get_group($groupid, 'courseid');
        $courseid = $group->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);

        $coursemodinfo = get_fast_modinfo($courseid, -1);
        $cms = $coursemodinfo->get_cms();

        $insertcalls = array();
        $deletecalls = array();

        foreach ($cms as $cm) {
            $cmid = $cm->id;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                        // Manager; do nothing.
                    } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                        // Teacher (enrolled) (active); do nothing.
                    } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                        // Student (enrolled) (active); continue checks.
                        if ($course->visible == 1) {
                            // Course is visible; continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $cminfo = $modinfo->get_cm($cmid);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                // Course module and section are visible and available.
                                // Insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            } else {
                                // User cannot access course module; delete permission.
                                try {
                                    $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                    $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                    if ($permission->role != 'owner') {
                                        $call = new stdClass();
                                        $call->fileid = $fileid;
                                        $call->permissionid = $permissionid->id;
                                        $deletecalls[] = $call;
                                        if (count($deletecalls) == 1000) {
                                            $this->batch_delete_permissions($deletecalls);
                                            $deletecalls = array();
                                        }
                                    }
                                } catch (Exception $e) {
                                    debugging($e);
                                }
                            }
                        }
                    } else {
                        // Unenrolled user; do nothing.
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Insert or delete Google Drive permissions when a group is assigned to a grouping.
     *
     * @param unknown $event
     */
    private function grouping_group_assigned($event) {
        global $DB;
        $groupid = $event->other['groupid'];
        $members = groups_get_members($groupid, 'userid');
        $group = groups_get_group($groupid, 'courseid');
        $courseid = $group->courseid;
        $course = $DB->get_record('course', array('id' => $courseid), 'visible');
        $coursecontext = context_course::instance($courseid);
        $coursemodinfo = get_fast_modinfo($courseid, -1);
        $cms = $coursemodinfo->get_cms();

        $insertcalls = array();
        $deletecalls = array();

        foreach ($cms as $cm) {
            $cmid = $cm->id;
            $cmcontext = context_module::instance($cmid);
            $fileids = $this->get_fileids($cmid);
            if ($fileids) {
                foreach ($fileids as $fileid) {
                    foreach ($members as $member) {
                        $gmail = $this->get_google_authenticated_users_gmail($member->userid);
                        if (has_capability('moodle/course:view', $coursecontext, $member->userid)) {
                            // Manager; do nothing.
                        } elseif (is_enrolled($coursecontext, $member->userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $member->userid)) {
                            // Teacher (enrolled) (active); do nothing.
                        } elseif (is_enrolled($coursecontext, $member->userid, null, true)) {
                            // Student (enrolled) (active); continue checks.
                            if ($course->visible == 1) {
                                // Course is visible, continue checks.
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $member->userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = $this->get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $member->userid, '', true)) {
                                    // Course module and section are visible and available.
                                    // Insert reader permission.
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->gmail = $gmail;
                                    $call->role = 'reader';
                                    $insertcalls[] = $call;
                                    if (count($insertcalls) == 1000) {
                                        $this->batch_insert_permissions($insertcalls);
                                        $insertcalls = array();
                                    }
                                } else {
                                    // User cannot access course module; delete permission.
                                    try {
                                        $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                        $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                        if ($permission->role != 'owner') {
                                            $call = new stdClass();
                                            $call->fileid = $fileid;
                                            $call->permissionid = $permissionid->id;
                                            $deletecalls[] = $call;
                                            if (count($deletecalls) == 1000) {
                                                $this->batch_delete_permissions($deletecalls);
                                                $deletecalls = array();
                                            }
                                        }
                                    } catch (Exception $e) {
                                        debugging($e);
                                    }
                                }
                            }
                            // Course is not visible; do nothing.
                        }
                        // User is not enrolled in course; do nothing.
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }

        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Insert Google Drive permissions when user is enroled in course.
     * Don't worry about reader/writer here - role_assigned handles after user enrolled.
     *
     * @param unknown $event
     */
    private function user_enrolment_created($event) {
        global $DB;
        $courseid = $event->courseid;
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);
        if ($gmail) {
            $course = $DB->get_record('course', array('id' => $courseid), 'visible');
            $coursecontext = context_course::instance($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();
            $insertcalls = array();
            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {                        
                        if ($course->visible == 1) {
                            // Course is visible, continue checks.
                            rebuild_course_cache($courseid, true);
                            $modinfo = get_fast_modinfo($courseid, $userid);
                            $cminfo = $modinfo->get_cm($cmid);
                            $sectionnumber = $this->get_cm_sectionnum($cmid);
                            $secinfo = $modinfo->get_section_info($sectionnumber);
                            if ($cminfo->uservisible && $secinfo->available && is_enrolled($coursecontext, $userid, '', true)) {
                                // Course module and section are visible and available.
                                // Insert reader permission.
                                $call = new stdClass();
                                $call->fileid = $fileid;
                                $call->gmail = $gmail;
                                $call->role = 'reader';
                                $insertcalls[] = $call;
                                if (count($insertcalls) == 1000) {
                                    $this->batch_insert_permissions($insertcalls);
                                    $insertcalls = array();
                                }
                            }
                            // Course module is not visible or available, do nothing.
                        }
                        // Course is not visible, do nothing.
                    }
                }
            }

            // Call any remaining batch requests.
            if (count($insertcalls) > 0) {
                $this->batch_insert_permissions($insertcalls);
            }
        }
    }
    
    /**
     * Insert or delete Google Drive permissions when user enrolment updated.
     * 
     * @param string $event
     */
    private function user_enrolment_updated($event) {
        global $DB;
        $courseid = $event->courseid;
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);
        $insertcalls = array();
        $deletecalls = array();
        if ($gmail) {
            $course = $DB->get_record('course', array('id' => $courseid), 'visible');
            $coursecontext = context_course::instance($courseid);
            $coursemodinfo = get_fast_modinfo($courseid, -1);
            $cms = $coursemodinfo->get_cms();
            foreach ($cms as $cm) {
                $cmid = $cm->id;
                $cmcontext = context_module::instance($cmid);
                $fileids = $this->get_fileids($cmid);
                if ($fileids) {
                    foreach ($fileids as $fileid) {
                        if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                            // Manager; do nothing.
                        } elseif (is_enrolled($coursecontext, $userid, null, true) && has_capability('moodle/course:manageactivities', $cmcontext, $userid)) {
                            // Teacher (enrolled) (active); insert writer permisson.
                            $call = new stdClass();
                            $call->fileid = $fileid;
                            $call->gmail = $gmail;
                            $call->role = 'writer';
                            $insertcalls[] = $call;
                            if (count($insertcalls) == 1000) {
                                $this->batch_insert_permissions($insertcalls);
                                $insertcalls = array();
                            }
                        } elseif (is_enrolled($coursecontext, $userid, null, true)) {
                            // Student (enrolled) (active); continue checks.
                            if ($course->visible == 1) {
                                // Course is visible, continue checks.
                                rebuild_course_cache($courseid, true);
                                $modinfo = get_fast_modinfo($courseid, $userid);
                                $cminfo = $modinfo->get_cm($cmid);
                                $sectionnumber = $this->get_cm_sectionnum($cmid);
                                $secinfo = $modinfo->get_section_info($sectionnumber);
                                if ($cminfo->uservisible && $secinfo->available) {
                                    // User can view and access course module and can access section; insert reader permission.
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->gmail = $gmail;
                                    $call->role = 'reader';
                                    $insertcalls[] = $call;
                                    if (count($insertcalls) == 1000) {
                                        $this->batch_insert_permissions($insertcalls);
                                        $insertcalls = array();
                                    }
                                }
                                // Course module not visible or avaiable; do nothing (module would not change here).
                            }
                            // Course not visible, do nothing (course would not change here).
                        } else {
                            // Unenrolled user; delete permission.
                            try {
                                $permissionid = $this->service->permissions->getIdForEmail($gmail);
                                $permission = $this->service->permissions->get($fileid, $permissionid->id);
                                if ($permission->role != 'owner') {
                                    $call = new stdClass();
                                    $call->fileid = $fileid;
                                    $call->permissionid = $permissionid->id;
                                    $deletecalls[] = $call;
                                    if (count($deletecalls) == 1000) {
                                        $this->batch_delete_permissions($deletecalls);
                                        $deletecalls = array();
                                    }
                                }
                            } catch (Exception $e) {
                                debugging($e);
                            }
                        }
                    }
                }
            }
        }
        
        // Call any remaining batch requests.
        if (count($insertcalls) > 0) {
            $this->batch_insert_permissions($insertcalls);
        }
        
        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Delete Google Drive permissions when user enrolment deleted.
     *
     * @param string $event
     */
    private function user_enrolment_deleted($event) {
        global $DB;
        $courseid = $event->courseid;
        $coursecontext = context_course::instance($courseid);
        $userid = $event->relateduserid;
        $gmail = $this->get_google_authenticated_users_gmail($userid);
        $deletecalls = array();
        if ($gmail) {
            $filerecs = $DB->get_records('repository_gdrive_references', array('courseid' => $courseid), '', 'id, reference');
            if ($filerecs) {
                foreach ($filerecs as $filerec) {
                    if (has_capability('moodle/course:view', $coursecontext, $userid)) {
                        // Manager; do nothing.
                    } else {
                        // Unenrolled user; delete permission.
                        try {
                            $permissionid = $this->service->permissions->getIdForEmail($gmail);
                            $permission = $this->service->permissions->get($filerec->reference, $permissionid->id);
                            if ($permission->role != 'owner') {
                                $call = new stdClass();
                                $call->fileid = $filerec->reference;
                                $call->permissionid = $permissionid->id;
                                $deletecalls[] = $call;
                                if (count($deletecalls) == 1000) {
                                    $this->batch_delete_permissions($deletecalls);
                                    $deletecalls = array();
                                }
                            }
                        } catch (Exception $e) {
                            debugging($e);
                        }
                    }
                }
            }
        }

        // Call any remaining batch requests.
        if (count($deletecalls) > 0) {
            $this->batch_delete_permissions($deletecalls);
        }
    }

    /**
     * Delete database records when user deleted.
     * Don't worry about Google Drive permissions - role_unassigned handles.
     *
     * @param unknown $event
     */
    private function user_deleted($event) {
        global $DB;
        $userid = $event->relateduserid;
        $token = $DB->get_record('repository_gdrive_tokens', array('userid' => $userid), 'refreshtokenid');
        $this->client->revokeToken($token->refreshtokenid);
        $DB->delete_records('repository_gdrive_tokens', array ('userid' => $userid));
    }

    /**
     * Get site admin ids and gmails.
     * 
     * @return array of siteadmins
     */
    private function get_siteadmins() {
        global $DB;
        $sql = "SELECT rgt.userid, rgt.gmail
                FROM mdl_repository_gdrive_tokens rgt
                JOIN mdl_config cfg
                ON cfg.name = 'siteadmins'
                WHERE find_in_set(rgt.userid, cfg.value) > 0;";
        $siteadmins = $DB->get_records_sql($sql);
        return $siteadmins;
    }

    /**
     * Get users (ids and gmails) for users in specified course.
     *
     * @param courseid $courseid
     * @return array of users
     */
    private function get_google_authenticated_users($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT rgt.userid, rgt.gmail
                FROM {user} u
                JOIN {repository_gdrive_tokens} rgt
                ON u.id = rgt.userid
                JOIN {user_enrolments} ue
                ON ue.userid = u.id
                JOIN {enrol} e
                ON (e.id = ue.enrolid AND e.courseid = :courseid)
                WHERE u.deleted = 0";
        $users = $DB->get_records_sql($sql, array('courseid' => $courseid));
        return $users;
    }
    
    /**
     * Get userids for users in specified course.
     *
     * @param courseid $courseid
     * @return array of userids
     */
    private function get_google_authenticated_userids($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT rgt.userid
                FROM {user} u
                JOIN {repository_gdrive_tokens} rgt
                ON u.id = rgt.userid
                JOIN {user_enrolments} ue
                ON ue.userid = u.id
                JOIN {enrol} e
                ON (e.id = ue.enrolid AND e.courseid = :courseid)
                WHERE u.deleted = 0";
        $users = $DB->get_recordset_sql($sql, array('courseid' => $courseid));
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
    private function get_google_authenticated_users_gmail($userid) {
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

    /**
     * Get file ids for file resource course modules.
     *
     * @param unknown $cmid
     * @return void|NULL[]|boolean
     */
    private function get_fileids($cmid) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type' => 'googledrive'), 'id');
        $id = $googledriverepo->id;
        if (empty($id)) {
            debugging('Could not find any instance of the repository');
            return false;
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
        }

        return false;
    }

    /**
     * Verify that user has manageactivities capability.
     * Used to determine writer Google Drive permissions.
     *
     * @param unknown $context
     * @param unknown $user
     * @return boolean
     */
    private function writer_capability($context, $user) {
        return has_capability('moodle/course:manageactivities', $context, $user);
    }

    /**
     * Batch insert Google Drive permissions.
     * Batches must be limited to 1000 calls - calling function should handle check.
     *
     * @param unknown $calls
     */
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

    /**
     * Batch delete Google Drive permissions.
     * Batches must be limited to 1000 calls - calling function should handle check.
     *
     * @param unknown $calls
     */
    private function batch_delete_permissions($calls) {
        $this->client->setUseBatch(true);
        try {
            $batch = $this->service->createBatch();

            foreach ($calls as $call) {
                $request = $this->service->permissions->delete($call->fileid, $call->permissionid);
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

    /**
     * Batch patch Google Drive permissions.
     * Batches must be limited to 1000 calls - calling function should handle check.
     *
     * @param unknown $calls
     */
    private function batch_patch_permissions($calls) {
        $this->client->setUseBatch(true);
        try {
            $batch = $this->service->createBatch();

            foreach ($calls as $call) {
                $patchedpermission = new Google_Service_Drive_Permission();
                $patchedpermission->setRole($call->role);
                $request = $this->service->permissions->patch($call->fileid, $call->permissionid, $patchedpermission);
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

    /**
     * Get course module records for specified section.
     *
     * @param section number $sectionnumber
     * @return array of course module records
     */
    private function get_section_course_modules($sectionnumber) {
        global $DB;
        $sql = "SELECT cm.id, cm.visible
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
