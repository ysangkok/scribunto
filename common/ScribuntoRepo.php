<?php
/*
TODO

- notimplemented issues?
- errormsg i18n
*/

class ScribuntoFile extends File {
	function __construct( Title $title, $repo ) {
		$this->title = File::normalizeTitle( $title, 'exception' );
		$this->name = $repo->getNameFromTitle( $title );
		$this->repo = $repo;
		$this->hasData = false;
	}

	static function mimeToExt( $mime ) {
		$partitionedBySpace = explode( " ", MimeMagic::singleton()->getExtensionsForType( $mime ), 2 );
		return File::normalizeExtension( $partitionedBySpace[0] );
	}

	public function getExtension() {
		return self::mimeToExt( $this->getMimeType() );
	}

	public static function newFromTitle( $title, $repo ) {
		return new ScribuntoFile( $title, $repo );
	}

	public static function newFromModuleTitle( $title, $repo ) {
		$title = Title::newFromText( "File:" . $title );
		return new ScribuntoFile( $title, $repo );
	}

	public function getWidth( $page = 1 ) {
		$this->ensureHasData();
		return $this->w;
	}

	public function getHeight( $page = 1 ) {
		$this->ensureHasData();
		return $this->h;
	}

	public function getMimeType() {
		if ( !$this->mime ) {
			$this->mime = $this->repo->getBackend()->executeMimeTypeGetterByTitle( Title::newFromText( $this->title->getText() ) );
		}
		return $this->mime;
	}

	public function getSize() {
		$this->ensureHasData();
		return $this->fsFile->getSize();
	}

	private function ensureHasData() {
		if ( !$this->hasData ) {
			$moduleStrippedOfFile = Title::newFromText( $this->title->getText() );
			$this->fsFile = $this->repo->getBackend()->executeAndSave( $moduleStrippedOfFile, $this );
			$this->hasData = true;
			$imageSize = $this->getImageSize( $this->fsFile->getPath() );
			if ( $imageSize !== false && $imageSize[0] !== 0 && $imageSize[1] !== 0 ) {
				list( $this->w, $this->h ) = $imageSize;
			} else {
				$metadata = SVGMetadataExtractor::getMetadata( $this->fsFile->getPath() );
				$this->w = $metadata["width"];
				$this->h = $metadata["height"];
			}
		}
	}
}

class ScribuntoOutput extends UploadBase {
	function __construct( $path ) {
		parent::__construct();
		$this->mTempPath = $path;
	}

	public function initializeFromRequest( &$request ) {
		throw new NotImplementedException();
	}

	public function verifyFile() { // change visibility to public
		return parent::verifyFile();
	}
}

class ScribuntoBackend extends FileBackendStore {
	static function endsWith( $bucket, $str ) {
		return substr( $bucket, -strlen( $str ) -1 ) === "-" . $str;
	}

	function __construct( $config, $repo ) {
		parent::__construct( $config );
		$this->repo = $repo;
		$this->images = new FSFileBackend( $config );
	}

	private static function assertThumbnail( $params ) {
		if ( isset( $params["dst"] ) ) {
			$key = "dst";
		} else if ( isset( $params["src"] ) ) {
			$key = "src";
		} else {
			return;
		}
		list( $bucket, $_title ) = self::urlToBucketAndTitle( $params[$key] );
		if ( !self::endsWith( $bucket, "thumb" ) ) {
			throw new MWException( "Only thumbnails can be stored (not '$bucket'): " . print_r( $params, 1 ) );
		}
	}

	public function isPathUsableInternal( $storagePath ) {
		throw new NotImplementedException();
	}

	protected function doCreateInternal( array $params ) {
		throw new NotImplementedException();
	}

	private static function unparse_url( $parsed_url ) { // from http://php.net/parse_url
		$scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass']  : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}

	protected function doStoreInternal( array $params ) {
		self::assertThumbnail( $params ); // we only store thumbnails because the modules are written though wikipages
		$url = parse_url( $params["dst"] );
		$url["path"] = dirname( $url["path"] );
		$this->images->prepare( array( "dir" => self::unparse_url( $url ) ) );
		return $this->images->quickStore( $params );
	}

	protected function doCopyInternal( array $params ) {
		throw new NotImplementedException();
	}

	protected function doDeleteInternal( array $params ) {
		throw new NotImplementedException();
	}

	protected function doDirectoryExists( $container, $dir, array $params ) {
		throw new NotImplementedException();
	}

	public function getDirectoryListInternal( $container, $dir, array $params ) {
		throw new NotImplementedException();
	}

	public function getFileListInternal( $container, $dir, array $params ) {
		throw new NotImplementedException();
	}

	protected function directoriesAreVirtual() {
		throw new NotImplementedException();
	}

	private static function urlToBucketAndTitle( $src ) {
		$parsed = parse_url( $src );
		$path = $parsed["path"];
		$exploded = explode( "/", $path );
		$bucket = $exploded[1];
		return array( $bucket, Title::newFromText( basename( $path ) ) );
		// example return ["scrib-thumb" , Title(Module:Bananas)]
	}

	protected function doGetFileStat( array $params ) {
		list( $bucket, $module ) = self::urlToBucketAndTitle( $params["src"] );
		if ( strpos( $bucket, "thumb" ) !== false ) {
			$thumbStat = $this->images->getFileStat( $params );
			$parsed = parse_url( $params["src"] );
			$orgfile = basename( dirname( $parsed["path"] ) );
			// mwstore://bla/bla/second last/john
			//		   ^^^^^^^^^^^ == Module:Bananas(.svg) == orgfile
			$stripped = self::stripExtension( Title::newFromText( $orgfile ) );
			$wikiPage = new WikiPage( $stripped[0] );
			if ( $thumbStat["mtime"] < $wikiPage->getTouched() ) {
				wfDebugLog( __METHOD__, "forced thumbnail regeneration: " . print_r( $thumbStat, 1 ) );
				$this->images->quickDelete( $params );
				return $this->images->getFileStat( $params );
			} else {
				wfDebugLog( __METHOD__, "reused thumbnail: " . print_r( $thumbStat, 1 ) );
				return $thumbStat;
			}
		} else {
			try {
				list ( $module2, $strippedExt ) = self::checkTitleValidity( $module );
			} catch ( ScribuntoException $e ) {
				return null;
			}
			$page = new WikiPage( $module2 );
			try {
				return array( "mtime" => $page->getTouched(), "size" => $this->executeAndSave( $module )->getSize() );
			} catch ( ExtensionException $e ) {
				return null;
			}
		}
	}

	function executeAndSave( $title, $endfile = null ) {
		if ( $endfile === null ) {
			$endfile = ScribuntoFile::newFromModuleTitle( $title, $this->repo );
		}
		$fsFile = TempFSFile::factory( 'localcopy_', $endfile->getExtension() );
		try {
			$out = self::executeImageGetterByTitle( $title );
			if ( ! $fsFile ) {
				throw new MWException( "Couldn't create module output image file" );
			}
			$bytes = file_put_contents( $fsFile->getPath(), $out );
			$scribuntoOutput = new ScribuntoOutput( $fsFile->getPath() );
			$verification = $scribuntoOutput->verifyFile();
			if ( $verification !== true ) {
				throw new MWException( "Verification of output ({$fsFile->getPath()}) failed: " . print_r( $verification, 1 ) );
			}
			if ( $bytes !== strlen( $out ) ) {
				$fsFile = null;
				throw new MWException( "Couldn't write file" );
			}
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			$w = 300;
			$h = 20;
			$str = <<<EOT
				<svg height="$h" width="$w" xmlns="http://www.w3.org/2000/svg">
				<text x="0" y="20" fill="red">$msg</text>
				</svg>
EOT;
			if ( $endfile->getMimeType() === "image/svg+xml" ) {
				$bytes = file_put_contents( $fsFile->getPath(), $str );
			} else {
				$tmpFile = TempFSFile::factory( 'errmsg_', "svg" );
				file_put_contents( $tmpFile->getPath(), $str );
				$svgHandler = new SvgHandler;
				if ( $endfile->getMimeType() === "image/png" ) {
					$svgHandler->rasterize( $tmpFile->getPath(), $fsFile->getPath(), $w, $h );
				} else {
					$tmpFile2 = TempFSFile::factory( 'errmsg_', "png" );
					$svgHandler->rasterize( $tmpFile->getPath(), $tmpFile2->getPath(), $w, $h );
					self::convert( $tmpFile2->getPath(), $fsFile->getPath() );
				}
			}
		}
		$url = $endfile->getPath();
		$split = parse_url( $url );
		$split["path"] = dirname( $split["path"] );
		$newurl = self::unparse_url( $split );
		$this->images->prepare( array( "dir" => $newurl ) );
		$this->images->quickStore( array( "src" => $fsFile->getPath(), "dst" => $url ) );
		return $fsFile;
	}

	private static function convert( $in, $out ) { // adapted from BitmapHandler. The functions there do not allow outputFormat != inputFormat
		global $wgImageMagickConvertCommand, $wgImageMagickTempDir;

		$bmHandler = new BitmapHandler;
		$cmd = call_user_func_array( 'wfEscapeShellArg', array_merge(
			array( $wgImageMagickConvertCommand ),
			array( $bmHandler->escapeMagickInput( $in, 0 ) ),
			array( $bmHandler->escapeMagickOutput( $out ) ) ) );

		$retval = 0;
                $env = array( 'OMP_NUM_THREADS' => 1 );
                if ( strval( $wgImageMagickTempDir ) !== '' ) {
                        $env['MAGICK_TMPDIR'] = $wgImageMagickTempDir;
                }
		$err = wfShellExecWithStderr( $cmd, $retval, $env );

		if ( $retval !== 0 ) {
			throw new MWException( $errMsg );
		}
	}

	protected function doGetLocalCopyMulti( array $params ) { // adapted from MemoryFileBackend
		$tmpFiles = array(); // (path => TempFSFile)
		foreach ( $params['srcs'] as $src ) {
			$fsFile = null;
			if ( $src !== null && $this->doGetFileStat( array( "src" => $src ) ) ) {
				list( $bucket, $title ) = self::urlToBucketAndTitle( $src );
				assert ( self::endsWith( $bucket, "public" ) );
				$fsFile = self::executeAndSave( $title );
			}
			$tmpFiles[$src] = $fsFile;
		}
		return $tmpFiles;
	}

	private static function makeEngine( ) {
		static $engine;
		if ( $engine ) {
			return $engine;
		}
		$parser = new Parser;
		$options = new ParserOptions;
		$options->setTemplateCallback( array( "Parser", 'statelessFetchTemplate' ) );
		$parser->startExternalParse( Title::newMainPage(), $options, Parser::OT_HTML, true );
		$engine = new Scribunto_LuaStandaloneEngine ( array(
			'parser' => $parser,
			'errorFile' => null,
			'luaPath' => null,
			'memoryLimit' => 50000000,
			'cpuLimit' => 5,
			'allowEnvFuncs' => false
		) );
		$parser->scribunto_engine = $engine;
		$engine->setTitle( $parser->getTitle() );
		$engine->getInterpreter();
		return $engine;
	}

	static function stripExtension( $title ) {
		$t = $title->getPrefixedText();
		$strippedExt = pathinfo( $t, PATHINFO_EXTENSION );
		$withoutExt = pathinfo( $t, PATHINFO_FILENAME );
		return array( Title::newFromText( $withoutExt ), $strippedExt );
	}

	private static function checkTitleValidity( $title ) {
		list ( $title, $strippedExt ) = self::stripExtension( $title );
		if ( $strippedExt === "" ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		if ( $title->getNamespace() !== NS_MODULE || Scribunto::isDocPage( $title ) ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		$engine = self::makeEngine();
		$module = $engine->fetchModuleFromParser( $title );
		if ( !$module ) {
			throw new ScribuntoException( 'scribunto-common-nosuchmodule' );
		}
		return array( $title, $module );
	}

	static function executeMimeTypeGetterByTitle( Title $nt ) {
		list( $title, $module ) = self::checkTitleValidity( $nt );
		$mimeType = strval( $module->invoke( "getMimeType", null ) );
		$a = ScribuntoFile::mimeToExt( $mimeType );
		$stripped = self::stripExtension( $nt );
		$b = $stripped[1];
		if ( $a !== $b ) {
			throw new ExtensionException( "Wrong extension for {$nt->__toString()}: " . var_export( $a, 1 ) . " !== " . var_export( $b, 1 ) );
		}
		return $mimeType;
	}

	private static function executeImageGetterByTitle( Title $nt2 ) {
		list( $title, $module ) = self::checkTitleValidity( $nt2 );
		$str = strval( $module->invoke( "getImage", null ) );
		return $str;
	}
}

class ExtensionException extends MWException {
}

class NotImplementedException extends MWException {
}

class ScribuntoRepo extends FileRepo {
	protected $fileFactory = array( 'ScribuntoFile', 'newFromTitle' );

	function __construct( $arr ) {
		parent::__construct( $arr ); // this takes the "url" param
		$this->backend = new ScribuntoBackend( $arr, $this );
	}

	protected function assertWritableRepo() {
		throw new MWException( "Write operations are not supported" );
	}
}
