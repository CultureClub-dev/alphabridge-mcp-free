<?php
/**
 * The rules of canon/1 one by one (AB_MCP_Sync_Canon, Z8 decision 11): the
 * addresses of both sites in every form of the replace engine neutral and
 * nothing that only begins like them; serialized values unpacked, nested
 * ones too, keys that begin with "~" kept apart from markers; an object, a
 * reference, a broken or too deep value locks; JSON with sorted keys, lists
 * in order, sets sorted, floats and bytes tagged; the version in every form;
 * the mapped form; the refusal of another version. Against WordPress itself
 * in tests/integration/SyncCanonTest.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Replace_Engine;
use AB_MCP_Sync_Canon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-canon.php';

final class SyncCanonFormTest extends TestCase {

	private const STAGING = 'https://staging.example.ch';
	private const LIVE    = 'https://www.example.ch';

	private static function ctx(): array {
		return AB_MCP_Sync_Canon::context(
			array( self::STAGING, self::LIVE . '/' ),
			static function ( int $id ) {
				return array( 1 => 'admin', 2 => 'redaktion' )[ $id ] ?? false;
			}
		);
	}

	private static function value( $raw, ?array &$acc = null ) {
		$acc = AB_MCP_Sync_Canon::acc();
		return AB_MCP_Sync_Canon::value( $raw, self::ctx(), $acc, 'post', 'meta:x' );
	}

	/* -------------------------------------------------------- addresses */

	public static function forms(): array {
		$out = array();
		foreach ( AB_MCP_Replace_Engine::FORMS as $form ) {
			$out[ $form ] = array( $form );
		}
		return $out;
	}

	#[DataProvider( 'forms' )]
	public function testBothAddressesAreNeutralInEveryFormOfTheReplaceEngine( string $form ): void {
		$staging = 'Link ' . AB_MCP_Replace_Engine::encode( $form, self::STAGING . '/kontakt/?a=1&b=2' ) . ' Ende';
		$live    = 'Link ' . AB_MCP_Replace_Engine::encode( $form, self::LIVE . '/kontakt/?a=1&b=2' ) . ' Ende';
		$a       = AB_MCP_Sync_Canon::text( $staging, self::ctx() );
		$b       = AB_MCP_Sync_Canon::text( $live, self::ctx() );
		self::assertSame( AB_MCP_Sync_Canon::encode( $a ), AB_MCP_Sync_Canon::encode( $b ) );
		self::assertIsArray( $a );
		$markers = array_values( array_filter( $a['~s'], 'is_array' ) );
		self::assertNotEmpty( $markers );
		self::assertArrayHasKey( '~addr', $markers[0] );
	}

	public function testOnlyTheAddressItselfIsNeutralNotWhatBeginsLikeIt(): void {
		foreach ( array( 'https://staging.example.chx.com/a', 'https://staging.example.ch.evil.com/', 'https://staging.example.ch:8443/', 'https://staging.example.ch-x/' ) as $text ) {
			self::assertSame( $text, AB_MCP_Sync_Canon::text( $text, self::ctx() ), $text );
		}
		foreach ( array( 'https://staging.example.ch', 'https://staging.example.ch/', 'https://staging.example.ch?x', 'Siehe https://staging.example.ch.', '"https://staging.example.ch"' ) as $text ) {
			self::assertIsArray( AB_MCP_Sync_Canon::text( $text, self::ctx() ), $text );
		}
		// The longer address wins where both would fit.
		$ctx = AB_MCP_Sync_Canon::context( array( 'https://a.ch', 'https://a.ch/sub' ) );
		self::assertSame( array( '~s' => array( array( '~addr' => 'plain' ), '/subx' ) ), AB_MCP_Sync_Canon::text( 'https://a.ch/subx', $ctx ), 'Not /sub: the path goes on.' );
		self::assertSame( array( '~s' => array( array( '~addr' => 'plain' ), '/' ) ), AB_MCP_Sync_Canon::text( 'https://a.ch/sub/', $ctx ) );
		// Neither an address without a scheme, nor anything without addresses.
		self::assertSame( '', AB_MCP_Sync_Canon::context( array( 'staging.example.ch', '' ) )['pattern'] );
	}

	/* ----------------------------------------------------------- values */

	public function testASerializedValueIsUnpackedAndAlikeAcrossSitesWhateverItsLengthPrefix(): void {
		$staging = serialize( array( 'url' => self::STAGING . '/x', 'n' => 2 ) );
		$live    = serialize( array( 'url' => self::LIVE . '/x', 'n' => 2 ) );
		self::assertNotSame( strlen( $staging ), strlen( $live ) );
		self::assertSame( AB_MCP_Sync_Canon::encode( self::value( $staging ) ), AB_MCP_Sync_Canon::encode( self::value( $live ) ) );
		self::assertArrayHasKey( '~php', self::value( $staging ) );
		// Nested serialization as well.
		$nested_a = serialize( array( 'inner' => serialize( array( 'u' => self::STAGING ) ) ) );
		$nested_b = serialize( array( 'inner' => serialize( array( 'u' => self::LIVE ) ) ) );
		self::assertSame( AB_MCP_Sync_Canon::encode( self::value( $nested_a ) ), AB_MCP_Sync_Canon::encode( self::value( $nested_b ) ) );
		// A text that only looks like a number is not unpacked.
		self::assertSame( 'a:1', self::value( 'a:1' ) );
	}

	public function testAStoredKeyThatBeginsWithATildeIsNoMarker(): void {
		$marker = self::value( serialize( array( 'sticky' => array( '~ref' => 'post', 'id' => 12 ) ) ) );
		self::assertSame( array( '~php' => array( 'sticky' => array( '~~ref' => 'post', 'id' => 12 ) ) ), $marker );
		self::assertSame( array( '~php' => array( '~~~x' => 1 ) ), self::value( serialize( array( '~~x' => 1 ) ) ) );
	}

	public static function locked(): array {
		return array(
			'an object'                 => array( 'O:8:"stdClass":1:{s:1:"a";i:1;}', '*:php_object' ),
			'an object inside an array' => array( 'a:1:{i:0;O:4:"Demo":0:{}}', '*:php_object' ),
			'an enum'                   => array( 'E:7:"Demo:A";', '*:php_object' ),
			'a reference'               => array( 'a:2:{i:0;i:1;i:1;R:2;}', '*:php_object' ),
			'a Serializable record'     => array( 'a:1:{i:0;C:4:"Demo":0:{}}', '*:php_object' ),
			'a broken value'            => array( 'a:2:{i:0;s:5:"kurz";}', '*:php_object' ),
			'a wrong length'            => array( 'a:1:{i:0;s:9:"kurz";}', '*:php_object' ),
		);
	}

	#[DataProvider( 'locked' )]
	public function testWhatCannotBeUnpackedLocksAndStaysText( string $raw, string $lock ): void {
		$form = self::value( $raw, $acc );
		self::assertSame( array( $lock ), array_column( $acc['locks'], 'key' ) );
		self::assertSame( 'meta:x', $acc['locks'][0]['at'] );
		self::assertSame( 'Z8d', $acc['locks'][0]['stage'] );
		self::assertSame( $raw, $form );
		self::assertFalse( class_exists( 'Demo', false ) );
	}

	public function testAValueThatDoesNotUnpackLocksAsUnreadable(): void {
		$read = AB_MCP_Sync_Canon::unserialized( 'a:1:{i:0;i:1;i:1;i:2;}' );
		self::assertFalse( $read['ok'] );
		self::assertSame( 'unreadable_value', $read['why'] );
		self::value( 'a:1:{i:0;i:1;i:1;i:2;}', $acc );
		self::assertSame( array( '*:unreadable_value' ), array_column( $acc['locks'], 'key' ) );
		// What is_serialized() does not take is text, as WordPress reads it.
		self::assertSame( 'a:1:{i:0;i:1;}garbage', self::value( 'a:1:{i:0;i:1;}garbage' ) );
		self::assertTrue( AB_MCP_Sync_Canon::unserialized( 'b:0;' )['ok'], 'false is a value, not a failure.' );
		self::assertFalse( AB_MCP_Sync_Canon::unserialized( 'b:0;' )['value'] );
	}

	public function testTooDeepAndTooManyLayersLock(): void {
		$deep = 'i:1;';
		for ( $i = 0; $i < AB_MCP_Sync_Canon::MAX_DEPTH + 2; $i++ ) {
			$deep = 'a:1:{i:0;' . $deep . '}';
		}
		self::value( $deep, $acc );
		self::assertSame( array( '*:unreadable_value' ), array_column( $acc['locks'], 'key' ) );

		$layered = 'x';
		for ( $i = 0; $i < AB_MCP_Sync_Canon::MAX_LAYERS + 1; $i++ ) {
			$layered = serialize( $layered );
		}
		self::value( $layered, $acc );
		self::assertSame( array( '*:unreadable_value' ), array_column( $acc['locks'], 'key' ) );
	}

	/* --------------------------------------------------------- encoding */

	public function testTheJsonIsSortedListsKeepTheirOrderSetsAreSorted(): void {
		self::assertSame( '{"a":1,"b":[3,1,2],"c":{"x":1,"y":2}}', AB_MCP_Sync_Canon::encode( array( 'c' => array( 'y' => 2, 'x' => 1 ), 'b' => array( 3, 1, 2 ), 'a' => 1 ) ) );
		self::assertSame( AB_MCP_Sync_Canon::encode( AB_MCP_Sync_Canon::set( array( 'b', 'a', array( 'z' => 1 ) ) ) ), AB_MCP_Sync_Canon::encode( AB_MCP_Sync_Canon::set( array( array( 'z' => 1 ), 'a', 'b' ) ) ) );
		self::assertNotSame( AB_MCP_Sync_Canon::encode( array( 'b', 'a' ) ), AB_MCP_Sync_Canon::encode( array( 'a', 'b' ) ) );
		// Types stay apart.
		$seen = array();
		foreach ( array( 1, '1', 1.0, true, null, '', 0, array(), array( '~b' => 'MQ==' ) ) as $v ) {
			$seen[ AB_MCP_Sync_Canon::encode( array( $v ) ) ] = true;
		}
		self::assertCount( 9, $seen );
		self::assertSame( '[{"~f":"' . bin2hex( pack( 'E', 0.1 ) ) . '"}]', AB_MCP_Sync_Canon::encode( array( 0.1 ) ) );
		self::assertSame( '[{"~b":"' . base64_encode( "\xff\xfe" ) . '"}]', AB_MCP_Sync_Canon::encode( array( "\xff\xfe" ) ) );
		self::assertSame( hash( 'sha256', '[1]' ), AB_MCP_Sync_Canon::hash( array( 1 ) ) );
	}

	public function testAFloatIsItsBitsWhateverSerializePrecisionSays(): void {
		$saved = ini_get( 'serialize_precision' );
		try {
			$forms = array();
			foreach ( array( '-1', '17', '3' ) as $precision ) {
				ini_set( 'serialize_precision', $precision );
				$forms[ $precision ] = array(
					AB_MCP_Sync_Canon::encode( self::value( 'd:0.1;' ) ),
					AB_MCP_Sync_Canon::encode( array( 1.2341 ) ),
					AB_MCP_Sync_Canon::encode( array( 1.2342 ) ),
				);
			}
		} finally {
			ini_set( 'serialize_precision', (string) $saved );
		}
		self::assertSame( $forms['-1'], $forms['17'], 'Alike under every setting.' );
		self::assertSame( $forms['-1'], $forms['3'] );
		self::assertNotSame( $forms['3'][1], $forms['3'][2], 'Two values stay two values under a low setting.' );
		self::assertSame( AB_MCP_Sync_Canon::encode( array( '~php' => 0.1 ) ), $forms['-1'][0], 'A float of a serialized value is the value WordPress reads.' );
		self::assertSame( AB_MCP_Sync_Canon::encode( self::value( 'd:0.1;' ) ), AB_MCP_Sync_Canon::encode( self::value( 'd:0.10;' ) ), 'The same value in other digits.' );
	}

	public function testTheVersionIsPartOfEveryFormAndAnotherIsRefused(): void {
		$acc    = AB_MCP_Sync_Canon::acc();
		$result = AB_MCP_Sync_Canon::finish( 'option', 'blogname', array(), array( 'value' => 'x' ), $acc );
		self::assertSame( 'canon/1', $result['form']['canon'] );
		self::assertSame( hash( 'sha256', '{"canon":"canon/1","kind":"option","value":"x"}' ), $result['hash'] );
		self::assertSame( strlen( '{"canon":"canon/1","kind":"option","value":"x"}' ), $result['size'] );
		self::assertTrue( $result['complete'] );
		$acc['unread'][] = 'options';
		self::assertFalse( AB_MCP_Sync_Canon::finish( 'option', 'blogname', array(), array(), $acc )['complete'] );

		self::assertNull( AB_MCP_Sync_Canon::version_refusal( 'canon/1' ) );
		foreach ( array( 'canon/2', '', null, 'canon/1 ' ) as $theirs ) {
			$no = AB_MCP_Sync_Canon::version_refusal( $theirs );
			self::assertInstanceOf( \WP_Error::class, $no );
			self::assertStringContainsString( 'try again', $no->get_error_message() );
		}
	}

	/* ------------------------------------------------------- references */

	public function testTheMappedFormPutsTheIdsIntoTheOtherIdSpace(): void {
		$form = array(
			'parent' => AB_MCP_Sync_Canon::ref( 'post', 12 ),
			'terms'  => AB_MCP_Sync_Canon::set( array( AB_MCP_Sync_Canon::ref( 'term', 5 ), AB_MCP_Sync_Canon::ref( 'term', 6 ) ) ),
			'author' => array( '~ref' => 'user', 'login' => 'admin' ),
			'text'   => array( '~s' => array( 'class="wp-image-', AB_MCP_Sync_Canon::ref( 'post', 12 ) + array( 'text' => true ), '"' ) ),
		);
		$map  = array(
			'post' => array( 12 => 340 ),
			'term' => array( 5 => 50, 6 => 4 ),
		);
		$live = array(
			'parent' => AB_MCP_Sync_Canon::ref( 'post', 340 ),
			'terms'  => AB_MCP_Sync_Canon::set( array( AB_MCP_Sync_Canon::ref( 'term', 4 ), AB_MCP_Sync_Canon::ref( 'term', 50 ) ) ),
			'author' => array( '~ref' => 'user', 'login' => 'admin' ),
			'text'   => array( '~s' => array( 'class="wp-image-', AB_MCP_Sync_Canon::ref( 'post', 340 ) + array( 'text' => true ), '"' ) ),
		);
		self::assertSame( AB_MCP_Sync_Canon::hash( $live ), AB_MCP_Sync_Canon::hash( AB_MCP_Sync_Canon::mapped( $form, $map ) ) );
		// Without a counterpart, never alike to any id.
		$unmapped = AB_MCP_Sync_Canon::mapped( $form, array( 'post' => array( 12 => null ) ) );
		self::assertSame( array( 'unmapped' => 12, '~ref' => 'post' ), $unmapped['parent'] );
	}

	public function testAnAuthorIsHisLogin(): void {
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( array( '~ref' => 'user', 'login' => 'redaktion' ), AB_MCP_Sync_Canon::user( 2, self::ctx(), $acc, 'post_author' ) );
		self::assertSame( array( '~ref' => 'user', 'missing' => 9 ), AB_MCP_Sync_Canon::user( 9, self::ctx(), $acc, 'post_author' ) );
		self::assertSame( 0, AB_MCP_Sync_Canon::user( 0, self::ctx(), $acc, 'post_author' ) );
		self::assertSame( array( 'user', 'user' ), array_column( $acc['refs'], 'space' ) );
		$ctx = AB_MCP_Sync_Canon::context( array(), static fn() => null );
		AB_MCP_Sync_Canon::user( 3, $ctx, $acc, 'post_author' );
		self::assertSame( array( 'post_author' ), $acc['unread'], 'A login that could not be read leaves the object incomplete.' );
	}
}
