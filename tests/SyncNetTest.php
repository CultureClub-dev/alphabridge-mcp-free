<?php
/**
 * The net over the raw value (AB_MCP_Sync_Net; Codex, rounds 18 to 21;
 * Thomas, 04.11.2026), clause by clause: the layers a stored value
 * unfolds to (its decodings, the comments of code, data: addresses in
 * their charset, base64, each again, to its depth), the (space, id) pairs
 * the addresses of a layer name as AB_MCP_Sync_Urls reads one address (the
 * address tokens first, then the root-relative addresses and every query),
 * the names of credentials with a value beside them, and the comparison
 * with what the readers of the unit found: as pairs, in an extracted layer
 * every pair. The cases of the rounds and their variations against the
 * core: tests/SyncCoreReads.php (netReferences(), netCredentials(),
 * netFree()).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Net;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-net.php';

final class SyncNetTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	private static function ctx(): array {
		return AB_MCP_Sync_Canon::context(
			array( 'https://staging.test', 'https://www.live.test' ),
			static fn( $id ) => 'user' . $id,
			array(
				'rules'   => array(
					'archives/([0-9]+)/?$'           => 'index.php?p=$matches[1]',
					'([0-9]{4})/([0-9]{1,2})/?$'     => 'index.php?year=$matches[1]&monthnum=$matches[2]',
					'(.?.+?)/?$'                     => 'index.php?pagename=$matches[1]',
				),
				'verbose' => false,
				'index'   => false,
				'pages'   => static fn( $p ) => null,
			),
			array( 'https://staging.test' )
		);
	}

	/** The layers as path => text (a path twice keeps its last text). */
	private static function layers( string $raw, ?bool &$deep = null, ?array &$unread = null ): array {
		$out = array();
		foreach ( AB_MCP_Sync_Net::layers( $raw, $deep, $unread ) as $layer ) {
			$out[ $layer[0] ] = $layer[1];
		}
		return $out;
	}

	private static function data( string $text, string $type = 'text/plain' ): string {
		return 'data:' . $type . ';base64,' . base64_encode( $text );
	}

	/* ------------------------------------------------------------ unfold */

	public function testTheRawTextIsTheFirstLayerAndEachDecodingOneMore(): void {
		self::assertSame( array( 'raw' => 'nur Text' ), self::layers( 'nur Text' ) );
		// The escapes of JSON, of JavaScript, of C and of CSS, percent
		// escapes: a layer each where it differs from the text; a decoding
		// that changes nothing is no layer.
		$layers = self::layers( 'a\\/b\\u002fc\\"d\\2f e%2Ff' );
		self::assertSame( 'a/b/c\\"d\\2f e%2Ff', $layers['raw/json'] );
		self::assertSame( 'a\\/b/c\\"d\\2f e%2Ff', $layers['raw/js'], 'JavaScript: \\u of any code point, not \\/.' );
		self::assertSame( 'a/bu002fc"d' . chr( 2 ) . 'f e%2Ff', $layers['raw/slashes'], 'stripcslashes(): \\u and an octal escape as C reads them.' );
		self::assertSame( 'a/bu002fc"d/e%2Ff', $layers['raw/css'], 'AB_MCP_Sync_Css::unescaped(): \\/ and \\" the character, \\u the letter, \\2f the code point.' );
		self::assertSame( 'a\\/b\\u002fc\\"d\\2f e/f', $layers['raw/percent'] );
		self::assertArrayHasKey( 'raw/json/percent', $layers, 'Each layer again.' );
		self::assertArrayNotHasKey( 'raw/percent/percent', $layers, 'A decoding that changes nothing is no layer.' );
		self::assertSame( array( 'raw' => 'a%b' ), self::layers( 'a%b' ), 'No escape to decode: the text alone.' );
		self::assertSame( array( 'raw' => 'a &amp; b' ), self::layers( 'a &amp; b' ), 'Without WP_HTML_Decoder (the unit suite) no reference is decoded here: the credential check fails closed on it.' );
	}

	public function testTheEscapesOfJavaScriptAndTheCommentsOfCodeAreLayers(): void {
		// Codex, round 21 (A-4): \u{XXXX}, \uXXXX of any code point, \xHH.
		$layers = self::layers( 'api\\u{5f}key api\\x5fkey api\\u005fkey \\u{e9} \\u00e9 \\u{110000} \\ud800' );
		self::assertContains( 'api_key api_key api_key é é \\u{110000} \\ud800', $layers, 'A surrogate and beyond U+10FFFF stay as they are (the layer first reached as raw/json/js).' );
		self::assertSame( 'api_key api_key api_key é é \\u{110000} \\ud800', $layers['raw/json/js'] );
		// The comments of code removed, the strings respected (", \' and `;
		// " and \' end at a line break, ` not); a // in a string is none.
		$layers = self::layers( "var cfg={api_key/*a*/:\"x\"}; // Kommentar\ncfg.b = 'x /* kein */' + `y //auch\nnicht` + \"z /\\\"*\"; /* ende" );
		self::assertSame( "var cfg={api_key:\"x\"}; \ncfg.b = 'x /* kein */' + `y //auch\nnicht` + \"z /\\\"*\"; ", $layers['raw/code'] );
		self::assertSame( 'x = "a /* b */ c"; ', self::layers( 'x = "a /* b */ c"; // d' )['raw/code'], 'A comment in a string is none.' );
		self::assertSame( "a 'b\n d", self::layers( "a 'b\n/* c */ d" )['raw/code'], 'A string of \' ends at the line break: the comment after it is one.' );
		self::assertArrayNotHasKey( 'raw/code', self::layers( 'ohne Kommentar' ) );
	}

	public function testADataAddressOfAnyTypeIsDecodedAsTheBrowserDecodesIt(): void {
		$layers = self::layers( 'x ' . self::data( 'Geheim' ) . ' y' );
		self::assertSame( 'Geheim', $layers['raw/data'] );
		$layers = self::layers( "url('data:text/plain,a b)c')" );
		self::assertSame( 'a b)c', $layers['raw/data'], 'To the quote it stands in.' );
		$layers = self::layers( 'x="data:text/plain,c%20d" y' );
		self::assertSame( 'c d', $layers['raw/data'], 'Percent-decoded.' );
		$layers = self::layers( 'url("data:image/svg+xml;charset=utf-8;base64,' . base64_encode( '<svg/>' ) . '") x' );
		self::assertSame( '<svg/>', $layers['raw/data'], 'base64 among the parameters, to its quote.' );
		$layers = self::layers( 'url(' . self::data( 'ohne Anfuehrungszeichen' ) . ') y' );
		self::assertSame( 'ohne Anfuehrungszeichen', $layers['raw/data'], 'Without a quote, to ")".' );
		$layers = self::layers( 'DATA:Text/Plain;BASE64,' . base64_encode( 'oben' ) );
		self::assertSame( 'oben', $layers['raw/data'], 'In any case.' );
		// Codex, round 22 (A-2): the header as the MIME Sniffing Standard
		// parses it: parameters in quotes, white space, any order; base64 as
		// the last token only.
		foreach ( array(
			'data:text/plain;name="icon";base64,' . base64_encode( 'Geheim' )        => 'Geheim',
			'data:text/plain; charset=utf-8 ;base64,' . base64_encode( 'Geheim' )   => 'Geheim',
			'data:text/plain;charset="utf-8";base64,' . base64_encode( 'Geheim' )  => 'Geheim',
			'data:text/plain;name="a,b\\"c";base64,' . base64_encode( 'Geheim' )   => 'Geheim',
			'data:text/plain;base64;name=x,' . base64_encode( 'Geheim' )           => base64_encode( 'Geheim' ),
			'data:;base64,' . base64_encode( 'ohne Typ' )                           => 'ohne Typ',
			'data:text/plain;CHARSET=utf-16le;base64,' . base64_encode( mb_convert_encoding( 'Geheim', 'UTF-16LE', 'UTF-8' ) ) => 'Geheim',
			'data: text/plain ;base64,' . base64_encode( 'Geheim' )                 => 'Geheim',
			'data:text/plain;name="a;charset=utf-16le";base64,' . base64_encode( 'Geheim' ) => 'Geheim',
		) as $raw => $want ) {
			self::assertSame( $want, self::layers( $raw )['raw/data'] ?? null, $raw );
		}
		self::assertArrayNotHasKey( 'raw/data', self::layers( 'data:text/plain;name=(x,Geheim' ), 'A bracket in the header: no address.' );
		self::assertArrayNotHasKey( 'raw/data', self::layers( "data:text/plain;name=\"x\ny\",Geheim" ), 'A line break in the header: no address.' );
		self::assertArrayNotHasKey( 'raw/data', self::layers( 'data:text/plain;name="' . str_repeat( 'x', 600 ) . '",Geheim' ), 'No "," within the header: no address.' );
		$layers = self::layers( 'a data:text/plain;base64,!!!! b data:text/plain, c' );
		self::assertArrayNotHasKey( 'raw/data', $layers, 'What does not decode, and an empty body, is no layer.' );
		$layers = AB_MCP_Sync_Net::layers( '"' . self::data( 'erste' ) . '" ' . self::data( 'zweite' ) );
		self::assertSame( array( 'raw', 'raw/data', 'raw/data' ), array_column( $layers, 0 ), 'Every data: address.' );
		self::assertSame( array( 'erste', 'zweite' ), array( $layers[1][1], $layers[2][1] ) );
		self::assertSame( array( false, true, true ), array_column( $layers, 2 ), 'Taken out of the text: extracted.' );
	}

	public function testABodyOfTextIsReadInUtf8ByItsBomOrCharsetElseNotAtAll(): void {
		// Codex, round 21 (A-3): UTF-16 with a BOM, by its charset, UTF-32,
		// ISO-8859-1, Windows-1252, a UTF-8 BOM.
		$svg    = '<svg><script>{"api_key":"x"}</script></svg>';
		$xml    = '<?xml version="1.0"?>' . $svg;
		$unread = array();
		foreach ( array(
			'BOM UTF-16LE'          => array( "\xFF\xFE" . mb_convert_encoding( $svg, 'UTF-16LE', 'UTF-8' ), 'image/svg+xml', $svg ),
			'BOM UTF-16BE'          => array( "\xFE\xFF" . mb_convert_encoding( $svg, 'UTF-16BE', 'UTF-8' ), 'image/svg+xml', $svg ),
			'charset utf-16'        => array( mb_convert_encoding( $svg, 'UTF-16LE', 'UTF-8' ), 'image/svg+xml;charset=utf-16', $svg ),
			'charset UTF-16BE'      => array( mb_convert_encoding( $svg, 'UTF-16BE', 'UTF-8' ), 'text/html;charset=UTF-16BE', $svg ),
			'BOM UTF-32LE'          => array( "\xFF\xFE\x00\x00" . mb_convert_encoding( $svg, 'UTF-32LE', 'UTF-8' ), 'text/xml', $svg ),
			'BOM UTF-32BE'          => array( "\x00\x00\xFE\xFF" . mb_convert_encoding( $svg, 'UTF-32BE', 'UTF-8' ), 'application/json', $svg ),
			'BOM UTF-8'             => array( "\xEF\xBB\xBF" . $svg, 'text/plain', $svg ),
			'a BOM before a charset' => array( "\xFE\xFF" . mb_convert_encoding( $svg, 'UTF-16BE', 'UTF-8' ), 'text/plain;charset=utf-16', $svg ),
			// Codex, round 22 (A-2): XML by its first bytes (Appendix F), and
			// by the encoding of its declaration.
			'XML UTF-16LE by its first bytes' => array( mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ), 'image/svg+xml', $xml ),
			'XML UTF-16BE by its first bytes' => array( mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?>' . $svg, 'UTF-16BE', 'UTF-8' ), 'text/xml', '<?xml version="1.0" encoding="UTF-16"?>' . $svg ),
			'XML UTF-32LE by its first bytes' => array( mb_convert_encoding( $xml, 'UTF-32LE', 'UTF-8' ), 'application/rss+xml', $xml ),
			'XML first bytes before a charset' => array( mb_convert_encoding( $xml, 'UTF-16BE', 'UTF-8' ), 'image/svg+xml;charset=latin1', $xml ),
			'XML UTF-32BE by its first bytes' => array( mb_convert_encoding( $xml, 'UTF-32BE', 'UTF-8' ), 'text/xml', $xml ),
			'XML by its first bytes, the type in capitals' => array( mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ), 'IMAGE/SVG+XML', $xml ),
			'XML by its first bytes, the type with spaces around it' => array( mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ), ' text/xml ', $xml ),
			'a BOM without a type (text/plain)' => array( "\xFF\xFE" . mb_convert_encoding( $svg, 'UTF-16LE', 'UTF-8' ), '', $svg ),
		) as $label => $case ) {
			$layers = self::layers( 'data:' . $case[1] . ';base64,' . base64_encode( $case[0] ), $deep, $unread );
			self::assertSame( $case[2], $layers['raw/data'] ?? null, $label );
			self::assertSame( array(), $unread, $label );
		}
		$layers = self::layers( 'data:text/plain;charset=iso-8859-1;base64,' . base64_encode( "caf\xe9 token=x" ), $deep, $unread );
		self::assertSame( 'café token=x', $layers['raw/data'] );
		$layers = self::layers( 'data:text/xml;base64,' . base64_encode( "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><r>caf\xe9</r>" ), $deep, $unread );
		self::assertSame( '<?xml version="1.0" encoding="ISO-8859-1"?><r>café</r>', $layers['raw/data'], 'The encoding of the declaration.' );
		$layers = self::layers( 'data:text/xml;base64,' . base64_encode( "<?xml version=\"1.0\" encoding=\"KOI8-R\"?><r>\xC1</r>" ), $deep, $unread );
		self::assertArrayNotHasKey( 'raw/data', $layers, 'An encoding of the declaration not known: not read.' );
		self::assertSame( array( 'raw' ), $unread );
		$layers = self::layers( 'data:application/octet-stream;base64,' . base64_encode( "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><r>caf\xe9</r>" ), $deep, $unread );
		self::assertSame( "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><r>caf\xe9</r>", $layers['raw/data'], 'Not XML by its type: bytes as they are.' );
		$layers = self::layers( 'data:text/plain;charset=windows-1252,' . rawurlencode( "\x80 token=x" ), $deep, $unread );
		self::assertSame( '€ token=x', $layers['raw/data'] );
		self::assertSame( 'ohne Typ', self::layers( 'data:;base64,' . base64_encode( 'ohne Typ' ) )['raw/data'], 'An empty type is text/plain.' );
		// A body of a text type that is then no text: not read, the layer
		// named.
		foreach ( array(
			'UTF-16 without a BOM or charset' => 'data:image/svg+xml;base64,' . base64_encode( mb_convert_encoding( $svg, 'UTF-16LE', 'UTF-8' ) ),
			'a null byte'                     => 'data:text/plain;base64,' . base64_encode( "a\0b" ),
			'invalid bytes'                   => 'data:text/html,%FF%FE%FF',
			'a charset not known'             => 'data:text/plain;charset=koi8-r;base64,' . base64_encode( "\xC1" ),
			'a null character in UTF-16'      => 'data:text/plain;charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( "a\0b", 'UTF-16LE', 'UTF-8' ) ),
			'a BOM before bytes of no text'   => 'data:text/plain;base64,' . base64_encode( "\xFF\xFE\x00\xD8" ),
			'a charset not known, bytes of ASCII' => 'data:text/plain;charset=koi8-r,abc',
			'a declaration not known, bytes of ASCII' => 'data:text/xml,' . rawurlencode( '<?xml version="1.0" encoding="KOI8-R"?><r>abc</r>' ),
			'a declaration in a type that is no XML' => 'data:application/json;base64,' . base64_encode( "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><r>caf\xe9</r>" ),
		) as $label => $raw ) {
			$layers = self::layers( $raw, $deep, $unread );
			self::assertArrayNotHasKey( 'raw/data', $layers, $label );
			self::assertSame( 'raw', $unread[0] ?? null, $label . ': the layer with the body named (a decoding of the text that holds the same body names itself as well).' );
		}
		// Bytes of any other type stay bytes: read as they are, no lock.
		$layers = self::layers( 'data:image/png;base64,' . base64_encode( "\x89PNG\0\0 api_key=x" ), $deep, $unread );
		self::assertSame( "\x89PNG\0\0 api_key=x", $layers['raw/data'] );
		self::assertSame( array(), $unread );
		// Bytes with a charset: a text whatever the type.
		$layers = self::layers( 'data:application/octet-stream;charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( 'token=y', 'UTF-16LE', 'UTF-8' ) ), $deep, $unread );
		self::assertSame( 'token=y', $layers['raw/data'] );
		// utf8(): the labels of UTF-8 and ASCII as they are, a null byte never.
		self::assertSame( 'abc', AB_MCP_Sync_Net::utf8( 'abc', 'utf-8' ) );
		self::assertSame( 'abc', AB_MCP_Sync_Net::utf8( 'abc', 'us-ascii' ) );
		self::assertNull( AB_MCP_Sync_Net::utf8( "a\0", 'utf-8' ) );
		self::assertNull( AB_MCP_Sync_Net::utf8( "\xFF", null ) );
		self::assertSame( 'é', AB_MCP_Sync_Net::utf8( "\xE9", 'latin1' ) );
	}

	public function testASpanOfBase64IsALayerWhereItsBytesAreText(): void {
		$layers = self::layers( 'vorher ' . base64_encode( 'api_key=demo-secret' ) . ' nachher' );
		self::assertSame( 'api_key=demo-secret', $layers['raw/base64'] );
		$layers = self::layers( base64_encode( '/?p=1234' ) );
		self::assertSame( '/?p=1234', $layers['raw/base64'], 'The shortest span (12 characters, its padding counted).' );
		self::assertSame( array( 'raw' => 'Lz9wPTEyMzQ' ), self::layers( 'Lz9wPTEyMzQ' ), 'Shorter: not decoded.' );
		$unread = array();
		self::assertArrayNotHasKey( 'raw/base64', self::layers( base64_encode( "\x89PNG\x00\xff\xfe\x80\x81\x82\x83\x84" ), $deep, $unread ), 'Bytes that are no UTF-8: no layer.' );
		self::assertSame( array(), $unread, 'Bytes without a BOM: a binary, not read, no lock.' );
		self::assertArrayNotHasKey( 'raw/base64', self::layers( 'x' . base64_encode( '/?p=1234' ) ), 'Inside a longer word: no span of its own.' );
		self::assertSame( '/?p=1234', self::layers( 'a:' . base64_encode( '/?p=1234' ) . ';' )['raw/base64'], 'At a character of no base64.' );
		// Codex, round 21 (A-3): UTF-16 by its BOM; a BOM before bytes that
		// are no text: not read.
		self::assertSame( 'token=x', self::layers( base64_encode( "\xFF\xFE" . mb_convert_encoding( 'token=x', 'UTF-16LE', 'UTF-8' ) ) )['raw/base64'] );
		$layers = self::layers( base64_encode( "\xFE\xFF" . str_repeat( "\xD8\x00", 6 ) ), $deep, $unread );
		self::assertArrayNotHasKey( 'raw/base64', $layers );
		self::assertSame( array( 'raw' ), $unread );
	}

	public function testALayerIsReadOnceAndNoDeeperThanTheDepth(): void {
		$deep   = null;
		$layers = self::layers( base64_encode( self::data( 'Geheimnis' ) ), $deep );
		self::assertSame( 'Geheimnis', $layers['raw/base64/data'] );
		self::assertFalse( $deep );
		// The same text twice: one layer.
		$same = base64_encode( 'ein Text hier' );
		self::assertCount( 2, self::layers( $same . ' ' . $same ) );
		// MAX_DEPTH derivations are followed, the next is not: deep.
		$text = 'Geheimnis';
		for ( $i = 0; $i < AB_MCP_Sync_Net::MAX_DEPTH; $i++ ) {
			$text = self::data( $text );
		}
		$layers = self::layers( $text, $deep );
		self::assertSame( 'Geheimnis', end( $layers ) );
		self::assertFalse( $deep, 'At the depth: read.' );
		$layers = self::layers( self::data( $text ), $deep );
		self::assertNotContains( 'Geheimnis', $layers );
		self::assertTrue( $deep, 'Beyond the depth: not read, deep.' );
		// A decoding that changes nothing at the depth is no derivation: the
		// layer was read, nothing is deep.
		$same = '50% Rabatt';
		for ( $i = 0; $i < AB_MCP_Sync_Net::MAX_DEPTH; $i++ ) {
			$same = self::data( $same );
		}
		$layers = self::layers( $same, $deep );
		self::assertSame( '50% Rabatt', end( $layers ) );
		self::assertFalse( $deep );
		// MAX_LAYERS layers, the next is not read: deep.
		$many = array();
		for ( $i = 0; $i < AB_MCP_Sync_Net::MAX_LAYERS + 2; $i++ ) {
			$many[] = base64_encode( 'Text Nummer ' . $i );
		}
		self::assertCount( AB_MCP_Sync_Net::MAX_LAYERS, AB_MCP_Sync_Net::layers( implode( ' ', $many ), $deep ) );
		self::assertTrue( $deep );
	}

	/* ------------------------------------------------------------- count */

	public function testTheAddressesOfALayerNameTheirPairsAsOneAddressIsRead(): void {
		$ctx = self::ctx();
		// With the host of a site, in the forms the replace engine knows;
		// with any host in another spelling (locked: "?"); root-relative
		// after a quote, "=", "(", ":", a space or at the start; a query
		// wherever it stands (Codex, round 21, A-1).
		foreach ( array(
			'https://staging.test/?p=12'                   => array( 'post:12' ),
			'Link https://staging.test/?p=12 im Text.'     => array( 'post:12' ),
			'{"u":"https:\\/\\/staging.test\\/?p=12"}'     => array( 'post:12' ),
			'https://STAGING.test/?p=12'                   => array( 'post:?' ),
			'//staging.test/?p=12'                         => array( 'post:12' ),
			'/?p=12'                                       => array( 'post:12' ),
			'"/?p=12"'                                     => array( 'post:12' ),
			'href=/?p=12'                                  => array( 'post:12' ),
			'url(/?p=12)'                                  => array( 'post:12' ),
			'x:/?p=12'                                     => array( 'post:12' ),
			'https://staging.test/?cat=1,2,3'              => array( 'term:1', 'term:2', 'term:3' ),
			'/?p=12 und /?page_id=13'                      => array( 'post:12', 'post:13' ),
			'/?p=12 und /?p=12'                            => array( 'post:12', 'post:12' ),
			'/archives/12'                                 => array( 'post:12' ),
			'siehe /archives/12.'                          => array( 'post:12' ),
			'https://staging.test/archives/12/'            => array( 'post:12' ),
			'?p=12'                                        => array( 'post:?' ),
			'index.php?p=12'                               => array( 'post:?' ),
			'./?p=12'                                      => array( 'post:?' ),
			'../?p=12'                                     => array( 'post:?' ),
			'a?p=12'                                       => array( 'post:?' ),
			'a/?p=12'                                      => array( 'post:?' ),
			'{"src":"index.php?p=12","type":"iframe"}'     => array( 'post:?' ),
			'x&p=12'                                       => array( 'post:?' ),
			'?a=1;x=/?p=12'                                => array( 'post:12' ),
			// Codex, round 22 (A-1): the value of a query variable that is an
			// address of the sites, as the reader reads it.
			'https://staging.test/?redirect_to=https://staging.test/?p=12' => array( 'post:12' ),
			'https://staging.test/wp-login.php?redirect_to=%2F%3Fp%3D12'  => array( 'post:12' ),
			'/x?b=https%3A%2F%2Fstaging.test%2F%3Fcat%3D1%2C2'             => array( 'term:1', 'term:2' ),
			'https://staging.test/?redirect_to=%2f%3fp%3d12'               => array( 'post:?' ),
			'/?redirect_to=index.php%3Fp%3D12'                             => array( 'post:?' ),
			'https://staging.test/?a=' . rawurlencode( '/?b=' . rawurlencode( '/?c=' . rawurlencode( '/?p=12' ) ) ) => array( 'post:?' ),
			'wp-login.php?redirect_to=%2F%3Fp%3D12'                        => array( 'post:?' ),
			'https://www.live.test/?p=12'                                  => array( 'post:?' ),
			'/?u=https%3A%2F%2Fwww.live.test%2F%3Fp%3D12'                  => array( 'post:?' ),
		) as $text => $pairs ) {
			self::assertSame( $pairs, AB_MCP_Sync_Net::references( $text, $ctx ), $text );
		}
		// A root-relative value where the sites share no root: not rewritten
		// (locked); with the host, read.
		$sub = AB_MCP_Sync_Canon::context( array( 'https://staging.test/sub', 'https://www.live.test' ), static fn( $id ) => 'user' . $id, null, array( 'https://staging.test/sub' ) );
		self::assertSame( array( 'post:?' ), AB_MCP_Sync_Net::references( 'https://staging.test/sub/?u=%2Fsub%2F%3Fp%3D12', $sub ) );
		self::assertSame( array( 'post:12' ), AB_MCP_Sync_Net::references( 'https://staging.test/sub/?u=https%3A%2F%2Fstaging.test%2Fsub%2F%3Fp%3D12', $sub ) );
		// Not an address, or none of the sites: after a letter, a digit,
		// "%", "/" or "."; a site without an id; no digit; another site's
		// host, whatever follows its "#" (Codex, round 21, B-2); the path of
		// a file; a path the rules route to no object; "\/" of JSON (its
		// layer).
		foreach ( array(
			'a/x',
			'1/2 Tag',
			'calc(100%/3)',
			'05/12/2024',
			'https://staging.test/ueber-uns/',
			'https://cdn.example.org/?p=12',
			'https://catalog.example/#/search?p=12',
			'<a href="https://catalog.example/#/search?p=12">Seite 12 im Katalog</a>',
			'//other.test/archives/12/?cat=3',
			'/wp-content/uploads/2024/05/a.jpg',
			'https://staging.test/wp-content/uploads/2024/05/a-300x200.jpg',
			'/2024/05/',
			'</div>',
			'a{grid-area:1 / 2 / 3 / 4;font:12px/1.5 Arial;aspect-ratio:16/9}',
			'https://staging.test/?cat=0&author=',
			'{"url":"\\/?p=12"}',
			'&amp;nbsp;12 &copy; 2024',
			'?x=12',
			'Ordner/archives/12',
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::references( $text, $ctx ), $text );
		}
	}

	public function testTheNamesOfCredentialsWithAValueBesideThemAreCounted(): void {
		$extra = array();
		foreach ( array(
			'api_key=demo-secret'                              => array( 'api_key' ),
			'?x=1&token=abc'                                   => array( 'token' ),
			"cfg['api_key']='demo-secret'"                     => array( 'api_key' ),
			'cfg[`api_key`] = "demo-secret"'                   => array( 'api_key' ),
			'"client_secret" => "x"'                           => array( 'client_secret' ),
			'{"api_key":"x"}'                                  => array( 'api_key' ),
			"{'access_token': 'x'}"                            => array( 'access_token' ),
			'{`refresh_token`: `x`}'                           => array( 'refresh_token' ),
			'var cfg={api_key:"demo-secret"}'                  => array( 'api_key' ),
			"{ token: 'y' }"                                   => array( 'token' ),
			"{\n  a: 1,\n  apiKey: \"x\"\n}"                   => array( 'api_key' ),
			'init({a:1; secret:"x"})'                          => array( 'secret' ),
			'token: "x"'                                       => array( 'token' ),
			'token: ` ${x}`'                                   => array( 'token' ),
			'[{"name":"api_key","value":"demo-secret"}]'       => array( 'api_key' ),
			'{"key":"Authorization","secret":"x"}'             => array( 'secret', 'authorization' ),
			'name=token&value=x'                               => array( 'token' ),
			'[{`name`:`token`,`value`:`x`}]'                   => array( 'token' ),
			'{"x":{"name":"api_key","value":"s"},"x":0}'       => array( 'api_key' ),
			'password=a&secret=b'                              => array( 'password', 'secret' ),
			'API_KEY = "x"'                                    => array( 'api_key' ),
			':root{--client-secret:1}'                         => array( 'client_secret' ),
			'a{color:red;--api-key:1}'                         => array( 'api_key' ),
			'{a:1, token:x}'                                   => array( 'token' ),
			// Codex, round 22 (A-3, A-4): white space in a key in brackets; a
			// brace in a string closes none.
			'cfg["api_key" ] = "demo-secret";'                 => array( 'api_key' ),
			"cfg[\n\"api_key\"\n]=\"x\""                       => array( 'api_key' ),
			'cfg[ `api_key` ] = `x`'                           => array( 'api_key' ),
			'var cfg = {a:"}",api_key:"demo-secret"};'          => array( 'api_key' ),
			'var cfg = {a:"{",api_key:"demo-secret"};'          => array( 'api_key' ),
			"var cfg = {a:'}}', b:`}`, token:\"x\"};"          => array( 'token' ),
			'var cfg = {a:"\\"}",api_key:"demo-secret"};'      => array( 'api_key' ),
			"x = \"\n{ a: 1, api_key: \"demo-secret\" }"       => array( 'api_key' ),
			// Codex, round 21 (A-5): a value is what is not empty after trim.
			'{"api_key":" demo-secret"}'                       => array( 'api_key' ),
			"{\"api_key\":\"\tdemo-secret\"}"                  => array( 'api_key' ),
			"{\"api_key\":\"\ndemo-secret\"}"                  => array( 'api_key' ),
			"api_key = '  x'"                                  => array( 'api_key' ),
			"{token:' x'}"                                     => array( 'token' ),
		) as $text => $names ) {
			self::assertSame( $names, AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		// No value, or no name: a word in prose, a label, "==", an empty value
		// (white space alone as well), a name after "," outside of braces, a
		// name that is none.
		foreach ( array(
			'/* design tokens */',
			'Token: x',
			'Das Passwort: geheim. Der Token: "abc".',
			'Felder: Name, Token: "abc" (Secret: "def")',
			'a{color:red} Token: x; secret: y',
			'if (token == "x")',
			'api_key=',
			'api_key=&x=1',
			'"api_key":""',
			'"api_key":"   "',
			"{\"api_key\":\"\n\"}",
			'{"name":"api_key"}',
			'{"name":"api_key","label":"x"}',
			'{"name":"api_key","note":"' . str_repeat( 'x', AB_MCP_Sync_Net::WINDOW ) . '","value":"far"}',
			'title=Kontakt',
			'[acme_button label="Token: anfordern"]',
			'input[type="password"]',
			'hasPassword=1&token_count=3',
			'`token`',
			'x = "{"; Felder: Name, Token: "abc"',
			"x = `\n{`; Felder: Name, Token: \"abc\"",
			'{token:` `}',
			'api_key=` `',
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		// The names a site redacts on top, and the normalised spelling.
		self::assertSame( array( 'my.hidden' ), AB_MCP_Sync_Net::credentials( 'my.hidden=x', array( 'my.hidden' ) ) );
		self::assertSame( array( 'client_secret' ), AB_MCP_Sync_Net::credentials( 'client-secret=x clientSecret=y', $extra ), 'One name in its spellings.' );
	}

	/* ----------------------------------------------------------- compare */

	/** A unit through its readers (a plain value) and the net. */
	private static function check( string $raw, ?callable $readers = null ): array {
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$mark = AB_MCP_Sync_Net::mark( $acc );
		if ( null === $readers ) {
			AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, 'post', 'u' );
		} else {
			$readers( $acc, $ctx );
		}
		AB_MCP_Sync_Net::check( $acc, $mark, 'post', 'u', $raw, $ctx );
		$locks = array();
		foreach ( $acc['locks'] as $lock ) {
			$locks[] = $lock['key'] . '@' . $lock['at'];
		}
		return array( $locks, $acc );
	}

	public function testTheNetLocksWhereALayerNamesAPairTheReadersDidNotRead(): void {
		// The readers of a plain value read the addresses with a host and the
		// root-relative ones (a text node; Codex, round 22, B-1), not a query
		// relative to the page: the net names its pair.
		list( $locks ) = self::check( 'siehe index.php?p=12' );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		list( $locks, $acc ) = self::check( 'siehe /?p=12' );
		self::assertSame( array( array(), array( 'post:12' ) ), array( $locks, $acc['addressed'] ), 'A root-relative address in a text node: read.' );
		// What they read as well: the same pairs, free.
		list( $locks, $acc ) = self::check( 'siehe https://staging.test/?p=12' );
		self::assertSame( array(), $locks );
		self::assertSame( array( 'post:12' ), $acc['addressed'] );
		// The same pair once more than they read it: locked (a copy a reader
		// does not rewrite).
		list( $locks ) = self::check( 'https://staging.test/?p=12 und index.php?p=12' );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		// Another pair beside the one they read: locked.
		list( $locks ) = self::check( 'https://staging.test/?p=12 und index.php?p=13' );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		// A layer beneath the raw text names the layer.
		list( $locks ) = self::check( base64_encode( '{"src":"/?p=12"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks );
		// Codex, round 21 (A-2): in a layer taken out of the text every pair
		// is unread, the one the readers read in the text as well.
		list( $locks ) = self::check( 'https://staging.test/?p=12 ' . base64_encode( 'https://staging.test/?p=13' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks );
		list( $locks ) = self::check( 'https://staging.test/?p=12 ' . base64_encode( 'https://staging.test/?p=12' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks, 'The same id hidden: locked.' );
		list( $locks ) = self::check( 'https://staging.test/?p=12 ' . self::data( '<a href="/?p=12">' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/data' ), $locks );
		list( $locks ) = self::check( 'https://staging.test/?p=12 ' . base64_encode( '{"u":"\\/?p=12"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64/json' ), $locks, 'A decoding of an extracted layer is extracted as well.' );
		// The first layer with more locks; one lock of a kind per unit.
		list( $locks ) = self::check( 'index.php?p=12 ' . base64_encode( '{"src":"/?p=13"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		// What the readers read in an earlier unit does not count for this one.
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$acc['addressed'][] = 'post:12';
		$mark = AB_MCP_Sync_Net::mark( $acc );
		AB_MCP_Sync_Net::check( $acc, $mark, 'post', 'u', '/?p=12', $ctx );
		self::assertSame( array( '*:unread_reference' ), array_column( $acc['locks'], 'key' ) );
	}

	public function testTheNetAddsNothingToAUnitTheReadersLocked(): void {
		// A reference in another spelling: locked by the readers, the net
		// adds nothing for the references, the credentials still read.
		list( $locks, $acc ) = self::check( 'https://staging.test/?p=1%32 und /?p=13' );
		self::assertSame( array( 'u?p' ), $acc['spelling'], 'Locked by the readers (the lock itself in finish()).' );
		self::assertSame( array(), $locks );
		list( $locks, $acc ) = self::check( 'https://staging.test/?p=1%32 ' . base64_encode( 'api_key=demo-secret' ) );
		self::assertSame( array( 'u?p' ), $acc['spelling'] );
		self::assertSame( array( '*:unread_credential@u:net:raw/base64' ), $locks );
		// Nested deeper than read: nothing is clean, the net adds nothing.
		$deep = static function ( array &$acc ): void {
			AB_MCP_Sync_Canon::too_deep( $acc, 'u', 'post' );
		};
		list( $locks ) = self::check( '/?p=12 ' . base64_encode( 'api_key=demo-secret' ), $deep );
		self::assertSame( array( '*:unreadable_value@u' ), $locks );
		// A reference in another spelling locked at once (with its kind):
		// nothing for the references, the credentials still read.
		$spelled = static function ( array &$acc ): void {
			AB_MCP_Sync_Canon::lock( $acc, 'post', 'reference_spelling', 'u' );
		};
		list( $locks ) = self::check( '/?p=12 ' . base64_encode( 'api_key=demo-secret' ), $spelled );
		self::assertSame( array( '*:reference_spelling@u', '*:unread_credential@u:net:raw/base64' ), $locks );
		// An object, a script, HTML outside the list, a credential field:
		// the same (the lock of the credential check).
		foreach ( array( 'php_object', 'script', 'html_outside_kses', 'credential_field', 'password_protected' ) as $op ) {
			$lock = static function ( array &$acc ) use ( $op ): void {
				AB_MCP_Sync_Canon::lock( $acc, 'post', $op, 'u' );
			};
			list( $locks ) = self::check( '/?p=12 api_key=demo-secret', $lock );
			self::assertSame( array( \AB_MCP_Sync_Kinds::lock( 'post', $op )['key'] . '@u' ), $locks, $op );
		}
		// A lock of the table (an option without a schema) is no lock of the
		// readers: the net reads the value.
		$table = static function ( array &$acc, array $ctx ): void {
			$acc['locks'][] = array( 'key' => 'option:unclassified', 'stage' => 'Z8d', 'reason' => 'x', 'at' => 'option' );
			AB_MCP_Sync_Canon::value( 'index.php?p=12', $ctx, $acc, 'option', 'u' );
		};
		list( $locks ) = self::check( 'index.php?p=12', $table );
		self::assertSame( array( 'option:unclassified@option', '*:unread_reference@u:net:raw' ), $locks );
	}

	public function testTheNetLocksANameTheCheckDidNotNameAndNotesIt(): void {
		// The credential check of a unit names what it reads (here: nothing,
		// the check runs before the readers, see AB_MCP_Sync_Db_Kinds); a
		// name with a value in a layer locks, the name noted, never a value.
		list( $locks, $acc ) = self::check( base64_encode( 'api_key=demo-secret' ) );
		self::assertSame( array( '*:unread_credential@u:net:raw/base64' ), $locks );
		self::assertSame( array( array( 'unit' => 'u', 'fields' => array( '~api_key' ) ) ), $acc['credentials']['mixed'] );
		self::assertStringNotContainsString( 'demo-secret', json_encode( $acc['credentials'] ) );
		// One lock and one note per unit, from the first layer that names one.
		list( $locks, $acc ) = self::check( 'api_key=x ' . base64_encode( 'api_key=y token=z' ) );
		self::assertSame( array( '*:unread_credential@u:net:raw' ), $locks );
		self::assertSame( array( array( 'unit' => 'u', 'fields' => array( '~api_key' ) ) ), $acc['credentials']['mixed'] );
		// A name the check named already (in any spelling of the path, the
		// path as a whole for an extra name with a dot): free.
		$named = static function ( array &$acc, array $ctx ): void {
			$acc['credentials']['mixed'][] = array( 'unit' => 'u', 'fields' => array( '0.apiKey', '[2].~token', '~api_key.v1' ) );
		};
		list( $locks ) = self::check( base64_encode( 'api_key=x' ) . ' ' . base64_encode( 'token=y' ) . ' ' . base64_encode( 'api_key.v1=z' ), $named );
		self::assertSame( array(), $locks );
		list( $locks ) = self::check( base64_encode( 'api_key=x' ) . ' ' . base64_encode( 'secret=y' ), $named );
		self::assertSame( array( '*:unread_credential@u:net:raw/base64' ), $locks, 'Another name: locked.' );
		// Names the check named in an earlier unit do not count for this one.
		$earlier = static function ( array &$acc ): void {
			$acc['credentials']['mixed'][] = array( 'unit' => 'v', 'fields' => array( 'api_key' ) );
		};
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$earlier( $acc );
		$mark = AB_MCP_Sync_Net::mark( $acc );
		AB_MCP_Sync_Net::check( $acc, $mark, 'post', 'u', base64_encode( 'api_key=x' ), $ctx );
		self::assertSame( array( '*:unread_credential' ), array_column( $acc['locks'], 'key' ) );
		// Codex, round 21 (A-4): the spellings of JavaScript, in a data: image.
		$svg = static fn( string $script ): string => 'a{mask:url(' . self::data( '<svg xmlns="http://www.w3.org/2000/svg"><script>' . $script . '</script></svg>', 'image/svg+xml' ) . ')}';
		foreach ( array(
			'var cfg={api\\u{5f}key:"demo-secret"};'      => 'raw/data/js',
			'var cfg={api\\x5fkey:"demo-secret"};'        => 'raw/data/js',
			'var cfg = {api_key/*a*/:"demo-secret"};'     => 'raw/data/code',
			'cfg[`api_key`] = "demo-secret";'             => 'raw/data',
			'cfg[`api_key`] = `${secret}`;'               => 'raw/data',
			"var cfg = {\n  api_key // Kommentar\n  : \"demo-secret\"\n};" => 'raw/data/code',
		) as $script => $layer ) {
			list( $locks, $acc ) = self::check( $svg( $script ) );
			self::assertSame( array( '*:unread_credential@u:net:' . $layer ), $locks, $script );
			self::assertSame( array( '~api_key' ), $acc['credentials']['mixed'][0]['fields'] );
		}
		// Codex, round 21 (A-3), 22 (A-2): UTF-16 by its charset, by its
		// first bytes as XML; without a declaration and a BOM, not read.
		$utf16 = mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg"><metadata><script type="application/json">{"api_key":"demo-secret"}</script></metadata></svg>', 'UTF-16LE', 'UTF-8' );
		list( $locks ) = self::check( 'a{background-image:url(data:image/svg+xml;charset=utf-16;base64,' . base64_encode( "\xff\xfe" . $utf16 ) . ')}' );
		self::assertSame( array( '*:unread_credential@u:net:raw/data' ), $locks );
		list( $locks ) = self::check( 'a{background-image:url(data:image/svg+xml;base64,' . base64_encode( $utf16 ) . ')}' );
		self::assertSame( array( '*:unread_credential@u:net:raw/data' ), $locks, 'XML by its first bytes.' );
		$bare = mb_convert_encoding( '<svg xmlns="http://www.w3.org/2000/svg"><script>{"api_key":"demo-secret"}</script></svg>', 'UTF-16LE', 'UTF-8' );
		list( $locks, $acc ) = self::check( 'a{background-image:url(data:image/svg+xml;base64,' . base64_encode( $bare ) . ')}' );
		self::assertSame( array( '*:unreadable_value@u:net:raw' ), $locks );
		self::assertSame( array( 'charset:u:net:raw' ), $acc['unread'] );
		// Codex, round 21 (A-5): a value with white space before it.
		list( $locks ) = self::check( base64_encode( '{"api_key":" demo-secret"}' ) );
		self::assertSame( array( '*:unread_credential@u:net:raw/base64' ), $locks );
	}

	public function testTheNetReadsTheTextsOfAStructureAndNothingOfAnEmptyValue(): void {
		// A widget instance: its texts, one per line, as one unit.
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$mark = AB_MCP_Sync_Net::mark( $acc );
		AB_MCP_Sync_Net::check( $acc, $mark, 'widget_area', 'w', array( 'title' => 'x', 'items' => array( array( 'url' => '/?p=12' ) ), 'n' => 3 ), $ctx );
		self::assertSame( array( '*:unread_reference@w:net:raw' ), array_map( static fn( $l ) => $l['key'] . '@' . $l['at'], $acc['locks'] ) );
		foreach ( array( '', null, 12, array(), array( 'n' => 12 ) ) as $empty ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Net::check( $acc, AB_MCP_Sync_Net::mark( $acc ), 'post', 'u', $empty, $ctx );
			self::assertSame( array(), $acc['locks'], json_encode( $empty ) );
		}
	}

	public function testBeyondTheDepthTheUnitIsNotReadToItsEnd(): void {
		$text = '/?p=12';
		for ( $i = 0; $i <= AB_MCP_Sync_Net::MAX_DEPTH; $i++ ) {
			$text = self::data( $text );
		}
		list( $locks, $acc ) = self::check( $text );
		self::assertSame( array( '*:unreadable_value@u:net' ), $locks );
		self::assertSame( array( 'depth:u:net' ), $acc['unread'] );
	}

	public function testTheMarkCountsWhatAnAccumulatorHolds(): void {
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( array( 'addressed' => 0, 'spelling' => 0, 'locks' => 0, 'mixed' => 0 ), AB_MCP_Sync_Net::mark( $acc ) );
		$acc['addressed'][]            = 'post:1';
		$acc['spelling'][]             = 'b';
		$acc['locks'][]                = array( 'key' => 'x', 'at' => 'y' );
		$acc['credentials']['mixed'][] = array( 'unit' => 'u', 'fields' => array() );
		self::assertSame( array( 'addressed' => 1, 'spelling' => 1, 'locks' => 1, 'mixed' => 1 ), AB_MCP_Sync_Net::mark( $acc ) );
		// An accumulator made before the net (no addressed): zero.
		unset( $acc['addressed'] );
		self::assertSame( 0, AB_MCP_Sync_Net::mark( $acc )['addressed'] );
	}

	public function testEveryPairOfAnAddressTheReadersReadIsNoted(): void {
		// AB_MCP_Sync_Canon::text(): rewritable and locked ("?") alike, in
		// JSON read by its round trip as well; a block attribute read whole.
		$ctx = self::ctx();
		foreach ( array(
			'https://staging.test/?p=12'                          => array( 'post:12' ),
			'https://staging.test/?cat=1,2'                       => array( 'term:1', 'term:2' ),
			'https://staging.test/?p=1%32'                        => array( 'post:?' ),
			'{"url":"\\/?p=12"}'                                  => array( 'post:12' ),
			'https://staging.test/archives/12/ und /?page_id=3'   => array( 'post:3', 'post:12' ),
			'<!-- wp:acme/x {"url":"/?p=12"} /-->'                => array( 'post:12' ),
		) as $text => $addressed ) {
			$acc = AB_MCP_Sync_Canon::acc();
			if ( 0 === strpos( $text, '<!--' ) ) {
				\AB_MCP_Sync_Refs::content( $text, $ctx, $acc, 'post', 'u' );
			} else {
				AB_MCP_Sync_Canon::text( $text, $ctx, array(), $acc, 'u' );
			}
			self::assertSame( $addressed, $acc['addressed'], $text );
		}
	}
}
