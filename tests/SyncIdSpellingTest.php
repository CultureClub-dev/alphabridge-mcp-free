<?php
/**
 * The one rule for every place the core reads an id (AB_MCP_Sync_Canon::
 * id_spelling(), Z8 decision 12, principle B): an id only in the spelling
 * that is rewritten, a JSON integer literal or a text of exactly the digits
 * [1-9][0-9]*; 0 where nothing is an id; every other form the core may read
 * as a number other than 0 locks. id_tokens() splits a text list as the core
 * does. The places themselves: SyncRefsTest (blocks, shortcodes, author
 * lists), SyncOptionSchemaTest, tests/integration/SyncHtmlRefsTest.php
 * (wp-image-<digits>, data-id).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-canon.php';

final class SyncIdSpellingTest extends TestCase {

	public static function spellings(): array {
		return array(
			// The spelling of an id.
			'an integer'              => array( 12, 12 ),
			'a text of digits'        => array( '12', 12 ),
			'18 digits'               => array( '123456789012345678', 123456789012345678 ),
			// No id: the core reads 0.
			'zero'                    => array( 0, 0 ),
			'zero as text'            => array( '0', 0 ),
			'zeros'                   => array( '00', 0 ),
			'minus zero as text'      => array( '-0', 0 ),
			'minus zero'              => array( -0.0, 0 ),
			'zero as a float'         => array( 0.0, 0 ),
			'empty'                   => array( '', 0 ),
			'null'                    => array( null, 0 ),
			'false'                   => array( false, 0 ),
			'an empty list'           => array( array(), 0 ),
			'a word'                  => array( 'none', 0 ),
			// Another form the core may read as a number other than 0.
			'a negative integer'      => array( -12, null ),
			'a negative text'         => array( '-12', null ),
			'a sign'                  => array( '+12', null ),
			'a space before'          => array( ' 12', null ),
			'a space after'           => array( '12 ', null ),
			'a leading zero'          => array( '012', null ),
			'an exponent'             => array( '1e1', null ),
			'a fraction'              => array( '12.0', null ),
			'a float'                 => array( 12.0, null ),
			'a fraction below one'    => array( 0.5, null ),
			'not a number'            => array( NAN, null ),
			'a character reference'   => array( '1&#50;', null ),
			'a reference first'       => array( '&#49;2', null ),
			'digits and letters'      => array( '12abc', null ),
			'hexadecimal'             => array( '0x1A', null ),
			'19 digits'               => array( '1234567890123456789', null ),
			'true'                    => array( true, null ),
			'a list'                  => array( array( 5 ), null ),
		);
	}

	#[DataProvider( 'spellings' )]
	public function testAnIdOnlyInItsSpellingAnyOtherNumberIsAnotherSpelling( mixed $value, ?int $want ): void {
		self::assertSame( $want, AB_MCP_Sync_Canon::id_spelling( $value ) );
	}

	public function testATextListIsSplitAsTheCoreSplitsIt(): void {
		// explode( ',' ): a space stays in the item.
		self::assertSame(
			array(
				array( 'at' => 0, 'len' => 1, 'id' => 1 ),
				array( 'at' => 2, 'len' => 2, 'id' => null ),
			),
			AB_MCP_Sync_Canon::id_tokens( '1, 2', '/,/' )
		);
		// wp_parse_list(): spaces and commas separate; items without an id are left out.
		self::assertSame(
			array(
				array( 'at' => 0, 'len' => 2, 'id' => 12 ),
				array( 'at' => 3, 'len' => 2, 'id' => 13 ),
				array( 'at' => 9, 'len' => 3, 'id' => null ),
			),
			AB_MCP_Sync_Canon::id_tokens( '12 13, 0,012', '/[\s,]+/' )
		);
		self::assertSame( array(), AB_MCP_Sync_Canon::id_tokens( '', '/,/' ) );
	}
}
