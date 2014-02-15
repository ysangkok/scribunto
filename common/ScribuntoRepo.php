<?php
/*
TODO

- notimplemented issues?
- errormsg i18n
*/
define("EXTS", true);

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
		$this->hasData = false;
	}

	static function mimeToExt($mime) {
		static $mimeMagic;
		if (!$mimeMagic) $mimeMagic = new MimeMagic;
		return File::normalizeExtension(explode(" ", $mimeMagic->getExtensionsForType($mime))[0]);
	}

	public function getExtension() {
		return self::mimeToExt($this->getMimeType());
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
		if (!$this->mime)
			$this->mime = $this->repo->getBackend()->execMimeType(Title::newFromText($this->title->getText()));
		return $this->mime;
	}

	public function getSize() {
		$this->ensureHasData();
		return $this->numBytesWritten;
	}

	private function ensureHasData() {
		if (!$this->hasData)
			list($this->fsFile, $this->w, $this->h, $this->numBytesWritten) = $this->repo->getBackend()->execTitle(Title::newFromText($this->title->getText()));
		$this->hasData = true;
	}
}

/*
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
	function __construct($config, $repo) {
		parent::__construct($config);
		$this->repo = $repo;
		$this->thumbs = new FSFileBackend($config);
	}

	private static function n() {
		throw new NotImplementedException();
	}

	private static function assertThumbnail($params) {
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
		self::assertThumbnail($params);
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
			if ($thumbStat["mtime"] < (new WikiPage(self::stripExt(Title::newFromText($orgfile))[0]))->getTouched()) {
				wfDebugLog(__METHOD__, "forced thumbnail regeneration: " . print_r($thumbStat,1));
				$this->thumbs->quickDelete($params);
				return $this->thumbs->getFileStat($params);
			} else {
				wfDebugLog(__METHOD__, "reused thumbnail: " . print_r($thumbStat,1));
				return $thumbStat;
			}
		}
		if (EXTS) {
			if (strpos($module->getText(),".") === false) return null; // no dot in filename
			list ($module2, $strippedExt) = self::stripExt($module);
		} else {
			$module2 = $module;
		}
		$page = new WikiPage($module2);
		if (!$page->exists()) return null; //throw new MWException("page don't exist " . $module2->getPrefixedText());
		try {
			return ["mtime" => $page->getTouched(), "size" => $this->execTitle($module)[3]];
		} catch (ExtensionException $e) {
			return null;
		}
	}

	function execTitle($title) {
		$endfile = ScribuntoFile::newFromModuleTitle($title, $this->repo);
		$fsFile = TempFSFile::factory( 'localcopy_', $endfile->getExtension() );
		try {
			list($out, $w, $h) = self::execScript($title);
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
			$url = $endfile->getPath();
			$split = parse_url($url);
			$split["path"] = dirname($split["path"]);
			$newurl = self::unparse_url($split);
			$this->thumbs->prepare(["dir" => $newurl]);
			$this->thumbs->quickStore(["src" => $fsFile->getPath(), "dst" => $url]);
		} catch (Exception $e) {
			$msg = $e->getMessage();
			$w = 500;
			$h = 30;
			$str = <<<END
				<svg height="$height" width="$width" xmlns="http://www.w3.org/2000/svg">
				<text x="0" y="10" fill="red">$msg</text>
				</svg>
END;
			$bytes = file_put_contents($fsFile->getPath(), $str); 
		}
		return [$fsFile, $w, $h, $bytes];
	}

	protected function doGetLocalCopyMulti( array $params ) {
		$tmpFiles = array(); // (path => TempFSFile)
		foreach ( $params['srcs'] as $src ) {
			$fsFile = null;
			if ( $src !== null && $this->doGetFileStat(["src" => $src])) {
				list($bucket, $title) = self::urlToBucketAndTitle($src);
				if (in_array($bucket, ["thumb", "archive", "deleted", "temp", "math"])) throw new MWException("unexpected bucket $bucket");
				list($fsFile, $_w, $_h, $_numBytesWritten) = self::execTitle($title);
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

	static function stripExt($title) {
		if (!EXTS) return [$title, null];
		$t = $title->getPrefixedText();
		$pos = strrpos($t, ".");
		if ($pos === false) throw new MWException("assertion error!");
		$strippedExt = substr($t, $pos+1);
		$withoutExt = strstr($t, '.', true);
		return [Title::newFromText($withoutExt), $strippedExt];
	}

	private static function checkCall($title) {
		list ($title, $strippedExt) = self::stripExt($title);
		if ( !$title || $title->getNamespace() !== NS_MODULE || Scribunto::isDocPage( $title ) ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		$engine = self::makeEngine();
		$module = $engine->fetchModuleFromParser( $title );
		if ( !$module ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		return [$title, $module];
	}

	static function execMimeType(Title $nt) {
		list($title, $module) = self::checkCall($nt);
		$mimeType = strval($module->invoke( "getMimeType", null ));
		$a = ScribuntoFile::mimeToExt($mimeType);
		$b = self::stripExt($nt)[1];
		if (EXTS && $a !== $b)
			throw new ExtensionException("Wrong extension! {$nt->__toString()} " . var_export($a,1) . " !== " . var_export($b,1) );
		return $mimeType;
	}

	private static function execScript(Title $nt2) {
		list($title, $module) = self::checkCall($nt2);
		$str = strval($module->invoke( "getImage", null ));
		$width = intval($module->invoke( "getWidth", null ));
		$height = intval($module->invoke( "getHeight", null ));
		if ($width < 1 || $height < 1) throw new MWException("Width and height must be positive!");
		return [$str, $width, $height];
	}
}

class ExtensionException extends MWException {
}

class ScribuntoRepo extends FileRepo {
	protected $fileFactory = array( 'ScribuntoFile', 'newFromTitle' );

	function __construct($arr) {
		parent::__construct($arr/* + ["hashLevels" => 0]*/); // this takes the "url" param
		xdebug_disable();
		//xdebug_start_trace("/tmp/trace.out");
		$this->backend = new ScribuntoBackend($arr, $this);
	}

	protected function assertWritableRepo() {
		throw new MWException( get_class( $this ) . ': write operations are not supported.' );
	}
}
?>
