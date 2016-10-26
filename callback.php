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
 * Repository instance callback script
 *
 * @since Moodle 2.0
 * @package    repository
 * @subpackage googledrive
 * @copyright  2009 Dongsheng Cai <dongsheng@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');

require_login();

// Call opener window to refresh repository
// the callback url should be something like this:
// http://xx.moodle.com/repository/repository_callback.php?repo_id=1&sid=xxx
// sid is the attached auth token from external source
// If Moodle is working on HTTPS mode, then we are not allowed to access
// parent window, in this case, we need to alert user to refresh the repository
// manually.
$strhttpsbug = get_string('cannotaccessparentwin', 'repository');
$strrefreshnonjs = get_string('refreshnonjsfilepicker', 'repository');
$js = <<<EOD
<html>
<head>
    <script type="text/javascript">
    if(window.opener){
        opener.location.reload();
        //window.close breaks behat tests
        if(window.name != 'behat_repo_auth') {
            window.close();
        }
    } else {
        alert("{$strhttpsbug }");
    }
    </script>
</head>
<body>
    <noscript>
    {$strrefreshnonjs}
    </noscript>
</body>
</html>
EOD;

die($js);
