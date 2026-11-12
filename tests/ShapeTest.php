<?php
/**
 * The one reading of a value in an explained form, AB_MCP_Shape (Z8b branch
 * 3, Behebung 4, Option C1): each type of form, a form that is not whole,
 * a list as PHP 8.0 tells it, a JSON text only as its writer wrote it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Shape;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-shape.php';
require_once __DIR__ . '/../includes/sync/class-sync-canon.php';

final class ShapeTest extends TestCase {

	private const RUN = array(
		'type'   => 'int',
		'digits' => false,
		'min'    => 1,
		'max'    => null,
	);

	public function testAWholeNumberIsItsIntOrItsDigitsWhereTheFormTakesThem(): void {
		self::assertSame( 7, AB_MCP_Shape::read( 7, self::RUN ) );
		self::assertNull( AB_MCP_Shape::read( '7', self::RUN ), 'No text where the form takes none.' );
		$digits = array_replace( self::RUN, array( 'digits' => true ) );
		self::assertSame( 7, AB_MCP_Shape::read( '7', $digits ), 'Its digits, without loss.' );
		self::assertSame( PHP_INT_MAX, AB_MCP_Shape::read( (string) PHP_INT_MAX, $digits ) );
		foreach ( array( '07', ' 7', '7 ', '+7', '-7', '7.0', '1e3', '7a', '', '9223372036854775808', 7.0, 7.5, true, false, null, array( 7 ) ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $digits ), var_export( $value, true ) );
		}
		self::assertNull( AB_MCP_Shape::read( 0, self::RUN ), 'Below its least.' );
		self::assertNull( AB_MCP_Shape::read( 11, array_replace( self::RUN, array( 'max' => 10 ) ) ), 'Above its most.' );
		self::assertSame( 10, AB_MCP_Shape::read( 10, array_replace( self::RUN, array( 'max' => 10 ) ) ) );
	}

	public function testATextIsOfItsValuesOrPatternAndEmptyOnlyWhereTheFormSays(): void {
		$in = array(
			'type'      => 'string',
			'empty'     => false,
			'max_bytes' => null,
			'in'        => array( 'open', 'closed' ),
		);
		self::assertSame( 'open', AB_MCP_Shape::read( 'open', $in ) );
		foreach ( array( 'Open', 'open ', '', 5, null, true, array( 'open' ) ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $in ), var_export( $value, true ) );
		}
		$free = array(
			'type'      => 'string',
			'empty'     => true,
			'max_bytes' => 3,
		);
		self::assertSame( '', AB_MCP_Shape::read( '', $free ), 'Empty where the form says so.' );
		self::assertNull( AB_MCP_Shape::read( 'abcd', $free ), 'Past its bytes.' );
		$hex = array(
			'type'      => 'string',
			'empty'     => false,
			'max_bytes' => null,
			'pattern'   => '/^[0-9a-f]{4}\z/',
		);
		self::assertSame( 'ab12', AB_MCP_Shape::read( 'ab12', $hex ) );
		self::assertNull( AB_MCP_Shape::read( "ab12\n", $hex ) );
	}

	public function testAListIsKeyedZeroToNMinusOneInOrderWithoutPhp81(): void {
		self::assertTrue( AB_MCP_Shape::is_list( array() ) );
		self::assertTrue( AB_MCP_Shape::is_list( array( 4, 5 ) ) );
		self::assertFalse( AB_MCP_Shape::is_list( array( 1 => 4, 0 => 5 ) ), 'Keys in another order.' );
		self::assertFalse( AB_MCP_Shape::is_list( array( 0 => 4, 2 => 5 ) ), 'A gap.' );
		self::assertFalse( AB_MCP_Shape::is_list( array( 'k' => 4 ) ) );
		self::assertFalse( AB_MCP_Shape::is_list( 'x' ) );
		$list = array(
			'type'      => 'list',
			'empty'     => false,
			'item'      => self::RUN,
			'unique'    => true,
			'max_items' => 2,
		);
		self::assertSame( array( 4, 5 ), AB_MCP_Shape::read( array( 4, 5 ), $list ) );
		foreach ( array( array(), array( 4, 4 ), array( 4, 5, 6 ), array( array( 4 ) ), array( '4' ), array( 'k' => 4 ), 4 ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $list ), var_export( $value, true ) );
		}
		self::assertSame( array(), AB_MCP_Shape::read( array(), array_replace( $list, array( 'empty' => true ) ) ), 'Empty where the form says so.' );
		self::assertSame( array( 4, 4 ), AB_MCP_Shape::read( array( 4, 4 ), array_replace( $list, array( 'unique' => false ) ) ) );
	}

	public function testAMapAndARecordReadEachKeyAndField(): void {
		$map = array(
			'type'  => 'map',
			'empty' => true,
			'key'   => self::RUN,
			'value' => array( 'type' => 'bool' ),
		);
		self::assertSame( array( 7 => true ), AB_MCP_Shape::read( array( 7 => true ), $map ) );
		self::assertSame( array(), AB_MCP_Shape::read( array(), $map ) );
		foreach ( array( array( 0 => true ), array( 'k' => true ), array( 7 => 1 ), array( 7 => null ), null ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $map ), var_export( $value, true ) );
		}
		$record = array(
			'type'   => 'record',
			'fields' => array(
				'run'  => self::RUN,
				'hash' => array(
					'type'      => 'string',
					'empty'     => false,
					'max_bytes' => null,
					'nullable'  => true,
				),
			),
			'extra'  => false,
		);
		self::assertSame( array( 'run' => 3, 'hash' => null ), AB_MCP_Shape::read( array( 'run' => 3, 'hash' => null ), $record ), 'A nullable field may be null.' );
		foreach ( array( array( 'run' => 3 ), array( 'run' => null, 'hash' => 'x' ), array( 'run' => 3, 'hash' => 'x', 'more' => 1 ), array( 3, 'x' ) ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $record ), var_export( $value, true ) );
		}
		$extra = array_replace( $record, array( 'extra' => true ) );
		self::assertSame( array( 'run' => 3, 'hash' => 'x', 'more' => 1 ), AB_MCP_Shape::read( array( 'run' => 3, 'hash' => 'x', 'more' => 1 ), $extra ), 'Other keys kept as they are where extra.' );
	}

	public function testAJsonTextIsReadOnlyAsItsWriterWroteIt(): void {
		$plan = array(
			'type'  => 'json',
			'codec' => 'AB_MCP_Sync_Canon',
			'depth' => 64,
			'value' => array(
				'type'   => 'record',
				'fields' => array(
					'reverts' => array(
						'type'      => 'list',
						'empty'     => false,
						'item'      => self::RUN,
						'unique'    => true,
						'max_items' => null,
					),
				),
				'extra'  => true,
			),
		);
		self::assertSame( array( 'items' => array(), 'reverts' => array( 10 ) ), AB_MCP_Shape::read( '{"items":[],"reverts":[10]}', $plan ) );
		// A1 of the confirmation of Behebung 3, and the spellings encode() does not write.
		foreach ( array( '{"reverts":{"0":11}}', '{"reverts":{"k":11}}', '{"reverts":[10],"reverts":[11]}', '{"reverts":[10],"items":[]}', '{"items": [],"reverts":[10]}', '{"items":[],"reverts":[10]} ', '{"reverts":', '[10]', 'null', '' ) as $text ) {
			self::assertNull( AB_MCP_Shape::read( $text, $plan ), $text );
		}
		self::assertNull( AB_MCP_Shape::read( str_repeat( '[', 70 ) . str_repeat( ']', 70 ), $plan ), 'Nested past the limit.' );
		$side = array(
			'type'   => 'json',
			'codec'  => 'json',
			'flags'  => JSON_UNESCAPED_SLASHES,
			'object' => false,
			'depth'  => 4,
			'value'  => array(
				'type'   => 'record',
				'fields' => array(),
				'extra'  => true,
			),
		);
		self::assertSame( array( 'format' => 'ab-mcp-store/1' ), AB_MCP_Shape::read( '{"format":"ab-mcp-store/1"}', $side ) );
		self::assertNull( AB_MCP_Shape::read( '{"format":"ab-mcp-store\/1"}', $side ), 'A slash its writer does not escape.' );
		self::assertNull( AB_MCP_Shape::read( '{"a":1,"a":2}', $side ), 'A key twice.' );
		self::assertNull( AB_MCP_Shape::read( '{"a":1.0}', $side ), 'A number its writer writes otherwise.' );
		$book = array_replace( $side, array( 'flags' => 0, 'object' => true, 'value' => array( 'type' => 'map', 'empty' => true, 'key' => self::RUN, 'value' => self::RUN ) ) );
		self::assertSame( array(), AB_MCP_Shape::read( '{}', $book ), 'The root an object, as its writer writes it.' );
		self::assertNull( AB_MCP_Shape::read( '[]', $book ) );
	}

	public function testAFormThatIsNotWholeReadsEveryValueAsUnknown(): void {
		foreach ( array( array(), array( 'type' => 'int' ), array( 'type' => 'list', 'empty' => true ), array( 'type' => 'nothing' ), array( 'type' => 'json', 'codec' => 'json', 'depth' => 4, 'value' => array( 'type' => 'bool' ) ) ) as $form ) {
			self::assertFalse( AB_MCP_Shape::valid_form( $form ), var_export( $form, true ) );
			self::assertNull( AB_MCP_Shape::read( 5, $form ) );
			self::assertNull( AB_MCP_Shape::read( 'true', $form ) );
		}
		self::assertFalse( AB_MCP_Shape::valid_form( array_replace( self::RUN, array( 'more' => 1 ) ) ), 'A property of no type.' );
		self::assertTrue( AB_MCP_Shape::valid_form( self::RUN ) );
	}

	public function testAFieldIsTheValueOfItsKeyOrNull(): void {
		self::assertSame( 3, AB_MCP_Shape::field( array( 'a' => 3 ), 'a' ) );
		self::assertNull( AB_MCP_Shape::field( array( 'a' => 3 ), 'b' ) );
		self::assertNull( AB_MCP_Shape::field( false, 'a' ) );
	}
}
