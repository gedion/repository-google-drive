INSTALLATION
============

1. Moodle Installation<br />
Install Moodle 3.0 following your normal procedure or using the Moodle install guide https://docs.moodle.org/30/en/Installation_quick_guide<br />
Note: you have installed Moodle in "dirroot".<br />


2. Install repository Google Drive Extended<br />
cd "dirroot"/repository<br />
git clone https://github.com/gedion/repository-google-drive googledrive<br />
cd ..<br />
php admin/cli/upgrade.php<br /> 
//validate that repository/google_drive installed properly<br />
git apply repository/googledrive/google_drive_core_changes.patch<br />

3. Enable Google Drive<br />
Go to site administration > plugins > repositories > Google Drive Extended 
Change to Enabled and visible<br />
Input ClientID and Secret from API dashboard (See Google set up section below)<br />
User menu > preferences > manage google account > connect<br />
Allow access and you should now be able to use your Google drive files in the file picker<br />

Google set up:<br />
The instructions on https://docs.moodle.org/30/en/Google_OAuth_2.0_setup and the google page it links to are pretty straight forward, but here is my version.<br />
Go to https://console.developers.google.com/apis/library and sign in to your google account<br />
Create a project - e.g. "Moodle Google"<br />
On the left, click on Credentials<br />
Select a project and choose "Moodle Google"<br />
Go to the OAuth consent screen tab<br />
Your email address should already be there, enter it if it isn't.<br />
Enter a product name, something like "Moodle Google Drive"<br />
Press Save<br />
In the Credentials tab, click Create credentials and choose OAuth client ID<br />
Choose Web application<br />
Change the name if you want, and leave Authorized JavaScript origins empty<br />
For Authorized redirect URIs, put <Moodle web root>/admin/oauth2callback.php<br />
Press Create<br />
This will give you the client ID and secret you need to input on the moodle site<br />
Go back to the API Manager Dashboard and click Enable API<br />
Under Google Apps APIs choose Drive API then click Enable<br />

USE<br />
===<br />

1) Go into a course<br /> 
2) Add an activity or resource<br />
3) Select File under Resources<br />
4) Enter a Name (and any other preferred data)<br />
5) Add a file in the Content area<br />
6) Select Google Drive Extended (repository_googledrive) from left panel of window (your Google Drive files should automatically appear in the right panel of the window, since your Google account has already been connected)<br />
7) Select Create an alias/shortcut to the file<br />
8) Save<br />

BEHAT<br />
===

If you are interested in running the behat(somewhat hardcoded at the moment) tests, your config.php file will require the below settings. Message me and I will provide you with the values.<br /> 

$CFG->forced_plugin_settings['googledrive']['clientid'] = '&lt;clientid&gt;';<br />
$CFG->forced_plugin_settings['googledrive']['secret'] = '&lt;secret&gt;';<br />
$CFG->forced_plugin_settings['googledrive']['behatuser'] = '&lt;gmailaccount&gt;';<br />
$CFG->forced_plugin_settings['googledrive']['behatpassword'] = '&lt;gmailpassword&gt;';<br />
