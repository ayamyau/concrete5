<?
defined('C5_EXECUTE') or die("Access Denied.");

/**
 * @package Core
 * @category Concrete
 * @author Andrew Embler <andrew@concrete5.org>
 * @copyright  Copyright (c) 2003-2008 Concrete5. (http://www.concrete5.org)
 * @license    http://www.concrete5.org/license/     MIT License
 *
 */

/**
 * An object that represents a particular request to the Concrete-powered website. The request object then determines what is being requested, based on the path, and presents itself to the rest of the dispatcher (which loads the page, etc...)
 * @package Core
 * @author Andrew Embler <andrew@concrete5.org>
 * @category Concrete
 * @copyright  Copyright (c) 2003-2008 Concrete5. (http://www.concrete5.org)
 * @license    http://www.concrete5.org/license/     MIT License
 *
 */
class Request {

	private $requestPath;
	private $task;
	private $params;
	private $includeType;
	private $filename;
	private $cID;
	private $cPath;
	
	// parses the current request and returns an 
	// object with tasks, tools, etc... defined in them
	// for use in the dispatcher
	// Thanks to Code Igniter for some of this code (in terms of getenv(), etc...)
	
	private static function parsePathFromRequest($var) {
		$path = (isset($_SERVER[$var])) ? $_SERVER[$var] : @getenv($var);
		if (!$path) {
			return false;
		}
		
		// if the path starts off with dir_rel, we remove it:
		
		if (DIR_REL != '') {
			$dr = trim(DIR_REL, '/');
			$path = trim($path, '/');
			if (strpos($path, $dr) === 0) {
				$path = substr($path, strlen($dr));	
			}
		}
		
		$path = trim($path, '/');
		if (strpos($path, DISPATCHER_FILENAME) === 0) {
			$path = substr($path, strlen(DISPATCHER_FILENAME));	
		}

		$path = trim($path, '/');
		
		if (defined('ENABLE_CMS_FOR_PATH') && ENABLE_CMS_FOR_PATH != '') {
			$path = ENABLE_CMS_FOR_PATH . '/' . $path;
		}
		return $path;
	}
	
	public function __construct($path) {
		$this->requestPath = $path;
		$this->parse();
	}
	
	/** 
	 * Gets a request object for the current request. Parses PATH_INFO as necessary.
	 * @return Request
	 */
	public static function get() {
		static $req;
		if (!isset($req) || C5_ENVIRONMENT_ONLY) {
			$path = false;
			if (defined('SERVER_PATH_VARIABLE')) {
				$path = Request::parsePathFromRequest(SERVER_PATH_VARIABLE);
			}
			if (!$path) {
				$path = Request::parsePathFromRequest('ORIG_PATH_INFO');
			}
			if (!$path) {
				$path = Request::parsePathFromRequest('PATH_INFO');
			}/*
			if (!$path) {
				$path = Request::parsePathFromRequest('REQUEST_URI');
			}*/
			if (!$path) {
				$path = Request::parsePathFromRequest('SCRIPT_NAME');
			}
			if (!$path) {
				$path = Request::parsePathFromRequest('REDIRECT_URL');
			}
			$req = new Request($path);
		}
		return $req;
	}
	
	/** 
	 * our new MVC way of doing things. Parses the collection path using like to find
	 * where the path stops and the parameters start. Enables us to use urls without a
	 * task/param separator in them
	 */
	public function getRequestedPage() {
		$path = $this->getRequestCollectionPath();
		$origPath = $path;
		$r = Cache::get('request_path_page', $path);
		if ($r == false) {
			$r = array();
			$db = Loader::db();
			$cID = false;
			while ((!$cID) && $path) {
				$cID = $db->GetOne('select cID from PagePaths where cPath = ?', $path);
				if ($cID) {
					$cPath = $path;
					break;
				}
				$path = substr($path, 0, strrpos($path, '/'));
			}
			
			/*
			// Get the longest path (viz most specific match) that is contained
			// within the request path
			$db = Loader::db();
			$r = $db->Execute("select cID,cPath from PagePaths where ? LIKE CONCAT(replace(cPath, '_','\_'),'%') ORDER BY LENGTH(cPath) DESC LIMIT 0,1", array($this->getRequestCollectionPath()));
			$r = $r->FetchRow();
			*/
			if ($cID && $cPath) { 
				$r['cID'] = $cID;
				$r['cPath'] = $cPath;
				Cache::set('request_path_page', $origPath, $r);
			}			
		}	
		
		if (is_array($r)) { 
			$req = Request::get();
			$cPath = $r['cPath'];
			$cID = $r['cID'];
			$req->setCollectionPath($cPath);			
			$c = Page::getByID($cID);
		} else {
			$c = new Page();
			$c->loadError(COLLECTION_NOT_FOUND);
		}
		return $c;
	}
	
	private function parse() {
		
		$path = $this->requestPath;
		
		if (isset($_REQUEST['cID']) && intval($_REQUEST['cID']) > 0) {
			$this->cID = $_REQUEST['cID'];
		} else {
			$this->cID = HOME_CID;
		}
		// home page w/param and task
		if (defined('ENABLE_LEGACY_CONTROLLER_URLS') && ENABLE_LEGACY_CONTROLLER_URLS == true) {
			if (preg_match("/^\-\/(.[^\/]*)\/(.*)/i", $path, $matches)) {
				$this->task = $matches[1];
				$this->params = $matches[2];
				return;
			}
	
			// home page w/just task
			if (preg_match("/^\-\/(.[^\/]*)/i", $path, $matches)) {
				$this->task = $matches[1];
				return;
			}
	
			// path + task + params
			if (preg_match("/^(.[^\.]*)\/\-\/(.[^\/]*)\/(.*)/i", $path, $matches)) {
				$this->cPath = $matches[1];
				$this->task = $matches[2];
				$this->params = $matches[3];
				return;
			}
			
			// path + task
			if (preg_match("/^(.[^\.]*)\/\-\/(.[^\/]*)/i", $path, $matches)) {
				$this->cPath = $matches[1];
				$this->task = $matches[2];
				return;
			}
		}
		
		// tools

		$exploded = explode('/', $path);
		if($exploded[0] == 'tools') {
			if($exploded[1] == 'blocks') {
				$this->btHandle = $exploded[2];
				unset($exploded[0]);
				unset($exploded[1]);
				unset($exploded[2]);
				$imploded = implode('/', $exploded);
				if(substr($imploded, -4) == '.php') {
					$this->filename = $imploded;
				} else {
					$this->filename = $imploded . '.php';
				}
				$this->includeType = 'BLOCK_TOOL';
				return;
			}

			if($exploded[1] == 'css' && $exploded[2] == 'themes') {
				unset($exploded[0]);
				unset($exploded[1]);
				unset($exploded[2]);
				$this->filename = 'css.php';
				$this->auxData = new stdClass;
				$this->auxData->theme = $exploded[3];
				unset($exploded[3]);
				$imploded = implode('/', $exploded);
				if(substr($imploded, -4) == '.css') {
					$this->auxData->file = $imploded;
				} else {
					$this->auxData->file = $imploded . '.css';
				}
				$this->includeType = 'CONCRETE_TOOL';
				return;
			}
			
			if($exploded[1] == 'packages') {
				$this->pkgHandle = $exploded[2];
				unset($exploded[0]);
				unset($exploded[1]);
				unset($exploded[2]);
				$imploded = implode('/', $exploded);
				if(substr($imploded, -4) == '.php') {
					$this->filename = $imploded;
				} else {
					$this->filename = $imploded . '.php';
				}
				$this->includeType = 'PACKAGE_TOOL';
				return;
			}
			
			if($exploded[1] == 'required') {
				unset($exploded[0]);
				unset($exploded[1]);
				$imploded = implode('/', $exploded);
				if(substr($imploded, -4) == '.php') {
					$this->filename = $imploded;
				} else {
					$this->filename = $imploded . '.php';
				}
				$this->includeType = 'CONCRETE_TOOL';
				return;
			}
			
			unset($exploded[0]);
			$imploded = implode('/', $exploded);
			if(substr($imploded, -4) == '.php') {
				$this->filename = $imploded;
			} else {
				$this->filename = $imploded . '.php';
			}
			$this->includeType = 'TOOL';
			return;
		}
		
		// just path
		if ($path != '') {
			$this->cPath = $path;
			return;
		}		
	}
	
	/** 
	 * Gets the path of the current request
	 */
	public function getRequestPath() {
		return $this->requestPath;
	}

	/** 
	 * Gets the current collection path as contained in the current request 
	 */
	public function getRequestCollectionPath() {
		// I think the regexps take care of the trimming for us but just to be sure..
		$cPath = trim($this->cPath, '/');
		if ($cPath != '') {
			return '/' . rawurldecode($cPath);
		}
		return '';
	}

	/** 
	 * Gets page ID of the current request 
	 * @return int
	 */
	public function getRequestCollectionID() {
		return $this->cID;
	}
	
	/** 
	 * Gets the current MVC task of the request
	 * @return string
	 */
	public function getRequestTask() {
		return $this->task;
	}

	/** 
	 * Gets the array of parameters for this current MVC task
	 */
	public function getRequestTaskParameters() {
		return $this->params;
	}
	
	/** 
	 * Returns whether this request wants to include a file (typically a tool)
	 * @return bool
	 */
	public function isIncludeRequest() {
		return $this->includeType != null;
	}
	
	/** 
	 * Gets the include type of the current request
	 */
	public function getIncludeType() {
		return $this->includeType;
	}

	/** 
	 * If the current request wants to include a file, this returns the filename it wants to include
	 */
	public function getFilename() {
		return $this->filename;
	}
	
	/** 
	 * Gets the block requested by the current request
	 */
	public function getBlock() {
		return $this->btHandle;
	}
	
	/** 
	 * Auxiliary data is anything that the request specifies that doesn't really fit inside the request object, but gets passed along anyway
	 */
	public function getAuxiliaryData() {
		return $this->auxData;
	}
	
	/** 
	 * Gets the package requested by the current request
	 */
	public function getPackageHandle() {
		return $this->pkgHandle;
	}

	/**
	 * Sets the controller task, used when the Page object identifies
	 * the actual path.
	 * @param string $task the name of the task function
	 */
	public function setRequestTask($task) {
		$this->task = $task;
	}
	
	public function setCurrentPage($page) {
		$this->currentPage = $page;
	}
	
	public function getCurrentPage() {
		return $this->currentPage;
	}
	
	/**
	 * Sets the controller params, used when the Page object identifies
	 * the actual path.
	 * @param string $params List of params, separated by "/"
	 */
	public function setRequestTaskParameters($params) {
		$this->params = $params;
	}
	
	/**
	 * Sets the request path, used when the Page object identifies
	 * the actual path.
	 * @param string $path The path
	 */
	public function setCollectionPath($path) {
		$this->cPath = $path;
	}

}