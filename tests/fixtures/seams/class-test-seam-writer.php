<?php
/**
 * A concurrent writer for a fault of kind before, as an array callable
 * (GitHub Codex on free#145: [$object, 'before'] and [Class, 'before']
 * must run, not go to the parser of writer ops). TestSeamTest.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Seam_Writer
 */
final class AB_MCP_Test_Seam_Writer {

	/** @var string File the static writer writes. */
	public static $file = '';

	/** @var string File the writer object writes. */
	private $own;

	/**
	 * @param string $own File of this writer.
	 */
	public function __construct( $own = '' ) {
		$this->own = (string) $own;
	}

	/**
	 * Write before the call, as an object method.
	 *
	 * @param string $method Method of the seam.
	 */
	public function before( $method ) {
		file_put_contents( $this->own, 'object before ' . $method );
	}

	/**
	 * Write before the call, as a static method.
	 *
	 * @param string $method Method of the seam.
	 */
	public static function before_static( $method ) {
		file_put_contents( self::$file, 'static before ' . $method );
	}
}
