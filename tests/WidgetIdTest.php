<?php
/**
 * split_id(): a widget id is base and number, matched to the very end.
 *
 * "$" also matches before a trailing newline, so "text-2\n" used to split
 * into text/2 while every map lookup compared the raw id — the settings row
 * was deleted, the sidebar entry stayed behind. Trimmed first, anchored with
 * \z since 4.3.4.
 */

require_once __DIR__ . '/../includes/tools/class-tools-base.php';
require_once __DIR__ . '/../includes/tools/class-tools-widgets.php';

class WidgetIdStringable {
	public function __toString(): string {
		return 'text-2';
	}
}

class WidgetIdProbe extends AB_MCP_Tools_Widgets {
	public static function split( $id ) {
		return self::split_id( $id );
	}
}

final class WidgetIdTest extends \PHPUnit\Framework\TestCase {

	/** @return array<string, array{mixed, array{string, int}}> */
	public static function ids(): array {
		return array(
			'plain'                 => array( 'text-2', array( 'text', 2 ) ),
			'trailing newline'      => array( "text-2\n", array( 'text', 2 ) ),
			'surrounding spaces'    => array( '  text-2 ', array( 'text', 2 ) ),
			'base with a dash'      => array( 'media_image-3', array( 'media_image', 3 ) ),
			'base with two dashes'  => array( 'a-b-3', array( 'a-b', 3 ) ),
			'leading zero'          => array( 'text-02', array( 'text', 2 ) ),
			'letters after number'  => array( 'text-2x', array( '', 0 ) ),
			'newline inside'        => array( "text-2\nx", array( '', 0 ) ),
			'no number'             => array( 'text-', array( '', 0 ) ),
			'no dash'               => array( 'text', array( '', 0 ) ),
			'empty'                 => array( '', array( '', 0 ) ),
			'not a string'          => array( array( 'text-2' ), array( '', 0 ) ),
			// Mutation probes (Codex): without ^ the id would be found mid-string,
			// with (.*) an empty base would pass, with is_array() instead of
			// ! is_scalar() a stringable object would be accepted.
			'id after a newline'    => array( "x\ntext-2", array( '', 0 ) ),
			'empty base'            => array( '-2', array( '', 0 ) ),
			'stringable object'     => array( new WidgetIdStringable(), array( '', 0 ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'ids' )]
	public function test_split( $id, array $want ): void {
		self::assertSame( $want, WidgetIdProbe::split( $id ) );
	}
}
