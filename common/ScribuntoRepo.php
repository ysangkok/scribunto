<?php
/*
TODO

- mtime / size
- notimplemented issues?
- errormsg i18n
- cache engine
- save to permanent storage and not tempfile
- dependencies
	- invalidate thumbnails on script update
	- invalidate script output on script update
*/

class ScribuntoFile extends File {
	function __construct($title, $repo) {
		if ( $title instanceof Title ) {
			$this->title = File::normalizeTitle( $title, 'exception' );
			$this->name = $repo->getNameFromTitle( $title );
		} else {
			$this->name = basename( $path );
			$this->title = File::normalizeTitle( $this->name, 'exception' );
		}
		$this->repo = $repo;
	}

	public static function newFromTitle($title, $repo) {
		return new ScribuntoFile($title, $repo);
	}
	
	public function getWidth($page = 1) {
		list($fsFile, $w, $h, $mime) = ScribuntoBackend::execTitle($this->title);
		return $w;
	}
	public function getHeight($page = 1) {
		list($fsFile, $w, $h, $mime) = ScribuntoBackend::execTitle($this->title);
		return $h;
	}
	public function getMimeType() {
		list($fsFile, $w, $h, $mime) = ScribuntoBackend::execTitle($this->title);
		return $mime;
	}
}

/*
class Decorator 
{
	protected $foo;

	function __construct($foo) {
		$this->foo = $foo;
	}

	function __call($method_name, $args) {
		wfDebugLog('scrib5','CALL1 '. get_class($this->foo). " " .$method_name." with args " . json_encode($args));
		$ret = call_user_func_array(array($this->foo, $method_name), $args);
		wfDebugLog("scrib5","CALL2 ". get_class($this->foo). " ". var_export($ret,1));
		return $ret;
	}
}
*/

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
	function __construct($config) {
		parent::__construct($config);
		$this->thumbs = new FSFileBackend($config);
	}

	private static function n() {
		throw new NotImplementedException();
	}

	private static function t($params) {
		if (isset($params["dst"])) $key = "dst";
		else if (isset($params["src"])) $key = "src";
		else return;
		$bucket = self::urlToTitle($params[$key])[0];
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

	private static function urlToTitle($src) {
		$path = parse_url($src)["path"];
		return [explode("/",$path)[1], Title::newFromText(basename($path))];
		// ["scrib-thumb" , Title(Module:Bananas)]
	}

	protected function doGetFileStat( array $params ) {
		list($bucket, $module) = self::urlToTitle($params["src"]);
		if (strpos($bucket, "thumb") !== false) {
			return $this->thumbs->getFileStat($params);
		}
		try {
			self::checkCall($module);
			return ["mtime" => 100, "size" => 42]; // TODO
		} catch (ScribuntoException $e) {
			return null;
		}
	}

	private static function mimeToExt($mime) {
		return explode(" ", (new MimeMagic)->getExtensionsForType($mime))[0];
	}

	static function execTitle($title) {
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
		return [$fsFile, $w, $h, $mime];
	}

	protected function doGetLocalCopyMulti( array $params ) {
		$tmpFiles = array(); // (path => TempFSFile)
		foreach ( $params['srcs'] as $src ) {
			$fsFile = null;
			if ( $src !== null && $this->doGetFileStat(["src" => $src])) {
				list($fsFile, $_w, $_h, $_mime) = self::execTitle(self::urlToTitle($src)[1]);
			}
			$tmpFiles[$src] = $fsFile;
		}
		return $tmpFiles;
	}


	private static function makeEngine( ) {
		$parser = new Parser;
		$options = new ParserOptions;
		$options->setTemplateCallback( array( "Parser", 'statelessFetchTemplate' ) );
		$parser->startExternalParse( Title::newMainPage(), $options, Parser::OT_HTML, true );
		$engine = new Scribunto_LuaStandaloneEngine ( array( 'parser' => $parser ) + array( 'errorFile' => null, 'luaPath' => null, 'memoryLimit' => 50000000, 'cpuLimit' => 30, 'allowEnvFuncs' => true) );
		$parser->scribunto_engine = $engine;
		$engine->setTitle( $parser->getTitle() );
		$engine->getInterpreter();
		return $engine;
	}

	private static function checkCall($title) {
		if ( !$title || $title->getNamespace() !== NS_MODULE || Scribunto::isDocPage( $title ) ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		$engine = self::makeEngine(); // TODO cache
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
		} catch (ScribuntoException $e) {
			$msg = $e->getMessage();
			$width = 800;
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
		parent::__construct($arr);
		$this->backend = new ScribuntoBackend($arr);
	}

	protected function assertWritableRepo() {
		throw new MWException( get_class( $this ) . ': write operations are not supported.' );
	}
}
?>
