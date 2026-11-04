<?php
/**
 * The net over the raw value (AB_MCP_Sync_Net; Codex, rounds 18 to 20;
 * Thomas, 04.11.2026), clause by clause: the layers a stored value
 * unfolds to (its decodings, data: addresses, base64, each again, to its
 * depth), the count of the addresses with an id in a layer as
 * AB_MCP_Sync_Urls reads one address, the names of credentials with a value
 * beside them, and the comparison with what the readers of the unit found.
 * The cases of the rounds and their variations against the core:
 * tests/SyncCoreReads.php (netReferences(), netCredentials(), netFree()).
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

	/** The layers as path => text. */
	private static function layers( string $raw, ?bool &$deep = null ): array {
		$out = array();
		foreach ( AB_MCP_Sync_Net::layers( $raw, $deep ) as $layer ) {
			$out[ $layer[0] ] = $layer[1];
		}
		return $out;
	}

	/* ------------------------------------------------------------ unfold */

	public function testTheRawTextIsTheFirstLayerAndEachDecodingOneMore(): void {
		self::assertSame( array( 'raw' => 'nur Text' ), self::layers( 'nur Text' ) );
		// The escapes of JSON, of C and of CSS, percent escapes: a layer each
		// where it differs from the text; a decoding that changes nothing is
		// no layer.
		$layers = self::layers( 'a\\/b\\u002fc\\"d\\2f e%2Ff' );
		self::assertSame( 'a/b/c\\"d\\2f e%2Ff', $layers['raw/json'] );
		self::assertSame( 'a/bu002fc"d' . chr( 2 ) . 'f e%2Ff', $layers['raw/slashes'], 'stripcslashes(): \\u and an octal escape as C reads them.' );
		self::assertSame( 'a/bu002fc"d/e%2Ff', $layers['raw/css'], 'AB_MCP_Sync_Css::unescaped(): \\/ and \\" the character, \\u the letter, \\2f the code point.' );
		self::assertSame( 'a\\/b\\u002fc\\"d\\2f e/f', $layers['raw/percent'] );
		self::assertArrayHasKey( 'raw/json/percent', $layers, 'Each layer again.' );
		self::assertArrayNotHasKey( 'raw/percent/percent', $layers, 'A decoding that changes nothing is no layer.' );
		self::assertSame( array( 'raw' => 'a%b' ), self::layers( 'a%b' ), 'No escape to decode: the text alone.' );
		self::assertSame( array( 'raw' => 'a &amp; b' ), self::layers( 'a &amp; b' ), 'Without WP_HTML_Decoder (the unit suite) no reference is decoded here: the credential check fails closed on it.' );
	}

	public function testADataAddressOfAnyTypeIsDecodedAsTheBrowserDecodesIt(): void {
		$layers = self::layers( 'x data:text/plain;base64,' . base64_encode( 'Geheim' ) . ' y' );
		self::assertSame( 'Geheim', $layers['raw/data'] );
		$layers = self::layers( "url('data:text/plain,a b)c')" );
		self::assertSame( 'a b)c', $layers['raw/data'], 'To the quote it stands in.' );
		$layers = self::layers( 'x="data:text/plain,c%20d" y' );
		self::assertSame( 'c d', $layers['raw/data'], 'Percent-decoded.' );
		$layers = self::layers( 'url("data:image/svg+xml;charset=utf-8;base64,' . base64_encode( '<svg/>' ) . '") x' );
		self::assertSame( '<svg/>', $layers['raw/data'], 'base64 among the parameters, to its quote.' );
		$layers = self::layers( 'url(data:text/plain;base64,' . base64_encode( 'ohne Anfuehrungszeichen' ) . ') y' );
		self::assertSame( 'ohne Anfuehrungszeichen', $layers['raw/data'], 'Without a quote, to ")".' );
		$layers = self::layers( 'DATA:Text/Plain;BASE64,' . base64_encode( 'oben' ) );
		self::assertSame( 'oben', $layers['raw/data'], 'In any case.' );
		$layers = self::layers( 'a data:text/plain;base64,!!!! b data:text/plain, c' );
		self::assertArrayNotHasKey( 'raw/data', $layers, 'What does not decode, and an empty body, is no layer.' );
		$layers = AB_MCP_Sync_Net::layers( '"data:text/plain;base64,' . base64_encode( 'erste' ) . '" data:text/plain;base64,' . base64_encode( 'zweite' ) );
		self::assertSame( array( 'raw', 'raw/data', 'raw/data' ), array_column( $layers, 0 ), 'Every data: address.' );
		self::assertSame( array( 'erste', 'zweite' ), array( $layers[1][1], $layers[2][1] ) );
	}

	public function testASpanOfBase64IsALayerWhereItsBytesAreText(): void {
		$layers = self::layers( 'vorher ' . base64_encode( 'api_key=demo-secret' ) . ' nachher' );
		self::assertSame( 'api_key=demo-secret', $layers['raw/base64'] );
		$layers = self::layers( base64_encode( '/?p=1234' ) );
		self::assertSame( '/?p=1234', $layers['raw/base64'], 'The shortest span (12 characters, its padding counted).' );
		self::assertSame( array( 'raw' => 'Lz9wPTEyMzQ' ), self::layers( 'Lz9wPTEyMzQ' ), 'Shorter: not decoded.' );
		self::assertArrayNotHasKey( 'raw/base64', self::layers( base64_encode( "\x89PNG\x00\xff\xfe\x80\x81\x82\x83\x84" ) ), 'Bytes that are no UTF-8: no layer.' );
		self::assertArrayNotHasKey( 'raw/base64', self::layers( 'x' . base64_encode( '/?p=1234' ) ), 'Inside a longer word: no span of its own.' );
		self::assertSame( '/?p=1234', self::layers( 'a:' . base64_encode( '/?p=1234' ) . ';' )['raw/base64'], 'At a character of no base64.' );
	}

	public function testALayerIsReadOnceAndNoDeeperThanTheDepth(): void {
		$deep   = null;
		$layers = self::layers( base64_encode( 'data:text/plain;base64,' . base64_encode( 'Geheimnis' ) ), $deep );
		self::assertSame( 'Geheimnis', $layers['raw/base64/data'] );
		self::assertFalse( $deep );
		// The same text twice: one layer.
		$same = base64_encode( 'ein Text hier' );
		self::assertCount( 2, self::layers( $same . ' ' . $same ) );
		// MAX_DEPTH derivations are followed, the next is not: deep.
		$text = 'Geheimnis';
		for ( $i = 0; $i < AB_MCP_Sync_Net::MAX_DEPTH; $i++ ) {
			$text = 'data:text/plain;base64,' . base64_encode( $text );
		}
		$layers = self::layers( $text, $deep );
		self::assertSame( 'Geheimnis', end( $layers ) );
		self::assertFalse( $deep, 'At the depth: read.' );
		$layers = self::layers( 'data:text/plain;base64,' . base64_encode( $text ), $deep );
		self::assertNotContains( 'Geheimnis', $layers );
		self::assertTrue( $deep, 'Beyond the depth: not read, deep.' );
		// A decoding that changes nothing at the depth is no derivation: the
		// layer was read, nothing is deep.
		$same = '50% Rabatt';
		for ( $i = 0; $i < AB_MCP_Sync_Net::MAX_DEPTH; $i++ ) {
			$same = 'data:text/plain;base64,' . base64_encode( $same );
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

	public function testTheAddressesWithAnIdOfALayerAreCountedAsOneAddressIsRead(): void {
		$ctx = self::ctx();
		// With the host of a site, in the forms the replace engine knows;
		// with any host in another spelling (locked, counted); root-relative
		// after a quote, "=", "(", ":", a space or at the start.
		foreach ( array(
			'https://staging.test/?p=12'                   => 1,
			'Link https://staging.test/?p=12 im Text.'     => 1,
			'{"u":"https:\\/\\/staging.test\\/?p=12"}'     => 1,
			'https://STAGING.test/?p=12'                   => 1,
			'//staging.test/?p=12'                         => 1,
			'/?p=12'                                       => 1,
			'"/?p=12"'                                     => 1,
			'href=/?p=12'                                  => 1,
			'url(/?p=12)'                                  => 1,
			'x:/?p=12'                                     => 1,
			'?p=12'                                        => 1,
			'https://staging.test/?cat=1,2,3'              => 3,
			'/?p=12 und /?page_id=13'                      => 2,
			'/archives/12'                                 => 1,
			'siehe /archives/12.'                          => 1,
			'https://staging.test/archives/12/'            => 1,
			'?a=1;x=/?p=12'                                => 1,
			'https://staging.test/?redirect_to=https%3A%2F%2Fstaging.test%2F%3Fp%3D12' => 0,
			'https://staging.test/?redirect_to=https://staging.test/?p=12'            => 1,
		) as $text => $count ) {
			self::assertSame( $count, AB_MCP_Sync_Net::references( $text, $ctx ), $text );
		}
		// Not an address: after a letter, a digit, "%", "/" or "."; a site
		// without an id; no digit; another site's host; the path of a file;
		// a path the rules route to no object.
		foreach ( array(
			'a/?p=12',
			'1/2 Tag',
			'calc(100%/3)',
			'05/12/2024',
			'index.php?p=12',
			'https://staging.test/ueber-uns/',
			'https://cdn.example.org/?p=12',
			'/wp-content/uploads/2024/05/a.jpg',
			'https://staging.test/wp-content/uploads/2024/05/a-300x200.jpg',
			'/2024/05/',
			'</div>',
			'a{grid-area:1 / 2 / 3 / 4;font:12px/1.5 Arial;aspect-ratio:16/9}',
			'https://staging.test/?cat=0&author=',
		) as $text ) {
			self::assertSame( 0, AB_MCP_Sync_Net::references( $text, $ctx ), $text );
		}
	}

	public function testTheNamesOfCredentialsWithAValueBesideThemAreCounted(): void {
		$extra = array();
		foreach ( array(
			'api_key=demo-secret'                              => array( 'api_key' ),
			'?x=1&token=abc'                                   => array( 'token' ),
			"cfg['api_key']='demo-secret'"                     => array( 'api_key' ),
			'"client_secret" => "x"'                           => array( 'client_secret' ),
			'{"api_key":"x"}'                                  => array( 'api_key' ),
			"{'access_token': 'x'}"                            => array( 'access_token' ),
			'var cfg={api_key:"demo-secret"}'                  => array( 'api_key' ),
			"{ token: 'y' }"                                   => array( 'token' ),
			"{\n  a: 1,\n  apiKey: \"x\"\n}"                   => array( 'api_key' ),
			'init({a:1; secret:"x"})'                          => array( 'secret' ),
			'token: "x"'                                       => array( 'token' ),
			'[{"name":"api_key","value":"demo-secret"}]'       => array( 'api_key' ),
			'{"key":"Authorization","secret":"x"}'             => array( 'secret', 'authorization' ),
			':root{--client-secret:1}'                         => array( 'client_secret' ),
			'a{color:red;--api-key:1}'                         => array( 'api_key' ),
			'{a:1, token:x}'                                   => array( 'token' ),
			'name=token&value=x'                               => array( 'token' ),
			'{"x":{"name":"api_key","value":"s"},"x":0}'       => array( 'api_key' ),
			'password=a&secret=b'                              => array( 'password', 'secret' ),
			'API_KEY = "x"'                                    => array( 'api_key' ),
		) as $text => $names ) {
			self::assertSame( $names, AB_MCP_Sync_Net::credentials( $text, $extra ), $text );
		}
		// No value, or no name: a word in prose, a label, "==", an empty value,
		// a name after "," outside of braces, a name that is none.
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
			'{"name":"api_key"}',
			'{"name":"api_key","label":"x"}',
			'{"name":"api_key","note":"' . str_repeat( 'x', AB_MCP_Sync_Net::WINDOW ) . '","value":"far"}',
			'title=Kontakt',
			'[acme_button label="Token: anfordern"]',
			'input[type="password"]',
			'hasPassword=1&token_count=3',
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

	public function testTheNetLocksWhereALayerCountsMoreIdsThanTheReadersRead(): void {
		// The readers of a plain value read the addresses with a host, not
		// the root-relative one in prose: the net counts it.
		list( $locks ) = self::check( 'siehe /?p=12' );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		// What they read as well: equal, free.
		list( $locks, $acc ) = self::check( 'siehe https://staging.test/?p=12' );
		self::assertSame( array(), $locks );
		self::assertSame( array( 'u?p' ), $acc['addressed'] );
		// A layer beneath the raw text names the layer.
		list( $locks ) = self::check( base64_encode( '{"src":"/?p=12"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw/base64' ), $locks );
		// The first layer with more locks; one lock of a kind per unit.
		list( $locks ) = self::check( '/?p=12 ' . base64_encode( '{"src":"/?p=13"}' ) );
		self::assertSame( array( '*:unread_reference@u:net:raw' ), $locks );
		// What the readers read in an earlier unit does not count for this one.
		$ctx  = self::ctx();
		$acc  = AB_MCP_Sync_Canon::acc();
		$acc['addressed'][] = 'v?p';
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
			AB_MCP_Sync_Canon::value( '/?p=12', $ctx, $acc, 'option', 'u' );
		};
		list( $locks ) = self::check( '/?p=12', $table );
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
			$text = 'data:text/plain;base64,' . base64_encode( $text );
		}
		list( $locks, $acc ) = self::check( $text );
		self::assertSame( array( '*:unreadable_value@u:net' ), $locks );
		self::assertSame( array( 'depth:u:net' ), $acc['unread'] );
	}

	public function testTheMarkCountsWhatAnAccumulatorHolds(): void {
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertSame( array( 'addressed' => 0, 'spelling' => 0, 'locks' => 0, 'mixed' => 0 ), AB_MCP_Sync_Net::mark( $acc ) );
		$acc['addressed'][]            = 'a';
		$acc['spelling'][]             = 'b';
		$acc['locks'][]                = array( 'key' => 'x', 'at' => 'y' );
		$acc['credentials']['mixed'][] = array( 'unit' => 'u', 'fields' => array() );
		self::assertSame( array( 'addressed' => 1, 'spelling' => 1, 'locks' => 1, 'mixed' => 1 ), AB_MCP_Sync_Net::mark( $acc ) );
		// An accumulator made before the net (no addressed): zero.
		unset( $acc['addressed'] );
		self::assertSame( 0, AB_MCP_Sync_Net::mark( $acc )['addressed'] );
	}

	public function testEveryIdOfAnAddressTheReadersReadIsNoted(): void {
		// AB_MCP_Sync_Canon::text(): rewritable and locked alike, in JSON
		// read by its round trip as well; a block attribute read whole.
		$ctx = self::ctx();
		foreach ( array(
			'https://staging.test/?p=12'                          => array( 'u?p' ),
			'https://staging.test/?cat=1,2'                       => array( 'u?cat', 'u?cat' ),
			'https://staging.test/?p=1%32'                        => array( 'u?p' ),
			'{"url":"\\/?p=12"}'                                  => array( 'u:json.url?p' ),
			'https://staging.test/archives/12/ und /?page_id=3'   => array( 'u%post_id%' ),
			'<!-- wp:acme/x {"url":"/?p=12"} /-->'                => array( 'u:acme/x.url?p' ),
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
