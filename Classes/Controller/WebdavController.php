<?php

class tx_Webdav_Controller_WebdavController {
	/**
	 * @var string The base uri of the server
	 */
	private $baseUri = null;

	/**
	 * @var Sabre_HTTP_BasicAuth
	 */
	private $auth;

	/**
	 * @var Sabre_DAV_ObjectTree
	 */
	private $objectTree;

	function main() {
		if(substr($_SERVER["PATH_INFO"],0,4) === '/dav') {
			$this->baseUri = $_SERVER["SCRIPT_NAME"] . '/dav';
			$this->initBeUser();
			$this->initDav();
			if($this->authenticate()) {
				$this->buildVFS();
				$this->handleRequest();
			}
			die();
		} elseif(substr($_SERVER["PATH_INFO"],0,15) === '/cyberduck.duck') {
			$this->initBeUser();
			$this->sendCyberduckBookmark();
			die();
		}
	}
	function sendCyberduckBookmark() {
		header('Content-Type:application/octet-stream');
		header('Content-Disposition: attachment;filename="cyber.duck"');
		echo '<?xml version="1.0" encoding="UTF-8"?>';
		echo '
		<plist>
		  <dict>
			<key>Protocol</key>
			<string>dav</string>
			<key>Nickname</key>
			<string>' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] . '</string>
			<key>Hostname</key>
			<string>' . t3lib_div::getIndpEnv('HTTP_HOST') .'</string>
			<key>Port</key>
			<string>80</string>
			<key>Username</key>
			<string>' . 'admin' . '</string>
			<key>Path</key>
			<string>' . dirname(t3lib_div::getIndpEnv('REQUEST_URI')) .'/dav/</string>
			<key>Access Timestamp</key>
			<string>' . time() . '</string>
		  </dict>
		</plist>
		';
	}
	function initBeUser() {
		global $BE_USER, $TYPO3_CONF_VARS;
			// create a new backendusersession ;) need to use basic auth here
		$BE_USER = t3lib_div::makeInstance('t3lib_tsfeBeUserAuth'); // New backend user object
		$BE_USER->warningEmail = $TYPO3_CONF_VARS['BE']['warning_email_addr'];
		$BE_USER->lockIP = $TYPO3_CONF_VARS['BE']['lockIP'];
		$BE_USER->auth_timeout_field = intval($TYPO3_CONF_VARS['BE']['sessionTimeout']);
		$BE_USER->OS = TYPO3_OS;
			// deactivate caching for be user
		if(version_compare(TYPO3_version,'4.5','<=')) {
			$BE_USER->userTS_dontGetCached = 1;
		}
		$BE_USER->start();
		$BE_USER->unpack_uc('');
	}
	function initDav() {
			// sabredav initialization
		require_once (t3lib_extMgm::extPath('webdav') . 'Resources/Contrib/SabreDav/lib/Sabre/autoload.php');
		require_once (t3lib_extMgm::extPath('webdav') . 'Classes/class.tx_webdav_rootDirs.php');
		require_once (t3lib_extMgm::extPath('webdav') . 'Classes/class.tx_webdav_browser_plugin.php');
		require_once (t3lib_extMgm::extPath('webdav') . 'Classes/class.tx_webdav_permission_plugin.php');
	}
	function authenticate() {
		global $BE_USER;
		$this->auth = new Sabre_HTTP_BasicAuth();
		$result = $this->auth->getUserPass();
		$BE_USER->setBeUserByName($result[0]);

		if (!$result || md5($result[1])!=$BE_USER->user['password']) {
			$this->auth->setRealm('WebDav for TYPO3');
			$this->auth->requireLogin();

				// Render template with fluid
			$base            = dirname(dirname($this->baseUri)) == '/' ? '/' : dirname(dirname($this->baseUri)) . '/';
			$extRoot   = $base . t3lib_extMgm::siteRelPath('webdav');
			$typo3root = $base . 'typo3/';
			$view = t3lib_div::makeInstance('Tx_Fluid_View_StandaloneView');
			$view->setTemplatePathAndFilename(t3lib_extMgm::extPath('webdav').'Resources/Public/Templates/accessdenied.html');
				//asign
			$view->assign('extRoot', $extRoot);
			$view->assign('typo3Root', $typo3root);
			$view->assign('sabre', array(
					'version'   => Sabre_DAV_Version::VERSION,
					'stability' => Sabre_DAV_Version::STABILITY,
				)
			);
			echo $view->render();
			return false;
		} else {
			return true;
		}
	}
	function buildVFS() {
		global $BE_USER, $TYPO3_CONF_VARS, $TYPO3_DB;
			// fetch filemounts
		$BE_USER->fetchGroupData();
		$fileMounts = $BE_USER->returnFilemounts();
	//--------------------------------------------------------------------------
	// create virtual directories for the filemounts in typo3
		$mounts     = array();
		foreach($fileMounts as $fileMount) {
			#$mounts[] = $m = new ks_sabredav_rootDirs($fileMount['path']);
			#$m->setName($fileMount['name'].'---'.htmlspecialchars($fileMount['path']));
			$mounts[] = $m = new Sabre_DAV_FS_Directory($fileMount['path']);
		}
		//----------------------------------------------------------------------
		// add special folders for admins
		if($BE_USER->isAdmin()) {
			//------------------------------------------------------------------
			// add root folder
			if(is_dir(PATH_site)) {
				$mounts[] = $m = new tx_webdav_rootDirs(PATH_site);
				$m->setName('T3 - PATH_site');
			}
			//------------------------------------------------------------------
			// add extension folder
			if(is_dir(PATH_typo3conf . 'ext/')) {
				$mounts[] = $m = new tx_webdav_rootDirs(PATH_site.'typo3conf/ext/');
				$m->setName('T3 - PATH_typo3conf-ext');
			}
			//------------------------------------------------------------------
			// add typo3conf folder
			if(is_dir(PATH_typo3conf)) {
				$mounts[] = $m = new tx_webdav_rootDirs(PATH_site.'typo3conf/ext/');
				$m->setName('T3 - PATH_typo3conf');
			}
			//------------------------------------------------------------------
			// add t3lib folder
			if(is_dir(PATH_t3lib)) {
				$mounts[] = $m = new tx_webdav_rootDirs(PATH_site.'typo3conf/ext/');
				$m->setName('T3 - PATH_t3lib');
			}
			//------------------------------------------------------------------
			// add typical template folder
			if(is_dir(PATH_site.'fileadmin/templates/')) {
				$mounts[] = $m = new tx_webdav_rootDirs(PATH_site.'fileadmin/templates/');
				$m->setName('T3 - fileadmin - templates');
			}
			//------------------------------------------------------------------
			// add user home folder
			if(is_dir($TYPO3_CONF_VARS['BE']['userHomePath'])) {
				$userDirs     = array();
				$userDirArray = $TYPO3_DB->exec_SELECTgetRows(
					'uid,username',
					'be_users',
					'',
					'',
					'username'
				);

				foreach($userDirArray as $userDir) {
					if(is_dir($TYPO3_CONF_VARS['BE']['userHomePath'].'/'.$userDir['uid'])) {
						$userDirs[] = $m = new tx_webdav_rootDirs($TYPO3_CONF_VARS['BE']['userHomePath'].'/'.$userDir['uid']);
						$m->setName($userDir['username']);
					}
				}
				unset($userDirArray);
				if(count($userDirs)>0) {
					$mounts[] = $m = new Sabre_DAV_SimpleCollection('T3 - userHomePath',$userDirs);
				}
			}

			//------------------------------------------------------------------
			// add group folder
			if(is_dir($TYPO3_CONF_VARS['BE']['groupHomePath'])) {
				$groupDirs     = array();
				$groupDirArray = $TYPO3_DB->exec_SELECTgetRows(
					'uid,title',
					'be_groups',
					'',
					'',
					'title'
				);
				foreach($groupDirArray as $groupDir) {
					if(is_dir($TYPO3_CONF_VARS['BE']['groupHomePath'].'/'.$groupDir['uid'])) {
						$groupDirs[] = $m = new tx_webdav_rootDirs($TYPO3_CONF_VARS['BE']['groupHomePath'].'/'.$groupDir['uid']);
						$m->setName($groupDir['title']);
					}
				}
				unset($groupDirArray);
				if(count($groupDirs)>0) {
					$mounts[] = $m = new Sabre_DAV_SimpleCollection('T3 - groupHomePath',$groupDirs);
				}

			}
		}
		$root       = new Sabre_DAV_SimpleCollection('root',$mounts);
		$this->objectTree = new Sabre_DAV_ObjectTree($root);
	}
	function handleRequest() {
		// configure dav server
		$server = new Sabre_DAV_Server($this->objectTree);
		
		$server->setBaseUri($this->baseUri);
		#$server->setBaseUri('typo3conf/ext/ks_sabredav/webdavserver.php/');
		//----------------------------------------------------------------------
		// add plugins
		$lockBackend = new Sabre_DAV_Locks_Backend_FS('data');
		$server->addPlugin(new Sabre_DAV_Mount_Plugin());
		$server->addPlugin(new Sabre_DAV_Locks_Plugin($lockBackend));
		#$server->addPlugin(new tx_webdav_browser_plugin());
		$server->addPlugin(new tx_webdav_permission_plugin());
		// for 1.2.x alpha only
		#$server->addPlugin(new Sabre_DAV_Browser_GuessContentType());
		//----------------------------------------------------------------------
		// start server
		$server->exec();
	}
}