<?php
/**
 * wp_search_replace and page builders (plan review S3, contract «Engine» and
 * «wp_search_replace builder-bewusst»):
 *
 *   forms  the encoded forms of a text as pure functions, with the measured
 *          special-character table of the plan (§2) as case table: which
 *          forms a stored page holds, what the old engine found, what this
 *          one finds, and that the replacement lands in the same form;
 *   kses   the kses guard on pairs measured with WordPress 7.0.2's own kses
 *          (test/fixtures/kses/, see messen.php there): which changes keep the
 *          page's meaning and which do not;
 *   tool   the tool itself on the in-memory site of builder-harness.php:
 *          scope text through a writer (copy only, locked_matches, the way to
 *          scope raw, 25 pages per call with next, a writer that throws),
 *          pages without a writer as before, the kses guard, scope raw with
 *          its undo point and the same cap.
 *
 * Run: php test/search-replace-builder-test.php forms|kses|tool
 *      (tool after ./sync-free-core.sh <free>)
 */

$scenario = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $scenario, array( 'forms', 'kses', 'tool' ), true ) ) {
	fwrite( STDERR, "Szenario fehlt oder unbekannt: forms, kses oder tool\n" );
	exit( 2 );
}

/* ====================================================== shared stand-ins */

if ( 'tool' !== $scenario ) {
	error_reporting( E_ALL );
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'AB_MCP_PRO_DIR', dirname( __DIR__, 2 ) . '/' );
	$GLOBALS['ab_fail']  = 0;
	$GLOBALS['ab_count'] = 0;
	function ok( $cond, $label ) {
		$GLOBALS['ab_count']++;
		if ( ! $cond ) {
			$GLOBALS['ab_fail']++;
			echo "  FAIL: $label\n";
		}
	}
	set_error_handler(
		static function ( $no, $str, $file, $line ) {
			if ( ! ( error_reporting() & $no ) ) {
				return true;
			}
			$GLOBALS['ab_fail']++;
			$GLOBALS['ab_count']++;
			echo '  FAIL: PHP: ' . $str . ' in ' . basename( (string) $file ) . ':' . $line . "\n";
			return true;
		}
	);
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function __( $s, $d = null ) { return $s; }
	$GLOBALS['ab_filters'] = array();
	function apply_filters( $tag, $value, ...$rest ) { return isset( $GLOBALS['ab_filters'][ $tag ] ) ? $GLOBALS['ab_filters'][ $tag ]( $value, ...$rest ) : $value; }
	function ab_result( $name ) {
		if ( $GLOBALS['ab_fail'] ) {
			printf( "%s: FAILED (%d von %d)\n", $name, $GLOBALS['ab_fail'], $GLOBALS['ab_count'] );
			exit( 1 );
		}
		printf( "ALLE %d %s BESTANDEN (0 Fehler)\n", $GLOBALS['ab_count'], $name );
		exit( 0 );
	}
}

/**
 * WordPress core 7.0.2, wp-includes/functions.php is_serialized() — verbatim,
 * so the engine meets the same notion of "looks serialized" as on a site.
 */
function ab_is_serialized_core( $data, $strict = true ) {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		if ( false !== $semicolon && $semicolon < 3 ) {
			return false;
		}
		if ( false !== $brace && $brace < 4 ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( ! str_contains( $data, '"' ) ) {
				return false;
			}
			// Or else fall through.
		case 'a':
		case 'O':
		case 'E':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
	}
	return false;
}

/**
 * WordPress core 7.0.2, wp-includes/blocks.php serialize_block_attributes()
 * — verbatim apart from the name, as the reference for the block_json form.
 */
function ab_serialize_block_attributes_core( $block_attributes ) {
	$encoded_attributes = json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return strtr(
		$encoded_attributes,
		array(
			'\\\\' => '\\u005c',
			'--'   => '\\u002d\\u002d',
			'<'    => '\\u003c',
			'>'    => '\\u003e',
			'&'    => '\\u0026',
			'\\"'  => '\\u0022',
		)
	);
}

/** The JSON of the block comments of a page, decoded, in order. */
function ab_block_attrs( $content ) {
	$out = array();
	foreach ( AB_MCP_Replace_Engine::attr_spans( $content ) as $span ) {
		$out[] = array( $span[2], json_decode( substr( $content, $span[0], $span[1] - $span[0] ), true ) );
	}
	return $out;
}

/* ============================================================ forms */

if ( 'forms' === $scenario ) {
	function is_serialized( $data, $strict = true ) { return ab_is_serialized_core( $data, $strict ); }
	require AB_MCP_PRO_DIR . 'includes/class-replace-engine.php';
	$E = 'AB_MCP_Replace_Engine';

	// F1. The forms of the measured text (plan §2, bloecke/README.md «Sonderzeichen»).
	$text = 'A & B "Zitat" <x> – Grüsse ü';
	$table = array(
		'plain'           => 'A & B "Zitat" <x> – Grüsse ü',
		'json'            => 'A & B \\"Zitat\\" <x> \\u2013 Gr\\u00fcsse \\u00fc',
		'block_json'      => 'A \\u0026 B \\u0022Zitat\\u0022 \\u003cx\\u003e – Grüsse ü',  // measured, all six block runs
		'html'            => 'A &amp; B "Zitat" &lt;x> – Grüsse ü',                         // measured, the block editor's HTML
		'html_full'       => 'A &amp; B &quot;Zitat&quot; &lt;x&gt; – Grüsse ü',
		'block_json_html' => 'A \\u0026amp; B \\u0022Zitat\\u0022 \\u0026lt;x\\u003e – Grüsse ü',
	);
	ok( array_keys( $table ) === $E::FORMS, 'F1 the forms, in their order: plain, json, block_json, html, html_full, block_json_html' );
	foreach ( $table as $form => $want ) {
		ok( $want === $E::encode( $form, $text ), "F1 $form of the measured text" );
	}
	ok( 'a\\u002d\\u002db \\u005c c' === $E::encode( 'block_json', 'a--b \\ c' ) && 'x' === $E::encode( 'nonsense', 'x' ), 'F1 block_json writes "--" and a backslash as serialize_block_attributes() does; an unknown form leaves the text' );
	// The block_json form is serialize_block_attributes() itself, for any text.
	$same = true;
	$pool = array( '&', '<', '>', '"', '\\', '-', '--', '/', 'ü', '–', "\n", "\t", "'", '{', '}', 'a', ' ', '€', '😀', "\x01" );
	mt_srand( 4711 );
	for ( $i = 0; $i < 500 && $same; $i++ ) {
		$s = '';
		for ( $j = mt_rand( 1, 12 ); $j > 0; $j-- ) {
			$s .= $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
		}
		$ref  = substr( ab_serialize_block_attributes_core( array( 'x' => $s ) ), 6, -2 );
		$same = $ref === $E::encode( 'block_json', $s );
	}
	ok( $same, 'F1 block_json equals serialize_block_attributes() of WordPress 7.0.2 on 500 random texts' );
	ok( 'A &amp; B' === $E::encode( 'html_full', 'A & B' ) && "\xff&amp;" === $E::encode( 'html_full', "\xff&" ), 'F1 html_full also encodes a text that is not valid UTF-8 (htmlspecialchars() would return nothing)' );

	// F2. needles(): one per form whose encoding is new, plain first.
	$n = $E::needles( 'A & B' );
	ok( array( 'plain', 'block_json', 'html', 'block_json_html' ) === array_column( $n, 'form' ), 'F2 "A & B": json and html_full repeat an earlier needle and are left out' );
	ok( array( 'plain' ) === array_column( $E::needles( 'Mueller' ), 'form' ), 'F2 a word without special characters has one needle' );
	ok( 6 === count( $E::needles( '"<x>"' ) ), 'F2 quotes and angle brackets give six different needles' );
	$eng = new AB_MCP_Replace_Engine( 'A & B', 'x' );
	ok( array( 'A & B', 'A \\u0026 B', 'A &amp; B', 'A \\u0026amp; B' ) === $eng->like_needles(), 'F2 the SQL prefilter covers every needle' );
	$rx = new AB_MCP_Replace_Engine( 'A.*B', 'x', false, true );
	ok( array() === $rx->like_needles() && array() === $rx->forms(), 'F2 regex mode has no forms and no prefilter, as before' );

	// F3. The measured hit table: stored pages of five block libraries.
	$fix   = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/standalone-lib/fixtures/forms/sonderzeichen.json' ), true );
	$pages = $fix['pages'];
	ok( 5 === count( $pages ), 'F3 five measured pages (core, GenerateBlocks, Kadence, Spectra, Stackable)' );
	// The engine before this change: the typed text and its wp_json_encode() form, one after the other.
	$old = static function ( $content, $search ) {
		$j = substr( (string) json_encode( $search ), 1, -1 );
		return substr_count( $content, $search ) + ( $j !== $search ? substr_count( $content, $j ) : 0 );
	};
	$new = static function ( $content, $search ) {
		$e = new AB_MCP_Replace_Engine( $search, 'ERSATZ', false, false, array( 'attr_context' => true ) );
		$n = 0;
		$e->replace_string( $content, $n );
		return $n;
	};
	// search => [ old engine, new engine ] for the four pages with attribute + HTML, and for Kadence's button (attribute only).
	$hits = array(
		'A & B'      => array( array( 0, 2 ), array( 0, 1 ) ),
		'"Zitat"'    => array( array( 1, 2 ), array( 0, 1 ) ),
		'<x>'        => array( array( 0, 2 ), array( 0, 1 ) ),
		'– Grüsse ü' => array( array( 2, 2 ), array( 1, 1 ) ),
	);
	foreach ( $hits as $search => $want ) {
		foreach ( $pages as $builder => $page ) {
			$exp = 'kadence' === $builder ? $want[1] : $want[0];
			ok( $exp === array( $old( $page['content'], $search ), $new( $page['content'], $search ) ), "F3 $builder, «{$search}»: before {$exp[0]}, now {$exp[1]} (attribute and HTML)" );
		}
	}

	// F4. The replacement in the form it was found: every copy reads the same afterwards.
	foreach ( $pages as $builder => $page ) {
		$e = new AB_MCP_Replace_Engine( 'A & B', 'C & D', false, false, array( 'attr_context' => true ) );
		$n = 0;
		$c = $e->replace_string( $page['content'], $n );
		$attrs = ab_block_attrs( $c );
		$text  = null;
		array_walk_recursive(
			$attrs,
			static function ( $v ) use ( &$text ) {
				if ( is_string( $v ) && false !== strpos( $v, 'Zitat' ) ) {
					$text = $v;
				}
			}
		);
		$want = 'kadence' === $builder ? 'C &amp; D "Zitat" <x> – Grüsse ü' : 'C & D "Zitat" <x> – Grüsse ü';
		ok( $want === $text, "F4 $builder: the attribute decodes to «{$want}»" );
		ok( false === strpos( $c, 'A &amp; B' ) && false === strpos( $c, 'A \\u0026' ) && ( 'kadence' === $builder || false !== strpos( $c, 'C &amp; D "Zitat" &lt;x>' ) ), "F4 $builder: no old copy is left, the HTML carries the new text as the editor writes it" );
		ok( count( ab_block_attrs( $page['content'] ) ) === count( array_filter( $attrs, static function ( $a ) { return is_array( $a[1] ); } ) ), "F4 $builder: every block's attributes are still valid JSON" );
	}

	// F5. Inside block attributes a plain word gets its replacement as JSON; raw keeps the old way.
	$page = '<!-- wp:heading {"metadata":{"name":"Mueller"}} --><h2>Mueller</h2><!-- /wp:heading -->';
	$e    = new AB_MCP_Replace_Engine( 'Mueller', 'Müller & "Söhne" -- <b>', false, false, array( 'attr_context' => true ) );
	$n    = 0;
	$c    = $e->replace_string( $page, $n );
	$a    = ab_block_attrs( $c );
	ok( 2 === $n && 'Müller & "Söhne" -- <b>' === $a[0][1]['metadata']['name'] && false !== strpos( $c, '<h2>Müller & "Söhne" -- <b></h2>' ), 'F5 scope text: a quote, "&", "--" and "<" in the replacement go into the attribute as JSON (the value decodes to the replacement), into the HTML as typed' );
	$e = new AB_MCP_Replace_Engine( 'Mueller', 'Müller "Söhne"' );
	$n = 0;
	$c = $e->replace_string( $page, $n );
	$a = ab_block_attrs( $c );
	ok( 2 === $n && null === $a[0][1], 'F5 without attr_context (scope raw) the replacement goes in as typed, as before: a quote breaks the attribute JSON — raw is the old way' );
	// A needle that spans the JSON structure is replaced as typed in both scopes.
	$page = '<!-- wp:heading {"level":2,"x":"y"} --><h2>t</h2><!-- /wp:heading -->';
	$e    = new AB_MCP_Replace_Engine( '"level":2', '"level":3', false, false, array( 'attr_context' => true ) );
	$n    = 0;
	$c    = $e->replace_string( $page, $n );
	ok( 1 === $n && 3 === ab_block_attrs( $c )[0][1]['level'], 'F5 a search that spans the attribute structure ("level":2) is replaced as typed: structure stays structure' );
	ok( false === $E::fits_json_string( '"a' ) && false === $E::fits_json_string( 'a\\b' ) && false === $E::fits_json_string( "a\nb" ) && true === $E::fits_json_string( 'A & B <x> –' ), 'F5 fits_json_string(): no quote, backslash or control character' );

	// F6. A block that prints its attributes as HTML gets the replacement HTML-escaped (Kadence's button, measured).
	$page = '<!-- wp:kadence/singlebtn {"text":"Los"} /--><!-- wp:heading {"metadata":{"name":"Los"}} --><h2>Los</h2><!-- /wp:heading -->';
	$e    = new AB_MCP_Replace_Engine( 'Los', '<b>Start</b> & mehr', false, false, array( 'attr_context' => true ) );
	$n    = 0;
	$c    = $e->replace_string( $page, $n );
	$a    = ab_block_attrs( $c );
	ok( '&lt;b>Start&lt;/b> &amp; mehr' === $a[0][1]['text'] && '<b>Start</b> & mehr' === $a[1][1]['metadata']['name'], 'F6 kadence/singlebtn: the replacement is HTML-escaped in its attribute (Kadence prints it raw); another block keeps it as text' );
	$GLOBALS['ab_filters']['ab_mcp_pro_attrs_printed_as_html'] = static function ( $list ) { return array(); };
	$n = 0;
	$c = ( new AB_MCP_Replace_Engine( 'Los', '<b>Start</b>', false, false, array( 'attr_context' => true ) ) )->replace_string( $page, $n );
	ok( '<b>Start</b>' === ab_block_attrs( $c )[0][1]['text'], 'F6 the list is a filter (ab_mcp_pro_attrs_printed_as_html)' );
	unset( $GLOBALS['ab_filters']['ab_mcp_pro_attrs_printed_as_html'] );

	// F7. replacement(): the case table of form and place.
	$R    = static function ( $form, $search, $replace, $ctx ) use ( $E ) { return $E::replacement( $form, $E::encode( $form, $search ), $replace, $ctx ); };
	$rows = array(
		array( 'plain', 'A', 'C & "D"', 'text', 'C & "D"' ),
		array( 'html', 'A & B', 'C & <D>', 'text', 'C &amp; &lt;D>' ),
		array( 'html_full', '"A"', '"C" & D', 'text', '&quot;C&quot; &amp; D' ),
		array( 'block_json', 'A & B', 'C & D', 'text', 'C \\u0026 D' ),
		array( 'block_json_html', 'A & B', 'C & D', 'text', 'C \\u0026amp; D' ),
		array( 'json', 'ü', 'ö/', 'text', '\\u00f6\\/' ),
		array( 'plain', 'A', 'C & "D"', 'attrs', 'C \\u0026 \\u0022D\\u0022' ),
		array( 'plain', '"A"', '"C"', 'attrs', '"C"' ),
		array( 'html', 'A & B', 'C & D', 'attrs', 'C \\u0026amp; D' ),
		array( 'block_json', 'A & B', '<C>', 'attrs', '\\u003cC\\u003e' ),
		array( 'block_json', 'A & B', '<C>', 'attrs_html', '\\u0026lt;C\\u003e' ),
		array( 'json', 'ü', 'ö', 'attrs', '\\u00f6' ),
		array( 'html_full', '"A"', '"C"', 'attrs', '\\u0026quot;C\\u0026quot;' ),
	);
	foreach ( $rows as $i => $r ) {
		ok( $r[4] === $R( $r[0], $r[1], $r[2], $r[3] ), 'F7 row ' . ( $i + 1 ) . ": {$r[0]} in {$r[3]}: «{$r[2]}» → «{$r[4]}»" );
	}

	// F8. One pass: the longest form wins at each place, and a replacement is never searched again.
	$e = new AB_MCP_Replace_Engine( '&', 'und' );
	$n = 0;
	ok( 'a und b und c und d' === $e->replace_string( 'a &amp; b & c \\u0026 d', $n ) && 3 === $n, 'F8 "&amp;" is the encoded "&", not "&" followed by "amp;"' );
	$e = new AB_MCP_Replace_Engine( 'A & B', 'A & B & C' );
	$n = 0;
	ok( 'A &amp; B &amp; C' === $e->replace_string( 'A &amp; B', $n ) && 1 === $n, 'F8 a replacement that holds the search text in another form is not replaced again' );
	$e = new AB_MCP_Replace_Engine( 'x', 'xx' );
	$n = 0;
	ok( 'xx-xx' === $e->replace_string( 'x-x', $n ) && 2 === $n, 'F8 nor one that holds it in the same form' );

	// F9. Case, Unicode, bytes.
	$e = new AB_MCP_Replace_Engine( 'grüsse & co', 'Gruss', true );
	$n = 0;
	ok( 'Gruss / Gruss' === $e->replace_string( 'GRÜSSE &amp; CO / Grüsse \\u0026 Co', $n ) && 2 === $n, 'F9 case-insensitive, umlauts included, in every form; the count is what was replaced' );
	$e = new AB_MCP_Replace_Engine( 'foo', 'bar' );
	$n = 0;
	ok( "\xff bar \xfe" === $e->replace_string( "\xff foo \xfe", $n ) && 1 === $n, 'F9 a value that is not valid UTF-8 is still searched when case matters' );
	$e = new AB_MCP_Replace_Engine( 'foo', 'bar', true );
	$n = 0;
	ok( "\xff foo" === $e->replace_string( "\xff foo", $n ) && 0 === $n, 'F9 and left as it is when case does not (the u modifier), as before' );
	$e = new AB_MCP_Replace_Engine( 'A & (B)', 'X', false, true );
	$n = 0;
	ok( 'A &amp; B, X' === $e->replace_string( 'A &amp; B, A & B', $n ) && 1 === $n, 'F9 regex mode matches the stored bytes only, as before' );

	// F10. Inside a JSON document (Elementor's data): walked as structure, the HTML form found in a field.
	$doc = '[{"id":"a1","settings":{"editor":"<p>A &amp; B<\\/p>","title":"A & B"}}]';
	$e   = new AB_MCP_Replace_Engine( 'A & B', 'C & D' );
	$n   = 0;
	$out = $e->apply_value( $doc, $n );
	$d   = json_decode( $out, true );
	ok( 2 === $n && '<p>C &amp; D</p>' === $d[0]['settings']['editor'] && 'C & D' === $d[0]['settings']['title'] && json_encode( $d ) === $out, 'F10 in a JSON document the HTML of an editor field and a plain title are both found and rewritten in their form; the document re-encodes byte-exact' );

	ab_result( 'SEARCH-REPLACE-BUILDER (forms)-CHECKS' );
}

/* ============================================================= kses */

if ( 'kses' === $scenario ) {
	$GLOBALS['ab_unfiltered'] = false;
	function current_user_can( $cap ) { return 'unfiltered_html' === $cap ? $GLOBALS['ab_unfiltered'] : true; }
	// What WordPress 7.0.2's kses stored for each measured page (fixtures); a
	// value it was not measured on is returned as it is.
	$GLOBALS['ab_kses'] = array();
	$GLOBALS['ab_kses_calls'] = array();
	function wp_kses( $value, $context ) {
		$GLOBALS['ab_kses_calls'][] = $context;
		return isset( $GLOBALS['ab_kses'][ $value ] ) ? $GLOBALS['ab_kses'][ $value ] : $value;
	}
	require AB_MCP_PRO_DIR . 'includes/class-kses-guard.php';
	$G = 'AB_MCP_Kses_Guard';

	// K1. The measured pairs: the guard calls changed what changed the page.
	$kept    = array( 'core-cover', 'core-media-text', 'core-paragraph', 'generateblocks-gb1-image', 'kadence-image' );
	$changed = array(
		'core-image'                      => 'a img tag',                                  // the data: image source dropped
		'core-sonderzeichen'              => 'the attributes of the block core/heading',   // "&" and "<x>" in an attribute
		'generateblocks-gb2-button'       => 'the attributes of the block generateblocks/text', // the hover style "&:is("
		'kadence-singlebtn-sonderzeichen' => 'the attributes of the block kadence/singlebtn',
		'spectra-about-20'                => 'a svg tag',                                  // the button icon
		'stackable-image'                 => 'a style tag',                                // the block's own CSS
		'pagelayer-code-page'             => 'a script tag',                               // an embed's script
	);
	$files = glob( dirname( __DIR__ ) . '/standalone-lib/fixtures/kses/*.json' );
	ok( 12 === count( $files ), 'K1 twelve measured pages' );
	foreach ( $files as $file ) {
		$name = basename( $file, '.json' );
		$fx   = json_decode( (string) file_get_contents( $file ), true );
		$GLOBALS['ab_kses'][ $fx['content'] ] = $fx['stored'];
		$same = $G::canonical( $fx['content'] ) === $G::canonical( $fx['stored'] );
		ok( in_array( $name, $kept, true ) ? $same : ! $same, "K1 $name: " . ( in_array( $name, $kept, true ) ? 'kses keeps its meaning' : 'kses changes it' ) );
		ok( $fx['changed'] === ! $same, "K1 $name: as recorded at the measurement" );
		ok( isset( $changed[ $name ] ) || in_array( $name, $kept, true ), "K1 $name has an expectation" );
	}
	foreach ( array( 'core-cover', 'core-media-text', 'generateblocks-gb1-image', 'kadence-image' ) as $name ) {
		$fx = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/standalone-lib/fixtures/kses/' . $name . '.json' ), true );
		ok( $fx['content'] !== $fx['stored'], "K1 $name: kses wrote other bytes (\"/>\" as \" />\", attributes serialised anew) — the guard compares meaning, not bytes" );
	}

	// K2. difference(): null where the meaning is kept, what changes otherwise; only for an account kses filters.
	foreach ( $files as $file ) {
		$name = basename( $file, '.json' );
		$fx   = json_decode( (string) file_get_contents( $file ), true );
		$why  = $G::difference( $fx['content'], 'post_content' );
		if ( in_array( $name, $kept, true ) ) {
			ok( null === $why, "K2 $name: written" );
		} else {
			ok( $changed[ $name ] === $why, "K2 $name: skipped, because kses would store {$changed[ $name ]} differently (got: " . var_export( $why, true ) . ')' );
		}
	}
	$GLOBALS['ab_kses_calls'] = array();
	$G::difference( 'Titel', 'post_title' );
	$G::difference( 'Auszug', 'post_excerpt' );
	ok( array( 'title_save_pre', 'post' ) === $GLOBALS['ab_kses_calls'], 'K2 a title is filtered as title_save_pre filters it, content and excerpt as post content' );
	$GLOBALS['ab_unfiltered'] = true;
	ok( false === $G::applies(), 'K2 an account that may post unfiltered HTML is not filtered: the guard does not apply' );
	$GLOBALS['ab_unfiltered'] = false;
	ok( true === $G::applies(), 'K2 and applies to one that may not' );

	// K3. canonical(): what counts as the same page.
	$same = array(
		array( '<img src="a" alt=""/>', '<img src="a" alt="" />' ),
		array( '<p class="a"  id=b>x</p>', '<p class="a" id="b">x</p>' ),
		array( '<p>A & B</p>', '<p>A &amp; B</p>' ),
		array( '<a href="?a=1&b=2">x</a>', '<a href="?a=1&amp;b=2">x</a>' ),
		array( '<!-- wp:x/y {"a":"v--w"} /-->', '<!-- wp:x/y {"a":"v\\u002d\\u002dw"} /-->' ),
		array( '<!-- wp:core/paragraph -->', '<!-- wp:paragraph -->' ),
		array( '<P CLASS="a">', '<p class="a">' ),
	);
	foreach ( $same as $i => $pair ) {
		ok( $G::canonical( $pair[0] ) === $G::canonical( $pair[1] ), 'K3 the same page: ' . $pair[0] );
	}
	$differ = array(
		array( '<p>x<svg><path d="M0"/></svg></p>', '<p>x</p>' ),
		array( '<img src="data:image/png;base64,AA">', '<img src="image/png;base64,AA">' ),
		array( '<!-- wp:x/y {"a":"A & B"} /-->', '<!-- wp:x/y {"a":"A &amp; B"} /-->' ),
		array( '<!-- wp:x/y {"&:hover":{"c":1}} /-->', '<!-- wp:x/y {"&amp;:hover":{"c":1}} /-->' ),
		array( '<p onclick="x()">a</p>', '<p>a</p>' ),
		array( '<style>a{}</style>', 'a{}' ),
	);
	foreach ( $differ as $i => $pair ) {
		ok( $G::canonical( $pair[0] ) !== $G::canonical( $pair[1] ), 'K3 another page: ' . $pair[0] );
	}
	ab_result( 'SEARCH-REPLACE-BUILDER (kses)-CHECKS' );
}

/* ============================================================= tool */

// Declared only when this scenario runs: the stand-ins above declare some
// of the same WordPress functions for theirs.
if ( 'tool' === $scenario ) {
	require dirname( __DIR__ ) . '/standalone-lib/builder-harness.php';

	/* ---- what wp_search_replace asks of WordPress beyond the harness ---- */
	function is_serialized( $data, $strict = true ) { return ab_is_serialized_core( $data, $strict ); }
	function get_the_title( $id ) { $p = get_post( $id ); return $p ? (string) $p->post_title : ''; }
	function get_post_type( $id ) { $p = get_post( $id ); return $p ? (string) $p->post_type : false; }
	function is_protected_meta( $key, $type = null ) { return '_' === substr( (string) $key, 0, 1 ); }
	function registered_meta_key_exists( $type, $key, $subtype = '' ) { return false; }
	function wp_slash( $v ) { if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = wp_slash( $x ); } return $v; } return is_string( $v ) ? addslashes( $v ) : $v; }
	function wp_unslash( $v ) { if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = wp_unslash( $x ); } return $v; } return is_string( $v ) ? stripslashes( $v ) : $v; }
	// Like core: slashed in; the columns it is given are written. Records every call.
	$GLOBALS['ab_post_writes'] = array();
	function wp_update_post( $data, $wp_error = false ) {
		$data = wp_unslash( $data );
		$id   = (int) $data['ID'];
		$GLOBALS['ab_post_writes'][] = $data;
		foreach ( $data as $k => $v ) {
			if ( 'ID' !== $k ) { $GLOBALS['db_posts'][ $id ][ $k ] = $v; }
		}
		return $id;
	}
	// Like core: the one row, the value serialized when it is not a string.
	$GLOBALS['ab_meta_writes'] = array();
	function update_metadata_by_mid( $type, $mid, $value ) {
		$GLOBALS['ab_meta_writes'][] = array( (int) $mid, $value );
		$GLOBALS['db_meta'][ (int) $mid ][2] = (string) maybe_serialize( $value );
		return true;
	}
	/*
	 * Stand-in for wp_kses(): only what the tests below need of WordPress' kses
	 * (measured, test/fixtures/kses/): an <svg> is dropped, "/>" is written
	 * " />". Not a sanitiser.
	 */
	$GLOBALS['ab_kses_calls'] = 0;
	function wp_kses( $value, $context ) {
		$GLOBALS['ab_kses_calls']++;
		$value = preg_replace( '~<svg\b.*?</svg>~s', '', (string) $value );
		return preg_replace( '~"/>~', '" />', $value );
	}

	$core = AB_MCP_PRO_DIR . 'includes/tools/';
	require_once $core . 'class-tools-base.php';
	require_once $core . 'class-tools-content.php';
	require_once $core . 'class-tools-search-bulk.php';
		require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-search-replace.php';

	/*
	 * The scans of wp_search_replace on the in-memory tables. The window of ids
	 * is read from the prepared arguments (the two placeholders of the cursor);
	 * the LIKE prefilter is not applied — the handlers judge every row anyway,
	 * as in regex mode — and every query is recorded.
	 */
	class AB_Scan_WPDB {
		public $posts    = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public $options  = 'wp_options';
		public $inner;
		public $log = array();
		public function __construct( $inner ) { $this->inner = $inner; }
		public function __get( $k ) { return $this->inner->$k; }
		public function __set( $k, $v ) { $this->inner->$k = $v; }
		public function prepare( $q, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
			return array( 'q' => $q, 'a' => $args );
		}
		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
		private static function window( $q, $a, $col ) {
			$at = strpos( $q, $col . ' > %d' );
			$k  = preg_match_all( '~%[sd]~', substr( $q, 0, (int) $at ) );
			return array( (int) $a[ $k ], (int) $a[ $k + 1 ] );
		}
		private static function post_ok( $row, $q ) {
			if ( in_array( $row['post_status'], array( 'trash', 'auto-draft' ), true ) ) { return false; }
			return ! ( false !== strpos( $q, "post_type <> 'revision'" ) && 'revision' === $row['post_type'] );
		}
		public function get_var( $p ) {
			$q = is_array( $p ) ? $p['q'] : (string) $p;
			$this->log[] = $q;
			if ( 0 === strpos( $q, 'SELECT MAX(' ) ) {
				$ids = false !== strpos( $q, 'meta_id' ) ? array_keys( $GLOBALS['db_meta'] ) : array_keys( $GLOBALS['db_posts'] );
				return array() === $ids ? null : (string) max( $ids );
			}
			return $this->inner->get_var( $p );
		}
		public function get_results( $p, $out = null ) {
			$q = $p['q'];
			$a = $p['a'];
			$this->log[] = $q;
			if ( 0 === strpos( $q, 'SELECT ID, post_type, post_parent, post_content, post_title, post_excerpt FROM wp_posts' ) || 0 === strpos( $q, 'SELECT p.ID, p.post_type, p.post_parent FROM wp_posts p' ) ) {
				list( $lo, $hi ) = self::window( $q, $a, false !== strpos( $q, 'p.ID >' ) ? 'p.ID' : 'ID' );
				// Only the columns the query names, as MySQL returns them: what
				// the scan did not select (post_content_filtered) it must read itself.
				$cols = 0 === strpos( $q, 'SELECT ID,' ) ? array( 'ID', 'post_type', 'post_parent', 'post_content', 'post_title', 'post_excerpt' ) : array( 'ID', 'post_type', 'post_parent' );
				$rows = array();
				ksort( $GLOBALS['db_posts'] );
				foreach ( $GLOBALS['db_posts'] as $id => $row ) {
					if ( $id > $lo && $id <= $hi && self::post_ok( $row, $q ) ) {
						$rows[] = array_intersect_key( $row + array( 'post_excerpt' => '' ), array_flip( $cols ) );
					}
				}
				return array_slice( $rows, 0, 200 );
			}
			if ( 0 === strpos( $q, 'SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_parent FROM wp_postmeta pm' ) ) {
				list( $lo, $hi ) = self::window( $q, $a, 'pm.meta_id' );
				$rows = array();
				ksort( $GLOBALS['db_meta'] );
				foreach ( $GLOBALS['db_meta'] as $mid => $m ) {
					$post = isset( $GLOBALS['db_posts'][ $m[0] ] ) ? $GLOBALS['db_posts'][ $m[0] ] : null;
					if ( $mid > $lo && $mid <= $hi && null !== $post && self::post_ok( $post, $q ) && ! in_array( $m[1], array( '_edit_lock' ), true ) ) {
						$rows[] = array( 'meta_id' => $mid, 'post_id' => $m[0], 'meta_key' => $m[1], 'meta_value' => $m[2], 'post_type' => $post['post_type'], 'post_parent' => $post['post_parent'] );
					}
				}
				return array_slice( $rows, 0, 200 );
			}
			if ( 0 === strpos( $q, 'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = %d' ) ) {
				$rows = array();
				foreach ( $GLOBALS['db_meta'] as $mid => $m ) {
					if ( $m[0] === (int) $a[0] ) { $rows[] = array( 'meta_key' => $m[1], 'meta_value' => $m[2] ); }
				}
				return $rows;
			}
			return $this->inner->get_results( $p, $out );
		}
		public function update( $t, $d, $w ) { return $this->inner->update( $t, $d, $w ); }
		public function insert( $t, $d ) { return $this->inner->insert( $t, $d ); }
		public function delete( $t, $w ) { return $this->inner->delete( $t, $w ); }
		public function queries_like( $needle ) { return array_values( array_filter( $this->log, static function ( $q ) use ( $needle ) { return false !== strpos( $q, $needle ); } ) ); }
	}
	$GLOBALS['wpdb'] = new AB_Scan_WPDB( $GLOBALS['wpdb'] );

	/* ---- a page builder with a writer: "Demo", storage B (A2 with demo_a2) ---- */
	class AB_Test_Demo_Adapter extends AB_MCP_Builder_Adapter {
		public function id(): string { return 'demo'; }
		public function name(): string { return 'Demo Builder'; }
		public function detect( $post ): bool { return '' !== ab_first_meta( (int) $post->ID, 'demo_layout' ); }
		public function is_active(): bool { return true; }
		public function verified(): bool { return true; }
		public function storage( $post ): string {
			if ( '' !== ab_first_meta( (int) $post->ID, 'demo_a' ) ) { return 'A'; }
			return '' !== ab_first_meta( (int) $post->ID, 'demo_a2' ) ? 'A2' : 'B';
		}
		public function own_data_shown( $post ): ?bool { return true; }
		public function copies( $post ): array {
			$a2 = 'A2' === $this->storage( $post );
			return AB_MCP_Builders::existing_copies(
				$post,
				array(
					array( 'loc' => 'post_content', 'shown' => $a2, 'role' => 'copy' ),
					array( 'loc' => 'meta:demo_layout', 'shown' => ! $a2, 'role' => 'source' ),
				),
				$this->locked_meta_keys()
			);
		}
		public function locked_meta_keys(): array { return array( 'demo_custom_css' ); }
		public function outline( $post, array $o ): array {
			$out = array();
			foreach ( (array) json_decode( ab_first_meta( (int) $post->ID, 'demo_layout' ), true ) as $el ) {
				$row = array( 'id' => $el['id'], 'type' => $el['type'], 'parent' => null, 'depth' => 0 );
				if ( ! empty( $el['locked'] ) ) {
					$row['locked'] = true;
					$row['reason'] = $el['locked'];
				} else {
					foreach ( $el['fields'] as $name => $f ) {
						$row['fields'][ $name ] = AB_MCP_Builders::cap_field( $f, isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : 2000 );
					}
				}
				$out[] = $row;
			}
			return $out;
		}
	}
	add_filter( 'ab_mcp_builder_adapters', static function ( $list ) { $list[] = new AB_Test_Demo_Adapter(); return $list; }, 20 );
	AB_MCP_Builders::reset();

	/**
	 * The writer of the Demo builder, written the way the contract asks: its
	 * replace() matches with text_engine() on the fields it may change, counts
	 * matches in locked elements, and hands the changes to execute().
	 */
	class AB_Test_Demo_Writer extends AB_MCP_Builder_Writer {
		public static $throw_on = 0;
		public static $cannot   = array();
		public static $calls    = array();
		public static $no_regex = false;
		public static $fail_readback_on = 0;      // read-back fails there, after someone else saved the page
		public static $touched          = array(); // touched_locs(): what a save touches besides the copies
		public static $leave_kind       = '';      // fields of this kind are left, with a reason, as a field rule would
		public static $refuse_on        = 0;       // the page is refused as a whole before anything is written (as Elementor's T2 guard does)
		public function id(): string { return 'demo'; }
		public function touched_locs( $post ): array { return self::$touched; }
		protected function readback( $post, array $change, array $ids_before ) {
			if ( (int) $post->ID === self::$fail_readback_on ) {
				$GLOBALS['db_posts'][ (int) $post->ID ]['post_content'] .= "\n(jemand anderes)";
				return new WP_Error( 'ab_mcp_builder_readback_fields', 'After the save these fields do not show their new value: h1:title.' );
			}
			return parent::readback( $post, $change, $ids_before );
		}
		public function can_write( $post ) {
			return in_array( (int) $post->ID, self::$cannot, true ) ? new WP_Error( 'ab_mcp_builder_cannot', 'Demo is in safe mode on this page.' ) : true;
		}
		public function set_fields( $post, $element_id, array $fields, array $opts ) { return new WP_Error( 'x', 'not used here' ); }
		public function replace( $post, $search, $replace, array $opts ) {
			self::$calls[] = array( (int) $post->ID, $opts );
			if ( (int) $post->ID === self::$throw_on ) {
				throw new RuntimeException( 'secret page detail' );
			}
			if ( self::$no_regex && ! empty( $opts['regex'] ) ) {
				// As the writers that do not take a pattern answer.
				return new WP_Error( 'ab_mcp_builder_regex', 'A regular expression is not applied to the fields of a Demo Builder page. Search for the text itself, or run wp_search_replace with scope: raw.' );
			}
			if ( (int) $post->ID === self::$refuse_on ) {
				return new WP_Error( 'ab_mcp_builder_demo_unregistered', 'Demo would drop the elements it has not registered when saving this page. Nothing was written. Activate the plugin that provides them.', array( 'types' => array( 'x-widget' ) ) );
			}
			$engine  = $this->text_engine( $search, $replace, $opts );
			$changes = array();
			$left    = array();
			$locked  = 0;
			foreach ( (array) json_decode( ab_first_meta( (int) $post->ID, 'demo_layout' ), true ) as $el ) {
				if ( ! empty( $el['locked'] ) ) {
					$n = 0;
					$engine->replace_string( (string) $el['code'], $n );
					$locked += $n;
					continue;
				}
				foreach ( $el['fields'] as $name => $f ) {
					$n     = 0;
					$after = $engine->replace_string( (string) $f['value'], $n );
					if ( $n > 0 && self::$leave_kind === $f['kind'] ) {
						// Left for a reason, and so not handled: counted as locked.
						$left[]  = $el['id'] . ':' . $name . ' left as it is: the new value breaks the rules of its kind.';
						$locked += $n;
					} elseif ( $n > 0 ) {
						$changes[] = array( 'element' => $el['id'], 'type' => $el['type'], 'field' => $name, 'kind' => $f['kind'], 'before' => $f['value'], 'after' => $after );
					}
				}
			}
			return $this->execute( $post, array( 'changes' => $changes, 'locked_matches' => $locked, 'left' => $left ), $opts );
		}
		protected function write( $post, array $change, array $opts ) {
			$id   = (int) $post->ID;
			$tree = json_decode( ab_first_meta( $id, 'demo_layout' ), true );
			$copy = array();
			foreach ( $tree as $k => $el ) {
				foreach ( $change['changes'] as $c ) {
					if ( $el['id'] === $c['element'] ) {
						$tree[ $k ]['fields'][ $c['field'] ]['value'] = $c['after'];
					}
				}
				foreach ( isset( $tree[ $k ]['fields'] ) ? $tree[ $k ]['fields'] : array() as $f ) {
					$copy[] = (string) $f['value'];
				}
			}
			$rows = ab_meta_rows( $id, 'demo_layout' );
			$GLOBALS['db_meta'][ key( $rows ) ][2] = json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$GLOBALS['db_posts'][ $id ]['post_content'] = implode( "\n", $copy );
			return true;
		}
	}
	AB_MCP_Builder_Writers::register_class( 'AB_Test_Demo_Writer' );
	AB_MCP_Builder_Writers::reset();

	/** A Demo page: elements as the tree, their texts as the copy in post_content. */
	function ab_demo_page( $id, array $extra = array(), $code = 'body{} /* Willkommen */' ) {
		$els = array(
			array( 'id' => 'h1', 'type' => 'heading', 'fields' => array( 'title' => array( 'kind' => 'heading', 'value' => 'Willkommen bei A & B' ) ) ),
			array( 'id' => 't1', 'type' => 'text', 'fields' => array( 'editor' => array( 'kind' => 'html', 'value' => '<p>Willkommen, A &amp; B grüsst.</p>' ) ) ),
			array( 'id' => 'b1', 'type' => 'button', 'fields' => array( 'text' => array( 'kind' => 'text', 'value' => 'Los' ), 'link' => array( 'kind' => 'url', 'value' => 'https://alt.example/kontakt' ) ) ),
			array( 'id' => 'x1', 'type' => 'html', 'locked' => 'html widget', 'code' => '<div>Willkommen</div><script>var u="https://alt.example";</script>' ),
		);
		$copy = array();
		foreach ( $els as $el ) {
			foreach ( isset( $el['fields'] ) ? $el['fields'] : array() as $f ) { $copy[] = $f['value']; }
		}
		ab_post( $id, array_merge( array( 'post_content' => implode( "\n", $copy ), 'post_title' => 'Seite ' . $id ), $extra ) );
		ab_meta_add( $id, 'demo_layout', json_encode( $els, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		ab_meta_add( $id, 'demo_custom_css', $code );
	}
	function ab_demo_value( $id, $el, $field ) {
		foreach ( json_decode( ab_first_meta( $id, 'demo_layout' ), true ) as $node ) {
			if ( $node['id'] === $el ) { return $node['fields'][ $field ]['value']; }
		}
		return null;
	}
	function ab_site_reset() {
		$GLOBALS['db_posts']       = array();
		$GLOBALS['db_meta']        = array();
		$GLOBALS['db_next']        = 1000;
		$GLOBALS['ab_post_writes'] = array();
		$GLOBALS['ab_meta_writes'] = array();
		$GLOBALS['ab_kses_calls']  = 0;
		$GLOBALS['ab_meta_deleted'] = array();
		$GLOBALS['ab_opts']        = array();
		$GLOBALS['ab_caps']        = array( 'manage_options' => true, 'unfiltered_html' => true, 'read_post' => true, 'edit_post' => true, 'edit_post_meta' => true, 'edit_posts' => true );
		$GLOBALS['ab_toolstate']   = array();
		$GLOBALS['wpdb']->log      = array();
		AB_Test_Demo_Writer::$throw_on = 0;
		AB_Test_Demo_Writer::$cannot   = array();
		AB_Test_Demo_Writer::$calls    = array();
		AB_Test_Demo_Writer::$no_regex = false;
		AB_Test_Demo_Writer::$fail_readback_on = 0;
		AB_Test_Demo_Writer::$touched          = array();
		AB_Test_Demo_Writer::$leave_kind       = '';
		AB_Test_Demo_Writer::$refuse_on        = 0;
		foreach ( ab_bin_files() as $f ) { @unlink( $f ); }
	}
	$SR = static function ( array $a ) { return AB_MCP_Tools_Search_Replace::search_replace( $a ); };
	// An undo point, or empty parts where there is none (a failing check, not a crash).
	$claim = static function ( $token ) {
		$c = AB_MCP_Undo::claim( (string) $token );
		return is_array( $c ) ? $c : array( 'entry' => array(), 'data' => array( 'posts' => array() ), 'parts' => array() );
	};
	$undo = static function ( $token, $force = false ) {
		$c = AB_MCP_Undo::claim( (string) $token );
		return is_array( $c ) ? AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'], array( 'parts' => $c['parts'], 'force' => $force ) ) : $c;
	};

	/* ===================== T1: scope text through the writer, dry run first */

	ab_site_reset();
	ab_demo_page( 10 );
	ab_post( 11, array( 'post_content' => '<!-- wp:paragraph --><p>Willkommen bei A &amp; B</p><!-- /wp:paragraph -->', 'post_title' => 'Blog' ) );
	$before_meta = $GLOBALS['db_meta'];
	$before_post = $GLOBALS['db_posts'];
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Grüezi' ) );
	ok( is_array( $r ) && 'text' === $r['scope'] && true === $r['dry_run'], 'T1 scope text is the default' );
	ok( isset( $r['tables']['builders'] ) && 1 === $r['tables']['builders']['matched'] && 2 === $r['tables']['builders']['occurrences'], 'T1 the Demo page goes through its writer: one page, two fields (heading and text)' );
	$smp = $r['tables']['builders']['samples'][0];
	ok( 10 === $smp['id'] && 'demo' === $smp['builder'] && array( 'h1', 't1' ) === array_column( $smp['changes'], 'element' ) && 'Grüezi bei A & B' === $smp['changes'][0]['after'], 'T1 the preview shows each field before and after' );
	ok( 1 === $r['tables']['builders']['locked_matches'] && 1 === $r['tables']['postmeta']['locked_matches'] && 2 === $r['locked_matches'], 'T1 matches in a locked element (the HTML widget) and in the page code the builder locks (demo_custom_css) are counted: locked_matches' );
	ok( isset( $r['locked_note'] ) && false !== strpos( $r['locked_note'], 'run again with scope: raw' ), 'T1 and the answer names the way: run again with scope: raw' );
	ok( array( 10 ) === $r['tables']['posts']['copy_only'] && 1 === $r['tables']['posts']['matched'] && 11 === $r['tables']['posts']['samples'][0]['id'], 'T1 post_content of the Demo page is only a copy (copy_only); the ordinary post is replaced as before' );
	ok( 0 === $r['tables']['postmeta']['matched'], 'T1 the builder data does not go through the engine (postmeta)' );
	ok( $before_meta === $GLOBALS['db_meta'] && $before_post === $GLOBALS['db_posts'] && array() === get_option( AB_MCP_Undo::OPTION, array() ) && null === $r['undo_token'], 'T1 the dry run writes nothing and records no undo point' );
	$call = AB_Test_Demo_Writer::$calls[0][1];
	ok( 'text' === $call['scope'] && 'wp_search_replace' === $call['undo_tool'] && false === $call['apply'] && 64 === strlen( $call['layout_hash'] ), 'T1 the writer is asked with scope text, for wp_search_replace, with the layout_hash read right before' );
	$exists = $GLOBALS['wpdb']->queries_like( 'EXISTS (SELECT 1 FROM wp_postmeta bm WHERE bm.post_id = p.ID AND bm.meta_value LIKE %s)' );
	ok( 1 <= count( $exists ) && false !== strpos( $exists[0], 'p.post_content_filtered LIKE %s' ), 'T1 a page is a candidate by post_content, its second copy or any meta value (prefilter of the builders scan)' );

	$r = $SR( array( 'search' => 'var u', 'replace' => 'let u', 'apply' => true ) );
	ok( 1 === $r['tables']['builders']['matched'] && 0 === $r['tables']['builders']['changed'] && 1 === $r['locked_matches'] && isset( $r['locked_note'] ) && false !== strpos( ab_first_meta( 10, 'demo_layout' ), 'var u=' ), 'T1 a text found only in a locked element: nothing is written, the match is counted and the way named' );

	/* ============================================ T2: apply, undo */

	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Grüezi', 'apply' => true ) );
	ok( 1 === $r['tables']['builders']['changed'] && array( 10 ) === $r['tables']['builders']['applied'], 'T2 applied: the Demo page is written by its writer' );
	ok( 1 === $r['tables']['builders']['locked_matches'] && 2 === $r['locked_matches'], 'T2 and the applied answer counts the locked matches as the preview did' );
	ok( 'Grüezi bei A & B' === ab_demo_value( 10, 'h1', 'title' ) && '<p>Grüezi, A &amp; B grüsst.</p>' === ab_demo_value( 10, 't1', 'editor' ), 'T2 the fields carry the replacement' );
	ok( false !== strpos( ab_first_meta( 10, 'demo_layout' ), '<div>Willkommen</div>' ) && 'body{} /* Willkommen */' === ab_first_meta( 10, 'demo_custom_css' ), 'T2 the locked element and the page code are left as they are' );
	ok( false !== strpos( $GLOBALS['db_posts'][10]['post_content'], 'Grüezi bei A & B' ), 'T2 the copy is the writer\'s, kept in step by it' );
	ok( 0 === count( array_filter( $GLOBALS['ab_post_writes'], static function ( $w ) { return 10 === (int) $w['ID']; } ) ), 'T2 the posts scan did not write the Demo page' );
	ok( '<!-- wp:paragraph --><p>Grüezi bei A &amp; B</p><!-- /wp:paragraph -->' === $GLOBALS['db_posts'][11]['post_content'], 'T2 the ordinary post is written as before' );
	ok( AB_MCP_Undo::valid_token( (string) $r['undo_token'] ), 'T2 the answer carries the undo token of the writer\'s point' );
	ok( false !== strpos( implode( ' ', $r['warnings'] ), 'undo_token takes back the page-builder pages in undo_pages' ) && false !== strpos( implode( ' ', $r['warnings'] ), 'other posts' ) && array( 10 ) === $r['undo_pages'], 'T2 and says what the token does not take back: the ordinary post this call changed too; undo_pages names the page it holds' );
	$c = $claim( $r['undo_token'] );
	ok( 'wp_search_replace' === $c['entry']['tool'] && 'builder_data' === $c['entry']['kind'] && 'text' === $c['data']['scope'], 'T2 the point: wp_search_replace, builder_data, scope text' );
	$back = $undo( $r['undo_token'] );
	ok( ! is_wp_error( $back ) && $before_meta[ key( ab_meta_rows( 10, 'demo_layout' ) ) ][2] === ab_first_meta( 10, 'demo_layout' ) && $before_post[10]['post_content'] === $GLOBALS['db_posts'][10]['post_content'], 'T2 wp_undo puts the Demo page back byte for byte' );

	/* ============================ T3: 25 pages per call, next continues */

	ab_site_reset();
	for ( $i = 1; $i <= 30; $i++ ) {
		ab_demo_page( 100 + $i );
	}
	$r1 = $SR( array( 'search' => 'Los', 'replace' => 'Los jetzt', 'apply' => true, 'tables' => array( 'posts', 'postmeta' ) ) );
	ok( 25 === $r1['tables']['builders']['changed'] && true === $r1['tables']['builders']['truncated'] && 125 === $r1['next']['builders'], 'T3 one call writes 25 pages through their writer and stops; next.builders names the last' );
	ok( false !== strpos( implode( ' ', $r1['tables']['builders']['notes'] ), 'the most one call does' ), 'T3 and says why' );
	$c = $claim( $r1['undo_token'] );
	ok( 24 === count( $c['parts'] ) && 25 === count( $c['entry']['after'] ), 'T3 one undo point for the call: the first page and 24 parts, the state after for all 25' );
	$r2 = $SR( array( 'search' => 'Los', 'replace' => 'Los jetzt', 'apply' => true, 'tables' => array( 'posts', 'postmeta' ), 'after' => $r1['next'] ) );
	ok( 5 === $r2['tables']['builders']['changed'] && false === $r2['tables']['builders']['truncated'] && $r1['undo_token'] !== $r2['undo_token'], 'T3 the next call writes the other 5, with an undo point of its own' );
	$twice = 0;
	for ( $i = 1; $i <= 30; $i++ ) {
		$twice += 'Los jetzt' === ab_demo_value( 100 + $i, 'b1', 'text' ) ? 0 : 1;
	}
	ok( 0 === $twice, 'T3 every page once: a replacement that holds its search text is not applied twice' );

	/* =================== T4: a writer that throws stops the scan, says where */

	ab_site_reset();
	for ( $i = 1; $i <= 4; $i++ ) {
		ab_demo_page( 200 + $i );
	}
	ab_post( 299, array( 'post_content' => 'Willkommen', 'post_title' => 'Blog' ) );
	AB_Test_Demo_Writer::$throw_on = 203;
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( array( 'id' => 203, 'error' => 'RuntimeException' ) === $r['failed_at'] && array( 201, 202 ) === $r['applied_so_far'], 'T4 failed_at names the page and the error class (not its message); applied_so_far the pages written before' );
	ok( AB_MCP_Undo::valid_token( (string) $r['undo_token'] ) && false !== strpos( implode( ' ', $r['warnings'] ), $r['undo_token'] ), 'T4 the undo token is in the answer and in the warning' );
	ok( false === strpos( wp_json_encode( $r ), 'secret page detail' ), 'T4 the exception\'s message is not passed on' );
	ok( 203 === $r['next']['builders'] && true === $r['tables']['builders']['truncated'], 'T4 the builders scan stopped there; next continues behind the failed page' );
	ok( 'Hallo' === $GLOBALS['db_posts'][299]['post_content'] && 'Willkommen bei A & B' === ab_demo_value( 204, 'h1', 'title' ), 'T4 the other tables still ran; the page after the failure was not touched' );
	AB_Test_Demo_Writer::$throw_on = 0;
	ab_site_reset();
	ab_demo_page( 210 );
	AB_Test_Demo_Writer::$throw_on = 210;
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( null === $r['undo_token'] && array() === $r['applied_so_far'] && false !== strpos( implode( ' ', $r['warnings'] ), 'wp_undo_list' ), 'T4 a writer that fails on the first page: no token to name, the answer points to wp_undo_list' );
	AB_Test_Demo_Writer::$throw_on = 0;
	ab_site_reset();
	for ( $i = 1; $i <= 4; $i++ ) {
		ab_demo_page( 200 + $i );
	}
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true, 'after' => array( 'builders' => 203 ) ) );
	ok( array( 204 ) === $r['tables']['builders']['applied'] && 'Willkommen bei A & B' === ab_demo_value( 203, 'h1', 'title' ), 'T4 continued with next, the scan goes on behind the failed page' );

	/* ====== T4b: a writer that refuses the page (regex): named, not written */

	ab_site_reset();
	ab_demo_page( 220 );
	ab_post( 221, array( 'post_content' => 'Willkommen', 'post_title' => 'Blog' ) );
	AB_Test_Demo_Writer::$no_regex = true;
	$before_meta = $GLOBALS['db_meta'];
	$before_page = $GLOBALS['db_posts'][220];
	$r = $SR( array( 'search' => 'Willk[o]mmen', 'replace' => 'Hallo', 'regex' => true, 'apply' => true ) );
	ok( is_array( $r ) && 0 === $r['tables']['builders']['changed'] && 1 === $r['tables']['builders']['matched'] && isset( $r['tables']['builders']['samples'][0]['refused'] ) && false !== strpos( $r['tables']['builders']['samples'][0]['refused'], 'scope: raw' ), 'T4b the page whose writer takes no pattern is not written; its sample says why and names the way' );
	ok( false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 220: A regular expression is not applied' ), 'T4b and a note names the post' );
	ok( $before_meta === $GLOBALS['db_meta'] && $before_page === $GLOBALS['db_posts'][220] && array( 220 ) === $r['tables']['posts']['copy_only'], 'T4b its builder data and its post_content copy are left as they are (no engine behind the writer\'s back)' );
	ok( 'Hallo' === $GLOBALS['db_posts'][221]['post_content'], 'T4b an ordinary post is still replaced' );
	AB_Test_Demo_Writer::$no_regex = false;

	/* ======== T5: no writer for a page: the old way, and the answer says so */

	ab_site_reset();
	ab_demo_page( 30 );
	AB_Test_Demo_Writer::$cannot = array( 30 );
	ab_fake_page( 31, ab_fake_elements() );
	$before_meta = $GLOBALS['db_meta'];
	$before_post = $GLOBALS['db_posts'];
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( 0 === $r['tables']['builders']['matched'], 'T5 no page goes through a writer' );
	ok( array( 'id' => 30, 'builder' => 'Demo Builder: Demo is in safe mode on this page.', 'storage' => 'B' ) === $r['builder_pages_without_writer'][0] && 31 === $r['builder_pages_without_writer'][1]['id'], 'T5 a page whose writer cannot write it now, and one whose builder has no writer, are listed with the reason' );
	ok( false !== strpos( $GLOBALS['db_posts'][30]['post_content'], 'Hallo' ) && false !== strpos( ab_first_meta( 30, 'demo_layout' ), '<div>Hallo</div>' ) && 'body{} /* Hallo */' === ab_first_meta( 30, 'demo_custom_css' ), 'T5 and replaced as this tool always did: post_content and the stored data as text, locked parts included (principle 9)' );
	ok( 0 === $r['locked_matches'], 'T5 without a writer there are no locked matches, as before' );
	$c = $claim( $r['undo_token'] );
	ok( 'builder_data' === $c['entry']['kind'] && 'text' === $c['data']['scope'] && array( 30, 31 ) === array_merge( array_column( $c['data']['posts'], 'id' ), array_column( isset( $c['parts'][0]['posts'] ) ? $c['parts'][0]['posts'] : array(), 'id' ) ) && array( 30, 31 ) === $r['undo_pages'], 'T5 both pages get an undo point of scope text (one token, the second page as a part): scope text replaced them as before, now with a way back' );
	ok( false !== strpos( implode( ' ', $r['warnings'] ), 'post 30, demo_custom_css' ), 'T5 the page code the builder locks is not in the undo point, and the answer says so' );
	$back = $undo( $r['undo_token'] );
	$css  = key( ab_meta_rows( 30, 'demo_custom_css' ) );
	$was  = $before_meta;
	$was[ $css ] = $GLOBALS['db_meta'][ $css ];
	ok( is_array( $back ) && array( 30, 31 ) === $back['restored'] && array() === $back['blocked'] && $was === $GLOBALS['db_meta'] && $before_post[30]['post_content'] === $GLOBALS['db_posts'][30]['post_content'] && $before_post[31]['post_content'] === $GLOBALS['db_posts'][31]['post_content'], 'T5 wp_undo without force puts both pages back byte for byte (the locked page code, which it does not hold, stays as written)' );
	ok( array( 30, 31 ) === $r['builder_caches_cleared'] && in_array( '30:_uag_page_assets', $GLOBALS['ab_meta_deleted'], true ), 'T5 what the page builders made from the old data is emptied for both pages, as after a writer\'s save' );

	/* ======================== T6: A2 — both copies through the writer */

	ab_site_reset();
	ab_demo_page( 40 );
	ab_meta_add( 40, 'demo_a2', '1' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo' ) );
	ok( array( 40 ) === $r['tables']['posts']['left_to_writer'] && array() === $r['tables']['posts']['copy_only'], 'T6 storage A2: post_content is one of two copies the builder keeps together and is left to the writer (left_to_writer), not called a copy' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( array( 40 ) === $r['tables']['builders']['applied'] && 'Hallo bei A & B' === ab_demo_value( 40, 'h1', 'title' ) && false !== strpos( $GLOBALS['db_posts'][40]['post_content'], 'Hallo bei A & B' ) && 0 === $r['tables']['posts']['matched'], 'T6 applied, the writer changes both copies; the posts scan finds nothing left to do' );
	ok( false === strpos( implode( ' ', $r['warnings'] ), 'undo_token takes back' ) && array() === $r['tables']['builders']['as_text'], 'T6 a call that changed only what the token holds carries no such warning; nothing was replaced as text' );

	/* =============== T7: an ordinary page: the forms, and attributes as JSON */

	ab_site_reset();
	ab_post( 50, array( 'post_content' => '<!-- wp:heading {"metadata":{"name":"A \\u0026 B"}} --><h2 class="wp-block-heading">A &amp; B</h2><!-- /wp:heading -->' ) );
	$r = $SR( array( 'search' => 'A & B', 'replace' => 'C & "D"', 'apply' => true ) );
	$attrs = ab_block_attrs( $GLOBALS['db_posts'][50]['post_content'] );
	ok( 1 === $r['tables']['posts']['matched'] && 2 === $r['tables']['posts']['occurrences'] && 'C & "D"' === $attrs[0][1]['metadata']['name'] && false !== strpos( $GLOBALS['db_posts'][50]['post_content'], '<h2 class="wp-block-heading">C &amp; "D"</h2>' ), 'T7 "A & B" is found in the attribute and in the HTML, and replaced in each form; the attribute stays valid JSON' );
	$page = '<!-- wp:heading {"metadata":{"name":"Mueller"}} --><h2>Mueller</h2><!-- /wp:heading -->';
	ab_post( 51, array( 'post_content' => $page ) );
	$r = $SR( array( 'search' => 'Mueller', 'replace' => 'Müller "Söhne"', 'apply' => true ) );
	ok( 'Müller "Söhne"' === ab_block_attrs( $GLOBALS['db_posts'][51]['post_content'] )[0][1]['metadata']['name'], 'T7 scope text: a quote in the replacement of a plain word goes into the block attribute as JSON' );
	ab_post( 51, array( 'post_content' => $page ) );
	$r = $SR( array( 'search' => 'Mueller', 'replace' => 'Müller "Söhne"', 'apply' => true, 'scope' => 'raw' ) );
	ok( null === ab_block_attrs( $GLOBALS['db_posts'][51]['post_content'] )[0][1], 'T7 scope raw writes it as typed, as before (the attribute JSON breaks): raw is the old way' );

	/* ============== T7a: a replacement that changes no byte is not written */

	ab_site_reset();
	$gb = '<!-- wp:heading {"metadata":{"name":"A \\u0026 B"}} --><h2 class="wp-block-heading">A &amp; B …</h2><!-- /wp:heading -->';
	ab_post( 52, array( 'post_content' => $gb ) );
	// Measured on a real site (sz same.mjs, post 423): changed 1, post_modified stamped, a revision.
	$r = $SR( array( 'search' => 'A & B', 'replace' => 'A & B', 'type' => 'post', 'tables' => array( 'posts' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['matched'] && 2 === $r['tables']['posts']['occurrences'] && 0 === $r['tables']['posts']['changed'] && 0 === $r['totals']['changed'] && array() === $GLOBALS['ab_post_writes'] && $gb === $GLOBALS['db_posts'][52]['post_content'] && 'unchanged' === $r['tables']['posts']['samples'][0]['skipped'], 'T7a search equal to replace with apply=true: the hits are shown, nothing is written or counted as changed (as the terms scan does): ' . json_encode( is_array( $r ) ? $r['tables']['posts'] : $r ) );
	$r = $SR( array( 'search' => '(A) &amp; (B)', 'replace' => '$1 &amp; $2', 'regex' => true, 'tables' => array( 'posts' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['matched'] && 0 === $r['tables']['posts']['changed'] && array() === $GLOBALS['ab_post_writes'] && 'unchanged' === $r['tables']['posts']['samples'][0]['skipped'], 'T7a a pattern that puts back what it found: matched, not written, not counted as changed: ' . json_encode( is_array( $r ) ? $r['tables']['posts'] : $r ) );
	$r = $SR( array( 'search' => 'a & b', 'replace' => 'a & b', 'case_insensitive' => true, 'tables' => array( 'posts' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['changed'] && false !== strpos( $GLOBALS['db_posts'][52]['post_content'], 'a &amp; b …' ), 'T7a case-insensitive, the same text still rewrites what it finds (A → a): written' );

	/* ======================================== T8: the kses guard */

	ab_site_reset();
	ab_post( 60, array( 'post_content' => '<!-- wp:x/btn --><a href="/k">Mehr<svg viewBox="0 0 1 1"><path d="M0"/></svg></a><!-- /wp:x/btn -->' ) );
	ab_post( 61, array( 'post_content' => '<!-- wp:image --><figure><img src="/a.jpg" alt=""/></figure><p>Mehr</p><!-- /wp:image -->' ) );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	$r = $SR( array( 'search' => 'Mehr', 'replace' => 'Weiter', 'apply' => true ) );
	ok( 1 === $r['tables']['posts']['kses_skipped'] && 2 === $r['tables']['posts']['matched'] && 1 === $r['tables']['posts']['changed'], 'T8 without unfiltered_html: the post kses would store otherwise is skipped, the other written' );
	ok( false !== strpos( $GLOBALS['db_posts'][60]['post_content'], 'Mehr<svg' ) && false !== strpos( $GLOBALS['db_posts'][61]['post_content'], 'Weiter' ), 'T8 the SVG page is untouched; the page where kses only writes " />" is written' );
	ok( false !== strpos( implode( ' ', $r['tables']['posts']['notes'] ), 'Post 60: not written' ) && false !== strpos( implode( ' ', $r['tables']['posts']['notes'] ), 'a svg tag' ) && 'kses' === $r['tables']['posts']['samples'][0]['skipped'], 'T8 the note names the post and what kses would change; the sample is marked' );
	ab_site_reset();
	ab_post( 60, array( 'post_content' => '<a>Mehr<svg><path d="M0"/></svg></a>' ) );
	$r = $SR( array( 'search' => 'Mehr', 'replace' => 'Weiter', 'apply' => true ) );
	ok( 0 === $GLOBALS['ab_kses_calls'] && 1 === $r['tables']['posts']['changed'], 'T8 with unfiltered_html kses does not run on post fields, nor does the guard' );
	ab_site_reset();
	ab_post( 60, array( 'post_content' => '<a>Mehr<svg><path d="M0"/></svg></a>' ) );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	$r = $SR( array( 'search' => 'Mehr', 'replace' => 'Weiter', 'apply' => true, 'scope' => 'raw' ) );
	ok( 0 === $r['tables']['posts']['changed'] && 1 === $r['tables']['posts']['kses_skipped'] && false !== strpos( $GLOBALS['db_posts'][60]['post_content'], 'Mehr<svg' ) && false !== strpos( implode( ' ', $r['tables']['posts']['notes'] ), 'Post 60: not written' ), 'T8 scope raw too: the post kses would strip is left as it is and named, not stripped and counted as changed (issue #14, as the readme says: either way)' );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;
	ab_post( 60, array( 'post_content' => '<a>Mehr<svg><path d="M0"/></svg></a>' ) );
	$r = $SR( array( 'search' => 'Mehr', 'replace' => 'Weiter', 'apply' => true, 'scope' => 'raw' ) );
	ok( 1 === $r['tables']['posts']['changed'] && 0 === $r['tables']['posts']['kses_skipped'] && false !== strpos( $GLOBALS['db_posts'][60]['post_content'], 'Weiter<svg' ), 'T8 counter-check: with unfiltered_html scope raw writes it' );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;

	/* ======================== T9: scope raw — the old way, with an undo point */

	ab_site_reset();
	ab_demo_page( 70 );
	ab_post( 71, array( 'post_content' => 'Willkommen', 'post_title' => 'Blog' ) );
	$before_meta = $GLOBALS['db_meta'];
	$before_post = $GLOBALS['db_posts'];
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true, 'scope' => 'raw' ) );
	ok( 'raw' === $r['scope'] && ! isset( $r['tables']['builders'] ) && 0 === $r['locked_matches'], 'T9 scope raw: no writer, no builders scan, nothing locked' );
	ok( false !== strpos( ab_first_meta( 70, 'demo_layout' ), '<div>Hallo</div>' ) && 'body{} /* Hallo */' === ab_first_meta( 70, 'demo_custom_css' ) && false !== strpos( $GLOBALS['db_posts'][70]['post_content'], 'Hallo' ), 'T9 the stored data is replaced as text everywhere, locked parts and page code included' );
	ok( false !== strpos( $r['warnings'][0], 'scope raw' ), 'T9 with a warning' );
	ok( array( 70 ) === $r['builder_caches_cleared'] && in_array( '70:_uag_page_assets', $GLOBALS['ab_meta_deleted'], true ), 'T9 the builder caches of the page are emptied (not those of the ordinary post)' );
	$c = $claim( $r['undo_token'] );
	ok( 'builder_data' === $c['entry']['kind'] && 'raw' === $c['data']['scope'] && array( 70 ) === array_column( array_merge( $c['data']['posts'] ), 'id' ) && 0 === count( $c['parts'] ), 'T9 one undo point (builder_data, scope raw) for the page built with a page builder, not for the ordinary post' );
	ok( array( 'meta:demo_layout', 'post_content' ) === array_keys( $c['data']['posts'][0]['locs'] ), 'T9 it holds post_content and the builder data the call rewrote' );
	ok( false !== strpos( implode( ' ', $r['warnings'] ), 'post 70, demo_custom_css' ), 'T9 the page code the builder locks has no undo point, and the answer says so' );
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	$denied = $undo( $r['undo_token'] );
	ok( is_array( $denied ) && 0 === $denied['undone'] && 'forbidden: unfiltered_html' === $denied['blocked'][0]['reason'] && false !== strpos( ab_first_meta( 70, 'demo_layout' ), 'Hallo' ), 'T9 undoing a raw point takes unfiltered_html: refused, nothing restored' );
	$GLOBALS['ab_caps']['unfiltered_html'] = true;
	$back = $undo( $r['undo_token'] );
	ok( $before_meta[ key( ab_meta_rows( 70, 'demo_layout' ) ) ][2] === ab_first_meta( 70, 'demo_layout' ) && $before_post[70]['post_content'] === $GLOBALS['db_posts'][70]['post_content'], 'T9 wp_undo puts the builder data and post_content back byte for byte' );

	ab_site_reset();
	ab_demo_page( 72 );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Willkommen', 'tables' => array( 'posts' ), 'apply' => true, 'scope' => 'raw' ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['matched'] && 0 === $r['tables']['posts']['changed'] && null === $r['undo_token'] && array() === $GLOBALS['ab_post_writes'] && array() === get_option( AB_MCP_Undo::OPTION, array() ), 'T9a scope raw, a replacement that leaves the page byte for byte: no write, no undo point for nothing' );

	/* =========================== T10: scope raw, at most 25 builder pages */

	ab_site_reset();
	for ( $i = 1; $i <= 27; $i++ ) {
		ab_demo_page( 300 + $i );
	}
	ab_post( 400, array( 'post_content' => 'Willkommen' ) );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw' ) );
	ok( 25 === $r['tables']['posts']['matched'] && 325 === $r['next']['posts'] && true === $r['tables']['posts']['truncated'], 'T10 the preview stops before the 26th builder page, like the write will' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw', 'apply' => true ) );
	$c = $claim( $r['undo_token'] );
	ok( 25 === $r['tables']['posts']['changed'] && 325 === $r['next']['posts'] && 24 === count( $c['parts'] ), 'T10 applied: 25 pages, each with its part of the undo point; next.posts names the 25th' );
	ok( 'Willkommen bei A & B' === json_decode( ab_first_meta( 326, 'demo_layout' ), true )[0]['fields']['title']['value'], 'T10 the 26th page is left for the next call, in the postmeta scan too' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw', 'apply' => true, 'after' => $r['next'] ) );
	ok( 3 === $r['tables']['posts']['changed'] && 'Hallo' === $GLOBALS['db_posts'][400]['post_content'] && false !== strpos( ab_first_meta( 327, 'demo_layout' ), '<div>Hallo</div>' ), 'T10 the next call writes the rest: the two builder pages and the ordinary post behind them' );

	/* ============== T11: scope raw when undo is off, or the point too large */

	ab_site_reset();
	ab_demo_page( 80 );
	$GLOBALS['ab_toolstate']['wp_undo'] = false;
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw', 'apply' => true ) );
	ok( null === $r['undo_token'] && false !== strpos( implode( ' ', $r['warnings'] ), 'Undo is switched off' ) && false !== strpos( ab_first_meta( 80, 'demo_layout' ), 'Hallo' ), 'T11 undo off: written, with the warning that there is no way back' );
	ab_site_reset();
	ab_demo_page( 81 );
	add_filter( 'ab_mcp_undo_snapshot_max', static function () { return 10; } );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw', 'apply' => true ) );
	ok( 0 === $r['tables']['posts']['changed'] && 0 === $r['tables']['postmeta']['changed'] && false !== strpos( implode( ' ', $r['tables']['posts']['notes'] ), 'allow_without_undo=true' ) && false === strpos( ab_first_meta( 81, 'demo_layout' ), 'Hallo' ), 'T11 an undo point over the limit: the page is not written, the note names allow_without_undo' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'scope' => 'raw', 'apply' => true, 'allow_without_undo' => true ) );
	ok( 1 === $r['tables']['posts']['changed'] && false !== strpos( implode( ' ', $r['warnings'] ), 'without an undo point, as allow_without_undo asked' ), 'T11 with allow_without_undo it is written, and the answer says it has no way back' );
	remove_all_filters( 'ab_mcp_undo_snapshot_max' );

	/* ===== T13a: storage A keeps the old way even with a writer; revisions too */

	ab_site_reset();
	ab_demo_page( 90 );
	ab_meta_add( 90, 'demo_a', '1' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( 0 === $r['tables']['builders']['matched'] && array() === AB_Test_Demo_Writer::$calls && false !== strpos( $GLOBALS['db_posts'][90]['post_content'], 'Hallo' ) && ! isset( $r['builder_pages_without_writer'] ), 'T13a a page that shows post_content (storage A) is replaced in post_content as before, also where its builder has a writer (plan §5.2 Art A)' );
	ab_site_reset();
	ab_demo_page( 91 );
	ab_post( 92, array( 'post_type' => 'revision', 'post_parent' => 91, 'post_status' => 'inherit', 'post_content' => 'Willkommen' ) );
	ab_meta_add( 92, 'demo_layout', ab_first_meta( 91, 'demo_layout' ) );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true, 'scope' => 'raw', 'exclude_revisions' => false ) );
	$c = $claim( $r['undo_token'] );
	ok( 'Hallo' === $GLOBALS['db_posts'][92]['post_content'] && false !== strpos( ab_first_meta( 92, 'demo_layout' ), 'Hallo' ) && array( 91 ) === array_values( array_unique( array_column( $c['data']['posts'], 'id' ) ) ) && 0 === count( $c['parts'] ), 'T13a a revision (exclude_revisions=false) is replaced as before, without an undo point of its own (edit_post is never granted on one)' );

	/* ====== T14: the kses guard judges every field kses filters on save */

	// wp_update_post() merges the stored post and saves every field again
	// (WordPress 7.0.2 wp-includes/post.php wp_update_post(), sanitize_post(
	// …, 'db' ); kses.php kses_init_filters()): a title change would strip
	// the SVG of an unchanged content, or of post_content_filtered.
	ab_site_reset();
	$GLOBALS['ab_caps']['unfiltered_html'] = false;
	ab_post( 120, array( 'post_title' => 'Willkommen', 'post_content' => '<!-- wp:html --><svg viewBox="0 0 1 1"><path d="M0"/></svg><!-- /wp:html -->' ) );
	ab_post( 121, array( 'post_title' => 'Willkommen auch', 'post_content' => '<p>Text</p>', 'post_content_filtered' => '<svg><path d="M0"/></svg>' ) );
	ab_post( 122, array( 'post_title' => 'Willkommen hier', 'post_content' => '<p>Text</p>' ) );
	$r     = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true, 'tables' => array( 'posts' ) ) );
	$notes = implode( ' ', $r['tables']['posts']['notes'] );
	ok( 2 === $r['tables']['posts']['kses_skipped'] && 1 === $r['tables']['posts']['changed'] && 'Willkommen' === $GLOBALS['db_posts'][120]['post_title'] && 'Willkommen auch' === $GLOBALS['db_posts'][121]['post_title'] && 'Hallo hier' === $GLOBALS['db_posts'][122]['post_title'], 'T14 only the titles match, but kses would strip the SVG of the unchanged content (120) or of post_content_filtered (121): those two are not written, the third is' );
	ok( false !== strpos( $notes, 'Post 120: not written' ) && false !== strpos( $notes, 'a svg tag in post_content' ) && false !== strpos( $notes, 'Post 121: not written' ) && false !== strpos( $notes, 'in post_content_filtered' ), 'T14 the notes name the post, what kses would change and in which field' );
	ok( array( 122 ) === array_map( 'intval', array_column( $GLOBALS['ab_post_writes'], 'ID' ) ), 'T14 wp_update_post() ran for the third post only' );

	/* ====== T15: A2, a text the writer finds in none of its fields */

	ab_site_reset();
	ab_demo_page( 130 );
	ab_meta_add( 130, 'demo_a2', '1' );
	$GLOBALS['db_posts'][130]['post_content'] .= "\n<p>Sonderangebot nur heute</p>";
	$before_page = $GLOBALS['db_posts'][130];
	$before_meta = $GLOBALS['db_meta'];
	$r = $SR( array( 'search' => 'Sonderangebot', 'replace' => 'Aktion' ) );
	ok( array( 130 ) === $r['tables']['builders']['as_text'] && 1 === $r['tables']['builders']['matched'] && 1 === $r['tables']['builders']['occurrences'] && array() === $r['tables']['posts']['left_to_writer'] && 0 === $r['tables']['posts']['matched'], 'T15 the site shows post_content and the writer finds the text in no field: the page is replaced in post_content as text (as_text), not silently left to the writer' );
	ok( isset( $r['builder_pages_without_writer'][0] ) && 130 === $r['builder_pages_without_writer'][0]['id'] && false !== strpos( $r['builder_pages_without_writer'][0]['builder'], 'none of the fields' ) && 'A2' === $r['builder_pages_without_writer'][0]['storage'], 'T15 and named with the reason among the pages replaced without a writer' );
	ok( $before_page === $GLOBALS['db_posts'][130] && $before_meta === $GLOBALS['db_meta'], 'T15 the dry run writes nothing' );
	$r = $SR( array( 'search' => 'Sonderangebot', 'replace' => 'Aktion', 'apply' => true ) );
	ok( false !== strpos( $GLOBALS['db_posts'][130]['post_content'], '<p>Aktion nur heute</p>' ) && $before_meta === $GLOBALS['db_meta'] && 1 === $r['tables']['builders']['changed'] && array() === $r['tables']['builders']['applied'], 'T15 applied: post_content is replaced, as earlier versions did; the builder\'s own data is untouched' );
	ok( in_array( 130, $r['builder_caches_cleared'], true ) && false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'as_text:' ), 'T15 the builder caches are emptied, and a note explains as_text' );
	$back = $undo( $r['undo_token'] );
	ok( array( 130 ) === $r['undo_pages'] && is_array( $back ) && array( 130 ) === $back['restored'] && $before_page['post_content'] === $GLOBALS['db_posts'][130]['post_content'] && $before_meta === $GLOBALS['db_meta'], 'T15 the page replaced as text has an undo point: wp_undo without force puts post_content back byte for byte' );

	/* ====== T16: a field the writer leaves for a reason is named and counted */

	ab_site_reset();
	ab_demo_page( 140 );
	AB_Test_Demo_Writer::$leave_kind = 'url';
	$r = $SR( array( 'search' => 'https://alt.example', 'replace' => 'https://neu.example', 'apply' => true ) );
	ok( 1 === $r['tables']['builders']['matched'] && 0 === $r['tables']['builders']['changed'] && 2 === $r['tables']['builders']['locked_matches'] && isset( $r['locked_note'] ), 'T16 every match left (the link for a reason, the HTML widget locked): the page is counted, both matches in locked_matches, with the way' );
	ok( false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 140: b1:link left as it is' ) && array( 'b1:link left as it is: the new value breaks the rules of its kind.' ) === $r['tables']['builders']['samples'][0]['left'], 'T16 the writer\'s reason reaches the answer (note and sample), it is not dropped as «nothing found»' );
	AB_Test_Demo_Writer::$leave_kind = 'html';
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	ok( array( 140 ) === $r['tables']['builders']['applied'] && 'Hallo bei A & B' === ab_demo_value( 140, 'h1', 'title' ) && false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 140: t1:editor left as it is' ), 'T16 a page written with one field left: the reason for the left field is named too' );
	AB_Test_Demo_Writer::$leave_kind = '';

	/* ====== T17: a page the writer refuses as a whole: its matches counted */

	ab_site_reset();
	ab_demo_page( 145 );
	AB_Test_Demo_Writer::$refuse_on = 145;
	$before_meta = $GLOBALS['db_meta'];
	foreach ( array( false, true ) as $apply ) {
		$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => $apply ) );
		ok( 3 === $r['tables']['builders']['locked_matches'] && 4 === $r['locked_matches'] && isset( $r['locked_note'] ) && false !== strpos( $r['locked_note'], 'scope: raw' ), 'T17 ' . ( $apply ? 'applied' : 'preview' ) . ': refused before writing (unregistered elements), the three matches in its builder data are counted (plus the page code), and the way named' );
		ok( false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 145: Demo would drop the elements' ) && $before_meta === $GLOBALS['db_meta'] && array( 145 ) === $r['tables']['posts']['copy_only'], 'T17 ' . ( $apply ? 'applied' : 'preview' ) . ': with the writer\'s reason; nothing is written behind the writer\'s back' );
	}

	// A refusal of the moment (another user has the page open) names its own
	// way, try again later; its matches are not counted for scope raw.
	ab_site_reset();
	ab_demo_page( 146 );
	ab_meta_add( 146, '_edit_lock', ( time() - 10 ) . ':99' );
	$before_meta = $GLOBALS['db_meta'];
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true, 'tables' => array( 'posts' ) ) );
	ok( 0 === $r['tables']['builders']['locked_matches'] && false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 146: User #99 has this page open' ) && $before_meta === $GLOBALS['db_meta'], 'T17 an open editor of another user: refused with that reason and its way; its matches are not handed to scope raw' );

	/* ====== T18: what a writer's save touches besides, replaced as before */

	ab_site_reset();
	ab_demo_page( 150 );
	ab_meta_add( 150, 'demo_page_settings', '{"background_image":{"url":"https://alt.example/bg.jpg","id":5}}' );
	AB_Test_Demo_Writer::$touched = array( 'meta:demo_page_settings' );
	$before_meta = $GLOBALS['db_meta'];
	$r = $SR( array( 'search' => 'https://alt.example', 'replace' => 'https://neu.example', 'apply' => true ) );
	ok( array( 150 ) === $r['tables']['builders']['applied'] && 1 === $r['tables']['postmeta']['changed'] && false !== strpos( ab_first_meta( 150, 'demo_page_settings' ), 'neu.example' ) && false === strpos( ab_first_meta( 150, 'demo_page_settings' ), 'alt.example' ), 'T18 the page settings a writer\'s save touches but does not search are replaced by the postmeta scan, as before (not left out unseen)' );
	ok( 1 === $r['tables']['builders']['locked_matches'] && 'https://neu.example/kontakt' === ab_demo_value( 150, 'b1', 'link' ), 'T18 the link went through the writer, the HTML widget is counted as locked' );
	$back = $undo( $r['undo_token'] );
	ok( is_array( $back ) && array( 150 ) === $back['restored'] && $before_meta === $GLOBALS['db_meta'], 'T18 wp_undo without force takes the page and its settings back byte for byte: the point learned the state the postmeta scan left' );

	/* ====== T20: scope raw, a match only in post_content, undo without force */

	ab_site_reset();
	ab_demo_page( 160 );
	$GLOBALS['db_posts'][160]['post_content'] .= "\nNurImInhalt";
	$before_post = $GLOBALS['db_posts'][160]['post_content'];
	$r = $SR( array( 'search' => 'NurImInhalt', 'replace' => 'Ersetzt', 'scope' => 'raw', 'apply' => true ) );
	ok( 1 === $r['tables']['posts']['changed'] && 0 === $r['tables']['postmeta']['changed'] && false !== strpos( $GLOBALS['db_posts'][160]['post_content'], 'Ersetzt' ), 'T20 (scope raw, the match only in post_content: only the posts scan writes)' );
	$back = $undo( $r['undo_token'] );
	ok( is_array( $back ) && array( 160 ) === $back['restored'] && array() === $back['blocked'] && $before_post === $GLOBALS['db_posts'][160]['post_content'], 'T20 wp_undo without force puts it back: the posts scan kept the state it left with the point' );

	/* ====== T21: a failure after the write keeps its point; the next pages join it */

	ab_site_reset();
	ab_demo_page( 170 );
	ab_demo_page( 171 );
	AB_Test_Demo_Writer::$fail_readback_on = 170;
	$r   = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'apply' => true ) );
	$c   = $claim( $r['undo_token'] );
	$ids = array_column( $c['data']['posts'], 'id' );
	foreach ( $c['parts'] as $part ) {
		$ids = array_merge( $ids, array_column( $part['posts'], 'id' ) );
	}
	ok( array( 171 ) === $r['tables']['builders']['applied'] && false !== strpos( implode( ' ', $r['tables']['builders']['notes'] ), 'Post 170:' ), 'T21 (post 170 fails its read-back after someone else saved it; post 171 is written)' );
	ok( array( 170, 171 ) === array_map( 'intval', $ids ), 'T21 one token for the call: the point the failed page kept, and post 171 as a part of it' );
	ok( array( 170, 171 ) === $r['undo_pages'], 'T21 undo_pages names both pages the token holds, the failed one included' );

	/* == T23: storage A (block libraries) in scope text: an undo point, as in raw == */

	// The measured case (2 October 2026, real installations): scope text
	// replaced these pages without an undo point, scope raw kept one. The
	// pages are the measured blocks of each library (test/fixtures/blocks).
	ab_site_reset();
	$fx  = dirname( __DIR__ ) . '/standalone-lib/fixtures/blocks/';
	$lib = array(
		201 => 'generateblocks/gb2-sonderzeichen.json',
		202 => 'generateblocks/gb1-sonderzeichen.json',
		203 => 'kadence/singlebtn-sonderzeichen.json',
		204 => 'spectra/buttons-child-sonderzeichen.json',
		205 => 'stackable/heading-sonderzeichen.json',
		206 => 'core/sonderzeichen.json',
	);
	foreach ( $lib as $id => $file ) {
		ab_post( $id, array( 'post_content' => json_decode( (string) file_get_contents( $fx . $file ), true )['content'] ) );
	}
	$want = array( 201 => 'generateblocks', 202 => 'generateblocks', 203 => 'kadence', 204 => 'spectra', 205 => 'stackable' );
	$seen = array();
	foreach ( $lib as $id => $file ) {
		$p           = AB_MCP_Builders::primary( get_post( $id ) );
		$seen[ $id ] = null === $p ? null : $p['id'] . '/' . AB_MCP_Builders::effective_storage( get_post( $id ) );
	}
	ok( array( 201 => 'generateblocks/A', 202 => 'generateblocks/A', 203 => 'kadence/A', 204 => 'spectra/A', 205 => 'stackable/A', 206 => null ) === $seen, 'T23 (setup: five measured block-library pages of storage A, and one of core blocks only): ' . json_encode( $seen ) );
	$before_post = $GLOBALS['db_posts'];
	$before_meta = $GLOBALS['db_meta'];
	$r = $SR( array( 'search' => 'A & B', 'replace' => 'C & D', 'type' => 'post' ) );
	ok( is_array( $r ) && null === $r['undo_token'] && array() === get_option( AB_MCP_Undo::OPTION, array() ) && $before_post === $GLOBALS['db_posts'] && 6 === $r['tables']['posts']['matched'], 'T23 the dry run finds all six pages and records nothing' );
	$r = $SR( array( 'search' => 'A & B', 'replace' => 'C & D', 'type' => 'post', 'apply' => true ) );
	ok( is_array( $r ) && 6 === $r['tables']['posts']['changed'] && 0 === $r['locked_matches'], 'T23 applied: the six pages are replaced as before (the reach of scope text is unchanged)' );
	$c   = $claim( $r['undo_token'] );
	$ids = array_column( $c['data']['posts'], 'id' );
	foreach ( $c['parts'] as $part ) {
		$ids = array_merge( $ids, array_column( $part['posts'], 'id' ) );
	}
	ok( 'builder_data' === $c['entry']['kind'] && 'text' === $c['data']['scope'] && array_keys( $want ) === array_map( 'intval', $ids ) && array_keys( $want ) === $r['undo_pages'], 'T23 the five block-library pages have an undo point of scope text, one token for the call; the page of core blocks only gets none, as in scope raw' );
	ok( false !== strpos( implode( ' ', $r['warnings'] ), 'undo_token takes back the page-builder pages in undo_pages' ), 'T23 and the answer says the post of core blocks is not in it' );
	$back = $undo( $r['undo_token'] );
	$same = true;
	foreach ( array_keys( $want ) as $id ) {
		$same = $same && $before_post[ $id ]['post_content'] === $GLOBALS['db_posts'][ $id ]['post_content'];
	}
	ok( is_array( $back ) && array_keys( $want ) === $back['restored'] && array() === $back['blocked'] && $same && $before_meta === $GLOBALS['db_meta'], 'T23 wp_undo without force puts the five pages back byte for byte' );
	ok( false === strpos( $GLOBALS['db_posts'][206]['post_content'], 'A &amp; B' ), 'T23 the post of core blocks keeps the replacement (no undo point of this tool holds it)' );
	// Gegenprobe: the same pages in scope raw get the same undo point.
	ab_site_reset();
	foreach ( $lib as $id => $file ) {
		ab_post( $id, array( 'post_content' => json_decode( (string) file_get_contents( $fx . $file ), true )['content'] ) );
	}
	$r = $SR( array( 'search' => 'A & B', 'replace' => 'C & D', 'type' => 'post', 'apply' => true, 'scope' => 'raw' ) );
	ok( is_array( $r ) && array_keys( $want ) === $r['undo_pages'], 'T23 scope raw holds the same pages' );

	// Pagelayer (measured page, its own copy in pagelayer-data where it has one).
	ab_site_reset();
	ab_post( 207, array( 'post_content' => json_decode( (string) file_get_contents( $fx . 'pagelayer/ability-page.json' ), true )['content'] ) );
	$p = AB_MCP_Builders::primary( get_post( 207 ) );
	$before_post = $GLOBALS['db_posts'][207]['post_content'];
	$r = $SR( array( 'search' => 'Mo–Fr', 'replace' => 'Mo–Sa', 'type' => 'post', 'apply' => true ) );
	$back = $undo( $r['undo_token'] );
	ok( null !== $p && 'pagelayer' === $p['id'] && is_array( $r ) && 1 === $r['tables']['posts']['changed'] && array( 207 ) === $r['undo_pages'] && is_array( $back ) && array( 207 ) === $back['restored'] && $before_post === $GLOBALS['db_posts'][207]['post_content'], 'T23 a Pagelayer page: replaced, with an undo point, and put back byte for byte' );

	/* ===== T24: scope text, undo points for 25 such pages per call; the reach stays ===== */

	// Undo parts for at most 25 such pages per call, counted alike in the
	// preview; the pages beyond them are written as scope text always wrote
	// them (the reach does not sink without an explicit switch), and named.
	$t24 = static function () {
		ab_site_reset();
		for ( $i = 1; $i <= 27; $i++ ) {
			ab_demo_page( 500 + $i );
			ab_meta_add( 500 + $i, 'demo_a', '1' );
		}
		ab_post( 600, array( 'post_content' => 'Willkommen' ) );
	};
	$t24();
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'limit' => 2000 ) );
	$w = implode( ' ', $r['warnings'] );
	ok( 28 === $r['tables']['posts']['matched'] && false === $r['tables']['posts']['truncated'] && false !== strpos( $w, '2 more would be written without one' ) && false !== strpos( $w, 'posts 526, 527' ) && false !== strpos( $w, 'stop_at_undo_cap=true' ), 'T24 the preview takes all 27 pages of storage A and the ordinary post, and names the two beyond the 25 with an undo point, with the way' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'limit' => 2000, 'apply' => true ) );
	$c = $claim( $r['undo_token'] );
	$w = implode( ' ', $r['warnings'] );
	ok( 28 === $r['tables']['posts']['changed'] && false === $r['tables']['posts']['truncated'] && 'Hallo' === $GLOBALS['db_posts'][600]['post_content'] && false !== strpos( $GLOBALS['db_posts'][527]['post_content'], 'Hallo' ), 'T24 applied: every page and the ordinary post behind them are written, as before (the reach of scope text stays)' );
	ok( 24 === count( $c['parts'] ) && range( 501, 525 ) === $r['undo_pages'] && false !== strpos( $w, '2 more were written without one' ) && false !== strpos( $w, 'posts 526, 527' ), 'T24 the first 25 pages have their part of the undo point; the two beyond are named as written without one' );
	// The postmeta scan treats them alike (a match only in the builder data).
	$t24();
	$r = $SR( array( 'search' => 'https://alt.example', 'replace' => 'https://neu.example', 'tables' => array( 'postmeta' ), 'apply' => true ) );
	ok( 27 === $r['tables']['postmeta']['changed'] && false === $r['tables']['postmeta']['truncated'] && isset( $r['undo_pages'] ) && 25 === count( $r['undo_pages'] ) && false !== strpos( implode( ' ', $r['warnings'] ), 'posts 526, 527' ), 'T24 postmeta: all 27 pages written, 25 with an undo part, the two beyond named' );
	// stop_at_undo_cap: stop before the 26th page instead, in the preview as in the write.
	$t24();
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'stop_at_undo_cap' => true ) );
	ok( 25 === $r['tables']['posts']['matched'] && 525 === $r['next']['posts'] && true === $r['tables']['posts']['truncated'] && false !== strpos( implode( ' ', $r['tables']['posts']['notes'] ), 'Pass next back as after to continue' ), 'T24 stop_at_undo_cap: the preview stops before the 26th page of storage A and names the way on' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'stop_at_undo_cap' => true, 'apply' => true ) );
	$c = $claim( $r['undo_token'] );
	ok( 25 === $r['tables']['posts']['changed'] && 525 === $r['next']['posts'] && 24 === count( $c['parts'] ) && 25 === count( $r['undo_pages'] ) && false === strpos( implode( ' ', $r['warnings'] ), 'without one' ), 'T24 stop_at_undo_cap applied: 25 pages, each with its part of the undo point, none without' );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'stop_at_undo_cap' => true, 'apply' => true, 'after' => $r['next'] ) );
	ok( 3 === $r['tables']['posts']['changed'] && 'Hallo' === $GLOBALS['db_posts'][600]['post_content'] && array( 526, 527 ) === $r['undo_pages'], 'T24 stop_at_undo_cap: the next call writes the rest, the ordinary post behind them too' );
	$t24();
	$r = $SR( array( 'search' => 'https://alt.example', 'replace' => 'https://neu.example', 'tables' => array( 'postmeta' ), 'stop_at_undo_cap' => true, 'apply' => true ) );
	ok( 25 === $r['tables']['postmeta']['changed'] && true === $r['tables']['postmeta']['truncated'] && isset( $r['undo_pages'] ) && 25 === count( $r['undo_pages'] ), 'T24 stop_at_undo_cap, postmeta: stops before the 26th page' );

	/* ===== T23b: scope text, a match only in the post meta of a page without a writer ===== */

	// The undo part then hangs on the postmeta scan alone.
	ab_site_reset();
	ab_demo_page( 700 );
	AB_Test_Demo_Writer::$cannot = array( 700 );
	$before_meta = $GLOBALS['db_meta'];
	$before_post = $GLOBALS['db_posts'];
	$r = $SR( array( 'search' => 'https://alt.example', 'replace' => 'https://neu.example', 'tables' => array( 'postmeta' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['postmeta']['changed'] && false !== strpos( ab_first_meta( 700, 'demo_layout' ), 'https://neu.example' ) && $before_post === $GLOBALS['db_posts'], 'T23b (only the builder data in post meta holds the match; the postmeta scan writes it)' );
	ok( null !== $r['undo_token'] && isset( $r['undo_pages'] ) && array( 700 ) === $r['undo_pages'] && false === strpos( implode( ' ', $r['warnings'] ), 'without an undo point' ), 'T23b the page has its undo point of scope text, taken by the postmeta scan' );
	$back = $undo( $r['undo_token'] );
	ok( is_array( $back ) && array( 700 ) === $back['restored'] && array() === $back['blocked'] && $before_meta === $GLOBALS['db_meta'], 'T23b wp_undo without force puts the post meta back byte for byte' );

	/* ===== T25: scope text writes such a page also where no undo point can be kept ===== */

	ab_site_reset();
	ab_demo_page( 610 );
	ab_meta_add( 610, 'demo_a', '1' );
	add_filter( 'ab_mcp_undo_snapshot_max', static function () { return 10; } );
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['changed'] && null === $r['undo_token'] && false !== strpos( $GLOBALS['db_posts'][610]['post_content'], 'Hallo' ), 'T25 an undo point over the limit does not hold the page back in scope text: written, as before' );
	ok( false !== strpos( implode( ' ', $r['warnings'] ), 'Post 610 was written without an undo point, because none could be recorded' ) && 1 === preg_match( '/raise the limit with the ab_mcp_undo_\w+ filter/', implode( ' ', $r['warnings'] ) ), 'T25 and the answer names the page, the reason and the way (the limit\'s filter)' );
	remove_all_filters( 'ab_mcp_undo_snapshot_max' );
	ab_site_reset();
	ab_demo_page( 611 );
	ab_meta_add( 611, 'demo_a', '1' );
	$GLOBALS['ab_toolstate']['wp_undo'] = false;
	$r = $SR( array( 'search' => 'Willkommen', 'replace' => 'Hallo', 'tables' => array( 'posts' ), 'apply' => true ) );
	ok( is_array( $r ) && 1 === $r['tables']['posts']['changed'] && null === $r['undo_token'] && false !== strpos( implode( ' ', $r['warnings'] ), 'Undo is switched off' ), 'T25 undo switched off: written, with the warning and the way to switch it on' );

	/* ===================================================== T12: arguments */

	ab_site_reset();
	$r = $SR( array( 'search' => 'x', 'scope' => 'everything' ) );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_scope' === $r->get_error_code() && false !== strpos( $r->get_error_message(), '"raw"' ), 'T12 an unknown scope is refused and the answer names both' );
	$r = $SR( array( 'search' => 'x', 'after' => array( 'builders' => 5 ) ) );
	ok( is_array( $r ) && 5 === $r['flags']['after']['builders'], 'T12 builders is a key of after/next' );

	/* ========================================== T13: description and readme */

	$src = (string) file_get_contents( AB_MCP_PRO_DIR . 'includes/tools/class-tools-search-replace.php' );
	ok( false !== strpos( $src, "'enum'        => array( 'text', 'raw' )" ) && false !== strpos( $src, 'scope "raw" reaches them' ), 'T13 the schema names both scopes, the description the way to raw (as a statement: tool descriptions give the model no instructions)' );
	$readme = (string) file_get_contents( AB_MCP_PRO_DIR . 'readme.txt' );
	$desc   = substr( $readme, 0, (int) strpos( $readme, '== Changelog ==' ) );
	ok( false !== strpos( $desc, 'scope' ) && false !== strpos( $desc, 'locked_matches' ), 'T13 the readme description tells what wp_search_replace does on page-builder pages' );

	/* ====== T22: SeedProd, the free core's reader and Pro's writer (storage A2) */

	// Last: it swaps the test reader for the free core's own (and keeps Demo).
	remove_all_filters( 'ab_mcp_builder_adapters' );
	add_filter( 'ab_mcp_builder_adapters', static function ( $list ) { $list[] = new AB_Test_Demo_Adapter(); return $list; }, 20 );
	define( 'SEEDPROD_VERSION', '6.20.10' );
	AB_MCP_Builders::reset();
	AB_MCP_Builder_Writers::reset();
	$fx   = dirname( __DIR__ ) . '/standalone-lib/fixtures/builders/seedprod/';
	$PAGE = json_decode( (string) file_get_contents( $fx . 'page.json' ), true );
	// The measured state sp-3: post_content changed alone («Mo–Sa»), SeedProd's data still «Mo–Fr».
	$e    = json_decode( (string) file_get_contents( $fx . 'editor-html.json' ), true )['fragments']['sp-3-alphabridge.json'];
	$html = '<div id="sp-page">' . implode( ' ', array( $e['h1'], isset( $e['text'] ) ? $e['text'] : $e['neu_text'], $e['link'], $e['button'], $e['bild_datei'] ) ) . '</div>';
	$sp   = static function () use ( $PAGE, $html ) {
		ab_site_reset();
		ab_post( 70, array( 'post_content' => $html, 'post_content_filtered' => $PAGE['post_content_filtered'] ) );
		foreach ( $PAGE['meta'] as $k => $v ) {
			ab_meta_add( 70, $k, $v );
		}
	};
	$sp();
	ok( 'seedprod' === AB_MCP_Builders::for_post( get_post( 70 ) )->id() && 'A2' === AB_MCP_Builders::effective_storage( get_post( 70 ) ) && 1 === substr_count( $html, 'Mo–Sa' ), 'T22 (setup: a SeedProd page of storage A2 whose HTML shows «Mo–Sa»)' );
	$r = $SR( array( 'search' => 'Mo–Sa', 'replace' => 'Di–Sa', 'apply' => true ) );
	ok( array( 70 ) === $r['tables']['builders']['as_text'] && false !== strpos( $GLOBALS['db_posts'][70]['post_content'], 'Di–Sa' ) && $PAGE['post_content_filtered'] === $GLOBALS['db_posts'][70]['post_content_filtered'], 'T22 a text only the shown HTML holds: replaced there as before (it was lost without a word); SeedProd\'s data untouched' );
	ok( isset( $r['builder_pages_without_writer'][0] ) && 0 === strpos( $r['builder_pages_without_writer'][0]['builder'], 'SeedProd: its writer found the text in none of the fields' ), 'T22 and the page is named: SeedProd restores its own copy at its next save' );
	$sp();
	$before_page = $GLOBALS['db_posts'][70];
	$r     = $SR( array( 'search' => 'Mo–Fr', 'replace' => 'Di–Sa', 'apply' => true ) );
	$notes = implode( ' ', $r['tables']['builders']['notes'] );
	ok( 1 === $r['tables']['builders']['matched'] && false !== strpos( $notes, 'Post 70: The HTML the site shows does not hold' ) && false !== strpos( $notes, 'Open and save the page in SeedProd\'s editor' ) && $before_page === $GLOBALS['db_posts'][70], 'T22 a text only SeedProd\'s data holds: the writer\'s reason and its way reach the answer; nothing is written' );
	// scope raw: the posts scan reads content, title and excerpt, not
	// SeedProd's own copy in post_content_filtered; the answer says that
	// SeedProd's next save brings the old text back.
	$sp();
	ok( 1 === substr_count( $html, 'Alpine Bikes' ) && false !== strpos( $PAGE['post_content_filtered'], 'Alpine Bikes' ), 'T22 (setup: «Alpine Bikes» stands in both copies)' );
	foreach ( array( false, true ) as $apply ) {
		$r = $SR( array( 'search' => 'Alpine Bikes', 'replace' => 'Berg Bikes', 'scope' => 'raw', 'apply' => $apply ) );
		$w = implode( ' ', $r['warnings'] );
		ok( 1 === $r['tables']['posts']['matched'] && $PAGE['post_content_filtered'] === $GLOBALS['db_posts'][70]['post_content_filtered'] && false !== strpos( $w, 'scope raw ' . ( $apply ? 'replaced' : 'would replace' ) . ' post_content only: SeedProd keeps its own copy' ) && false !== strpos( $w, 'posts 70.' ) && false !== strpos( $w, 'except a copy a page builder keeps in post_content_filtered' ), 'T22 scope raw ' . ( $apply ? 'applied' : 'preview' ) . ': post_content only, SeedProd\'s copy untouched, and the answer names the page and SeedProd\'s next save: ' . $w );
	}
	ok( false !== strpos( $GLOBALS['db_posts'][70]['post_content'], 'Berg Bikes' ), 'T22 scope raw wrote post_content' );

	ab_result( 'SEARCH-REPLACE-BUILDER (tool)-CHECKS' );
}


