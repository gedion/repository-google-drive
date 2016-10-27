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

    private static $GOOGLE_LIVE_DRIVE_TYPES = array('document', 'presentation', 'spreadsheet');
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
        $this->client->setScopes(array(Google_Service_Drive::DRIVE_FILE,Google_Service_Drive::DRIVE, 'email'));
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
     * Checks whether the user is authenticate or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        global $USER, $DB;
        $googlerefreshtokens = $DB->get_record('repository_gdrive_tokens', array ('userid'=>$USER->id));

        if ($googlerefreshtokens && !is_null($googlerefreshtokens->refreshtokenid)) {
            try {
                $this->client->refreshToken($googlerefreshtokens->refreshtokenid);
            } catch(Exception $e) {
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
     * @param string $search_text text to search for.
     * @param int $page search page.
     * @return array of results.
     */
    public function search($search_text, $page = 0) {
        $path = $this->build_node_path('root', get_string('pluginname', 'repository_googledrive'));
        $path = $this->build_node_path('search', $search_text, $path);

        // Query the Drive.
        $q = "fullText contains '" . str_replace("'", "\'", $search_text) . "'";
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
                    $source = $item['downloadUrl'];
                } else {
                    // The file is probably a Google Doc file, we get the corresponding export link.
                    // This should be improved by allowing the user to select the type of export they'd like.
                    $type = str_replace('application/vnd.google-apps.', '', $item['mimeType']);
                    $title = '';
                    $exportType = '';
                    switch ($type){
                        case 'document':
                            $title = $item['title'] . '.rtf';
                            $exportType = 'application/rtf';
                            break;
                        case 'presentation':
                            $title = $item['title'] . '.pptx';
                            $exportType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                            break;
                        case 'spreadsheet':
                            $title = $item['title'] . '.csv';
                            $exportType = 'text/csv';
                            break;
                    }
                    // Skips invalid/unknown types.
                    if (empty($title) || !isset($item['exportLinks'][$exportType])) {
                        continue;
                    }
                    $source = $item['exportLinks'][$exportType];
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
        $url = $this->get_doc_url_by_doc_id($source, $downloadUrl = true);
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
    public function get_link($ref){
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
        return FILE_INTERNAL | FILE_EXTERNAL | FILE_REFERENCE;
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
            $downloadUrl = true;
            $url = $this->get_doc_url_by_doc_id($id, $downloadUrl);
            header('Location: ' . $url);
            die;
        } else {
            $file = $this->service->files->get($id);
            $type = str_replace('application/vnd.google-apps.', '', $file['mimeType']);
            if (in_array($type, self::$GOOGLE_LIVE_DRIVE_TYPES)) {
                redirect($file->alternateLink);
            } else {
                header("Location: " . $file->downloadUrl . '&access_token='. $token->access_token);
                die;
            }
        }
    }

    private function get_doc_url_by_doc_id($id, $download_url=false) {
        $file = $this->service->files->get($id);
        if (isset($file['fileExtension'])) {
            if ($download_url) {
                $token = json_decode($this->get_access_token());
                return $file['downloadUrl']. '&access_token='. $token->access_token;
            } else {
                return $file['webContentLink'];
            }
        } else {
            // The file is probably a Google Doc file, we get the corresponding export link.
            // This should be improved by allowing the user to select the type of export they'd like.
            $type = str_replace('application/vnd.google-apps.', '', $file['mimeType']);
            $exportType = '';
            switch ($type){
                case 'document':
                    $exportType = 'application/rtf';
                    break;
                case 'presentation':
                    $exportType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    break;
                case 'spreadsheet':
                    $exportType = 'text/csv';
                    break;
            }
            // Skips invalid/unknown types.
            if (!isset($file['exportLinks'][$exportType])) {
                throw new repository_exception('repositoryerror', 'repository', '', 'Uknown file type');
            }
            return $file['exportLinks'][$exportType];
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
        $this->client->revokeToken();
        $this->delete_refresh_token();
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
        $DB->delete_records('repository_gdrive_tokens', array ('userid'=>$USER->id));
    }

    public function get_name() {
        return 'repository_googledrive';
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

        if(!is_null($newdata->refreshtokenid) && !is_null($newdata->gmail)) {
            $rectoken = $DB->get_record('repository_gdrive_tokens', array ('userid'=>$USER->id));
            if ($rectoken) {
                $newdata->id = $rectoken->id;
                if($newdata->gmail === $rectoken->gmail){
                    unset($newdata->gmail);
                }
                $DB->update_record('repository_gdrive_tokens', $newdata);
            } else {
                $newdata->userid = $USER->id;
                $newdata->gmail_active = 1;
                $DB->insert_record('repository_gdrive_tokens', $newdata);
            }
        }
    }

    /**
    * Retrieve a list of permissions.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to retrieve permissions for.
    * @return Array List of permissions.
    */
   function retrieve_file_permissions($fileId) {
     try {
       $permissions = $this->service->permissions->listPermissions($fileId);
       return $permissions->getItems();
     } catch (Exception $e) {
         //print("Can't access the file and so it's permissions.<br/>");
        print "An error occurred: " . $e->getMessage();
        print "<br/>";
     }
     return NULL;
   }

    /**
    * Print the Permission ID for an email address.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $email Email address to retrieve ID for.
    */
   function print_permission_id_for_email($gmail) {
     try {
       $permissionId = $this->service->permissions->getIdForEmail($gmail);
       return $permissionId->getId();
     } catch (Exception $e) {
       print "An error occurred: " . $e->getMessage();
     }
   }

   /**
    * Print information about the specified permission.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to print permission for.
    * @param String $permissionId ID of the permission to print.
    */
   public function print_user_permission($fileId, $permissionId) {
     try {
       $permission = $this->service->permissions->get($fileId, $permissionId);
       print "Name: " . $permission->getName();
       print "<br/>";
       print "Role: " . $permission->getRole();
       print "<br/>";
       print "permission: " . $permissionId;
       print "<br/>";
       $additionalRoles = $permission->getAdditionalRoles();
       if(!empty($additionalRoles)) {
         foreach($additionalRoles as $additionalRole) {
           print "Additional role: " . $additionalRole;
         }
       }
     } catch (Exception $e) {
         print"User is not permitted to access the resource.<br/>";
        //print "An error occurred: " . $e->getMessage();
     }
   }

   /**
    * Insert a new permission.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to insert permission for.
    * @param String $value User or group e-mail address, domain name or NULL for
                          "default" type.
    * @param String $type The value "user", "group", "domain" or "default".
    * @param String $role The value "owner", "writer" or "reader".
    * @return Google_Servie_Drive_Permission The inserted permission. NULL is
    *     returned if an API error occurred.
    */
   function insert_permission($fileId, $value, $type, $role) {
     $name = explode('@', $value);
     $gmail = $value;
     $newPermission = new Google_Service_Drive_Permission();
     $newPermission->setValue($value);
     $newPermission->setType($type);
     $newPermission->setRole($role);
     $newPermission->setEmailAddress($gmail);
     $newPermission->setDomain($name[1]);
     $newPermission->setName($name[0]);
     $optParams = array(
         'sendNotificationEmails' => false
     );
     try {
         return $this->service->permissions->insert($fileId, $newPermission, $optParams);
     } catch (Exception $e) {
       //print("Insert permission failed. Please retry with approriate permission role.");
       print "An error occurred: " . $e->getMessage();
     }
     return NULL;
   }

   /**
    * Remove a permission.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to remove the permission for.
    * @param String $permissionId ID of the permission to remove.
    */
    function remove_permission($fileId, $permissionId) {
     try {
         $permission = $this->service->permissions->get($fileId, $permissionId);
         $role = $permission->getRole();
         if ($role != 'owner') {
             $this->service->permissions->delete($fileId, $permissionId);
             print("Succefully deleted the specified permission");
         }
     } catch (Exception $e) {
       debugging("Delete failed...");
       print "<br/> An error occurred: " . $e->getMessage() . "<br/>";
     }
   }

   /**
    * Update a permission's role.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to update permission for.
    * @param String $permissionId ID of the permission to update.
    * @param String $newRole The value "owner", "writer" or "reader".
    * @return Google_Servie_Drive_Permission The updated permission. NULL is
    *     returned if an API error occurred.
    */
   function update_permission($fileId, $permissionId, $newRole) {
     try {
       // First retrieve the permission from the API.
       $permission = $this->service->permissions->get($fileId, $permissionId);
       $value = $permission->getValue();
       $type = $permission->getType();
       $this->remove_permission($fileId, $permissionId);
       return $this->insert_permission($fileid, $value, $type, $newRole);
     } catch (Exception $e) {
       print "An error occurred: " . $e->getMessage();
     }
     return NULL;
   }

   /**
    * Patch a permission's role.
    *
    * @param Google_Service_Drive $service Drive API service instance.
    * @param String $fileId ID of the file to update permission for.
    * @param String $permissionId ID of the permission to patch.
    * @param String $newRole The value "owner", "writer" or "reader".
    * @return Google_Servie_Drive_Permission The patched permission. NULL is
    *     returned if an API error occurred.
    */
   function patch_permission($fileId, $permissionId, $newRole) {
     $patchedPermission = new Google_Service_Drive_Permission();
     $patchedPermission->setRole($newRole);
     try {
       return $this->service->permissions->patch($fileId, $permissionId, $patchedPermission);
     } catch (Exception $e) {
       print "An error occurred: " . $e->getMessage();
     }
     return NULL;
   }

    /**
     * Sync google resource permissions based on various events.
     *
     * @param \core\event\* $event The event fired.
     */
   function manage_resources($event) {
        global $DB;
        $permissions = null;
        $courseid = $event->courseid;
        $course = $DB->get_record('course', array('id' => $courseid));
        switch($event->eventname) {
            case '\core\event\course_updated':
                $usersemails = $this->get_google_authenticated_users($courseid);
                $resources  = $this->get_resources($courseid);
                foreach ($resources as $fileid) {
                    foreach($usersemails as $email) {
                        if($course->visible == 1) {
                                $permissions[] = $this->insert_permission($fileid, $email, 'user', 'reader');
                        } else {
                                $permissionid = $this->print_permission_id_for_email($email);
                                $permissions[] = $this->remove_permission($fileid, $permissionid);
                        }
                    }
                }
                break;
            case '\core\event\course_module_created':
            case '\core\event\course_module_updated':
                $fileid = current($this->get_resources($courseid, $event->contextinstanceid));
                $usersemails = $this->get_google_authenticated_users($courseid);
                foreach($usersemails as $email) {
                    if($course->visible == 1) {
                      $permissions = $this->insert_permission($fileid, $email, 'user', 'reader');
                    }
                }
                break;
            case '\core\event\role_assigned':
                $email = $this->get_google_authenticated_users_email($event->relateduserid);
                $resources  = $this->get_resources($courseid);
                foreach ($resources as $fileid) {
                    $permissions = $this->insert_permission($fileid, $email, 'user', 'reader');
                }
                break;
            case '\core\event\role_unassigned':
                $email = $this->get_google_authenticated_users_email($event->relateduserid);
                $resources  = $this->get_resources($courseid);
                foreach ($resources as $fileid) {
                    $permissionid = $this->print_permission_id_for_email($email);
                    $permissions[] = $this->remove_permission($fileid, $permissionid);
                }
                break;
            case '\core\event\course_module_deleted':
                $fileid = current($this->get_resources($courseid, $event->contextinstanceid));
                $usersemails = $this->get_google_authenticated_users($courseid);
                foreach($usersemails as $email) {
                    $permissions[] = $this->remove_permission($fileid, $email);
                }
                break;
        }
        return $permissions;
   }

   private static function get_google_authenticated_users_email($userid) { global $DB;
            $googlerefreshtoken = $DB->get_record('repository_gdrive_tokens', array ('userid'=> $userid));
            return $googlerefreshtoken->gmail;
    }

    private function get_resources($courseid, $contextinstanceid=null) {
        global $DB;
        $googledriverepo = $DB->get_record('repository', array ('type'=>'googledrive'));
        $id = $googledriverepo->id;
        if (empty($id)) {
            // We did not find any instance of googledrive.
            mtrace('Could not find any instance of the repository');
            return;
        }

        $sql = "SELECT f.contextid, r.reference
            FROM {files_reference} r
            LEFT JOIN {files} f
            ON f.referencefileid = r.id
            WHERE r.repositoryid = :repoid
            AND f.referencefileid IS NOT NULL
            AND NOT (f.component = :component
            AND f.filearea = :filearea)";
        $resources = array();
        $filerecords = $DB->get_recordset_sql($sql, array('component' => 'user', 'filearea' => 'draft', 'repoid' => $id));
        foreach ($filerecords as $filerecord) {
            $docid = $filerecord->reference;
            list($context, $course, $cm) = get_context_info_array($filerecord->contextid);
            if($course->id == $courseid && is_null($contextinstanceid) or
                $course->id == $courseid && $cm->id == $contextinstanceid) {
                $resources[] = $docid;
            }
        }
        return $resources;
    }

    private function get_google_authenticated_users($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT grt.gmail
                  FROM {user} eu1_u
                  JOIN {repository_gdrive_tokens} grt
                        ON eu1_u.id = grt.userid
                  JOIN {user_enrolments} eu1_ue
                       ON eu1_ue.userid = eu1_u.id
                  JOIN {enrol} eu1_e
                       ON (eu1_e.id = eu1_ue.enrolid AND eu1_e.courseid = :courseid)
                WHERE eu1_u.deleted = 0 AND eu1_u.id <> :guestid ";
        $users = $DB->get_recordset_sql($sql, array('courseid' => $courseid, 'guestid' => '1'));
        $usersarray = array();
        foreach($users as $user) {
            $usersarray[] = $user->gmail;
        }
        return $usersarray;
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
            navigation_node::TYPE_SETTING, null, 'monitor', new pix_icon('i/settings', ''));

    if (isset($subsnode) && !empty($navigation)) {
        $navigation->add_node($subsnode);
    }
}
// Icon from: http://www.iconspedia.com/icon/google-2706.html.
