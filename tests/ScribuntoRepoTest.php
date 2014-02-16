<?php

/**
 * @group Extensions/Scribunto
 */
class ScribuntoRepoTest extends MediaWikiTestCase {
	
	function setUp() {
		parent::setUp();
		global $wgParser;
		$this->user = self::userFromName("scrib5");
		$this->page = Wikipage::factory(Title::newFromText("Module:ScribuntoRepoTest {$this->getName()} " . time()));
		$this->wgParser = $wgParser;
		$this->options = "";
	}

	static function getSVG($maybeScript) {
		return "	local p = {}
				function p.getImage()
					return [[<svg width='103' height='112' xmlns='http://www.w3.org/2000/svg'>$maybeScript</svg>]]
				end
				function p.getMimeType()
					return 'image/svg+xml'
				end
				return p";
	}

	static function getRuntimeError($ext) {
		return "	local p = {}
				function p.getImage()
					return nil + nil
				end
				function p.getMimeType()
					return 'image/$ext'
				end
				return p";
	}

	function toFileWithExt($ext) {
		return Title::newFromText("File:{$this->page->getTitle()->getPrefixedText()}$ext");
	}

	function testSVG() {
		$this->edit(self::getSVG(""));
		$newtitle = $this->toFileWithExt(".svg");

		$html = $this->wgParser->makeImage($newtitle, $this->options);
		$this->assertContains("<img", $html);
		$html = $this->wgParser->makeImage($newtitle, $this->options);
		$this->assertContains("<img", $html);

		$this->wgParser->makeImage(Title::newFromText("File:ModuleBob:" . $this->page->getTitle()->getText()), $this->options); // trigger NS_MODULE check
	}

	function testRuntimeError2XConversion() {
		$this->edit(self::getRuntimeError("gif"));
		$newtitle = $this->toFileWithExt(".gif");
		$html = $this->wgParser->makeImage($newtitle, $this->options); // provoke errmsg conversion
	}

	function testRuntimeError1XConversion() {
		$this->edit(self::getRuntimeError("png"));
		$newtitle = $this->toFileWithExt(".png");
		$html = $this->wgParser->makeImage($newtitle, $this->options); // provoke errmsg conversion
	}

	function testExtension() {
		$html = $this->wgParser->makeImage($this->toFileWithExt(""), $this->options); // no dot in filename
		$html = $this->wgParser->makeImage($this->toFileWithExt("."), $this->options); // no text after dot
		$html = $this->wgParser->makeImage($this->toFileWithExt("bar.foo"), $this->options); // page doesn't exist
		$html = $this->wgParser->makeImage($this->toFileWithExt(".png"), $this->options); // trigger other ExtensionException
	}

	function testVerification() {
		$this->edit(self::getSVG("<script></script>")); // trigger executeAndSave exception handler
		$this->wgParser->makeImage($this->toFileWithExt(".svg"), $this->options);
	}

	function tearDown() {
		//$this->delete();
	}

	function delete($title) {
		$wp = Wikipage::factory($title);
		$status = $wp->doDeleteArticleReal(/*reason*/ "just a test module", /*suppress*/ false, /*id*/ 0, /*commit*/ true, $error, $this->user);
		$this->assertTrue($status->isGood(), print_r($status,1));
	}

	static function userFromName($user) {
		$user = User::newFromName( $user );

		if ( $user->getId() === 0 ) {
			$user->addToDatabase();
		}
		return $user;
	}

	function edit($baseText) {
		$content = ContentHandler::makeContent( $baseText, $this->page->getTitle() );
		$this->page->doEditContent( $content, "base text for test" );
	}
}
