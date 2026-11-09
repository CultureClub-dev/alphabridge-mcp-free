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
use PHPUnit\Framework\Attributes\DataProvider;
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
		// Codex, round 25 (A-6): "+" a space in a query, as parse_str()
		// decodes it, from "?" or "&" to "#" or the end of the address;
		// round 26: elsewhere itself (a media type, a path).
		self::assertSame( 'img/svg+xml?q=a b/c d+e x&h=i j#f+g', self::layers( 'img/svg+xml?q=a+b%2Fc d+e x&h=i+j#f+g' )['raw/percent'] );
		self::assertSame( 'a+b/c "?q=x y"+z', self::layers( 'a+b%2Fc "?q=x+y"+z' )['raw/percent'] );
		self::assertSame( 'url(&quot;data:image/svg+xml,<svg/>&quot;) &#38;b=c+d &amp;e=f+g', self::layers( 'url(&quot;data:image/svg+xml,%3Csvg/%3E&quot;) &#38;b=c+d &amp;e=f+g' )['raw/percent'], 'An "&" that begins a character reference begins no query.' );
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
		// Codex, round 23 (A-2, A-3), round 24 (A-2, B-1), round 25 (B-1):
		// no header, a parameter twice, a type that is none after the tabs
		// and line breaks are removed: not read, and where the address
		// stands as an address – an address attribute, a candidate of
		// srcset, url(), @import, a candidate of image-set(), a string
		// value of JSON (also in a block attribute, a context, a line of
		// its own) – the layer is named (the unit locks); "data:" in prose,
		// in quotes as well, in a text node, in another attribute or as a
		// key of JSON begins none. %j: the address as JSON writes it.
		foreach ( array(
			'a bracket in the header'        => 'data:text/plain;name=(x,Geheim',
			'a bracket in the header of an SVG' => 'data:image/svg+xml;name=(x;charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( '<svg/>', 'UTF-16LE', 'UTF-8' ) ),
			'no "," within HEAD bytes'       => 'data:text/plain;name="' . str_repeat( 'x', 600 ) . '",Geheim',
			'charset twice'                  => 'data:image/svg+xml;charset=utf-16le;charset=utf-16be;base64,' . base64_encode( mb_convert_encoding( '<svg/>', 'UTF-16LE', 'UTF-8' ) ),
			'a parameter twice'              => 'data:text/plain;name=a;name=b,Geheim',
			'a parameter twice in any case'  => 'data:text/plain;Name=a;NAME=b,Geheim',
			'a space in the type'            => 'data:image/svg+x ml;base64,' . base64_encode( '<svg/>' ),
			'a bracket in the type'          => 'data:image/svg+x(ml;base64,' . base64_encode( '<svg/>' ),
			'a type without a slash'         => 'data:svg;base64,' . base64_encode( '<svg/>' ),
		) as $label => $raw ) {
			$fill = static fn( string $context ): string => str_replace( array( '%J', '%j', '%s' ), array( (string) json_encode( ' ' . $raw, JSON_UNESCAPED_SLASHES ), (string) json_encode( $raw, JSON_UNESCAPED_SLASHES ), $raw ), $context );
			foreach ( array( "url('%s')", 'url(%s)', 'src="%s"', "src='%s'", 'srcset=%s', 'SRC = "%s"', '<image xlink:href="%s"/>', 'data-src="%s"', 'srcset="a.jpg 1x, %s 2x"', '{"src":%j}', '{"src":%J}', '[%j]', '{"a":[1,{"b": %j}]}', "{\"a\":1}\n{\"src\":%j}", '{"t":"a{b","u":%j}', '{"x":{"a":1},"u":%j}', '[%j,' . str_repeat( '[', 600 ) . str_repeat( ']', 600 ) . ']', '<!-- wp:acme/x {"url":%j} /-->', '<div data-wp-context=\'{"img":%j}\'>x</div>', '@import "%s";', 'image-set("%s" 1x)', 'image-set(url(a.png) 1x, "%s" 2x)' ) as $context ) {
				$layers = self::layers( $fill( $context ), $deep, $unread );
				self::assertArrayNotHasKey( 'raw/data', $layers, $label );
				self::assertSame( array( 'raw' ), $unread, $label . ' in ' . $context );
			}
			foreach ( array( '<p>%s</p>', 'Siehe %s dort', '%s', '<p>Format: "%s"</p>', 'Hinweis: "%s" (Tabelle)', 'title="%s"', 'data-x="%s"', 'data-href="%s"', 'x = "%s"', '{%j:1}', '{%j : 1}', '{img:%j}', 'image-set("a.png" 1x) "%s"' ) as $context ) {
				$layers = self::layers( $fill( $context ), $deep, $unread );
				self::assertArrayNotHasKey( 'raw/data', $layers, $label );
				self::assertSame( array(), $unread, $label . ' in ' . $context );
			}
		}
		// More brackets around it than JSON_TRIES, none read as JSON: not
		// decided, so it stands as an address; fewer: none.
		$raw = 'data:image/svg+x(ml;base64,' . base64_encode( '<svg/>' );
		self::layers( str_repeat( '[', AB_MCP_Sync_Net::JSON_TRIES + 1 ) . '"' . $raw . '"', $deep, $unread );
		self::assertSame( array( 'raw' ), $unread );
		self::layers( str_repeat( '[', AB_MCP_Sync_Net::JSON_TRIES ) . '"' . $raw . '"', $deep, $unread );
		self::assertSame( array(), $unread );
		foreach ( array( '<p>Data: CSV/JSON</p>', '<p>Data: text/plain (UTF-8)</p>', 'Data: siehe (Tabelle 3)', "Metadata:\n2024, ohne Typ", 'data:x', 'Felder: data: image/png (Bild), name', '<p>Format: "Data: CSV/JSON"</p>', '<p>Hinweis: "data: siehe Tabelle"</p>', '{"note":"Hinweis: \\"data: siehe Tabelle\\""}', '{"a": "x", "data: b": 1}' ) as $raw ) {
			$layers = self::layers( $raw, $deep, $unread );
			self::assertSame( array(), $unread, $raw );
		}
		// A tab or a line break in the header is none (the URL parser removes it).
		self::assertSame( 'Geheim', self::layers( "data:text/plain;name=\"x\ny\",Geheim" )['raw/data'] ?? null );
		self::assertSame( 'Geheim', self::layers( "data:text/pl\tain;ba\nse64," . base64_encode( 'Geheim' ) )['raw/data'] ?? null );
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
			'XML by its first bytes, a tab in the type' => array( mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ), "image/svg+x\tml", $xml ),
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
			// Codex, round 23 (A-1): in an address of no site as well (a share
			// link); of the host alone in an address relative to the page,
			// nothing rewritten: locked.
			'https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12' => array( 'post:12' ),
			'mailto:?body=https%3A%2F%2Fstaging.test%2F%3Fcat%3D1%2C2'      => array( 'term:1', 'term:2' ),
			'https://twitter.com/intent/tweet?text=x&url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12' => array( 'post:?' ),
			'wp-login.php?redirect_to=https%3A%2F%2Fstaging.test%2Fkontakt%2F' => array( 'post:?' ),
			// Codex, round 23 (A-5, A-6): userinfo, an escape in the host.
			'https://viewer@staging.test/?p=12'                            => array( 'post:?' ),
			'https://_user:demo-secret@staging.test/?p=12'                 => array( 'post:?' ),
			'https://other.test/wp-login.php?redirect_to=%2F%3Fp%3D12'     => array( 'post:?' ),
			'mailto:?body=Lesen%3A%20https%3A%2F%2Fstaging.test%2F%3Fp%3D12%20und%20https%3A%2F%2Fstaging.test%2F%3Fpage_id%3D13' => array( 'post:12', 'post:13' ),
			'https://staging.test%2Eother.test/?p=12'                      => array( 'post:?' ),
			'https://www.live.test/?p=12'                                  => array( 'post:?' ),
			'/?u=https%3A%2F%2Fwww.live.test%2F%3Fp%3D12'                  => array( 'post:?' ),
			// Codex, round 25 (A-5): under a host of no site a relative
			// target names a pair only where it carries a reference: an id
			// variable with digits, a path the rules route to an id, a
			// segment of digits relative to the page.
			'https://other.test/wp-login.php?redirect_to=index.php%3Fp%3D12' => array( 'post:?' ),
			'//other.test/?u=%2F%3Fpage_id%3D13'                           => array( 'post:?' ),
			'https://other.test/?r=%2Farchives%2F12%2F'                    => array( 'post:?' ),
			'https://other.test/?r=12%3F'                                  => array( 'post:?' ),
			'https://other.test/?t=Lesen%3A%20%2Farchives%2F12'            => array( 'post:?' ),
			'mailto:info@example.test?subject=Preis%201%20%2F2'            => array(),
			'https://twitter.com/intent/tweet?text=Termin%20%2F2026%2F11%2F04' => array(),
			'https://other.test/?r=%2F2026%2F11%2F'                        => array(),
			'https://other.test/?r=Teil2%3F'                               => array(),
			'https://other.test/?r=Kapitel%2012%3F'                        => array(),
			// Codex, round 25 (A-6): a share text as urlencode() writes it.
			'mailto:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12'    => array( 'post:12' ),
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
			// Codex, round 23 (A-4): the braces of a comment count not.
			"// a } b\n{x:1, token:\"x\"}"                      => array( 'token' ),
			"/* } */ {x:1; secret:\"x\"}"                        => array( 'secret' ),
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
		// Codex, round 23 (A-4): a RegExp literal before the name, or more
		// braces closed than opened: the count cannot decide (undecidable),
		// nothing named here; a division, a comment, a "/" that no "/" on
		// the line closes, are no literal.
		foreach ( array(
			'var cfg={r:/}/,api_key:"demo-secret"};',
			'var cfg={r:/[}]/g,api_key:"demo-secret"};',
			"var r=/}/;\nvar cfg={a:1}, token:\"x\"",
			'return /}/, token: "x"',
			'x = {a:1}}, token:"x"',
		) as $text ) {
			$undecidable = false;
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable ), $text );
			self::assertTrue( $undecidable, $text );
		}
		// Codex, round 24 (A-1): in a layer of code every "/" that is no
		// division after an operand (an identifier that is no keyword, a
		// number, ")", "]", the end of a string) may begin a literal.
		foreach ( array(
			'const c={x:1+/}/.test("x"),api_key:"demo-secret"};',
			'const c={x:void /}/,api_key:"demo-secret"};',
			'const c={x:typeof /}/,api_key:"demo-secret"};',
			'const c={x:1-/}/.test("x"),api_key:"demo-secret"};',
		) as $text ) {
			$undecidable = false;
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable, true ), $text );
			self::assertTrue( $undecidable, $text );
			$undecidable = false;
			AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable );
			self::assertFalse( $undecidable, 'Not in the text itself: ' . $text );
		}
		// Codex, round 25 (A-1): read token by token, a division before a
		// literal hides none; the ")" of if, while, for or with is no
		// operand; a literal that begins before the name counts, wherever
		// it closes on its line.
		foreach ( array(
			'const c={x:1/2+/}/.test("x"),api_key:"demo-secret"};',
			'const c={x:6/2 + /}/.test("x"),api_key:"demo-secret"};',
			'const c={x:a[0]/b+/}/.test("x"),api_key:"demo-secret"};',
			'const c={x:"a"/2 + /}/.test("x"),api_key:"demo-secret"};',
			'const c={x:(1)/2 + /}/.test("x"),api_key:"demo-secret"};',
			'const c={x:++/}/.lastIndex,api_key:"demo-secret"};',
			'const c={x:[/}/][0],api_key:"demo-secret"};',
			'var o={k:(function(){ if (a) /}/.test(b) })(), api_key:"s"};',
			'var o={k:(function(){ while(a) /}/.test(b) })(), api_key:"s"};',
			'var o={k:(function(){ for (;;) /}/.test(b) })(), api_key:"s"};',
			'var o={k:(function(){ with (a) /}/.test(b) })(), api_key:"s"};',
			'var o={k:[/[/}]/], api_key:"s"};',
			'var o={k:[/\\/}/], api_key:"s"};',
			'x = /a, token: "y"/.test(s)',
			"x = 'a\n; r = /x/, token:\"y\"",
			'var o={k:(function(){ a.b; if (a) /}/.test(b) })(), api_key:"s"};',
		) as $text ) {
			$undecidable = false;
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable, true ), $text );
			self::assertTrue( $undecidable, $text );
		}
		foreach ( array(
			'x = 6/2, token:"x"',
			'x = a / b, token:"x"',
			'x = (1)/2 + y[0]/3, token:"x"',
			'x = "a"/2, token:"x"',
			"x = 'a'/2/3, token:\"x\"",
			'x = `a`/2/3, token:"x"',
			'x = a /2/ b, token:"x"',
			'a/b/c, token:"x"',
			'(1)/2/3, token:"x"',
			'x++ / 2 / 3, token:"x"',
			'x-- / 2 / 3, token:"x"',
			'f(a) / 2 / 3, token:"x"',
			'if (f(a) / 2 / 3) b, token:"x"',
			'y = 1.5e3 / 2 / 3, token:"x"',
			'x = a[1] / 2 / 3, token:"x"',
			'x = y /= 2 / 3, token:"x"',
			"x = \"a\nb\" / 2 / 3, token:\"x\"",
			"x = 1, y = / 2\nz = a / b, token:\"x\"",
			'a = 1, token:"x"; r = /}/',
			'while; (a) / 2 / 3, token:"x"',
			'<p>Siehe <a href="https://example.test/">hier</a> und <em>dort</em>, Ort: "Zürich"</p>',
			// Codex, round 26: a property named as a keyword; identifiers of
			// Unicode (of ASCII where the text is no UTF-8).
			'x = obj.if(a) / 2 / 3, token:"x"',
			'x = obj.while (a) / 2 / 3, token:"x"',
			'x = obj. for(a) / 2 / 3, token:"x"',
			'x = obj.return / 2 / 3, token:"x"',
			'x = 1 / π / 3, token:"x"',
			'x = ä / 2 / 3, token:"x"',
			'x = straße / 2 / 3, token:"x"',
			"x = a / b / c, token:\"x\" \xFF",
		) as $text ) {
			$undecidable = false;
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable, true ), $text );
			self::assertFalse( $undecidable, $text );
		}
		foreach ( array(
			'a = b / c, token:"x"',
			'a = b / c / d, token:"x"',
			'// see /x/' . "\n" . 'Felder: Name, Token: "abc"',
			'url(/x), Token: "abc"',
			'x = 1 /* } */, token:"x"',
			'x = "/}/", token:"x"',
		) as $text ) {
			$undecidable = false;
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra, $undecidable ), $text );
			self::assertFalse( $undecidable, $text );
		}
		$undecidable = false;
		self::assertSame( array( 'api_key' ), AB_MCP_Sync_Net::credentials( 'var cfg={r:/}/,api_key:"demo-secret"}; api_key="x"', $extra, $undecidable ), 'Named elsewhere: named.' );
		self::assertTrue( $undecidable );
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

	/** The tokens of the tests, composed at run time (tests/fixtures/sync-tokens.php). */
	private static function tokens(): array {
		return require __DIR__ . '/fixtures/sync-tokens.php';
	}

	public function testTheTokensOfAServiceAndAJsonWebTokenCountByTheirFormInAnyContext(): void {
		// The steering, 09.11.2026: by the prefix and the form the service
		// gives them, wherever they stand (alone, in prose, after Bearer, in a
		// tag, in quotes); the form decides, a word in it frees none (Codex
		// round 3 of branch 11b, A1).
		$t     = self::tokens();
		$alnum = $t['alnum'];
		$hex   = $t['hex'];
		$extra = array();
		foreach ( array(
			'github_token'      => array( $t['ghp'], $t['github_pat'], 'gh' . 'p_' . str_repeat( 'fake', 9 ), 'gh' . 'p_' . $alnum( 'g', 40 ) ),
			'stripe_secret_key' => array( $t['sk_live'], $t['rk_live'], 'sk' . '_live_' . str_repeat( 'my', 12 ) ),
			'aws_access_key_id' => array( $t['akia'], 'AK' . 'IAQ3ZT7W2VX' . 'EXAMPLE', 'AK' . 'IAIOSFODNN7' . 'EXAMPLE1', 'AK' . 'IAIOSFODNN7' . 'EXAMPLEx' ),
			'slack_token'       => array( $t['xoxb'], $t['xoxp'] ),
			'mailchimp_api_key' => array( $t['mailchimp'], $hex( 'm', 32 ) . '-us' . '1' ),
			'access_token'      => array( $t['jwt'] ),
		) as $name => $values ) {
			foreach ( $values as $value ) {
				foreach ( array( '%s', 'Mein Schlüssel: %s, bitte nicht weitergeben.', 'Bearer %s', '<code>%s</code>', '"%s"', "x\n%s." ) as $context ) {
					$text = sprintf( $context, $value );
					self::assertSame( array( $name ), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
				}
			}
		}
		// Not in the form: shorter than the service writes it, glued to a
		// letter or digit before it, a letter or digit after a token of a
		// fixed length, a JSON Web Token whose header has no «alg» or is no
		// JSON, a part of a length base64url never has, one cut short.
		$b64url = $t['b64url'];
		$jwt    = explode( '.', $t['jwt'] );
		foreach ( array(
			'gh' . 'p_' . $alnum( 'g', 35 ),
			'xgh' . 'p_' . $alnum( 'g', 36 ),
			'github' . '_pat_' . $alnum( 'g', 35 ),
			'sk' . '_live_' . $alnum( 's', 23 ),
			'sk' . '_test_' . $alnum( 's', 24 ),
			'pk' . '_live_' . $alnum( 's', 24 ),
			'1sk' . '_live_' . $alnum( 's', 24 ),
			'AK' . 'IA' . 'IOSFODNN7EXAMP',
			'X' . $t['akia'],
			'xo' . 'xb-2048-4096-' . $alnum( 'x', 7 ),
			'xo' . 'xa-2048-4096-' . $alnum( 'x', 24 ),
			'xo' . 'xb-your-token',
			'axo' . 'xb-2048-4096-' . $alnum( 'x', 24 ),
			$hex( 'm', 31 ) . '-us21',
			$hex( 'm', 32 ) . '-us21x',
			$hex( 'm', 32 ) . '-us',
			'a' . $hex( 'm', 32 ) . '-us21',
			'abc123def456-us21',
			$b64url( '{"typ":"JWT"}' ) . '.' . $jwt[1] . '.' . $jwt[2],
			'eyJ' . $alnum( 'h', 16 ) . '.' . $jwt[1] . '.' . $jwt[2],
			$jwt[0] . '.' . $jwt[1] . 'AAA.' . $jwt[2],
			$jwt[0] . '.' . $jwt[1] . '.' . $jwt[2] . 'AB',
			$jwt[0] . '.' . $jwt[1] . "\u{2026}",
			$jwt[0] . '.' . $jwt[1] . '.',
			'x' . $t['jwt'],
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		self::assertTrue( AB_MCP_Sync_Net::jwt( $jwt[0], $jwt[1], $jwt[2] ) );
		self::assertTrue( AB_MCP_Sync_Net::jwt( $b64url( '{"alg":"none"}' ), $jwt[1], 'c2ln' ), 'Its header unpadded, any alg.' );
		$kid = $b64url( '{"alg":"HS256","kid":"~~~"}' );
		self::assertStringContainsString( '-', $kid );
		self::assertTrue( AB_MCP_Sync_Net::jwt( $kid, $jwt[1], $jwt[2] ), 'Its header with the - and _ of base64url.' );
		self::assertSame( array( 'access_token' ), AB_MCP_Sync_Net::credentials( $kid . '.' . $jwt[1] . '.' . $jwt[2], $extra ) );
		self::assertFalse( AB_MCP_Sync_Net::jwt( $jwt[0] . 'A', $jwt[1], $jwt[2] ) );
		self::assertFalse( AB_MCP_Sync_Net::jwt( $b64url( '"alg"' ), $jwt[1], $jwt[2] ), 'Its header no object.' );
	}

	public function testADocumentedExampleIsFreeOnlyAsTheWholeValue(): void {
		// The steering after Codex round 4 of branch 11b: the examples of the
		// documentation of AWS, jwt.io, RFC 7617, MDN, RFC 6750 and Slack free
		// themselves only, as the whole value, in every form the net judges by
		// its value; kept as SHA-256, put together here from parts.
		$t     = self::tokens();
		$e     = $t['examples'];
		$extra = array();
		$names = array();
		foreach ( $e as $name => $value ) {
			self::assertTrue( AB_MCP_Sync_Net::example( $value ), $name );
			self::assertSame( $name, AB_MCP_Sync_Net::EXAMPLES[ hash( 'sha256', $value ) ] );
			$names[] = $name;
		}
		self::assertSame( array_values( AB_MCP_Sync_Net::EXAMPLES ), $names, 'Each entry of the list held by a value here.' );
		$jwt = explode( '.', $e['jwt.io'] );
		foreach ( array(
			'Access Key ID (zum Beispiel ' . $e['aws access key id'] . ')',
			"AWS Access Key ID [None]: " . $e['aws access key id'] . "\nAWS Secret Access Key [None]: " . $e['aws secret access key'],
			$e['aws access key id'] . '.',
			'Authorization: Bearer ' . $e['jwt.io'],
			"Beispiel:\nAuthorization: Bearer " . $e['jwt.io admin'],
			$e['jwt.io'] . '.',
			'Authorization: Bearer ' . $e['rfc 6750'],
			'Authorization: Basic ' . $e['rfc 7617'],
			'Authorization: Basic ' . $e['mdn basic'],
			'Authorization: Bearer ' . $e['aws secret access key'],
			'Authorization: Basic ' . base64_encode( $e['aws access key id'] . ':' . $e['aws secret access key'] ),
			'Authorization: Basic ' . base64_encode( 'admin:' . $e['aws secret access key'] ),
			$e['slack 1'] . "\n" . $e['slack 2'] . "\n" . $e['slack 3'],
			'Authorization: Bearer ' . $e['slack 3'],
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		// One character more or less, a value of its own: no example.
		foreach ( array(
			'aws_access_key_id' => array( $e['aws access key id'] . ' und ' . $t['akia'], $e['aws access key id'] . '2', 'AK' . 'IAQ3ZT7W2VX' . 'EXAMPLE', substr( $e['aws access key id'], 0, 19 ) . 'F', $e['aws access key id'] . '/x', 'Mein ' . $e['aws access key id'] . 'X' ),
			'access_token'      => array( $jwt[0] . '.' . $jwt[1] . '.' . strrev( $jwt[2] ), $e['jwt.io'] . 'A', $jwt[0] . '.' . $jwt[1] . 'AAAA.' . $jwt[2] ),
			'slack_token'       => array( $e['slack 2'] . '9', $e['slack 3'] . '-x' ),
			'authorization'     => array( 'Authorization: Bearer ' . $e['aws secret access key'] . 'X', 'Authorization: Bearer x' . $e['aws secret access key'], 'Authorization: Basic ' . base64_encode( 'admin:' . $e['aws secret access key'] . '1' ), 'Authorization: Basic ' . base64_encode( 'a:' . $e['rfc 6750'] . 'x' ) ),
		) as $name => $texts ) {
			foreach ( $texts as $text ) {
				self::assertSame( array( $name ), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
			}
		}
		self::assertFalse( AB_MCP_Sync_Net::example( '' ) );
		self::assertFalse( AB_MCP_Sync_Net::example( ' ' . $e['rfc 6750'] ) );
	}

	public function testTheHeaderAuthorizationCountsAtTheStartOfALineOrAField(): void {
		// Codex round 1 of branch 11b, A1; the steering, 09.11.2026, and after
		// Codex round 4 (B): at the start of the text, after a line break,
		// «;», a quote or the «:» of an array of headers; Bearer with a value
		// in the form of a token (20 characters of a token or more, with a
		// digit or without, no placeholder of words), Basic with the base64
		// of user:password whose password is no example.
		$t     = self::tokens();
		$hex   = $t['hex32'];
		$tok   = 'Qm9dXyT3kLz8wR2vNp6s';
		$b64   = static fn( string $s ): string => base64_encode( $s );
		$under = implode( '_', str_split( substr( $hex, 0, 10 ) . 'Qm9dXyT3kL' . substr( $hex, 10, 10 ), 10 ) );
		$extra = array();
		self::assertSame( 20, strlen( $tok ) );
		self::assertSame( array( 32, 2 ), array( strlen( $under ), substr_count( $under, '_' ) ) );
		self::assertMatchesRegularExpression( '/[0-9]/', $under );
		foreach ( array(
			'Authorization: Bearer ' . $tok,
			'authorization: bearer ' . $tok,
			'AUTHORIZATION:Bearer ' . $tok . '.',
			'Accept: application/json; Authorization: Bearer ' . $hex,
			'Accept: */*;Authorization: Bearer ' . $tok,
			"Host: example.test\nAuthorization: Bearer " . $hex,
			"Host: example.test\r\n\tAuthorization: Bearer " . $tok,
			"Host: example.test\nAuthorization: 'Bearer " . $tok . "'",
			'curl -H "Authorization: Bearer ' . $hex . '" https://api.example.test/',
			"-H 'Authorization: Bearer " . $tok . "'",
			'`Authorization: Bearer ' . $tok . '`',
			'headers: Authorization: Bearer ' . $tok,
			'Proxy-Authorization: Bearer ' . $tok,
			// The form of a token: 20 or more, with a digit or without; a
			// value of words only where its groups are joined by «_» or «-».
			'Authorization: Bearer ' . substr( $hex, 0, 20 ),
			'Authorization: Bearer QmdXyTkLzwRvNpsHcaJf',
			'Authorization: Bearer abcdefghijklmnopqrs1',
			'Authorization: Bearer ABCDEFGHIJKLMNOPQRS1',
			'Authorization: Bearer abcdefghijklmnopqrst',
			'Authorization: Bearer abcdefghijklmnopqrstuvwx',
			'Authorization: Bearer ABCDEFGHIJKLMNOPQRST',
			'Authorization: Bearer ' . $under,
			'Authorization: Bearer Your_api_token_goes_here',
			'Authorization: Bearer your_api_token_2026_here',
			'Authorization: Bearer a_bcdefghijklmnopqrstu',
			'Authorization: Bearer your.api.token.goes.here',
			'Authorization: Bearer ' . $hex . substr( $hex, 0, 8 ),
			'Authorization: Basic ' . $b64( 'review:REVIEW_ONLY' ),
			'Authorization: Basic ' . $b64( 'review:Sommer2026' ),
			'Authorization: Basic ' . $b64( 'a:b' ),
			'Authorization: Basic ' . $b64( 'a:b1' ),
			'Authorization: Basic ' . $b64( 'admin:admin' ),
			'Authorization: Basic ' . $b64( 'key-of-the-account:' ),
			'Authorization: Basic ' . $b64( ':secret-only' ),
			'Proxy-Authorization: Basic ' . $b64( 'proxy:Kq7vR2pX' ) . ';',
			// A placeholder or an example word in a word is none (Codex round 3, A1).
			'Authorization: Bearer my_' . $hex,
			'Authorization: Bearer fake_token_1234567890',
			'Authorization: Bearer YOUR_TOKEN_abcdefghij1',
			'Authorization: Basic ' . $b64( 'admin:my_password' ),
			'Authorization: Basic ' . $b64( 'admin:password123' ),
			'Authorization: Basic ' . $b64( 'admin:open sesame!' ),
			// A field after a field is one of its own (Codex round 3, A3).
			'Authorization: Basic ' . $b64( 'abc' ) . ';Authorization: Bearer ' . $tok,
			'Authorization: Bearer YOUR_TOKEN;Authorization: Bearer ' . $tok,
			'Authorization: Bearer <token>; Authorization: Basic ' . $b64( 'a:b1' ),
		) as $text ) {
			self::assertSame( array( 'authorization' ), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		foreach ( array(
			// The examples of tutorials (Codex round 4, B): shorter than 20, a
			// word in one case without a digit, cut short.
			'Authorization: Bearer abc123',
			'Authorization: Bearer TOKEN',
			'Authorization: bearer TOKEN',
			'curl -H "Authorization: Bearer TOKEN" https://api.github.com/graphql',
			"Beispiel:\nAuthorization: Bearer abc123",
			'Accept: application/json; Authorization: Bearer REVIEW_ONLY',
			'Authorization: Bearer ' . substr( $tok, 0, 19 ),
			'Authorization: Bearer ' . substr( $tok, 0, 19 ) . '.',
			'Authorization: Bearer your_api_token_goes_here.',
			'Authorization: Bearer <' . $tok . '>',
			'Authorization: Bearer &lt;' . $tok . '&gt;',
			'Authorization: Bearer your_api_token_goes_here',
			'Authorization: Bearer your_personal_access_token',
			'Authorization: Bearer YOUR-PERSONAL-ACCESS-TOKEN',
			'Authorization: Bearer YOUR_API_TOKEN_HERE',
			'Authorization: Bearer ABCDEFGHIJ-KLMNOPQRST',
			'Authorization: Bearer ab__cd--ef_gh-ij_kl_mn',
			"Authorization: Bearer eyJ\u{2026}",
			'Authorization: Bearer sk' . "_live_\u{2026}",
			'Authorization: Bearer ' . $tok . "\u{2026}",
			'Authorization: Bearer ' . $tok . '...',
			'Authorization: Bearer ' . $tok . '==...',
			'Authorization: Bearer Qm9dXyT3kL...z8wR2vNp6s',
			'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOi...',
			// Placeholders in their exact form, and code that builds the value.
			'Authorization: Bearer <token>',
			'Authorization: Bearer &lt;token&gt;',
			'Authorization: Bearer YOUR_TOKEN',
			'Authorization: Bearer YOUR_ACCESS_TOKEN.',
			'Authorization: Bearer YOUR_TOKEN_2026_ABCDEFGH',
			'Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxx',
			'Authorization: Bearer ************',
			'Authorization: Bearer ...',
			"Authorization: Bearer \u{2026}",
			'Authorization: Bearer $TOKEN',
			'Authorization: Bearer ${TOKEN}',
			'Authorization: Bearer {token}',
			'Authorization: Bearer {{token}}',
			'Authorization: Bearer [REDACTED]',
			"\$h[] = 'Authorization: Bearer ' . \$token;",
			// Basic: the example words as the whole password, in any case.
			'Authorization: Basic ' . $b64( 'user:password' ),
			'Authorization: Basic ' . $b64( 'admin:PASSWORD' ),
			'Authorization: Basic ' . $b64( 'admin:Passwort' ),
			'Authorization: Basic ' . $b64( 'api:SECRET' ),
			'Authorization: Basic ' . $b64( 'pass:' ),
			'Authorization: Basic ' . $t['examples']['rfc 7617'],
			'Authorization: Basic ' . $t['examples']['mdn basic'],
			'Authorization: Basic ' . $b64( 'admin:Open Sesame' ),
			'Authorization: Basic ' . $b64( 'ali:OpenSesame' ),
			'Authorization: Basic <base64>',
			'Authorization: Basic ' . $b64( 'foobar' ),
			'Authorization: Basic ' . $b64( 'admin:YOUR_PASSWORD' ),
			'Authorization: Basic ' . $b64( 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' ),
			'Authorization: Basic ' . $b64( 'admin:<password>' ),
			'Authorization: Basic ' . $b64( 'YOUR_API_KEY:' ),
			'Authorization: Basic ' . $b64( ':' ),
			'Authorization: Basic ' . $b64( "a:\x01b1" ),
			'Authorization: Basic ' . $b64( "a:\xFFb1" ),
			'Authorization: Basic mit Benutzer und Passwort',
			// At the start of no line and no field: a limit (GRENZEN.md).
			'Setze den Header Authorization: Bearer ' . $hex . ' in Postman.',
			'<code>Authorization: Bearer ' . $hex . '</code>',
			'Accept: */*, Authorization: Bearer ' . $hex,
			// Another scheme, no scheme, no header.
			'Authorization: Token ' . $hex,
			'Authorization: Digest username="a"',
			'Authorization: erteilt am 3.11. durch die Leitung.',
			'Bearer ' . $hex,
			'This is a bearer instrument.',
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		self::assertTrue( AB_MCP_Sync_Net::bearer( $tok . '</code>' ) );
		self::assertFalse( AB_MCP_Sync_Net::bearer( 'abc123</code>' ) );
		self::assertFalse( AB_MCP_Sync_Net::bearer( '' ) );
		self::assertTrue( AB_MCP_Sync_Net::basic( $b64( 'a:b1' ) . '</code>' ) );
		self::assertFalse( AB_MCP_Sync_Net::basic( '' ) );
	}

	public function testAPrivateKeyInPemCountsByItsFrameAndABodyOfBase64(): void {
		// The steering, 09.11.2026: by its frame (BEGIN and END of the same
		// label) and a body of valid base64, in any context.
		$t     = self::tokens();
		$pem   = $t['pem'];
		$body  = $t['pem_body'];
		$extra = array();
		$end   = static fn( string $label ): string => '-----END ' . $label . 'PRIVATE' . ' KEY-----';
		foreach ( array(
			$pem( '', $body ),
			$pem( 'RSA ', $body ),
			$pem( 'EC ', $body ),
			$pem( 'OPENSSH ', $body ),
			$pem( 'ENCRYPTED ', $body ),
			$pem( 'RSA ', "Proc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,3F17F5316E2BAC89\n\n" . $body ),
			str_replace( 'PRIVATE KEY-----', 'PRIVATE KEY BLOCK-----', $pem( 'PGP ', "Version: GnuPG v2\n\n" . $body . "=nP3x\n" ) ),
			str_replace( "\n", "\r\n", $pem( '', $body ) ),
			str_replace( "\n", "\r", $pem( '', $body ) ),
			'<p>' . str_replace( "\n", '<br>', $pem( '', $body ) ) . '</p>',
			str_replace( "\n", "<br />\n", $pem( '', $body ) ),
			$pem( '', preg_replace( '/^/m', '    ', $body ) ),
			'Hier mein Schlüssel: ' . $pem( '', $body ) . ' – bitte nicht weitergeben.',
			$pem( '', "MIIEvQIB\n" ),
			$pem( '', "MIIEvQIBADANBg...\n" ) . "\n" . $pem( '', $body ),
		) as $text ) {
			self::assertSame( array( 'private_key' ), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		foreach ( array(
			$pem( '', "MIIEvQIBADANBgkqhkiG9w0BAQEFAASC...\n" ),
			$pem( '', "MIIEpAIBAAKCAQEA\u{2026}\n" ),
			$pem( '', "(dein Schlüssel)\n" ),
			$pem( '', "Hier kommt der Key 2 hin\n" ),
			$pem( '', "Schluessel\n" ),
			$pem( '', '' ),
			$pem( '', "...\n" ),
			"-----BEGIN PUBLIC KEY-----\n" . $body . '-----END PUBLIC KEY-----',
			"-----BEGIN CERTIFICATE-----\n" . $body . '-----END CERTIFICATE-----',
			'-----BEGIN RSA PRIVATE' . " KEY-----\n" . $body . $end( 'EC ' ),
			str_replace( 'BEGIN PGP PRIVATE KEY-----', 'BEGIN PGP PRIVATE KEY BLOCK-----', $pem( 'PGP ', $body ) ),
			'-----BEGIN PRIVATE' . " KEY-----\n" . $body,
			'Ein privater Schlüssel beginnt mit -----BEGIN PRIVATE' . ' KEY----- und endet mit ' . $end( '' ) . '. Dazwischen steht Base64.',
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		self::assertTrue( AB_MCP_Sync_Net::pem( "MIIE\nvQIB\n" ) );
		self::assertTrue( AB_MCP_Sync_Net::pem( "Proc-Type: 4,ENCRYPTED\nMIIE" ) );
		self::assertTrue( AB_MCP_Sync_Net::pem( "=nP3x\nMIIE" ) );
		self::assertTrue( AB_MCP_Sync_Net::pem( 'MII=' ) );
		self::assertFalse( AB_MCP_Sync_Net::pem( 'MIIE vQIB' ) );
		self::assertFalse( AB_MCP_Sync_Net::pem( 'MIIEv' ) );
		self::assertFalse( AB_MCP_Sync_Net::pem( 'MIIE=vQI' ) );
	}

	public function testTheValueOfALoginCookieCountsByTheFormWordPressGivesIt(): void {
		// The steering, 09.11.2026: user|expiration|token|hmac as
		// wp_generate_auth_cookie() writes it, raw or URL-encoded, with the
		// name of the cookie or alone; a user with spaces raw, as «+» and as
		// %20 (Codex round 3 of branch 11b, A4).
		$t     = self::tokens();
		$tail  = $t['cookie_tail'];
		$alnum = $t['alnum'];
		$hex   = $t['hex'];
		$extra = array();
		foreach ( array(
			'wordpress_logged_in_5c3e2a91=admin|' . $tail,
			'Cookie: wordpress_logged_in_5c3e2a91=' . rawurlencode( 'admin|' . $tail ) . '; wp-settings-1=x',
			'wordpress_sec_5c3e2a91=Anna Muster|' . $tail,
			'wordpress_sec_5c3e2a91=' . urlencode( 'Anna Muster|' . $tail ),
			'wordpress_sec_5c3e2a91=' . rawurlencode( 'Anna Muster|' . $tail ),
			'Anna Muster|' . $tail,
			str_replace( '%7C', '%7c', rawurlencode( 'anna@example.test|' . $tail ) ),
			'<p>admin|' . $tail . '</p>',
			'{"c":"admin|' . $tail . '"}',
			'|' . $tail,
		) as $text ) {
			self::assertSame( array( 'auth_token' ), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		$exp = '1762790400';
		foreach ( array(
			'Cookie: wordpress_logged_in_5c3e' . "\u{2026}" . '=admin%7C1700000000%7C' . "\u{2026}" . '%7C' . "\u{2026}",
			'Cookie: wordpress_logged_in_5c3e=admin%7C1762700000%7Cabc%7Cdef; wp-settings-1=x',
			'Set-Cookie: wordpress_logged_in_5c3e2a91=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT',
			'Das Cookie wordpress_sec_[hash] hat den Wert benutzer|ablauf|token|hmac.',
			'Cookie: wordpress_test_cookie=WP%20Cookie%20check',
			'admin|176279040|' . $alnum( 'c', 43 ) . '|' . $hex( 'c', 64 ),
			'admin|' . $exp . '|' . $alnum( 'c', 42 ) . '|' . $hex( 'c', 64 ),
			'admin|' . $exp . '|' . $alnum( 'c', 43 ) . '|' . $hex( 'c', 63 ),
			'admin|' . $exp . '|' . $alnum( 'c', 43 ) . '|' . strtoupper( $hex( 'c', 64 ) ),
			'admin%7D' . $exp . '%7C' . $alnum( 'c', 43 ) . '%7C' . $hex( 'c', 64 ),
			'admin|' . $exp . '-' . $alnum( 'c', 43 ) . '|' . $hex( 'c', 64 ),
			'admin|' . $exp . '|' . $alnum( 'c', 43 ) . '%7D' . $hex( 'c', 64 ),
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
	}

	public function testAKeyOfTheSerializedFormatWithAValueCountsInATextThatIsNotUnpacked(): void {
		// The steering, 09.11.2026: a field of a password in the forms of code
		// and configuration (=, =>, "password": of JSON, an array of PHP, a
		// key of the serialized format), the last in a column, a leaf, a
		// fragment in prose.
		$extra = array();
		foreach ( array(
			's:8:"password";s:10:"Sommer2026";'              => array( 'password' ),
			'Export: a:1:{s:7:"api_key";s:3:"abc";} Ende'     => array( 'api_key' ),
			's:6:"secret";i:1234;'                            => array( 'secret' ),
			's:11:"db_password";d:1.5;'                       => array( 'db_password' ),
			"\$cfg = array( 'db_password' => 'Sommer2026' );" => array( 'db_password' ),
			'{"password": "Sommer2026"}'                      => array( 'password' ),
		) as $text => $names ) {
			self::assertSame( $names, AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		foreach ( array(
			's:8:"password";s:0:"";',
			's:8:"password";N;',
			's:8:"password";b:1;',
			's:8:"password";a:0:{}',
			's:5:"title";s:3:"abc";',
			'x:"password";s:3:"abc";',
		) as $text ) {
			self::assertSame( array(), AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
	}

	public function testAPlaceholderIsOneOnlyInItsExactFormAsTheWholeValue(): void {
		// The steering, 09.11.2026; Codex round 3 of branch 11b, A1: never as a
		// part of a word.
		foreach ( array( '<token>', '<your token>', '{token}', '{{token}}', '$TOKEN', '${API_KEY}', 'YOUR_TOKEN', 'YOUR_PASSWORD', 'YOUR_ACCESS_TOKEN_2', 'xxx', 'XXXXXXXX', 'xxxx xxxx xxxx xxxx xxxx xxxx', 'xxxx-xxxx', '***', '************', '...', '.....', "\u{2026}", "\u{2026}\u{2026}", '[REDACTED]' ) as $value ) {
			self::assertTrue( AB_MCP_Sync_Net::placeholder( $value ), $value );
		}
		foreach ( array( '', 'fake', 'my_token', 'example', 'your-token', 'your_api_token', 'YOUR_', 'YOUR_TOKENabc', 'xYOUR_TOKEN', 'YOUR_TOKEN.', 'xx', 'x x x', 'xxxx  xxxx', 'xxx1', '**', '..', 'a...', '...a', '<token>x', 'x<token>', '<>', '{}', '$1', '$', '[redacted]', '[REDACTED] ', ' xxx', "xxx\n", "\xFF" ) as $value ) {
			self::assertFalse( AB_MCP_Sync_Net::placeholder( $value ), $value );
		}
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
		// CI (round 23): the same pair a second time in a decoding only –
		// raw, "?p%3D12" names nothing, decoded it names post 12 once more
		// than the readers read it (each pair as often as they read it).
		list( $locks, $acc ) = self::check( 'https://staging.test/?p=12 https://staging.test/?p%3D12' );
		self::assertSame( array( array( '*:unread_reference@u:net:raw/percent' ), array( 'post:12' ) ), array( $locks, $acc['addressed'] ) );
		// A layer beneath the raw text names the layer.
		list( $locks ) = self::check( base64_encode( '{"src":"/?p=12"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks );
		// Codex, round 23 (A-1): a share link read by its round trip, free;
		// encoded twice, the readers read nothing, the net names the layer.
		list( $locks, $acc ) = self::check( 'https://twitter.com/intent/tweet?url=https%3A%2F%2Fstaging.test%2F%3Fp%3D12' );
		self::assertSame( array( array(), array( 'post:12' ) ), array( $locks, $acc['addressed'] ) );
		list( $locks, $acc ) = self::check( 'https://twitter.com/intent/tweet?url=https%253A%252F%252Fstaging.test%252F%253Fp%253D12' );
		self::assertSame( array( array(), array( 'u?url' ) ), array( $locks, $acc['spelling'] ), 'Encoded twice: the readers lock (Codex, round 24, A-6).' );
		// Codex, round 24 (A-6): a text with an address of the sites by the
		// same round trip; (A-5) a relative target under a host of no site.
		list( $locks, $acc ) = self::check( 'https://twitter.com/intent/tweet?text=Lesen%3A%20https%3A%2F%2Fstaging.test%2F%3Fp%3D12' );
		self::assertSame( array( array(), array( 'post:12' ) ), array( $locks, $acc['addressed'] ) );
		list( $locks, $acc ) = self::check( 'https://other.test/wp-login.php?redirect_to=%2F%3Fp%3D12' );
		self::assertSame( array( array(), array( 'u?redirect_to' ) ), array( $locks, $acc['spelling'] ) );
		// Codex, round 25 (A-6): "+" for a space (urlencode()) in a share
		// text: the percent layer reads it as the readers do, free.
		foreach ( array(
			'mailto:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12',
			'https://twitter.com/intent/tweet?text=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12',
			'sms:?body=Lesen+https%3A%2F%2Fstaging.test%2F%3Fp%3D12',
			'mailto:?body=Lesen%3A%20https%3A%2F%2Fstaging.test%2F%3Fp%3D12',
		) as $raw ) {
			list( $locks, $acc ) = self::check( $raw );
			self::assertSame( array( array(), array(), array( 'post:12' ) ), array( $locks, $acc['spelling'], $acc['addressed'] ), $raw );
		}
		// Codex, round 25 (A-5): a relative target under a host of no site
		// locks only with a reference (the rules of ctx(): archives/12 is
		// post 12, a date and a name are none).
		foreach ( array(
			'mailto:info@example.test?subject=Warum%3F'                     => array(),
			'https://twitter.com/intent/tweet?text=Warum%3F'                => array(),
			'mailto:info@example.test?subject=Preis%201%20%2F2'             => array(),
			'https://twitter.com/intent/tweet?text=Termin%20%2F2026%2F11%2F04' => array(),
			'https://other.test/wp-login.php?redirect_to=%2Fkontakt%2F'     => array(),
			'https://other.test/wp-login.php?redirect_to=index.php%3Fp%3D12' => array( 'u?redirect_to' ),
			'//other.test/?u=%2F%3Fpage_id%3D13'                            => array( 'u?u' ),
			'https://other.test/wp-login.php?redirect_to=%2Farchives%2F12%2F' => array( 'u?redirect_to' ),
			'https://twitter.com/intent/tweet?text=Lesen%3A%20%2Farchives%2F12' => array( 'u?text' ),
		) as $raw => $spelling ) {
			list( $locks, $acc ) = self::check( $raw );
			self::assertSame( array( array(), $spelling ), array( $locks, $acc['spelling'] ), $raw );
		}
		// Codex, round 23 (A-4): the braces of code undecidable: never clean.
		list( $locks, $acc ) = self::check( 'var cfg={r:/}/,api_key:"demo-secret"};' );
		self::assertSame( array( '*:unreadable_value@u:net:raw' ), $locks );
		self::assertSame( array( 'code:u:net:raw' ), $acc['unread'] );
		// Codex, round 24 (A-1): after an operator, in the layer of code;
		// round 25 (A-1): after a division as well. A division after a
		// postfix ++, an end tag of HTML: free.
		foreach ( array(
			'const c={x:1+/}/.test("x"),api_key:"demo-secret"};',
			'const c={x:1/2+/}/.test("x"),api_key:"demo-secret"};',
			'const c={x:6/2 + /}/.test("x"),api_key:"demo-secret"};',
		) as $raw ) {
			list( $locks ) = self::check( $raw );
			self::assertSame( array( '*:unreadable_value@u:net:raw/code' ), $locks, $raw );
		}
		foreach ( array(
			'const o={}; let x=4; x++ / 2 / 3; label: "x";',
			'const o={}; a/b/c; (1)/2/3; 6/2; label: "x";',
			'<p>Siehe <a href="https://example.test/">hier</a> und <em>dort</em>, Ort: Zürich</p>',
			// Codex, round 26.
			'<pre><code>const o={}; x = obj.if(a) / 2 / 3; label: "x";</code></pre>',
			'<pre><code>const o={}; x = obj.while(a) / 2 / 3; label: "x";</code></pre>',
			'<pre><code>const o={}; x = 1 / π / 3; label: "x";</code></pre>',
			'<pre><code>const o={}; x = ä / 2; label: "x";</code></pre>',
		) as $raw ) {
			list( $locks ) = self::check( $raw );
			self::assertSame( array(), $locks, $raw );
		}
		list( $locks ) = self::check( 'a{background:url(/x.png)} b{--brand:"x"}, Token: "abc"' );
		self::assertSame( array(), $locks, 'CSS: url(/x) is no literal; a name after "," outside the blocks is prose.' );
		list( $locks ) = self::check( 'a{background:url(/x.png); --api-key:"x"}' );
		self::assertSame( array( '*:unread_credential@u:net:raw' ), $locks, 'The name in its block counted.' );
		// Codex, round 24 (B-1): a header that is none in an attribute locks,
		// in a text node not.
		list( $locks ) = self::check( '<img src="data:image/svg+x(ml;base64,' . base64_encode( '<svg/>' ) . '" alt="">' );
		self::assertSame( array( '*:unreadable_value@u:net:raw' ), $locks );
		list( $locks ) = self::check( '<p>Data: CSV/JSON</p> <p>Data: text/plain (UTF-8)</p>' );
		self::assertSame( array(), $locks );
		// Codex, round 26: an SVG as a data: address in CSS keeps its "+"
		// (image/svg+xml) in the percent layer: free.
		foreach ( array(
			'a{mask:url("data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E")}',
			'a{background-image:image-set("data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22/%3E" 1x)}',
			'a{width:100%;mask:url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) . ')}',
		) as $raw ) {
			list( $locks ) = self::check( $raw );
			self::assertSame( array(), $locks, $raw );
		}
		// Codex, round 25 (B-1): in quotes in prose as well; in a block
		// attribute (JSON) it locks.
		list( $locks ) = self::check( '<p>Format: "Data: CSV/JSON"</p> <p>Hinweis: "data: siehe Tabelle"</p>' );
		self::assertSame( array(), $locks );
		list( $locks ) = self::check( '<!-- wp:acme/x {"url":"data:image/svg+x(ml;base64,' . base64_encode( '<svg/>' ) . '"} /-->' );
		self::assertSame( array( '*:unreadable_value@u:net:raw' ), $locks );
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

	/* -------------------------------------------- the form (branch 11b) */

	/**
	 * A stored value (a meta value, an option) through the readers of its
	 * kind and the net in its stored form, as meta_form() and option() run
	 * it (Thomas, 08.11.2026: branch 11b); without readers ($readers false)
	 * the net alone.
	 */
	private static function stored( string $raw, bool $readers = true ): array {
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$mark = AB_MCP_Sync_Net::mark( $acc );
		if ( $readers ) {
			\AB_MCP_Sync_Credentials::note( $acc, 'post', 'u', \AB_MCP_Sync_Credentials::unit( 'ab_note', $raw ) );
			AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, 'post', 'u' );
		}
		AB_MCP_Sync_Net::check( $acc, $mark, 'post', 'u', $raw, $ctx, true, true );
		$locks = array();
		foreach ( $acc['locks'] as $lock ) {
			$locks[] = $lock['key'] . '@' . $lock['at'];
		}
		return array( $locks, $acc );
	}

	public function testProseInASerializedValueIsProseWhereTheValueIsReadUnpacked(): void {
		// Branch 11b (Thomas, 08.11.2026; the find of branch 11): the net read
		// a serialized meta value or option as its text, whose braces made it
		// read prose as a field of code. Read unpacked, as WordPress hands it
		// out, each key and leaf on its own: prose is prose there.
		$prose = 'Spieler: Anna, Token: drei';
		foreach ( array(
			'an array'                  => serialize( array( 'text' => $prose ) ),
			'nested'                    => serialize( array( 'text' => serialize( array( 'text' => $prose ) ) ) ),
			'serialized twice'          => serialize( serialize( array( 'text' => $prose ) ) ),
			'a list'                    => serialize( array( 'Felder: Name, Token: "abc"', $prose ) ),
			'a leaf of CSS beside it'   => serialize( array( 'css' => '{{WRAPPER}} .x{color:red', 'text' => $prose ) ),
			'deeper in arrays'          => serialize( array( 'a' => array( 'b' => array( 'c' => $prose ) ), 'n' => 3, 'f' => 0.5, 'on' => true ) ),
			'a text serialized'         => serialize( $prose ),
			'with white space around'   => "\n " . serialize( array( 'text' => $prose ) ) . "\n",
		) as $case => $raw ) {
			list( $locks, $acc ) = self::stored( $raw );
			self::assertSame( array(), $locks, $case );
			self::assertSame( array( array(), array() ), array( $acc['credentials']['mixed'], $acc['unread'] ), $case );
		}
		// A text read as it is stored (a column of a row, which WordPress
		// hands out as stored): its braces as before.
		list( $locks ) = self::check( serialize( array( 'text' => $prose ) ) );
		self::assertSame( array( '*:unread_credential@u:net:raw' ), $locks, 'A column: the raw text.' );
		// A meta value that is no serialized text: the raw text, as before.
		list( $locks ) = self::stored( 'x = {a:1, token: "abc"}' );
		self::assertSame( array( '*:unread_credential@u:net:raw' ), $locks );
	}

	public function testACredentialInASerializedValueLocksWhereverItStands(): void {
		// The other way: a name of a credential with a value beside it in a
		// key or a leaf, nested, encoded, still locks the unit; the place
		// names positions, never a key or a value.
		$b64 = base64_encode( 'password=demo-secret' );
		foreach ( array(
			'code in a leaf'      => array( serialize( array( 'cfg' => '{api_key:"demo-secret"}' ) ), 'php.0:raw' ),
			'nested'              => array( serialize( array( 'x' => serialize( array( 'cfg' => '{api_key:"demo-secret"}' ) ) ) ), 'php.0.php.0:raw' ),
			'base64 in a leaf'    => array( serialize( array( 'note' => $b64 ) ), 'php.0:raw/base64' ),
			'base64 nested'       => array( serialize( array( 'a' => array( 'b' => serialize( array( 'note' => $b64 ) ) ) ) ), 'php.0.0.php.0:raw/base64' ),
			'base64 as a key'     => array( serialize( array( 'title' => 'x', $b64 => 'frei' ) ), 'php.1.key:raw/base64' ),
			'base64 as a key nested' => array( serialize( array( 'x' => serialize( array( $b64 => 'frei' ) ) ) ), 'php.0.php.0.key:raw/base64' ),
			'data: in a leaf'     => array( serialize( array( 'img' => 'data:text/plain;base64,' . base64_encode( 'token=demo-secret' ) ) ), 'php.0:raw/data' ),
			'a leaf of JSON in base64' => array( serialize( array( 'b' => base64_encode( '{"name":"token","value":"s"}' ) ) ), 'php.0:raw/base64' ),
		) as $case => list( $raw, $place ) ) {
			list( $locks, $acc ) = self::stored( $raw );
			self::assertSame( array( '*:unread_credential@u:net:' . $place ), $locks, $case );
			self::assertSame( 'u', $acc['credentials']['mixed'][0]['unit'], $case );
			self::assertStringNotContainsString( 'demo-secret', (string) json_encode( array( $acc['locks'], $acc['credentials'], $acc['unread'] ) ), $case );
			self::assertStringNotContainsString( $b64, (string) json_encode( array( $acc['locks'], $acc['credentials'], $acc['unread'] ) ), $case . ': no key in a place.' );
		}
		// What unpacking drops is read in the raw text: the first value of a
		// key twice (the second replaces it), what follows the end of the
		// value. The value does not serialize back to the text byte for byte.
		$twice = 'a:2:{s:4:"note";s:' . strlen( $b64 ) . ':"' . $b64 . '";s:4:"note";s:4:"frei";}';
		self::assertSame( array( 'note' => 'frei' ), unserialize( $twice ), 'The first value is gone unpacked.' );
		foreach ( array(
			'the first value of a key twice' => array( $twice, 'string.1:raw/base64' ),
			'nested'                         => array( serialize( array( 'x' => $twice ) ), 'php.0.string.1:raw/base64' ),
			'after the end of the value'     => array( serialize( array( 'note' => 'frei' ) ) . serialize( array( 'note' => $b64 ) ), 'string.3:raw/base64' ),
		) as $case => list( $raw, $place ) ) {
			list( $locks ) = self::stored( $raw );
			self::assertSame( array( '*:unread_credential@u:net:' . $place ), $locks, $case );
		}
		// One lock and one note per unit, from the first text that names one.
		list( $locks, $acc ) = self::stored( serialize( array( 'a' => base64_encode( 'token=demo-secret' ), 'b' => '{api_key:"demo-secret"}' ) ) );
		self::assertSame( array( '*:unread_credential@u:net:php.0:raw/base64' ), $locks );
		self::assertCount( 1, $acc['credentials']['mixed'] );
		// A name the readers named already is free, in a leaf as in the raw text.
		list( $locks, $acc ) = self::stored( serialize( array( 'cfg' => '{"api_key":"demo-secret"}' ) ) );
		self::assertSame( array( '*:credential_field@u' ), $locks, 'The credential check named it: the net adds nothing.' );
	}

	public function testAStoredValueThatDoesNotUnpackOrHoldsAnObjectIsNeverClean(): void {
		// The readers of the kinds lock such a value (the credential check,
		// the canonical form), and the net adds nothing to it; the net alone
		// fails closed as well. Unpacked only through
		// AB_MCP_Sync_Canon::unserialized(): no object is made.
		$object = 'a:1:{s:1:"x";O:24:"AB_Net_Test_Never_Woken":0:{}}';
		foreach ( array(
			'a count too high'   => array( 'a:2:{s:4:"text";s:4:"kurz";}', '*:unreadable_value@u' ),
			'a length too high'  => array( 'a:1:{s:4:"text";s:9:"zu kurz";}', '*:php_object@u' ),
			'an object'          => array( $object, '*:php_object@u' ),
			'an enum'            => array( 'a:1:{s:1:"x";E:11:"Suit:Hearts";}', '*:php_object@u' ),
		) as $case => list( $raw, $lock ) ) {
			list( $locks ) = self::stored( $raw );
			self::assertSame( array( $lock ), $locks, $case . ': the lock of the readers.' );
			list( $locks ) = self::stored( $raw, false );
			self::assertSame( array( $lock . ':net' ), $locks, $case . ': the net alone.' );
		}
		self::assertFalse( class_exists( 'AB_Net_Test_Never_Woken', false ), 'No class was asked for.' );
		// Nested: the leaf that does not unpack, by its place.
		list( $locks ) = self::stored( serialize( array( 'x' => $object ) ), false );
		self::assertSame( array( '*:php_object@u:net:php.0' ), $locks );
		list( $locks ) = self::stored( serialize( array( 'ok' => 'x', 'y' => 'a:2:{s:1:"z";s:4:"kurz";}' ) ), false );
		self::assertSame( array( '*:unreadable_value@u:net:php.1' ), $locks );
		// Beyond the layers and the depth of the readers: not read to its end.
		$layers = 'Spieler: Anna, Token: drei';
		for ( $i = 0; $i <= AB_MCP_Sync_Canon::MAX_LAYERS; $i++ ) {
			$layers = serialize( $layers );
		}
		list( $locks, $acc ) = self::stored( $layers, false );
		self::assertSame( array( '*:unreadable_value@u:net:' . implode( '.', array_fill( 0, AB_MCP_Sync_Canon::MAX_LAYERS, 'php' ) ) ), $locks );
		self::assertSame( array( 'depth:u:net:' . implode( '.', array_fill( 0, AB_MCP_Sync_Canon::MAX_LAYERS, 'php' ) ) ), $acc['unread'] );
		$deep = 'x';
		for ( $i = 0; $i <= AB_MCP_Sync_Canon::MAX_DEPTH; $i++ ) {
			$deep = array( $deep );
		}
		list( $locks, $acc ) = self::stored( serialize( $deep ), false );
		self::assertSame( array( '*:unreadable_value' ), array_map( static fn( $l ) => explode( '@', $l )[0], $locks ) );
		self::assertCount( 1, $acc['unread'] );
		self::assertStringStartsWith( 'depth:u:net:php.0.0.0', $acc['unread'][0] );
	}

	/** A data: address the net reads too deep, and a body in UTF-16 that declares no charset (Codex round 1 of branch 11b, A2). */
	private static function unread_leaves(): array {
		$enc = 'password=REVIEW_ONLY';
		for ( $i = 0; $i < 4; $i++ ) {
			$enc = rawurlencode( $enc );
		}
		return array(
			'deep'  => 'data:text/plain;charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( $enc, 'UTF-16LE', 'UTF-8' ) ) . ' x',
			'utf16' => 'data:text/plain;base64,' . base64_encode( mb_convert_encoding( 'password=REVIEW_ONLY', 'UTF-16LE', 'UTF-8' ) ) . ' x',
		);
	}

	public function testALeafTheNetDoesNotReadToItsEndLocksAsAColumnDoes(): void {
		// Codex round 1 of branch 11b, A2: what the layers of a text of the
		// value do not read to its end (deeper than MAX_DEPTH, a body that is
		// no text in UTF-8) locks the unit, as the same text does as a column
		// or a plain value. The raw text does not stand for it: the quotes of
		// the serialized text end the data: address there (" x" inside it),
		// its body then does not decode.
		$leaves = self::unread_leaves();
		foreach ( array(
			'plain'  => array( $leaves['deep'], '*:unreadable_value@u:net', 'depth:u:net' ),
			'a leaf' => array( serialize( array( 'text' => $leaves['deep'] ) ), '*:unreadable_value@u:net:php.0', 'depth:u:net:php.0' ),
			'nested' => array( serialize( array( 'x' => serialize( array( 'text' => $leaves['deep'] ) ) ) ), '*:unreadable_value@u:net:php.0.php.0', 'depth:u:net:php.0.php.0' ),
			'a key'  => array( serialize( array( $leaves['deep'] => 'frei' ) ), '*:unreadable_value@u:net:php.0.key', 'depth:u:net:php.0.key' ),
		) as $case => list( $raw, $lock, $unread ) ) {
			list( $locks, $acc ) = self::stored( $raw );
			self::assertSame( array( $lock ), $locks, 'deep, ' . $case );
			self::assertSame( array( $unread ), $acc['unread'], 'deep, ' . $case );
		}
		foreach ( array(
			'plain'  => array( $leaves['utf16'], 'u:net:raw' ),
			'a leaf' => array( serialize( array( 'text' => $leaves['utf16'] ) ), 'u:net:php.0:raw' ),
			'nested' => array( serialize( array( 'x' => serialize( array( 'text' => $leaves['utf16'] ) ) ) ), 'u:net:php.0.php.0:raw' ),
		) as $case => list( $raw, $place ) ) {
			list( $locks, $acc ) = self::stored( $raw );
			self::assertSame( array( '*:unreadable_value@' . $place ), $locks, 'UTF-16, ' . $case );
			self::assertSame( array( 'charset:' . $place ), $acc['unread'], 'UTF-16, ' . $case );
		}
		// A widget instance (a structure) alike.
		$ctx = self::ctx();
		foreach ( $leaves as $leaf ) {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Net::check( $acc, AB_MCP_Sync_Net::mark( $acc ), 'widget_area', 'w', array( 'title' => 'x', 'text' => $leaf ), $ctx );
			self::assertSame( array( '*:unreadable_value' ), array_values( array_unique( array_column( $acc['locks'], 'key' ) ) ) );
			self::assertNotSame( array(), $acc['unread'] );
		}
	}

	public function testAReferenceInALayerTakenOutOfALeafLocks(): void {
		// The same cause as A2 (Codex round 1 of branch 11b): in the raw text
		// the quotes of the serialized text end the data: address after " x",
		// its body in UTF-16 does not decode there, and its span of base64 is
		// no text without a BOM: the raw text holds no layer with the pair.
		// Every pair in a layer taken out of a text of the value locks, as in
		// a column.
		$data = 'data:text/plain;charset=utf-16le;base64,' . base64_encode( mb_convert_encoding( '<a href="/?p=12">Regeln</a>', 'UTF-16LE', 'UTF-8' ) ) . ' x';
		foreach ( array(
			'plain'  => array( $data, 'u:net:raw/data' ),
			'a leaf' => array( serialize( array( 'text' => $data ) ), 'u:net:php.0:raw/data' ),
			'nested' => array( serialize( array( 'x' => serialize( array( 'text' => $data ) ) ) ), 'u:net:php.0.php.0:raw/data' ),
		) as $case => list( $raw, $place ) ) {
			list( $locks ) = self::stored( $raw );
			self::assertSame( array( '*:unread_reference@' . $place ), $locks, $case );
		}
		// One lock of the references per unit.
		list( $locks ) = self::stored( serialize( array( 'a' => $data, 'b' => $data ) ) );
		self::assertSame( array( '*:unread_reference@u:net:php.0:raw/data' ), $locks );
		// The readers locked the unit already (another spelling): nothing is added.
		$spelled = 'https://staging.test/?p=1%32 ';
		list( $locks, $acc ) = self::stored( serialize( array( 'a' => $spelled, 'text' => $data ) ) );
		self::assertSame( array( array(), array( 'u.a?p' ) ), array( $locks, $acc['spelling'] ) );
		// An address the readers read in a leaf stays free: only a layer taken
		// out of a text counts every pair.
		list( $locks ) = self::stored( serialize( array( 'url' => 'https://staging.test/?p=12', 'text' => 'siehe /?p=13' ) ) );
		self::assertSame( array(), $locks );
	}

	/**
	 * The comparison of Codex round 1 of branch 11b (A1, B; the table the
	 * report names): each text in a serialized value of its kind (a leaf, a
	 * nested leaf, a value with a float of 17 digits, a widget instance, an
	 * option) and the same text as a plain value of that kind. expected is
	 * the verdict both give where it is settled; null where the plain value
	 * misses a pattern of a credential already on 8df6146 (a finding of its
	 * own, reported, not in this branch): there only the equality is held.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string,3:?string}>
	 */
	public static function leafAndPlainValue(): array {
		$prose = 'Spieler: Anna, Token: drei';
		$leaf  = static fn( string $t ): string => serialize( array( 'text' => $t ) );
		$cases = self::unread_leaves();
		$tok   = self::tokens();
		$text  = array(
			'Bearer in headers'           => array( 'Accept: application/json; Authorization: Bearer ' . $tok['hex32'], 'unread_credential' ),
			'Bearer REVIEW_ONLY in headers' => array( 'Accept: application/json; Authorization: Bearer REVIEW_ONLY', 'frei' ),
			'Basic with base64'           => array( 'Accept: application/json; Authorization: Basic ' . base64_encode( 'review:REVIEW_ONLY' ), 'unread_credential' ),
			'quoted prose after Token:'   => array( 'Token: "Ein Spielstein pro Zug"', 'unread_credential' ),
			'prose'                       => array( $prose, 'frei' ),
			'a leaf too deep'             => array( $cases['deep'], 'unreadable_value' ),
			'a leaf not readable'         => array( $cases['utf16'], 'unreadable_value' ),
			'password=geheim'             => array( 'password=geheim', 'credential_field' ),
			'api_key: abc123def'          => array( 'api_key: abc123def', null ),
			'a plain address'             => array( 'https://example.test/spiele/regeln/', 'frei' ),
			'a private key'               => array( self::tokens()['pem']( '', "MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7\n" ), 'unread_credential' ),
			'a cookie header'             => array( 'Cookie: wordpress_logged_in_5c3e=admin%7C1762700000%7Cabc%7Cdef; wp-settings-1=x', 'frei' ),
			// The fixed formats (the steering, 09.11.2026) and Codex round 3
			// of branch 11b: A1 a placeholder in a word, A3 a field after a
			// field, A4 a user with spaces; credentials in prose stay free
			// (limit C, GRENZEN.md), A2 among them.
			'a token of a service'        => array( 'Mein Token ist ' . $tok['ghp'] . ', bitte nicht weitergeben.', 'unread_credential' ),
			'a JSON Web Token'            => array( $tok['jwt'], 'unread_credential' ),
			'Bearer at the start'         => array( 'Authorization: Bearer Qm9dXyT3kLz8wR2vNp6s', 'unread_credential' ),
			'Bearer abc123 (Codex 4, B)'  => array( 'Authorization: Bearer abc123', 'frei' ),
			'Bearer TOKEN after a break'  => array( "Beispiel:\nAuthorization: Bearer TOKEN", 'frei' ),
			'Basic user:password'         => array( 'Authorization: Basic ' . base64_encode( 'user:password' ), 'frei' ),
			'Basic admin:admin'           => array( 'Authorization: Basic ' . base64_encode( 'admin:admin' ), 'unread_credential' ),
			'Bearer YOUR_TOKEN'           => array( 'Authorization: Bearer YOUR_TOKEN', 'frei' ),
			'A1 a placeholder in a word'  => array( 'Authorization: Bearer my_fake_' . $tok['hex32'], 'unread_credential' ),
			'A3 a field after a field'    => array( 'Authorization: Basic ' . base64_encode( 'abc' ) . ';Authorization: Bearer ' . $tok['hex32'], 'unread_credential' ),
			'A4 a cookie, a space'        => array( 'wordpress_sec_5c3e2a91=Anna Muster|' . $tok['cookie_tail'], 'unread_credential' ),
			'A4 a cookie, a space as +'   => array( 'wordpress_sec_5c3e2a91=' . urlencode( 'Anna Muster|' . $tok['cookie_tail'] ), 'unread_credential' ),
			'A4 a cookie, a space as %20' => array( 'wordpress_sec_5c3e2a91=' . rawurlencode( 'Anna Muster|' . $tok['cookie_tail'] ), 'unread_credential' ),
			'a key of the serialized format' => array( 'Export: s:8:"password";s:10:"Sommer2026";', 'unread_credential' ),
			'password: Sommer2026'        => array( 'password: Sommer2026', 'frei' ),
			'A2 password before a tag'    => array( '<p>password: Sommer2026</p>', null ),
			'A3 user:, password: in prose' => array( 'user: anna, password: Sommer2026', 'frei' ),
			'Token: Zugreihenfolgemarkierung' => array( 'Token: Zugreihenfolgemarkierung', 'frei' ),
			'Password: RESET'             => array( 'Password: RESET', 'frei' ),
		);
		$out = array();
		foreach ( $text as $name => list( $t, $expected ) ) {
			$out[ 'meta, ' . $name ]          = array( 'meta', $leaf( $t ), $t, $expected );
			$out[ 'meta nested, ' . $name ]   = array( 'meta', serialize( array( 'x' => $leaf( $t ) ) ), $t, $expected );
			$out[ 'option, ' . $name ]        = array( 'option', $leaf( $t ), $t, $expected );
		}
		$out['meta, a float of 17 digits and prose'] = array( 'meta', 'a:2:{s:1:"f";d:0.10000000000000001;s:4:"text";s:' . strlen( $prose ) . ':"' . $prose . '";}', $prose, 'frei' );
		$out['a text widget with prose']             = array( 'widget', array( 'title' => 'Spielanleitung', 'text' => $prose, 'filter' => true, 'visual' => true ), $prose, 'frei' );
		$out['the option of a plugin with prose']    = array( 'option', serialize( array( 'login_label' => 'Login: Name, Token: aus der App', 'enabled' => '1' ) ), 'Login: Name, Token: aus der App', 'frei' );
		return $out;
	}

	/** The verdict of the readers of a kind and the net on a value: the keys of the locks of the checks, or frei. */
	private static function verdict( string $kind, $raw ): string {
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$mark = AB_MCP_Sync_Net::mark( $acc );
		$k    = 'meta' === $kind ? 'post' : ( 'widget' === $kind ? 'widget_area' : 'option' );
		$at   = 'meta' === $kind ? 'meta:ab_note' : ( 'widget' === $kind ? 'widget:text-2' : 'option_value' );
		$name = 'meta' === $kind ? 'ab_note' : ( 'widget' === $kind ? 'text' : 'image_default_link_type' );
		if ( \AB_MCP_Sync_Credentials::note( $acc, $k, $at, \AB_MCP_Sync_Credentials::unit( $name, $raw ) ) ) {
			if ( is_array( $raw ) ) {
				AB_MCP_Sync_Canon::structure( $raw, $ctx, $acc, $k, $at );
			} else {
				AB_MCP_Sync_Canon::value( $raw, $ctx, $acc, $k, $at );
			}
			AB_MCP_Sync_Net::check( $acc, $mark, $k, $at, $raw, $ctx, true, 'widget' !== $kind );
		}
		$keys = array();
		foreach ( $acc['locks'] as $lock ) {
			if ( 0 === strpos( (string) $lock['key'], '*:' ) ) {
				$keys[ substr( (string) $lock['key'], 2 ) ] = true;
			}
		}
		return array() === $keys ? 'frei' : implode( '+', array_keys( $keys ) );
	}

	#[DataProvider( 'leafAndPlainValue' )]
	public function testATextInASerializedValueIsJudgedAsTheSameTextAsAPlainValue( string $kind, $serialized, string $plain, ?string $expected ): void {
		// Codex round 1 of branch 11b, A1 and B: a leaf gets no other context
		// than the same text as a plain value of its kind (the same reader,
		// the start of the text, the fields after a separator), nested, in a
		// value that does not serialize back, in a widget, in an option.
		$leaf = self::verdict( $kind, $serialized );
		self::assertSame( self::verdict( $kind, $plain ), $leaf );
		if ( null !== $expected ) {
			self::assertSame( $expected, $leaf );
		}
	}

	public function testTheReferencesOfAStoredValueAreCountedOverItsRawText(): void {
		// The count of branch 4 stays over the raw value: what the readers
		// read in the leaves, the net counts in the raw text; a key, which no
		// reader rewrites, counts as well.
		list( $locks ) = self::stored( serialize( array( 'text' => 'siehe index.php?p=12' ) ) );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		list( $locks, $acc ) = self::stored( serialize( array( 'url' => 'https://staging.test/?p=12', 'text' => 'siehe /?p=13' ) ) );
		self::assertSame( array( array(), array( 'post:12', 'post:13' ) ), array( $locks, $acc['addressed'] ) );
		list( $locks ) = self::stored( serialize( array( '/?p=12' => 'x' ) ) );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks, 'An address with an id in a key.' );
		list( $locks ) = self::stored( serialize( array( 'note' => base64_encode( '{"src":"/?p=12"}' ) ) ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks );
	}

	public function testEachKeyAndLeafOfAStructureIsReadOnItsOwn(): void {
		// A widget instance (a structure): every key and every leaf a text of
		// its own (branch 11b; before, the leaves joined and no key), its
		// keys counted for the references too.
		$ctx = self::ctx();
		$net = static function ( array $settings ) use ( $ctx ): array {
			$acc = AB_MCP_Sync_Canon::acc();
			AB_MCP_Sync_Net::check( $acc, AB_MCP_Sync_Net::mark( $acc ), 'widget_area', 'w', $settings, $ctx );
			return array_map( static fn( $l ) => $l['key'] . '@' . $l['at'], $acc['locks'] );
		};
		self::assertSame( array( '*:unread_credential@w:net:1.key:raw/base64' ), $net( array( 'title' => 'x', base64_encode( 'password=demo-secret' ) => 'frei' ) ) );
		self::assertSame( array( '*:unread_credential@w:net:0.1:raw/base64' ), $net( array( 'items' => array( 'a', base64_encode( 'token=demo-secret' ) ) ) ) );
		self::assertSame( array( '*:unread_reference@w:net:raw' ), $net( array( '/?p=12' => 'x' ) ), 'An address with an id in a key.' );
		// A leaf with an open brace leaves the next one prose.
		self::assertSame( array(), $net( array( 'css' => '.x{', 'text' => 'Felder: Name, Token: "abc"' ) ) );
		// A leaf serialized in its turn: unpacked.
		self::assertSame( array(), $net( array( 'text' => serialize( array( 'text' => 'Spieler: Anna, Token: drei' ) ) ) ) );
		self::assertSame( array( '*:unread_credential@w:net:0.php.0:raw' ), $net( array( 'cfg' => serialize( array( 'x' => '{api_key:"demo-secret"}' ) ) ) ) );
		// An object in a structure (none comes from AB_MCP_Sync_Canon::
		// unserialized(), fail closed all the same).
		self::assertSame( array( '*:php_object@w:net:1' ), $net( array( 'title' => 'x', 'o' => new \stdClass() ) ) );
		// A structure is unpacked already, as AB_MCP_Sync_Canon::structure()
		// counts it: a leaf serialized as often as the layers allow is not
		// read to its end.
		$leaf = 'Spieler: Anna, Token: drei';
		for ( $i = 1; $i < AB_MCP_Sync_Canon::MAX_LAYERS; $i++ ) {
			$leaf = serialize( $leaf );
		}
		self::assertSame( array(), $net( array( 'text' => $leaf ) ), 'Within the layers.' );
		self::assertSame( array( '*:unreadable_value@w:net:0' . str_repeat( '.php', AB_MCP_Sync_Canon::MAX_LAYERS - 1 ) ), $net( array( 'text' => serialize( $leaf ) ) ), 'Beyond them.' );
	}

	public function testAValueThatDoesNotSerializeBackIsReadByTheStringsOfItsFormat(): void {
		// A value whose text does not come back byte for byte from its value
		// although nothing was dropped (a float written with another
		// serialize_precision, as PHP 8.5 writes it with 17; a key of digits
		// as a text) is read by every string of its format as well, each on
		// its own, not as the whole text with its braces: prose is prose
		// there too (Codex round 1 of branch 11b, the table of A1 and B).
		$prose = 'Spieler: Anna, Token: drei';
		$float = 'a:2:{s:1:"f";d:0.10000000000000001;s:4:"text";s:' . strlen( $prose ) . ':"' . $prose . '";}';
		self::assertNotSame( $float, serialize( unserialize( $float ) ) );
		foreach ( array(
			'a float'                 => $float,
			'a key of digits as text' => 'a:1:{s:1:"5";s:' . strlen( $prose ) . ':"' . $prose . '";}',
			'a float, nested'         => serialize( array( 'x' => $float ) ),
			'a float, a null, a flag' => 'a:4:{s:1:"f";d:0.10000000000000001;s:1:"n";N;s:1:"b";b:1;s:4:"text";s:' . strlen( $prose ) . ':"' . $prose . '";}',
			'a float without prose'   => 'a:1:{s:1:"f";d:0.10000000000000001;}',
		) as $case => $raw ) {
			list( $locks, $acc ) = self::stored( $raw );
			self::assertSame( array( array(), array() ), array( $locks, $acc['unread'] ), $case );
		}
		// A credential in such a value still locks, by its string.
		$b64 = base64_encode( 'password=demo-secret' );
		list( $locks ) = self::stored( 'a:2:{s:1:"f";d:0.10000000000000001;s:4:"note";s:' . strlen( $b64 ) . ':"' . $b64 . '";}' );
		self::assertSame( array( '*:unread_credential@u:net:string.2:raw/base64' ), $locks );
		// A string of the format that is serialized in its turn is unpacked
		// (a dropped value that holds an object locks, never made).
		$inner = 'a:1:{s:1:"o";O:8:"stdClass":0:{}}';
		list( $locks ) = self::stored( 'a:2:{s:1:"x";s:' . strlen( $inner ) . ':"' . $inner . '";s:1:"x";s:4:"frei";}', false );
		self::assertSame( array( '*:php_object@u:net:string.1' ), $locks );
	}

	public function testAScalarOfTheFormatOnlyInItsStrictFormElseTheTextIsReadWhole(): void {
		// Codex round 2 of branch 11b, A: serialized_strings() took any bytes
		// after i:, d: or b: to the next ";" as a scalar, so what follows the
		// end of the value went unread (unserialize() drops it, the round
		// trip differs, the strings were read: none held it). A scalar counts
		// only in the strict form serialize() writes it; anything else is no
		// record, the text is read as a whole.
		$b64 = base64_encode( 'password=REVIEW_ONLY' );
		foreach ( array(
			'i:'             => array( 'i:password=REVIEW_ONLY;', 'raw' ),
			'd:'             => array( 'd:password=REVIEW_ONLY;', 'raw' ),
			'b:'             => array( 'b:password=REVIEW_ONLY;', 'raw' ),
			'base64 after i:' => array( 'i:' . $b64 . ';', 'raw/base64' ),
		) as $case => list( $tail, $path ) ) {
			$raw = 'a:1:{s:4:"note";s:4:"frei";}' . $tail;
			self::assertSame( array( 'note' => 'frei' ), @unserialize( $raw ), $case . ': unserialize() drops it.' );
			list( $locks ) = self::stored( $raw );
			self::assertSame( array( '*:unread_credential@u:net:' . $path ), $locks, $case . ' (the readers find it clean)' );
		}
		// The forms serialize() writes stay records, read by their strings:
		// prose beside them free (the text as a whole would lock it).
		$prose = 'Spieler: Anna, Token: drei';
		foreach ( array( 'i:-12;', 'i:+7;', 'd:0.1;', 'd:-1.5E-10;', 'd:1.0E+25;', 'd:INF;', 'd:-INF;', 'd:NAN;', 'b:0;', 'b:1;', 'N;' ) as $scalar ) {
			list( $locks ) = self::stored( 'a:3:{s:1:"f";d:0.10000000000000001;s:1:"x";' . $scalar . 's:4:"text";s:' . strlen( $prose ) . ':"' . $prose . '";}', false );
			self::assertSame( array(), $locks, $scalar );
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
