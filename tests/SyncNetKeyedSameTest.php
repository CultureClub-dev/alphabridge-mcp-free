<?php
/**
 * The rebuilt AB_MCP_Sync_Net::keyed() answers as keyed() did at 8daf33f
 * (Z8b branch 5, R12b part 1a, section 5a of the order): the copy of
 * 8daf33f (tests/support/keyed-8daf33f.php, F3-4) against keyed(), the same
 * answer (===) for the raw text and every layer layers() gives of it, as
 * over_name() asks, over
 * (i)   the 2656 paths of case M0 of part 1 (tests/fixtures/keyed-same.json),
 * (ii)  the 36 other cases of part 1 with K = 0, 10, 100 and 1000 extra keys
 *       (qxk0001 …), there held() and over_name() against what part 1
 *       measured (belege/r12b-t1-messung-roh.jsonl);
 * (iii) the new cases of part 1b: a long word before many signs (M1c), signs
 *       with blanks between them, many lengths of keys, invalid UTF-8 and a
 *       prefix \0…\0, also where held() does not reach them; and what the
 *       mutations of part 1a.2 found missing, each with every key alone
 *       that meets at one of its signs (keys_at_signs());
 * (iv)  the names of the tests of keyed(), over_name() and held() in
 *       tests/SyncEnvTest.php;
 * (v)   texts and lists of keys drawn with a fixed seed, and (i) to (iii)
 *       with one byte replaced, put in or taken out;
 * with the fixed cases of the order (E4, E8, F3-3, the mutation of B4), types
 * other than strings (F3-3), and for each property E1 to E12 the count of
 * the inputs that meet it: red where one meets none.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Redactor;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Net;
use AB_Test_Keyed_8daf33f;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/support/keyed-8daf33f.php';

final class SyncNetKeyedSameTest extends TestCase {

	/** The seed of (v). */
	private const SEED = 20261114;

	/** The count of drawn texts in (v). */
	private const DRAWN = 4000;

	/** Each input of (i) to (iii) changed this many times in (v), for every so many inputs. */
	private const CHANGED = array( 3, 7 );

	/**
	 * The cases of part 1 whose held() and over_name() need the core: the
	 * HTML decoder (the layer html, AB_MCP_Sync_Net::decoded()) and the HTML
	 * API of the content check, which the unit suite does not load. There
	 * tests/integration/SyncEnvTest.php reads them; keyed() is compared here
	 * over the layers the unit suite gives, there over those of WordPress.
	 */
	private const WITH_THE_CORE = array( 'M2-nl64-voll', 'M2-nl65-voll', 'M2-max-ent', 'M3a', 'M3b', 'M3c', 'M4d', 'M4e', 'M4f' );

	/** The blanks of Unicode the order names (5a.5 (v)), UTF-8. */
	private const BLANKS = array( "\u{00A0}", "\u{2000}", "\u{2003}", "\u{200A}", "\u{200B}", "\u{3000}", "\u{FEFF}" );

	/** @var array<string,int> The inputs that meet each property, over every test of this class. */
	private static $met = array();

	/** @var array<string,array<int,array{0:string,1:array}>>|null The inputs, by group. */
	private static $inputs = null;

	protected function setUp(): void {
		ab_test_reset();
	}

	/* -------------------------------------------------------- the fixed cases */

	public function testTheFixedCasesOfTheOrderAnswerAsBefore(): void {
		$cases = array(
			array( '.:', array( '' ), true ),               // E4: an empty key in the last word (names_credential()).
			array( 'Ä:', array( 'Ä' ), true ),               // E4: a key as given, no lower case of Unicode.
			array( "\0\xFF:\0x  y:", array( 'x_y' ), true ), // E8: a second \0 frees valid UTF-8 again.
			array( "x \t: y:", array( 'x_:_y' ), true ),     // B4: the blank cut before a sign counts inside a later text.
			array( 'note:EXAMPLE.php', array(), false ),
			array( 'reset-password:guide.php', array(), true ),
		);
		foreach ( $cases as $case ) {
			list( $text, $extra, $want ) = $case;
			self::assertSame( $want, AB_Test_Keyed_8daf33f::keyed( $text, $extra ), bin2hex( $text ) . ': the copy of 8daf33f.' );
			self::assertSame( $want, AB_MCP_Sync_Net::keyed( $text, $extra ), bin2hex( $text ) );
		}
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( '%00%FF:%00x%20%20y:', array( 'x_y' ) ), 'E8 through held(): raw/percent.' );
		self::assertNull( AB_MCP_Sync_Env::held( 'note:EXAMPLE.php', array( 42 ) ), 'F3-3: a key that is no string, as before.' );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( 'note:42:', array( 42 ) ), 'F3-3.' );
	}

	public function testTypesOtherThanStringsTakeTheWayAsBefore(): void {
		$texts  = array( 42, 4.5, 0, true, false, null, self::stringable( 'password:' ), self::stringable( 'note:x' ), '42:', 'note:4.5:', 'a1:' );
		$extras = array( array( 42 ), array( 4.5 ), array( true ), array( false ), array( null ), array( 0 ), array( self::stringable( 'vault' ) ), array( 'vault', 42 ), array( 42, 'vault' ), array( '1' ), array() );
		$n      = 0;
		foreach ( $texts as $text ) {
			foreach ( $extras as $extra ) {
				foreach ( array( $text, 'vault:' . self::shown( $text ), self::shown( $text ) . ':' ) as $one ) {
					self::assertSame( AB_Test_Keyed_8daf33f::keyed( $one, $extra ), AB_MCP_Sync_Net::keyed( $one, $extra ), self::shown( $one ) . ' ' . (string) json_encode( $extra ) );
					++$n;
				}
			}
		}
		self::assertSame( 363, $n );
		self::assertTrue( AB_MCP_Sync_Net::keyed( 'vault:', array( self::stringable( 'vault' ) ) ), 'A key that is no string is read as its text, as before.' );
		self::assertTrue( AB_MCP_Sync_Net::keyed( '42:', array( 42 ) ) );
		self::assertTrue( AB_MCP_Sync_Net::keyed( self::stringable( 'password:' ), array() ), 'A name that is no string, as its text.' );
		self::assertFalse( AB_MCP_Sync_Net::keyed( 42, array( 42 ) ), 'No sign in it.' );
	}

	/* ------------------------------------------------------- the five groups */

	public function testThe2656PathsOfM0AnswerAsBefore(): void {
		$data = self::fixture();
		self::assertCount( 2656, $data['m0'] );
		foreach ( self::k_lists() as $k => $extra ) {
			foreach ( $data['m0'] as $name ) {
				self::assertNull( AB_MCP_Sync_Env::held( $name, $extra ), $name . ', K = ' . $k . ': held() as in part 1.' );
				self::assertNull( AB_MCP_Sync_Net::over_name( $name, $extra, true ), $name );
			}
		}
		self::assertSame( 4 * 2656, self::same( 'i' ) );
	}

	public function testTheCasesOfPartOneAnswerAsBefore(): void {
		$data = self::fixture();
		self::assertCount( 36, $data['faelle'] );
		$lists = self::k_lists();
		$held  = 0;
		$over  = 0;
		foreach ( $data['faelle'] as $case ) {
			if ( in_array( $case['id'], self::WITH_THE_CORE, true ) ) {
				continue;
			}
			++$held;
			foreach ( $lists as $k => $extra ) {
				$name = $case['namen'][ (string) $k ];
				self::assertSame( $case['held'][ (string) $k ], AB_MCP_Sync_Env::held( $name, $extra ), $case['id'] . ', K = ' . $k . ': held() as in part 1.' );
				if ( array_key_exists( (string) $k, $case['over_name'] ) ) {
					self::assertSame( $case['over_name'][ (string) $k ], AB_MCP_Sync_Net::over_name( $name, $extra, true ), $case['id'] . ', K = ' . $k . ': over_name() as in part 1.' );
					++$over;
				}
			}
		}
		self::assertSame( 27, $held, 'The cases the unit suite reads as WordPress does.' );
		self::assertSame( 88, $over, 'over_name() where part 1 reached it.' );
		self::assertSame( 4 * 36, self::same( 'ii' ) );
	}

	public function testTheNewCasesOfPartOneBAnswerAsBefore(): void {
		self::assertGreaterThan( 100, self::same( 'iii' ) );
		// Where held() reaches them (C1 of Codex over version 3): the long
		// word before the signs is a clean name; the prefix \0…\0 and
		// invalid UTF-8 as the raw text.
		foreach ( array( 256, 512, 683 ) as $w ) {
			self::assertNull( AB_MCP_Sync_Env::held( self::m1c( $w ), array() ), 'M1c, w = ' . $w );
		}
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( "\0\xFF:\0x  y:", array( 'x_y' ) ) );
		self::assertSame( 'credential_field', AB_MCP_Sync_Env::held( "\0EXAMPLE\0password:x", array() ) );
	}

	public function testTheNamesOfTheTestsOfSyncEnvAnswerAsBefore(): void {
		self::assertGreaterThan( 400, self::same( 'iv' ) );
	}

	public function testDrawnAndChangedInputsAnswerAsBefore(): void {
		self::assertGreaterThan( self::DRAWN, self::same( 'v' ) );
	}

	/**
	 * Each property E1 to E12 met by at least one input of the groups
	 * (5a.5, «Abdeckung»); E12-limit counts a pattern that fails at a limit
	 * of PCRE, not at UTF-8 (none expected; the order: a finding if it shows).
	 * Inputs with more than 60 keys are not read here: their texts are in
	 * the groups with 0 and 10 keys as well, and the keys alone meet E3 to
	 * E5, which the comparison reads.
	 */
	public function testEachPropertyIsMetByAnInput(): void {
		$met = array_fill_keys( array( 'E1', 'E2', 'E2-kept', 'E3', 'E4', 'E4-empty', 'E5', 'E6', 'E7', 'E7-inner', 'E7-end', 'E8', 'E8-again', 'E9', 'E10', 'E10-later', 'E11-long', 'E11-none', 'E11-high', 'E12', 'E12-limit' ), 0 );
		$all = 0;
		foreach ( self::inputs() as $group ) {
			foreach ( $group as $input ) {
				if ( count( $input[1] ) > 60 ) {
					continue;
				}
				foreach ( self::texts( $input[0] ) as $text ) {
					self::meets( $text, $input[1], $met );
					++$all;
				}
			}
		}
		$report = getenv( 'AB_KEYED_SAME_REPORT' );
		if ( is_string( $report ) && '' !== $report ) {
			file_put_contents( $report, (string) json_encode( array( 'texts' => $all, 'met' => $met ), JSON_PRETTY_PRINT ) . "\n" );
		}
		foreach ( $met as $property => $count ) {
			if ( 'E12-limit' !== $property ) {
				self::assertGreaterThan( 0, $count, $property . ' is met by no input: ' . (string) json_encode( $met ) );
			}
		}
		self::assertSame( 0, $met['E12-limit'], 'A pattern of the copy failed at a limit of PCRE: a finding for the lead (E12).' );
	}

	/* ---------------------------------------------------------- comparison */

	/**
	 * The copy of 8daf33f against keyed() for every input of a group, the
	 * raw text and each of its layers; every difference with its input.
	 *
	 * @return int The inputs compared.
	 */
	private static function same( string $group ): int {
		$differ = array();
		$n      = 0;
		foreach ( self::inputs()[ $group ] as $input ) {
			list( $text, $extra ) = $input;
			foreach ( self::texts( $text ) as $layer ) {
				$old = AB_Test_Keyed_8daf33f::keyed( $layer, $extra );
				$new = AB_MCP_Sync_Net::keyed( $layer, $extra );
				if ( $old !== $new && count( $differ ) < 20 ) {
					$differ[] = array( 'text' => bin2hex( $layer ), 'extra' => array_map( 'bin2hex', $extra ), 'old' => $old, 'new' => $new );
				}
			}
			++$n;
		}
		self::assertSame( array(), $differ, 'keyed() answers otherwise than at 8daf33f (hex): ' . (string) json_encode( $differ ) );
		return $n;
	}

	/**
	 * The raw text and every layer of it as over_name() reads them.
	 *
	 * @return string[]
	 */
	private static function texts( string $text ): array {
		$out = array( $text );
		foreach ( AB_MCP_Sync_Net::layers( $text ) as $layer ) {
			if ( $layer[1] !== $text ) {
				$out[] = $layer[1];
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------- inputs */

	/** @return array<string,array<int,array{0:string,1:array}>> */
	private static function inputs(): array {
		if ( null !== self::$inputs ) {
			return self::$inputs;
		}
		$data  = self::fixture();
		$lists = self::k_lists();
		$in    = array( 'i' => array(), 'ii' => array(), 'iii' => array(), 'iv' => array(), 'v' => array() );
		foreach ( $lists as $extra ) {
			foreach ( $data['m0'] as $name ) {
				$in['i'][] = array( $name, $extra );
			}
		}
		foreach ( $data['faelle'] as $case ) {
			foreach ( $lists as $k => $extra ) {
				$in['ii'][] = array( $case['namen'][ (string) $k ], $extra );
			}
		}
		$in['iii'] = self::new_cases( $data, $lists );
		foreach ( self::names_of_sync_env() as $name ) {
			foreach ( array( array(), array( 'vault' ), array( 'vault', 'my_vault' ), array( 'my_vault' ), array( '' ) ) as $extra ) {
				$in['iv'][] = array( $name, $extra );
			}
		}
		$in['v']      = self::drawn( array_merge( $in['i'], $in['ii'], $in['iii'] ) );
		self::$inputs = $in;
		return $in;
	}

	/** @return array{m0:string[],faelle:array<int,array>} */
	private static function fixture(): array {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/keyed-same.json' ), true );
		self::assertIsArray( $data );
		return $data;
	}

	/**
	 * The lists of K extra keys of part 1: qxk0001 … (K = 0, 10, 100, 1000).
	 *
	 * @return array<int,string[]>
	 */
	private static function k_lists(): array {
		$out = array();
		foreach ( array( 0, 10, 100, 1000 ) as $k ) {
			$keys = array();
			for ( $i = 1; $i <= $k; $i++ ) {
				$keys[] = sprintf( 'qxk%04d', $i );
			}
			$out[ $k ] = $keys;
		}
		return $out;
	}

	/** M1c: a word of w bytes of [A-Za-z0-9_\-.] that names no credential, then = : ; " ' in turn to 1024 bytes. */
	private static function m1c( int $w, string $word = 'x' ): string {
		return substr( str_repeat( $word, $w ), 0, $w ) . substr( str_repeat( '=:;"\'', 205 ), 0, 1024 - $w );
	}

	/**
	 * (iii): the new cases of part 1b (section 5 of the order).
	 *
	 * @param array               $data  The fixture.
	 * @param array<int,string[]> $lists The lists of K extra keys.
	 * @return array<int,array{0:string,1:array}>
	 */
	private static function new_cases( array $data, array $lists ): array {
		$texts = array();
		$out   = array();
		$own   = array( array(), array( 'x_y' ), array( 'a_b' ), array( 'vault', 'my_vault' ), array( '' ), array( 'Ä', 'x_:_y', '_x', 'a ', 'b;c' ) );
		// M1c, and the same filling in the case of M2 with 64 layers of the
		// whole text (there the three lengths of the order and K up to 100:
		// the copy needs seconds for each over its layers).
		$m2 = '';
		foreach ( $data['faelle'] as $case ) {
			if ( 'M2-nl64-voll' === $case['id'] ) {
				$m2 = substr( $case['namen']['0'], 0, (int) strpos( $case['namen']['0'], 'a=a:' ) );
			}
		}
		self::assertNotSame( '', $m2 );
		foreach ( array( 1, 2, 100, 255, 256, 257, 512, 683, 1000, 1023 ) as $w ) {
			$texts[] = self::m1c( $w );
			$texts[] = self::m1c( $w, 'aB3_-.z' );
		}
		foreach ( array( 256, 512, 683 ) as $w ) {
			foreach ( array( $own[0], $own[1], $lists[10], $lists[100] ) as $extra ) {
				$out[] = array( $m2 . self::m1c( $w ), $extra );
			}
		}
		$texts[] = self::m1c( 300, "z\xC3\xA9" );
		$texts[] = str_repeat( 'x', 1500 ) . str_repeat( '=:;"\'', 300 );
		$texts[] = str_repeat( 'x', 3000 ) . ':';
		// Signs with blanks between them, of ASCII and of Unicode.
		$between = array( ' ', "\t", "\u{00A0}", "\u{2003}", "\u{200B}", "\u{FEFF}", "\u{3000}", " \u{00A0} ", "\n\u{FEFF}" );
		foreach ( $between as $i => $blank ) {
			$signs = '';
			foreach ( str_split( str_repeat( '=:;"\'', 70 ) ) as $sign ) {
				$signs .= $sign . $blank;
			}
			$texts[] = 'a' . substr( $signs, 0, 1000 );
			$texts[] = 'password' . $blank . substr( $signs, 0, 400 );
			$texts[] = $blank . 'my' . $blank . 'vault' . $blank . substr( $signs, 0, 300 ) . 'x' . $blank . $between[ ( $i + 1 ) % count( $between ) ] . 'token' . $blank . ':';
		}
		// Invalid UTF-8 and a prefix \0…\0, raw and in decoded layers.
		foreach ( array( "\0\xFF:\0x  y:", '%00%FF:%00x%20%20y:', "\xFFpassword:", "pass\xFFword:", "\0EXAMPLE\0password:x", "\0\0token=", "\0token:", '%00abc%00token=x', base64_encode( "\0x\0password:" ), "\xC3:\xA9password:", "x\0:y\0z:", "\0\xFF\xFE:\0a b:", "\0 \0 password:", "\0\xFF:x\0\u{00A0}my vault:", "\xED\xA0\x80:auth:", "\xC0\xAFtoken:", "\xF5:a\0b:", "\0" . str_repeat( 'a:', 300 ) . "\0b:token:", "\0\xFF" . str_repeat( 'Ab-c ', 60 ) . ":\0my-vault :", str_repeat( "\xFF", 300 ) . ':' . str_repeat( 'x', 300 ) . ':' ) as $text ) {
			$texts[] = $text;
		}
		// What the mutations of part 1a.2 showed missing (r12b-1a2-rot.log), each
		// with every key alone that meets at one of its signs (keys_at_signs()),
		// and with the lists of $own and of K keys: a name over an inner
		// sign and the blank after it; after a part that is no UTF-8, a part
		// whose blanks the pattern joins otherwise than str_replace(); a dash
		// in a part of bytes normalize_key() leaves otherwise; U+FEFF and
		// U+200B at the start of the text (the keys _x and _token of $own); a
		// word with parts at «.» after a byte that ends no word.
		$gaps = array();
		foreach ( array( ' ', "\t", '  ', "\u{00A0}", " \u{3000} ", ' - ' ) as $blank ) {
			$gaps[] = 'x' . $blank . ':' . $blank . 'y' . $blank . ':' . $blank . 'z:' . $blank . 'w=';
			$gaps[] = "\xFF:x" . $blank . 'y:' . $blank . 'z-' . $blank . 'w:';
		}
		foreach ( array( 'a-b/c:', 'my-vault/x:', 'x--y/z=', '-x_y/z:', 'a-b:c-d/e;' ) as $text ) {
			$gaps[] = $text;
		}
		foreach ( array( "\u{FEFF}", "\u{200B}", "\u{FEFF}\u{200B}", "\u{200B} \u{FEFF}" ) as $lead ) {
			$gaps[] = $lead . ' x:';
			$gaps[] = $lead . "\u{00A0}token=";
			$gaps[] = $lead . '-x:a:';
		}
		foreach ( array( '/', '(', '#', ',', '+', '@', "\0" ) as $byte ) {
			foreach ( array( 'token.v1', 'pass.x', 'secret.1', 'auth.v1', 'vault.v1', 'user_pass.x' ) as $word ) {
				$gaps[] = 'note' . $byte . $word . ':';
			}
		}
		foreach ( $gaps as $text ) {
			$texts[] = $text;
			foreach ( self::keys_at_signs( $text ) as $keys ) {
				$out[] = array( $text, $keys );
			}
		}
		foreach ( $texts as $text ) {
			foreach ( array_merge( $own, array( $lists[0], $lists[10], $lists[100] ) ) as $extra ) {
				$out[] = array( $text, $extra );
			}
		}
		foreach ( array( 256, 512, 683 ) as $w ) {
			$out[] = array( self::m1c( $w ), $lists[1000] );
		}
		// Many lengths of keys, no key in the text (Codex B5): K keys of
		// lengths 1 to K, over the long texts of part 1 and M1c.
		$long = array( self::m1c( 683 ) );
		foreach ( $data['faelle'] as $case ) {
			if ( in_array( $case['id'], array( 'M1b1', 'M1b4', 'M2-nl64-voll', 'M4a' ), true ) ) {
				$long[] = $case['namen']['0'];
			}
		}
		foreach ( array( 10, 100, 1000 ) as $k ) {
			$keys = array();
			for ( $i = 1; $i <= $k; $i++ ) {
				$keys[] = 'q' . str_repeat( 'k', $i - 1 );
			}
			foreach ( $long as $text ) {
				$out[] = array( $text, $keys );
			}
		}
		return $out;
	}

	/**
	 * Lists of one key for a text: the ends of the text before each sign (2
	 * to 24 bytes) in lower case and as normalize_key() reads it, without
	 * those the copy of 8daf33f holds at a sign before it; one key a list,
	 * so that a short end, which a wrong reading may meet as well, does not
	 * answer for a long one (part 1a.2: in one list for each sign the short
	 * ends kept two mutations unseen).
	 *
	 * @return array<int,string[]>
	 */
	private static function keys_at_signs( string $text ): array {
		$keys = array();
		$size = strlen( $text );
		$at   = strcspn( $text, '=:;"\'' );
		while ( $at < $size ) {
			$before = rtrim( substr( $text, 0, $at ), " \t\n\r\x0B\f" );
			foreach ( array( strtolower( $before ), AB_MCP_Redactor::normalize_key( $before ) ) as $form ) {
				foreach ( array( 2, 3, 4, 6, 9, 12, 16, 24 ) as $n ) {
					$key = substr( $form, -$n );
					if ( '' !== $key && ! AB_Test_Keyed_8daf33f::keyed( substr( $text, 0, $at ), array( $key ) ) ) {
						$keys[ $key ] = true;
					}
				}
			}
			$at += 1 + strcspn( $text, '=:;"\'', $at + 1 );
		}
		$out = array();
		foreach ( array_keys( $keys ) as $key ) {
			$out[] = array( (string) $key );
		}
		return $out;
	}

	/**
	 * (iv): the names the tests of keyed(), over_name() and held() in
	 * tests/SyncEnvTest.php give (lines 519 to 747 at 8daf33f).
	 *
	 * @return string[]
	 */
	private static function names_of_sync_env(): array {
		$names = array( 'password=', 'password=EXAMPLE_SECRET.php', 'my-plugin/api_key=EXAMPLE_SECRET.php', 'token%3DEXAMPLE_SECRET', base64_encode( 'password=EXAMPLE_SECRET' ) . '.php', '{"secret":"EXAMPLE_SECRET"}', str_repeat( '[', 80 ) . str_repeat( ']', 80 ), 'akismet/akismet.php', 'reset-password/reset-password.php', 'woocommerce_api_keys', 'wp-oauth-server/wp-oauth.php', '2026/10/photo-150x150.jpg', 'gf_form', 'twentytwentyfive', 'secret-santa.php', 'vault=1.php', 'x=1.php', 'password:EXAMPLE_SECRET.php', 'my-plugin/api_key:EXAMPLE_SECRET.php', 'token : EXAMPLE_SECRET', 'abenv_password:EXAMPLE_SECRET', 'keep/client_secret:EXAMPLE_SECRET/f.txt', '-password:EXAMPLE_SECRET.php', '.token:EXAMPLE_SECRET.php', base64_encode( 'password:EXAMPLE_SECRET' ) . '.php', 'reset-password/reset-password.php', 'wp-oauth-server/wp-oauth-server.php', 'password-protected/password-protected.php', 'note:EXAMPLE.php', 'a:b:c', 'vault:EXAMPLE_SECRET.php', 'password:EXAMPLE_SECRET' );
		$nested = 'password=x';
		for ( $i = 0; $i < 8; $i++ ) {
			$nested = base64_encode( $nested );
		}
		$names[] = $nested;
		$names   = array_merge(
			$names,
			array( 'note:password:EXAMPLE_SECRET.php', 'password:;EXAMPLE_SECRET.php', 'x.token = EXAMPLE_SECRET', 'api_key:EXAMPLE_SECRET', 'api_key:EXAMPLE_SECRET/api.php', 'keep/note:password:EXAMPLE_SECRET/f.txt', 'PASSWORD=EXAMPLE_SECRET', 'Password : EXAMPLE_SECRET', 'accessToken:EXAMPLE_SECRET.php', 'my-oauth-server:EXAMPLE_SECRET.php', 'password:', 'a=b:token=;EXAMPLE_SECRET', 'xpassword:EXAMPLE_SECRET', 'xpassWord:EXAMPLE_SECRET', 'password:x:b.php', 'keep/oauth_server:EXAMPLE_SECRET', 'keep/oauth_server :x', 'xpassWord :x', 'my-auth:EXAMPLE_SECRET', rawurlencode( 'password:;EXAMPLE_SECRET' ) . '.php', base64_encode( 'note:password:EXAMPLE_SECRET' ) . '.php', 'reset-password:guide.php' ),
			array( 'reset-password', 'wp-oauth-server', 'password-protected', 'my-token-plugin', 'password-x:EXAMPLE.php', 'tokens.php', 'R&D notes.pdf', 'note:vault:EXAMPLE_SECRET.php', 'vault:;EXAMPLE_SECRET', 'my-vault=EXAMPLE_SECRET', 'myVault : EXAMPLE_SECRET', 'MY_VAULT:x', 'my vault:x', 'vault-plugin/vault.php', 'Password: reset' ),
			array( 'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}.php', 'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}', '{"password" : "EXAMPLE_SECRET"}', "{'api_key' : 'EXAMPLE_SECRET'}.php", 'password;EXAMPLE_SECRET.php', 'x.token ; EXAMPLE_SECRET', 'password"EXAMPLE_SECRET.php', "keep/client_secret'EXAMPLE_SECRET/f.txt", 'note;password;EXAMPLE_SECRET.php', 'keep/oauth_server"x', base64_encode( 'a:1:{s:8:"password";s:14:"EXAMPLE_SECRET";}' ) . '.php', 'reset-password;v2.php', "O'Reilly password's.pdf", '<input name="password" value="EXAMPLE_SECRET">.php', 'note;EXAMPLE.php', "O'Reilly notes.pdf", 'say "hello".txt', 'R&D notes.php', 'vault;EXAMPLE_SECRET.php' )
		);
		self::assertCount( 89, $names );
		return $names;
	}

	/**
	 * (v): texts drawn with a fixed seed from an alphabet that meets each
	 * property, with lists of 0 to 1000 keys; and inputs of (i) to (iii) with
	 * one byte replaced, put in or taken out.
	 *
	 * @param array<int,array{0:string,1:array}> $given (i) to (iii).
	 * @return array<int,array{0:string,1:array}>
	 */
	private static function drawn( array $given ): array {
		mt_srand( self::SEED );
		$atoms = array_merge(
			array( '=', ':', ';', '"', "'", '=:', ' ', '  ', "\t", "\n", "\r", "\x0B", "\f", "\0", "\0\0", '-', '_', '.', '~', '--', ' - ', '%', '&amp;', '\\', '/' ),
			self::BLANKS,
			array( 'a', 'b', 'z', 'Z', 'AB', 'Cd', 'xY', 'aB', 'ABc', 'XMLHttp', 'accessToken', 'APIKey', 'MyVault', 'my vault', 'my-vault', 'API_KEY', 'q9', '0', '9', '42' ),
			array( 'é', 'Ä', '日本', "\xF0\x9F\x98\x80", "\xFF", "\x80", "\xC3", "\xE2\x80", "\xED\xA0\x80", "\xC0\xAF", "\xF5" ),
			AB_MCP_Redactor::CREDENTIAL_EXACT,
			AB_MCP_Redactor::EXTRA_NAMES,
			AB_MCP_Redactor::CREDENTIAL_NEEDLES,
			array( 'vault', 'x_y', 'note', 'example', 'reset', 'v1', 'has', 'is' )
		);
		$last = count( $atoms ) - 1;
		$out  = array();
		for ( $i = 0; $i < self::DRAWN; $i++ ) {
			$size = mt_rand( 1, 100 );
			if ( $size > 97 ) {
				$size = mt_rand( 300, 900 );
			} elseif ( $size > 85 ) {
				$size = mt_rand( 40, 250 );
			} else {
				$size = mt_rand( 1, 30 );
			}
			$text = '';
			if ( mt_rand( 1, 6 ) === 1 ) {
				$text = "\0";
			}
			for ( $j = 0; $j < $size; $j++ ) {
				$text .= $atoms[ mt_rand( 0, $last ) ];
			}
			$out[] = array( $text, self::drawn_keys( $text, strlen( $text ) > 2000 ) );
		}
		// One byte replaced, put in or taken out.
		$bytes = implode( '', $atoms );
		$top   = strlen( $bytes ) - 1;
		foreach ( $given as $n => $input ) {
			if ( 0 !== $n % self::CHANGED[1] || '' === $input[0] ) {
				continue;
			}
			for ( $c = 0; $c < self::CHANGED[0]; $c++ ) {
				$text = $input[0];
				$at   = mt_rand( 0, strlen( $text ) - 1 );
				$byte = $bytes[ mt_rand( 0, $top ) ];
				if ( 0 === $c ) {
					$text[ $at ] = $byte;
				} elseif ( 1 === $c ) {
					$text = substr( $text, 0, $at ) . $byte . substr( $text, $at );
				} else {
					$text = substr( $text, 0, $at ) . substr( $text, $at + 1 );
				}
				$out[] = array( $text, $input[1] );
			}
		}
		return $out;
	}

	/**
	 * A list of keys for a drawn text: empty ones, twice the same, not
	 * normalized, in upper case, with a sign, with _ first, with blanks,
	 * many lengths, and ends of the text itself (so that some meet).
	 *
	 * @return string[]
	 */
	private static function drawn_keys( string $text, bool $few ): array {
		$pool  = array( '', '', 'vault', 'vault', 'Vault', 'my-vault', 'my vault', 'my_vault', ' vault', 'vault ', 'MyVault', 'x_:_y', 'a=b', "o'r", '_x', '_token', "\t", 'Ä', 'é', 'x_y', 'a_b', 'qxk0001', 'z', '42', '0' );
		$count = mt_rand( 1, 100 );
		if ( $count > 95 && ! $few ) {
			$count = mt_rand( 101, 1000 );
		} elseif ( $count > 70 ) {
			$count = mt_rand( 6, 60 );
		} else {
			$count = mt_rand( 0, 5 );
		}
		$keys = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$pick = mt_rand( 1, 10 );
			if ( $pick <= 3 ) {
				$keys[] = $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
			} elseif ( $pick <= 5 ) {
				$keys[] = 'q' . str_repeat( 'z', mt_rand( 0, 40 ) );
			} elseif ( '' !== $text ) {
				// An end of the text before a sign (every other one: the next
				// sign from a place drawn; part 1a.2 found too few names that
				// meet at a sign over a part before it), lower case or as
				// normalize_key() reads it, or a part of the text.
				$at = mt_rand( 0, strlen( $text ) - 1 );
				if ( mt_rand( 0, 1 ) === 1 ) {
					$at += strcspn( $text, '=:;"\'', $at );
				}
				$before = rtrim( substr( $text, 0, $at ), " \t\n\r\x0B\f" );
				$form   = mt_rand( 0, 1 ) === 1 ? AB_MCP_Redactor::normalize_key( $before ) : strtolower( $before );
				$keys[] = substr( $form, -mt_rand( 1, 24 ) );
			}
		}
		return $keys;
	}

	/* ---------------------------------------------------------- properties */

	/**
	 * Which properties E1 to E12 an input meets, read with the steps of the
	 * copy of 8daf33f and of normalize_key() (RD:300–312): each counted once
	 * for an input.
	 *
	 * @param string             $text  A text.
	 * @param array              $extra Its keys.
	 * @param array<string,int>  $met   The counts.
	 */
	private static function meets( string $text, array $extra, array &$met ): void {
		foreach ( $extra as $key ) {
			if ( ! is_string( $key ) ) {
				return;
			}
		}
		if ( ! preg_match_all( '/[=:;"\']/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		$keys  = array_merge( AB_MCP_Redactor::CREDENTIAL_EXACT, AB_MCP_Redactor::EXTRA_NAMES, $extra );
		$at    = array_column( $m[0], 1 );
		$flags = array();
		foreach ( $at as $n => $p ) {
			if ( $n > 0 && $at[ $n - 1 ] === $p - 1 ) {
				$flags['E1'] = true;
			}
		}
		if ( in_array( '', $extra, true ) ) {
			$flags['E4-empty'] = true;
		}
		$cuts = array();
		$back = array();
		foreach ( $at as $n => $p ) {
			$raw    = substr( $text, 0, $p );
			$before = rtrim( $raw, " \t\n\r\x0B\f" );
			if ( $before !== $raw ) {
				$flags['E2'] = true;
				if ( $n < count( $at ) - 1 ) {
					$flags['E10-later'] = true;
				}
			}
			if ( '' !== $before && ( "\0" === substr( $before, -1 ) || self::ends_blank( $before ) ) ) {
				$flags['E2-kept'] = true;
			}
			// normalize_key(), step by step.
			$key = $before;
			if ( "\0" === substr( $key, 0, 1 ) ) {
				$end    = strpos( $key, "\0", 1 );
				$cuts[] = false === $end ? 1 : $end + 1;
				$key    = false === $end ? substr( $key, 1 ) : substr( $key, $end + 1 );
			}
			$trimmed = preg_replace( '/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $key );
			$utf     = is_string( $trimmed );
			$back[]  = $utf;
			if ( ! $utf ) {
				$flags['E8']  = true;
				$flags['E12'] = true;
				if ( PREG_BAD_UTF8_ERROR !== preg_last_error() ) {
					$flags['E12-limit'] = true;
				}
				$trimmed = trim( $key );
			} else {
				if ( 1 === preg_match( '/^[\s\p{Z}\x{200B}\x{FEFF}]+/u', $key, $lead ) && 1 === preg_match( '/[\x80-\xFF]/', $lead[0] ) ) {
					$flags['E7'] = true;
				}
				if ( 1 === preg_match( '/[=:;"\'][\x{00A0}\x{2000}-\x{200B}\x{3000}\x{FEFF}]/u', $trimmed ) ) {
					$flags['E7-inner'] = true;
				}
				if ( $n > 0 && self::ends_blank( $key ) ) {
					$flags['E7-end'] = true;
				}
			}
			$split = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1_$2', $trimmed );
			$split = is_string( $split ) ? preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $split ) : null;
			if ( ! is_string( $split ) ) {
				$flags['E12']       = true;
				$flags['E12-limit'] = true;
				$split              = '';
			}
			if ( $split !== $trimmed ) {
				$flags['E9'] = true;
			}
			$lower  = strtolower( $split );
			$joined = preg_replace( '/[\s\p{Z}\-]+/u', '_', $lower );
			if ( is_string( $joined ) ) {
				if ( 1 === preg_match( '/[\s\p{Z}\-]{2}/u', $lower ) ) {
					$flags['E10'] = true;
				}
				$form = $joined;
			} else {
				if ( $utf ) {
					$flags['E12-limit'] = true;
				}
				$form = str_replace( array( '-', ' ' ), '_', $lower );
			}
			self::meets_keys( array( strtolower( $before ), $form ), $keys, $flags );
			if ( preg_match( '/[A-Za-z0-9_\-.\x80-\xFF]+\z/', $before, $word ) ) {
				if ( strlen( $word[0] ) > 255 ) {
					$flags['E11-long'] = true;
				}
				if ( 1 === preg_match( '/[\x80-\xFF]/', $word[0] ) ) {
					$flags['E11-high'] = true;
				}
			} elseif ( '' !== $before ) {
				$flags['E11-none'] = true;
			}
		}
		if ( count( array_unique( $cuts ) ) > 1 ) {
			$flags['E6'] = true;
		}
		$was = true;
		foreach ( $back as $utf ) {
			if ( ! $was && $utf ) {
				$flags['E8-again'] = true;
			}
			$was = $utf;
		}
		foreach ( array_keys( $flags ) as $property ) {
			++$met[ $property ];
		}
	}

	/**
	 * E3 to E5 at one sign: one form meets a key, the other not; a key held
	 * by a form but not at its end; a key that meets with a sign, a blank, a
	 * - or a _ first.
	 *
	 * @param string[]            $forms strtolower() and normalize_key() of the text before it.
	 * @param array<int,string>   $keys  The keys of the copy.
	 * @param array<string,bool>  $flags The properties met.
	 */
	private static function meets_keys( array $forms, array $keys, array &$flags ): void {
		$ends = array( false, false );
		foreach ( $keys as $key ) {
			if ( '' === $key ) {
				continue;
			}
			foreach ( $forms as $f => $form ) {
				if ( str_ends_with( $form, $key ) ) {
					$ends[ $f ] = true;
					if ( false !== strpbrk( $key, "=:;\"' \t\n\r\x0B\f-" ) || '_' === $key[0] ) {
						$flags['E5'] = true;
					}
				} elseif ( str_contains( $form, $key ) ) {
					$flags['E4'] = true;
				}
			}
		}
		if ( $ends[0] !== $ends[1] ) {
			$flags['E3'] = true;
		}
	}

	/** Whether a text ends in a blank of Unicode the order names (bytes). */
	private static function ends_blank( string $text ): bool {
		foreach ( self::BLANKS as $blank ) {
			if ( str_ends_with( $text, $blank ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------- helpers */

	/** An object with a text, as a key or a name of another type. */
	private static function stringable( string $text ): object {
		return new class( $text ) {
			/** @var string */
			private $text;

			public function __construct( string $text ) {
				$this->text = $text;
			}

			public function __toString(): string {
				return $this->text;
			}
		};
	}

	/** @param mixed $value */
	private static function shown( $value ): string {
		if ( is_object( $value ) ) {
			return (string) $value;
		}
		return var_export( $value, true );
	}
}
