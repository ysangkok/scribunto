<?php

class Scribunto_LuaSandboxInterpreterTest extends MediaWikiTestCase {
	var $stdOpts = array(
		'errorFile' => null,
		'luaPath' => null,
		'memoryLimit' => 50000000,
		'cpuLimit' => 30,
	);

	function setUp() {
		try {
			$interpreter = $this->newInterpreter();
		} catch ( MWException $e ) {
			if ( preg_match( '/extension is not present/', $e->getMessage() ) ) {
				$this->markTestSkipped( "LuaSandbox not available" );
				return;
			}
		}
	}

	function getBusyLoop( $interpreter ) {
		$chunk = $interpreter->loadString( '
			local args = {...}
			local x, i
			local s = string.rep("x", 1000000)
			local n = args[1]
			for i = 1, n do
				x = x or string.find(s, "y", 1, true)
			end', 
			'busy' );
		return $chunk;
	}

	function getPassthru( $interpreter ) {
		return $interpreter->loadString( 'return ...', 'passthru' );
	}

	function newInterpreter( $opts = array() ) {
		$opts = $opts + $this->stdOpts;
		$engine = new Scribunto_LuaSandboxEngine( $this->stdOpts );
		return new Scribunto_LuaSandboxInterpreter( $engine, $opts );
	}

	/** @dataProvider provideRoundtrip */
	function testRoundtrip( /*...*/ ) {
		$args = func_get_args();
		$args = $this->normalizeOrder( $args );
		$interpreter = $this->newInterpreter();
		$passthru = $interpreter->loadString( 'return ...', 'passthru' );
		$finalArgs = $args;
		array_unshift( $finalArgs, $passthru );
		$ret = call_user_func_array( array( $interpreter, 'callFunction' ), $finalArgs );
		$ret = $this->normalizeOrder( $ret );
		$this->assertSame( $args, $ret );
	}

	/** @dataProvider provideRoundtrip */
	function testDoubleRoundtrip( /* ... */ ) {
		$args = func_get_args();
		$args = $this->normalizeOrder( $args );

		$interpreter = $this->newInterpreter();
		$interpreter->registerLibrary( 'test',
			array( 'passthru' => array( $this, 'passthru' ) ) );
		$doublePassthru = $interpreter->loadString( 
			'return test.passthru(...)', 'doublePassthru' );

		$finalArgs = $args;
		array_unshift( $finalArgs, $doublePassthru );
		$ret = call_user_func_array( array( $interpreter, 'callFunction' ), $finalArgs );
		$ret = $this->normalizeOrder( $ret[0] );
		$this->assertSame( $args, $ret );
	}

	function normalizeOrder( $a ) {
		ksort( $a );
		foreach ( $a as &$value ) {
			if ( is_array( $value ) ) {
				$value = $this->normalizeOrder( $value );
			}
		}
		return $a;
	}

	function passthru( /* ... */ ) {
		$args = func_get_args();
		return $args;
	}

	function provideRoundtrip() {
		return array(
			array( 1 ),
			array( true ),
			array( false ),
			array( 'hello' ),
			array( 1, 2, 3 ),
			array( array() ),
			array( array( 0 => 'foo', 1 => 'bar' ) ),
			array( array( 1 => 'foo', 2 => 'bar' ) ),
			array( array( 'x' => 'foo', 'y' => 'bar', 'z' => array() ) )
		);
	}

	function testGetMemoryUsage() {
		$interpreter = $this->newInterpreter();
		$chunk = $interpreter->loadString( 's = string.rep("x", 1000000)', 'mem' );
		$interpreter->callFunction( $chunk );
		$mem = $interpreter->getMemoryUsage();
		$this->assertGreaterThan( 1000000, $mem, 'memory usage' );
		$this->assertLessThan( 10000000, $mem, 'memory usage' );
	}

	/** 
	 * @expectedException ScribuntoException
	 * @expectedExceptionMessage The time allocated for running scripts has expired.
	 */
	function testTimeLimit() {
		$interpreter = $this->newInterpreter( array( 'cpuLimit' => 1 ) );
		$chunk = $this->getBusyLoop( $interpreter );
		$interpreter->callFunction( $chunk, 1e9 );
	}

	/**
	 * @expectedException ScribuntoException
	 * @expectedExceptionMessage Lua error: not enough memory
	 */
	function testTestMemoryLimit() {
		$interpreter = $this->newInterpreter( array( 'memoryLimit' => 20 * 1e6 ) );
		$chunk = $interpreter->loadString( '
			t = {}
			for i = 1, 10 do
				t[#t + 1] = string.rep("x" .. i, 1000000)
			end
			',
			'memoryLimit' );
		$interpreter->callFunction( $chunk );
	}
}
