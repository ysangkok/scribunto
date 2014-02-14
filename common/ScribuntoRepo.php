<?php
/*
TODO

- notimplemented issues?
- errormsg i18n
*/

class ScribuntoFile extends File {
	function __construct($title, $repo) {
		if ( $title instanceof Title ) {
			$this->title = $title; //File::normalizeTitle( $title, 'exception' );
			$this->name = $repo->getNameFromTitle( $title );
		} else {
			$this->name = basename( $path );
			$this->title = File::normalizeTitle( $this->name, 'exception' );
		}
		$this->repo = $repo;
		$this->hasData = false;
	}

	public static function newFromTitle($title, $repo) {
		return new ScribuntoFile($title, $repo);
	}

	public static function newFromModuleTitle($title, $repo) {
		$title = Title::newFromText("File:" . $title);
		return new ScribuntoFile($title, $repo);
	}
	
	public function getWidth($page = 1) {
		$this->ensureHasData();
		return $this->w;
	}

	public function getHeight($page = 1) {
		$this->ensureHasData();
		return $this->h;
	}

	public function getMimeType() {
		$this->ensureHasData();
		return $this->mime;
	}

	public function getSize() {
		$this->ensureHasData();
		return $this->numBytesWritten;
	}

	private function ensureHasData() {
		if (!$this->hasData)
			list($this->fsFile, $this->w, $this->h, $this->mime, $this->numBytesWritten) = $this->repo->getBackend()->execTitle(Title::newFromText($this->title->getDBKey()));
		$this->hasData = true;
	}
}

class Decorator 
{
	protected $foo;

	function __construct($foo, $bucket = "default") {
		$this->foo = $foo;
		$this->bucket = $bucket;
	}

	function __call($method_name, $args) {
		wfDebugLog('scrib5',"{$this->bucket} CALL1 ". get_class($this->foo). " " .$method_name." with args " . json_encode($args));
		$ret = call_user_func_array(array($this->foo, $method_name), $args);
		wfDebugLog("scrib5","{$this->bucket} CALL2 ". get_class($this->foo). " ". var_export($ret,1));
		return $ret;
	}
}

class ScribuntoOutput extends UploadBase {
	function __construct($path) {
		parent::__construct();
		$this->mTempPath = $path;
	}

	public function initializeFromRequest(&$request) {
		throw new NotImplementedException();
	}

	public function verifyFile() {
		global $wgVerifyMimeType;
		$bu = $wgVerifyMimeType;
		$wgVerifyMimeType = false;
		$ver = parent::verifyFile();
		$wgVerifyMimeType = $bu;
		return $ver;
	}
}

class ScribuntoBackend extends FileBackendStore {
	function __construct($config, $repo) {
		parent::__construct($config);
		$this->repo = $repo;
		$this->thumbs = new Decorator(new FSFileBackend($config));
		$this->larges = new Decorator(new FSFileBackend(["name" => "scrib", "wikiId" => "donkeyballs", "containerPaths" => ["scrib-public" => "/home/janus/core/images"]]));
	}

	private static function n() {
		throw new NotImplementedException();
	}

	private static function t($params) {
		if (isset($params["dst"])) $key = "dst";
		else if (isset($params["src"])) $key = "src";
		else return;
		$bucket = self::urlToBucketAndTitle($params[$key])[0];
		if (strpos($bucket, "thumb") === false) throw new MWException("can only store thumbnails (not in $bucket): " . print_r($params,1));
	}

	public function isPathUsableInternal( $storagePath ) {
		self::n();
	}

	protected function doCreateInternal( array $params ) {
		self::n();
	}

	private static function unparse_url($parsed_url) {
		$scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		$user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
		$pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
		$pass     = ($user || $pass) ? "$pass@" : '';
		$path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
		$query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
		$fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	protected function doStoreInternal( array $params ) {
		self::t($params);
		$url = parse_url($params["dst"]);
		$url["path"] = substr($url["path"], 0, strrpos($url["path"],'/'));
		$this->thumbs->prepare(["dir" => self::unparse_url($url)]);
		return $this->thumbs->quickStore($params);
	}

	protected function doCopyInternal( array $params ) {
		self::n();
	}

	protected function doDeleteInternal( array $params ) {
		self::n();
	}

	protected function doDirectoryExists( $container, $dir, array $params ) {
		self::n();
	}

	public function getDirectoryListInternal( $container, $dir, array $params ) {
		self::n();
	}

	public function getFileListInternal( $container, $dir, array $params ) {
		self::n();
	}

	protected function directoriesAreVirtual() {
		self::n();
	}

	private static function urlToBucketAndTitle($src) {
		$path = parse_url($src)["path"];
		return [explode("/",$path)[1], Title::newFromText(basename($path))];
		// ["scrib-thumb" , Title(Module:Bananas)]
	}

	protected function doGetFileStat( array $params ) {
		list($bucket, $module) = self::urlToBucketAndTitle($params["src"]);
		if (strpos($bucket, "thumb") !== false) {
			$thumbStat = $this->thumbs->getFileStat($params);
			$orgfile = strrev(explode("/",strrev(dirname(parse_url($params["src"])["path"])))[0]);
			// mwstore://bla/bla/næst sidste/john
			//                   ^^^^^^^^^^^ == Module:Bananas == orgfile
			if ($thumbStat["mtime"] < (new WikiPage(Title::newFromText($orgfile)))->getTouched()) {
				wfDebugLog(__METHOD__, "forced thumbnail regeneration: " . print_r($thumbStat,1));
				$this->thumbs->quickDelete($params);
				return $this->thumbs->getFileStat($params);
			} else {
				wfDebugLog(__METHOD__, "reused thumbnail: " . print_r($thumbStat,1));
				return $thumbStat;
			}
		}
		try {
			self::checkCall($module);
			$page = new WikiPage($module);
			return ["mtime" => $page->getTouched(), "size" => $this->execTitle($module)[4]];
		} catch (ScribuntoException $e) {
			return null;
		}
	}

	private static function mimeToExt($mime) {
		static $mimeMagic;
		if (!$mimeMagic) $mimeMagic = new MimeMagic;
		return explode(" ", $mimeMagic->getExtensionsForType($mime))[0];
	}

	function execTitle($title) {
		list($out, $w, $h, $mime) = self::execScript($title);
		$ext = self::mimeToExt($mime);
		// Create a new temporary file with the same extension...
		$fsFile = TempFSFile::factory( 'localcopy_', $ext );
		if ( ! $fsFile ) {
			throw new MWException("couldn't create module output image file");
		}
		$bytes = file_put_contents( $fsFile->getPath(), $out );
		$verification = (new ScribuntoOutput( $fsFile->getPath() ))->verifyFile();
		if ( $verification !== true ) {
			throw new MWException("Verification of output ({$fsFile->getPath()}) failed: " . print_r($verification,1));
		}
		if ( $bytes !== strlen( $out ) ) {
			$fsFile = null;
			throw new MWException("couldn't write file");
		}
		$url = ScribuntoFile::newFromModuleTitle($title, $this->repo)->getPath();
		$split = parse_url($url);
		$split["path"] = dirname($split["path"]);
		$newurl = self::unparse_url($split);
		$this->larges->prepare(["dir" => $newurl]);
		$this->larges->quickStore(["src" => $fsFile->getPath(), "dst" => $url]);
		return [$fsFile, $w, $h, $mime, $bytes];
	}

	protected function doGetLocalCopyMulti( array $params ) {
		$tmpFiles = array(); // (path => TempFSFile)
		foreach ( $params['srcs'] as $src ) {
			$fsFile = null;
			if ( $src !== null && $this->doGetFileStat(["src" => $src])) {
				list($bucket, $title) = self::urlToBucketAndTitle($src);
				if (in_array($bucket, ["thumb", "archive", "deleted", "temp", "math"])) throw new MWException("unexpected bucket $bucket");
				list($fsFile, $_w, $_h, $_mime, $_numBytesWritten) = self::execTitle($title);
			}
			$tmpFiles[$src] = $fsFile;
		}
		return $tmpFiles;
	}


	private static function makeEngine( ) {
		static $engine;
		if ($engine) return $engine;
		$parser = new Parser;
		$options = new ParserOptions;
		$options->setTemplateCallback( array( "Parser", 'statelessFetchTemplate' ) );
		$parser->startExternalParse( Title::newMainPage(), $options, Parser::OT_HTML, true );
		$engine = new Scribunto_LuaStandaloneEngine ( array( 'parser' => $parser ) + array( 'errorFile' => null, 'luaPath' => null, 'memoryLimit' => 50000000, 'cpuLimit' => 5, 'allowEnvFuncs' => false) );
		$parser->scribunto_engine = $engine;
		$engine->setTitle( $parser->getTitle() );
		$engine->getInterpreter();
		return $engine;
	}

	private static function checkCall($title) {
		if ( !$title || $title->getNamespace() !== NS_MODULE || Scribunto::isDocPage( $title ) ) {
			throw new MWException($title);
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		$engine = self::makeEngine();
		$module = $engine->fetchModuleFromParser( $title );
		if ( !$module ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		return [$title, $module];
	}

	private static function execScript(Title $nt2) {
		try {
			list($title, $module) = self::checkCall($nt2);
			$str = strval($module->invoke( "getImage", null ));
			$mime = strval($module->invoke( "getMimeType", null ));
			$width = intval($module->invoke( "getWidth", null ));
			$height = intval($module->invoke( "getHeight", null ));
			if ($width < 1 || $height < 1) throw new MWException("Width and height must be positive!");
		} catch (Exception $e) {
			$msg = $e->getMessage();
			$width = 500;
			$height = 30;
			$mime = "image/svg+xml";
			$str = <<<END
				<svg height="$height" width="$width" xmlns="http://www.w3.org/2000/svg">
				<text x="0" y="15" fill="red">$msg</text>
				</svg>
END;
		}
		//$str = "<div><img width='$width' height='$height' src='data:image/svg+xml;base64," . base64_encode(strval($str)) . "' /></div>";
		return [$str, $width, $height, $mime];
	}
}

class ScribuntoRepo extends FileRepo {
	protected $fileFactory = array( 'ScribuntoFile', 'newFromTitle' );

	function __construct($arr) {
		parent::__construct($arr/* + ["hashLevels" => 0]*/); // this takes the "url" param
		//xdebug_start_trace("/tmp/trace.out");
		$this->backend = new ScribuntoBackend($arr, $this);
	}

	protected function assertWritableRepo() {
		throw new MWException( get_class( $this ) . ': write operations are not supported.' );
	}
}
?>
