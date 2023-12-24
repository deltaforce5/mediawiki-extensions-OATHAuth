forked from https://github.com/wikimedia/mediawiki-extensions-OATHAuth

Extension page: https://www.mediawiki.org/wiki/Extension:OATHAuth

# Installation
- Download and move the extracted OATHAuth folder to your extensions/ directory.
- Developers and code contributors should install the extension from Git instead, using:
```
 cd extensions/
 git clone https://github.com/deltaforce5/mediawiki-extensions-OATHAuth.git
 mv mediawiki-extensions-OATHAuth OATHAuth
```

- Only when installing from Git, run Composer to install PHP dependencies, by issuing <code>composer install --no-dev</code> in the extension directory. (See task T173141 for potential complications.)
```
 cd
 composer install --no-dev
```

- Add the following code at the bottom of your LocalSettings.php file:
```
 wfLoadExtension( 'OATHAuth' );
```

PS: To avoid conflict with the "standard" OATHAuth extension, you may want to change the extension directory to something different than <b>OATHAuth</b>, like <b>OATHAuth_mod</b> and to change the <code>wfLoadExtension</code> directive accordingly

- Run the update script which will automatically create the necessary database tables that this extension needs.
```
 cd ../maintenance
 php update.php
```

- Configure as required.

- It is strongly recommended to setup caching when using OATHAuth. This will improve performance, but also the security of your wiki if you're using OATHAuth. If you are only running one application/web server and have php-apcu installed, and no specific cache configured, MediaWiki will likely fallback to using APCu. If you are using multiple application/web server it is advised to setup local cluster caching that can be used by all hosts. Examples include Memcached.

- Done – Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

# Sample config
This config enables users with no 2FA active to browse preferences and the OATHAuth extension page only
```
$wgOATHExclusiveRights = ['read'];
$wgOATHRequiredForGroups = ['user'];
$wgWhitelistRead = [
   'Special:UserLogin',
   'Special:Preferences',
   'Special:Manage Two-factor authentication',
   'Special:OATHAuth',
   'MediaWiki:Common.css',
   'MediaWiki:Common.js'
]
```
