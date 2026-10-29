<?php
/**
 * Serialisation-safe search & replace engine (Pro).
 *
 * Replaces text inside plain strings, PHP-serialized structures (arrays and
 * objects, any nesting) and JSON payloads (e.g. Elementor's _elementor_data)
 * without corrupting length prefixes or escaping. The engine operates on PHP
 * values: callers read rows, unserialize once, run apply_value() and write
 * back through the WordPress APIs (update_option, update_metadata_by_mid,
 * wp_update_post), which regenerate the serialization. Stored bytes are never
 * string-patched, so s:12:"…" length prefixes can never break.
 *
 * Extras handled here that a naive replace misses:
 * - Nested serialization (serialized string stored inside a serialized array).
 * - JSON documents: replaced structurally when a lossless round-trip
 *   (json_encode(json_decode($s)) === $s) is possible — this covers
 *   Elementor's _elementor_data exactly as Elementor writes it.
 * - Encoded forms of the text (plain, non-regex mode): a text is stored in
 *   other forms than it was typed in, and a search for the typed form missed
 *   them (measured: "A & B" was found in no block library, page-builder
 *   plan §2). The engine looks for every form in one pass and writes the
 *   replacement in the same form it found (FORMS, encode()):
 *   - json: as wp_json_encode() writes it ("https:\/\/a.ch", \u00fc), the
 *     form Elementor and older code store;
 *   - block_json: as serialize_block_attributes() writes block attributes
 *     (\u0026 \u003c \u003e \u0022 \u002d\u002d);
 *   - html: as the block editor writes text into HTML (&amp; &lt;);
 *   - html_full: as htmlspecialchars() writes it (&amp; &lt; &gt; &quot;);
 *   - block_json_html: HTML text inside a block attribute (\u0026amp;), the
 *     form rich-text attributes take.
 *   With the option attr_context, a match inside the attributes of a block
 *   comment (<!-- wp:name {…} -->) gets its replacement as JSON, so that a
 *   quote or an ampersand in it cannot break the attributes.
 * - __PHP_Incomplete_Class objects and corrupt serialized rows are left
 *   untouched and counted, never rewritten.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Replace_Engine
 */
class AB_MCP_Replace_Engine {

	/**
	 * How deep apply_value() and holds_object() walk. Elementor's
	 * _elementor_data nests two levels per layout level (an element, then its
	 * elements array): the measured test page (one container, three widgets)
	 * reaches 7; below n layout levels a widget field sits at 4 + 2n, a link in
	 * a repeater item at 7 + 2n and an image URL in the class styles of an
	 * Elementor 4 atomic element at 19 + 2n. At 12, a repeater link three
	 * containers down and every such style URL were missed. References are
	 * refused before unserialize() and JSON has none, so the limit no longer
	 * keeps a cycle out; it only bounds the recursion, and 64 keeps that short,
	 * far below the 512 levels json_decode() accepts. A value beyond it is left
	 * as it is and counted in $skipped_unchecked_values, never missed in silence.
	 */
	const MAX_DEPTH = 64;

	/**
	 * How many strings inside one another apply_string() unpacks (each
	 * serialized or JSON string it decodes counts one). Bounded apart from
	 * MAX_DEPTH because every unpacked layer holds a copy of nearly the whole
	 * row while the walk goes on below it: 64 layers around a few MB of text
	 * took the memory of 64 copies and ended the call in a fatal error, dry
	 * run included. Real pages need one layer (the JSON of _elementor_data) or
	 * two (JSON inside a serialized option); their depth comes from the
	 * structure. 12 is the reach the engine had while one limit of 12 bounded
	 * both. A string that would need a 13th is left as it is and counted in
	 * $skipped_unchecked_values.
	 */
	const MAX_LAYERS = 12;

	/**
	 * Search needle (plain text or PCRE pattern without delimiters).
	 *
	 * @var string
	 */
	private $search;

	/**
	 * Replacement text.
	 *
	 * @var string
	 */
	private $replace;

	/**
	 * Case-insensitive matching?
	 *
	 * @var bool
	 */
	private $ci;

	/**
	 * Treat search as a regular expression?
	 *
	 * @var bool
	 */
	private $regex;

	/**
	 * Compiled PCRE pattern (regex mode).
	 *
	 * @var string
	 */
	private $pattern = '';

	/**
	 * The forms of the search text this engine looks for, plain first, each
	 * needle once: [ { form, needle } ].
	 *
	 * @var array<int,array{form:string,needle:string}>
	 */
	private $forms = array();

	/**
	 * One pattern for every needle, longest first (plain mode; '' in regex mode).
	 *
	 * @var string
	 */
	private $forms_pattern = '';

	/**
	 * Replacements per form and context, computed once: [ context ][ form ].
	 *
	 * @var array<string,array<string,string>>
	 */
	private $subs = array();

	/**
	 * Whether a match inside block attributes gets its replacement as JSON.
	 *
	 * @var bool
	 */
	private $attr_context = false;

	/**
	 * The forms a text is looked for in, in this order (plain mode). The
	 * first form that yields a needle owns it; at one place in a value the
	 * longest needle wins, so "&amp;" is taken as the encoded "&", never as
	 * "&" followed by "amp;".
	 */
	const FORMS = array( 'plain', 'json', 'block_json', 'html', 'html_full', 'block_json_html' );

	/** Forms whose needle is JSON string content. */
	const JSON_FORMS = array( 'json', 'block_json', 'block_json_html' );

	/**
	 * Blocks whose attributes their plugin prints as HTML, unescaped, so a
	 * replacement goes into them HTML-escaped (measured: kadence/singlebtn
	 * prints its "text" raw, "<x>" became an element; page-builder plan
	 * §5.2). Filter ab_mcp_pro_attrs_printed_as_html.
	 */
	const ATTRS_PRINTED_AS_HTML = array( 'kadence/singlebtn' );

	/**
	 * The block delimiter as WordPress' block parser reads it
	 * (WP_Block_Parser::next_token(), wp-includes/class-wp-block-parser.php).
	 */
	const BLOCK_DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Constructor error (invalid regex), or null.
	 *
	 * @var WP_Error|null
	 */
	public $error = null;

	/**
	 * Rows whose serialized payload could not be unserialized (left untouched).
	 *
	 * @var int
	 */
	public $skipped_corrupt = 0;

	/**
	 * Rows or nested strings the engine leaves alone: they hold an object or a
	 * bare Serializable record.
	 * Classes are never restored from stored data, and an object that
	 * implements Serializable (a `C:` record) loses its payload the moment it
	 * is written back without its class — so nothing that holds one is rewritten.
	 *
	 * @var int
	 */
	public $skipped_object_values = 0;

	/**
	 * Constructor.
	 *
	 * @param string $search           Search text / pattern.
	 * @param string $replace          Replacement text.
	 * @param bool   $case_insensitive Case-insensitive matching.
	 * @param bool   $regex            Regex mode (PCRE without delimiters).
	 * @param array  $opts             attr_context (bool, default false): a
	 *                                 match inside block attributes gets its
	 *                                 replacement as JSON (see replacement()).
	 */
	public function __construct( $search, $replace, $case_insensitive = false, $regex = false, array $opts = array() ) {
		$this->search       = (string) $search;
		$this->replace      = (string) $replace;
		$this->ci           = (bool) $case_insensitive;
		$this->regex        = (bool) $regex;
		$this->attr_context = ! empty( $opts['attr_context'] );

		if ( $this->regex ) {
			$this->pattern = '~' . str_replace( '~', '\~', $this->search ) . '~u' . ( $this->ci ? 'i' : '' );
			if ( false === @preg_match( $this->pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probing user-supplied pattern validity.
				$this->error = new WP_Error(
					'ab_mcp_bad_regex',
					sprintf(
						/* translators: %s: PCRE error */
						__( 'Invalid regex pattern: %s Pass the pattern in PCRE syntax without delimiters, or leave regex out to search for the text itself.', 'alphabridge-mcp' ),
						function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : 'PCRE error'
					)
				);
			}
			return;
		}

		// Every form of the search text, and the pattern that finds them all
		// in one pass, computed once.
		$this->forms = self::needles( $this->search );
		$by_length   = $this->forms;
		usort(
			$by_length,
			static function ( $a, $b ) {
				return strlen( $b['needle'] ) - strlen( $a['needle'] );
			}
		);
		$alts = array();
		foreach ( $by_length as $f ) {
			$alts[] = '(?P<' . $f['form'] . '>' . preg_quote( $f['needle'], '~' ) . ')';
		}
		// Byte by byte when case matters: a valid UTF-8 needle cannot start
		// inside a character, and a value that is not valid UTF-8 is still
		// searched. Case-insensitive needs the u modifier for umlauts, and a
		// value that is not valid UTF-8 is then left as it is (as before).
		$this->forms_pattern = '~' . implode( '|', $alts ) . '~' . ( $this->ci ? 'ui' : '' );
	}

	/* ------------------------------------------- forms of a text (pure) */

	/**
	 * A text in one of the stored forms (FORMS). Pure.
	 *
	 * - plain: as typed.
	 * - json: wp_json_encode() without options, between the quotes: "/" as
	 *   "\/", '"' as '\"', every character beyond ASCII as \uXXXX.
	 * - block_json: serialize_block_attributes() of WordPress 7.0.2
	 *   (wp-includes/blocks.php), between the quotes: JSON without escaped
	 *   slashes and Unicode, then \ & < > " and "--" as \u005c, \u0026,
	 *   \u003c, \u003e, \u0022 and \u002d\u002d. Measured in all six block
	 *   runs: "A & B "Zitat" <x> – Grüsse ü" is stored as
	 *   "A \u0026 B \u0022Zitat\u0022 \u003cx\u003e – Grüsse ü".
	 * - html: what the block editor writes into the HTML of a block
	 *   (escapeHTML, @wordpress/escape-html): "&" as "&amp;", "<" as "&lt;";
	 *   ">" and '"' stay. Measured: "A &amp; B "Zitat" &lt;x>".
	 * - html_full: htmlspecialchars() with ENT_COMPAT: & < > " as &amp; &lt;
	 *   &gt; &quot; — what server code and classic content store, and the
	 *   form of a quote inside an HTML attribute.
	 * - block_json_html: html, then block_json — rich text kept in a block
	 *   attribute ("&" as "\u0026amp;"). Derived from the two measured forms;
	 *   that the editor stores button labels this way is open (plan §5.2).
	 *
	 * @param string $form One of FORMS.
	 * @param string $text Text.
	 * @return string The text in that form; the text itself for an unknown
	 *                form or one that cannot encode it (invalid UTF-8).
	 */
	public static function encode( $form, $text ) {
		$text = (string) $text;
		switch ( (string) $form ) {
			case 'json':
				$j = json_encode( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() without options gives these bytes; the form must not depend on WordPress being loaded.
				return is_string( $j ) ? substr( $j, 1, -1 ) : $text;
			case 'block_json':
				$j = json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the flags of serialize_block_attributes().
				if ( ! is_string( $j ) ) {
					return $text;
				}
				return strtr(
					substr( $j, 1, -1 ),
					array(
						'\\\\' => '\\u005c',
						'--'   => '\\u002d\\u002d',
						'<'    => '\\u003c',
						'>'    => '\\u003e',
						'&'    => '\\u0026',
						'\\"'  => '\\u0022',
					)
				);
			case 'html':
				return str_replace( array( '&', '<' ), array( '&amp;', '&lt;' ), $text );
			case 'html_full':
				// htmlspecialchars() would return '' for a text that is not valid
				// UTF-8; byte by byte, "&" first, gives its result for every other.
				return str_replace( array( '&', '<', '>', '"' ), array( '&amp;', '&lt;', '&gt;', '&quot;' ), $text );
			case 'block_json_html':
				return self::encode( 'block_json', self::encode( 'html', $text ) );
		}
		return $text;
	}

	/**
	 * The needles for a search text: one per form whose encoding differs from
	 * every form before it, plain first. Pure.
	 *
	 * @param string $search Search text.
	 * @return array<int,array{form:string,needle:string}>
	 */
	public static function needles( $search ) {
		$out  = array();
		$seen = array();
		foreach ( self::FORMS as $form ) {
			$needle = self::encode( $form, $search );
			if ( '' === $needle || isset( $seen[ $needle ] ) ) {
				continue;
			}
			$seen[ $needle ] = true;
			$out[]           = array(
				'form'   => $form,
				'needle' => $needle,
			);
		}
		return $out;
	}

	/**
	 * Could this text stand inside a JSON string as it is? Not with a quote,
	 * a backslash or a control character: a match of such a text inside
	 * block attributes spans their structure, not a value. Pure.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function fits_json_string( $text ) {
		return 1 !== preg_match( '~["\\\\\x00-\x1f]~', (string) $text );
	}

	/**
	 * What a match is replaced with, by the form it was found in and where.
	 * Pure.
	 *
	 * Outside block attributes (context 'text'): the replacement in the form
	 * of the match — found as &amp;, written as &amp;.
	 *
	 * Inside the JSON of a block comment (context 'attrs' or, for a block in
	 * ATTRS_PRINTED_AS_HTML, 'attrs_html'): always valid JSON string content.
	 * A needle that fits a JSON string but is not JSON-encoded itself (a
	 * plain word) gets the replacement as block_json, so a quote, "&" or
	 * "--" in it cannot end the value or the comment; a JSON-encoded needle
	 * gets it in its own JSON form. A needle that cannot stand inside a JSON
	 * string spans the structure (a search for '"level":2'), and gets the
	 * replacement as typed, as before. In 'attrs_html' the text is HTML-escaped
	 * first, because the block prints it unescaped.
	 *
	 * @param string $form    Form the needle was found in.
	 * @param string $needle  The needle that matched.
	 * @param string $replace Replacement as typed.
	 * @param string $context text, attrs or attrs_html.
	 * @return string
	 */
	public static function replacement( $form, $needle, $replace, $context = 'text' ) {
		$replace = (string) $replace;
		if ( 'attrs' !== $context && 'attrs_html' !== $context ) {
			return self::encode( $form, $replace );
		}
		$json = in_array( $form, self::JSON_FORMS, true );
		if ( ! $json && ! self::fits_json_string( $needle ) ) {
			return self::encode( $form, $replace );
		}
		// What the attribute holds, decoded: text for the text forms, HTML for
		// the HTML forms; a block that prints its attributes as HTML gets
		// HTML-escaped text either way.
		if ( 'html_full' === $form ) {
			$value = self::encode( 'html_full', $replace );
		} elseif ( 'html' === $form || 'block_json_html' === $form || 'attrs_html' === $context ) {
			$value = self::encode( 'html', $replace );
		} else {
			$value = $replace;
		}
		return self::encode( 'json' === $form ? 'json' : 'block_json', $value );
	}

	/**
	 * Where block attributes stand in a text: the JSON of every block
	 * comment, as byte ranges [ start, end ), with the block name. Pure.
	 *
	 * @param string $text Text (post_content).
	 * @return array<int,array{0:int,1:int,2:string}>
	 */
	public static function attr_spans( $text ) {
		$text = (string) $text;
		if ( false === strpos( $text, '<!--' ) || ! preg_match_all( self::BLOCK_DELIMITER, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $d ) {
			if ( ! isset( $d['attrs'] ) || -1 === $d['attrs'][1] || '' === $d['attrs'][0] ) {
				continue;
			}
			$json  = rtrim( $d['attrs'][0] );
			$ns    = isset( $d['namespace'] ) && -1 !== $d['namespace'][1] && '' !== $d['namespace'][0] ? $d['namespace'][0] : 'core/';
			$out[] = array( (int) $d['attrs'][1], (int) $d['attrs'][1] + strlen( $json ), $ns . $d['name'][0] );
		}
		return $out;
	}

	/**
	 * Blocks whose attributes their plugin prints as HTML.
	 *
	 * @return string[]
	 */
	public static function attrs_printed_as_html() {
		$list = self::ATTRS_PRINTED_AS_HTML;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Blocks whose attributes their plugin prints as HTML, unescaped:
			 * wp_search_replace writes a replacement into them HTML-escaped.
			 *
			 * @param string[] $blocks Block names (namespace/name).
			 */
			$list = (array) apply_filters( 'ab_mcp_pro_attrs_printed_as_html', $list );
		}
		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/**
	 * Needles a SQL LIKE prefilter must cover: every form of the search text.
	 * Empty in regex mode (no prefilter possible).
	 *
	 * @return string[]
	 */
	public function like_needles() {
		if ( $this->regex ) {
			return array();
		}
		$needles = array();
		foreach ( $this->forms as $f ) {
			$needles[] = $f['needle'];
		}
		return $needles;
	}

	/**
	 * The forms of the search text this engine looks for.
	 *
	 * @return array<int,array{form:string,needle:string}>
	 */
	public function forms() {
		return $this->regex ? array() : $this->forms;
	}

	/**
	 * Recursively apply the replacement to any PHP value.
	 *
	 * @param mixed $value  Value (string, array, object, scalar).
	 * @param int   $count  Occurrence counter (by reference).
	 * @param int   $depth  Recursion depth.
	 * @param int   $layers Strings already unpacked above this value.
	 * @return mixed New value.
	 */
	public function apply_value( $value, &$count, $depth = 0, $layers = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			// Left as it is, but not in silence: a JSON document (or a value
			// nested in a string) deeper than the walk goes is counted like a
			// serialized value nobody could walk to the end. What could not
			// hold a match — a number, a boolean, null, an empty string or
			// container — is not counted.
			if ( self::can_hold_text( $value ) ) {
				$this->skipped_unchecked_values++;
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			return $this->apply_string( $value, $count, $depth, $layers );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->apply_value( $v, $count, $depth + 1, $layers );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			if ( $value instanceof __PHP_Incomplete_Class ) {
				return $value; // Class not loaded here; rewriting it would corrupt the row.
			}
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->{$k} = $this->apply_value( $v, $count, $depth + 1, $layers );
			}
			return $value;
		}
		return $value;
	}

	/**
	 * Could a value hold text the search can match?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function can_hold_text( $value ) {
		if ( is_string( $value ) ) {
			return '' !== $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		return is_array( $value ) && array() !== $value;
	}

	/**
	 * Does serialized text carry an object (`O:`), Serializable (`C:`) or
	 * enum (`E:`) record at a structural position?
	 *
	 * Decided on the text, before unserialize() runs: an enum reaches the
	 * autoloader even with classes disallowed, and a duplicate array key makes
	 * an object vanish from the unserialized structure while it stays in the
	 * row. Decided by walking the format rather than by a pattern, so that a
	 * record-looking token INSIDE a string payload (`s:20:"Text;O:4:"Demo":0:{}";`)
	 * does not count (Codex). Anything the walk does not understand — an
	 * escaped string (`S:`), a truncated value, an unknown token — counts as a
	 * record: refusing to touch it is the safe answer. So does a reference
	 * (`r:`/`R:`), see below.
	 *
	 * @param string $text Serialized text, trimmed.
	 * @return bool
	 */
	public function carries_record( $text ) {
		$text = (string) $text;
		$n    = strlen( $text );
		$i    = 0;
		while ( $i < $n ) {
			switch ( $text[ $i ] ) {
				case 'O':
				case 'C':
				case 'E':
				case 'S':
					return true;
				case 'a':
					// a:<count>:{ … }
					if ( ! preg_match( '/^a:\\d+:\\{/', substr( $text, $i, 24 ), $m ) ) {
						return true;
					}
					$i += strlen( $m[0] );
					break;
				case 's':
					// s:<bytes>:"<payload>"; — the payload is skipped by its byte length,
					// which is what keeps a token inside it from counting.
					if ( ! preg_match( '/^s:(\\d+):"/', substr( $text, $i, 24 ), $m ) ) {
						return true;
					}
					// The length is checked against what is left BEFORE it is added:
					// a stored s:9223372036854775807 overflowed the offset into a
					// float and substr() then threw (Codex). (int) saturates, so the
					// comparison itself cannot overflow.
					$len = (int) $m[1];
					$i  += strlen( $m[0] );
					if ( $len < 0 || $len > $n - $i ) {
						return true;
					}
					$i += $len;
					if ( '";' !== substr( $text, $i, 2 ) ) {
						return true;
					}
					$i += 2;
					break;
				case 'r':
				case 'R':
					// A reference makes one value reachable many times over; a small
					// text can then take an exponential walk (Codex). Nothing this
					// plugin or WordPress writes uses them: refused.
					return true;
				case 'i':
				case 'd':
				case 'b':
					// <type>:<value>;
					$end = strpos( $text, ';', $i );
					if ( false === $end ) {
						return true;
					}
					$i = $end + 1;
					break;
				case 'N':
					if ( 'N;' !== substr( $text, $i, 2 ) ) {
						return true;
					}
					$i += 2;
					break;
				case '}':
					$i++;
					break;
				default:
					return true;
			}
		}
		return false;
	}

	/**
	 * Does a value hold an object anywhere inside?
	 *
	 * @param mixed $value Unserialized value.
	 * @return bool
	 */
	public function holds_object( $value ) {
		$this->visits     = 0;
		$this->gave_up    = false;
		return $this->walk_for_object( $value, 0 );
	}

	/** @var bool Whether the last holds_object() ran out of budget rather than finding an object. */
	public $gave_up = false;

	/** @var int Values left alone because they were too large or too deep to check, not because they carry an object. */
	public $skipped_unchecked_values = 0;

	/** Nodes one holds_object() call may visit before it gives up ("leave it alone"). */
	const MAX_VISITS = 50000;

	/** @var int Nodes visited by the current holds_object() call. */
	private $visits = 0;

	/**
	 * @param mixed $value Value.
	 * @param int   $depth Recursion depth.
	 * @return bool
	 */
	private function walk_for_object( $value, $depth ) {
		// No deeper than the engine itself goes, and no wider than the budget:
		// a cyclic or many-times-shared structure (references build both)
		// would otherwise never end here; beyond either limit the answer is
		// "leave it alone" (Codex).
		if ( $depth > self::MAX_DEPTH || ++$this->visits > self::MAX_VISITS ) {
			// Not a find: the budget ran out, so the answer is "cannot say",
			// and a row nobody could check is left alone — counted apart from
			// the rows that really carry an object.
			$this->gave_up = true;
			return true;
		}
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( $this->walk_for_object( $v, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * String leaf: descend into nested serialization / lossless JSON, else replace.
	 *
	 * @param string $s      String value.
	 * @param int    $count  Occurrence counter (by reference).
	 * @param int    $depth  Recursion depth.
	 * @param int    $layers Strings already unpacked above this one.
	 * @return string
	 */
	private function apply_string( $s, &$count, $depth, $layers ) {
		// A Serializable record on its own. WordPress' is_serialized() does not
		// know `C:`, so the string would be treated as prose and a replacement
		// inside it would falsify the byte length it carries (Codex).
		if ( preg_match( '/^\s*C:\d+:"[^"]*":\d+:\{/', $s ) ) {
			$this->skipped_object_values++;
			return $s;
		}

		// An escaped string record on its own: is_serialized() does not know
		// `S:` either, and a replacement inside it corrupts its byte length.
		if ( preg_match( '/^\s*S:\d+:"/', $s ) ) {
			$this->skipped_object_values++;
			return $s;
		}

		// Nested PHP serialization stored inside a string value.
		if ( is_serialized( $s ) ) {
			$trimmed = trim( $s );
			// Object, Serializable and enum records are found by walking the
			// text, before unserialize() sees it: an enum (`E:`) reaches the
			// autoloader even with classes disallowed, and a duplicate array key
			// can hide an object from the unserialized structure while it stays
			// in the text (Codex).
			if ( $this->carries_record( $trimmed ) ) {
				$this->skipped_object_values++;
				return $s;
			}
			if ( $layers >= self::MAX_LAYERS ) {
				$this->skipped_unchecked_values++;
				return $s;
			}
			// allowed_classes => false: this string can be a post title or body,
			// which a contributor writes and core never unserializes. Without the
			// option, an `O:` payload there instantiated its class the moment an
			// administrator ran a search — in the dry run too. Objects come back
			// as __PHP_Incomplete_Class, which apply_value() leaves untouched and
			// serialize() writes back byte for byte.
			$inner   = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Stored data; classes deliberately not restored.
			if ( false === $inner && 'b:0;' !== $trimmed ) {
				$this->skipped_corrupt++;
				return $s;
			}
			if ( $this->holds_object( $inner ) ) {
				if ( $this->gave_up ) {
					$this->skipped_unchecked_values++;
				} else {
					$this->skipped_object_values++;
				}
				return $s;
			}
			$before = $count;
			$inner  = $this->apply_value( $inner, $count, $depth + 1, $layers + 1 );
			return ( $count > $before ) ? serialize( $inner ) : $s; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Re-serializing a nested value we just unserialized.
		}

		// JSON documents — structural replace only when re-encoding is lossless.
		$t = ltrim( $s );
		if ( strlen( $t ) > 1 && ( '{' === $t[0] || '[' === $t[0] ) ) {
			$decoded = json_decode( $s );
			if ( ( is_object( $decoded ) || is_array( $decoded ) ) && json_encode( $decoded ) === $s ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Byte-exact round-trip check requires plain json_encode.
				// At the layer limit it is decoded only to tell JSON from text
				// that merely starts like it (that text is still replaced
				// below); the decoded copy is dropped here, not walked.
				if ( $layers >= self::MAX_LAYERS ) {
					$this->skipped_unchecked_values++;
					return $s;
				}
				$before  = $count;
				$decoded = $this->apply_value( $decoded, $count, $depth + 1, $layers + 1 );
				return ( $count > $before ) ? json_encode( $decoded ) : $s; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Must mirror the original encoder byte-for-byte.
			}
		}

		return $this->replace_string( $s, $count );
	}

	/**
	 * Flat replacement on one string: the regex, or every form of the text in
	 * one pass. One pass, so a replacement is never searched again (a
	 * replacement that contains the search text, or an encoded form of it,
	 * stays as written), and at each place the longest form wins.
	 *
	 * @param string $s     Subject.
	 * @param int    $count Occurrence counter (by reference).
	 * @return string
	 */
	public function replace_string( $s, &$count ) {
		if ( '' === $s ) {
			return $s;
		}
		if ( $this->regex ) {
			$n   = 0;
			$new = preg_replace( $this->pattern, $this->replace, $s, -1, $n );
			if ( null === $new ) {
				return $s; // PCRE runtime error on this value; leave untouched.
			}
			$count += (int) $n;
			return $new;
		}
		if ( array() === $this->forms ) {
			return $s;
		}
		if ( ! $this->ci ) {
			// Most values hold none of the needles; strpos() says so faster
			// than the pattern.
			$any = false;
			foreach ( $this->forms as $f ) {
				if ( false !== strpos( $s, $f['needle'] ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				return $s;
			}
		}
		$spans = $this->attr_context ? self::attr_spans( $s ) : array();
		$html  = array() !== $spans ? self::attrs_printed_as_html() : array();
		$n     = 0;
		$new   = preg_replace_callback(
			$this->forms_pattern,
			function ( $m ) use ( $spans, $html, &$n ) {
				$text   = $m[0][0];
				$at     = (int) $m[0][1];
				$form   = 'plain';
				$needle = $this->search;
				foreach ( $this->forms as $f ) {
					if ( isset( $m[ $f['form'] ] ) && -1 !== $m[ $f['form'] ][1] ) {
						$form   = $f['form'];
						$needle = $f['needle'];
						break;
					}
				}
				$context = 'text';
				foreach ( $spans as $span ) {
					if ( $at >= $span[0] && $at + strlen( $text ) <= $span[1] ) {
						$context = in_array( $span[2], $html, true ) ? 'attrs_html' : 'attrs';
						break;
					}
				}
				$n++;
				if ( ! isset( $this->subs[ $context ][ $form ] ) ) {
					$this->subs[ $context ][ $form ] = self::replacement( $form, $needle, $this->replace, $context );
				}
				return $this->subs[ $context ][ $form ];
			},
			$s,
			-1,
			$unused,
			PREG_OFFSET_CAPTURE
		);
		if ( null === $new ) {
			return $s; // PCRE runtime error (a case-insensitive search in a value that is not valid UTF-8); leave untouched.
		}
		$count += $n;
		return $new;
	}
}
