INSTALLATION
============

1. Initial Set Up  
Starting in home directory  
cd Projects  
git clone git://git.moodle.org/moodle.git  
cd moodle
git checkout MOODLE_30_STABLE  
git pull   
git submodule init && git submodule update   
cd /var/www/  
sudo ln -s /home/kylematter/Projects/moodle/  
sudo vim /etc/apache2/apache2.conf  
Add the following directory part underneath all of the other sections just like it.  
The path to your moodle directory is probably /home/yourname/Projects/moodle
<Directory /path/to/your/moodle/directory>  
        Options Indexes FollowSymLinks  
        AllowOverride None  
        Require all granted  
</Directory>  
Add this to the bottom of the apache config file using your moodle directory like before  
Alias /gdoc /path/to/your/moodle/directory  
sudo service apache2 restart  

2. Set up empty database and grant permissions  
Run the following commands in ~/Projects/moodle
If they don't work, add -p to the end and use your password, possibly 'test'  
mysql --user=root --execute="CREATE DATABASE moodlegdoc DEFAULT CHARACTER SET UTF8 COLLATE utf8_unicode_ci;"  
mysql --user=root --execute="GRANT ALL PRIVILEGES ON moodlegdoc.* TO 'moodle'@'localhost' IDENTIFIED BY 'test'; FLUSH PRIVILEGES;"  

3. Moodle Installation  
go to localhost/moodle
Install moodle while making the noted changes. These may not all be totally necessary but they worked for me.  
change data directory to /opt/moodledata  
change database type to mariadb  
change database name to moodlegdoc  
user moodle  
password test  
create then copy and paste the information it gives you into a new file called config.php in ~/Projects/moodle  
Finish going through the moodle installation, should be straight forward  

4. Install repository google drive  
cd ~/Projects/moodle/repository  
git clone https://github.com/gedion/repository-google-drive  
mv repository-google-drive googledrive  
cd ..  
php admin/cli/upgrade.php   
//validate that repository/google_drive installed properly  
git apply repository/googledrive/google_drive_core_changes.patch  

5. Enable Google Drive  
Go to site administration > plugins > repositories > Google Drive Extended 
Change to Enabled and visible  
Input ClientID and Secret from API dashboard (See Google set up section)  
User menu > preferences > manage google account > connect  
Allow access and you should now be able to use your Google drive files in the file picker  

Google set up:  
The instructions on https://docs.moodle.org/30/en/Google_OAuth_2.0_setup and the google page it links to are pretty straight forward, but here is my version.  
Go to https://console.developers.google.com/apis/library and sign in to your google account  
Create a project called morsleucla  
On the left, click on Credentials  
Select a project and choose morsleucla  
Go to the OAuth consent screen tab  
Your email address should already be there, enter it if it isn't.  
Enter a product name, something like moodle google docs  
Press Save  
In the Credentials tab, click Create credentials and choose OAuth client ID  
Choose Web application  
Change the name if you want, and leave Authorized JavaScript origins empty  
For Authorized redirect URIs, put http://localhost/gdoc/admin/oauth2callback.php  
Press Create  
This will give you the client ID and secret you need to input on the moodle site  
Go back to the API Manager Dashboard and click Enable API  
Under Google Apps APIs choose Drive API then click Enable  

USE  
===  

1) Go into a course   
2) Add an activity or resource  
3) Select File under Resources  
4) Enter a Name (and any other preferred data)  
5) Add a file in the Content area  
6) Select Google Drive Extended (repository_googledrive) from left panel of window (your Google Drive files should automatically appear in the right panel of the window, since your Google account has already been connected)  
7) Select Create an alias/shortcut to the file  
8) Save  

BEHAT  
===

If you are interested in running the behat(somewhat hardcoded at the moment) tests, your config.php file will require the below settings. Message me and I will provide you with the values.   

```
$CFG->forced_plugin_settings['googledrive']['clientid'] = '<clientid>' ;
$CFG->forced_plugin_settings['googledrive']['secret'] = '<secret>';
$CFG->forced_plugin_settings['googledrive']['behatuser'] = '<gmailaccount>';
$CFG->forced_plugin_settings['googledrive']['behatpassword'] = '<gmailpassword>';
```
