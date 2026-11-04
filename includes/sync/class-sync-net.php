<?php
/**
 * The net over the raw value (Codex, rounds 18 to 21; Thomas, 04.11.2026):
 * one check that knows no format, run at the end of every unit (a stored
 * value that is canonicalized: a column, a meta row, an option, a widget
 * instance, a key of theme mods, the CSS of a stylesheet) after its
 * structured readers (AB_MCP_Sync_Refs, AB_MCP_Sync_Urls, AB_MCP_Sync_Css,
 * AB_MCP_Sync_Json, AB_MCP_Sync_Credentials) have read it and before its
 * canonical form is written. Twenty rounds showed that rebuilding formats
 * does not converge: every format (CSS variables, data: addresses, SVG, the
 * JSON of a plugin in data-*) opened a gap of its own. The net closes the
 * class of gaps instead of each gap: it counts, in every layer of text the
 * stored value holds, what must never pass unread, and locks the unit
 * where the structured readers read less.
 *
 * UNFOLD (layers()): from the raw value every layer of text, without
 * understanding a format: the raw text; its decodings (character
 * references as WP_HTML_Decoder decodes a text node, the escapes of JSON
 * (\/, \uXXXX), of JavaScript (\u{XXXX}, \uXXXX, \xHH), of C
 * (stripcslashes(), what shortcode_parse_atts() takes), of CSS
 * (AB_MCP_Sync_Css::unescaped()), percent escapes, and the text without
 * the comments of code (/*…*‍/ and //… to the end of the line, its strings
 * respected)); every data: address decoded (base64 and percent, of any
 * type: a text type, or one with a charset, read as UTF-8 by its BOM or
 * charset (utf8(): UTF-16, UTF-32, ISO-8859-1, Windows-1252), and a body
 * that is then no UTF-8 or holds a null byte is not read: the unit locks
 * (*:unreadable_value); any other type is bytes); every span of base64 of
 * MIN_BASE64 characters or more whose bytes are a text (UTF-8, or by a BOM
 * UTF-16 or UTF-32; one with a BOM that is then no text locks the unit);
 * each of these again (a decoded string may
 * hold a data: address of its own), to MAX_DEPTH derivations from the raw
 * text and MAX_LAYERS layers of one unit. Beyond, the unit is not read to
 * its end (AB_MCP_Sync_Canon::too_deep(): *:unreadable_value, incomplete).
 * A layer taken out of the text (a data: address, base64, and every
 * decoding of such a layer) is EXTRACTED: no reader of the core read it.
 * Without WP_HTML_Decoder no character reference is decoded here: a text
 * with one fails closed in the credential check already
 * (AB_MCP_Sync_Credentials::decodings()). Without a function that
 * transcodes (mb_convert_encoding(), iconv()) a body in another charset is
 * not read: the unit locks.
 *
 * COUNT, in every layer:
 * - references(): the (space, id) pairs the addresses of a layer name, read
 *   as AB_MCP_Sync_Urls reads one address (pairs(): the query variables of
 *   WP_Query through parse_str(), the paths through the rewrite rules of the
 *   site, WP_MatchesMapRegex), an id in a spelling that is not read as "?".
 *   First the address tokens: from every host (AB_MCP_Sync_Urls::HOST: with
 *   its scheme or without, also \/\/ of JSON) to the end of an address
 *   (AB_MCP_Sync_Urls::EXTENT, as the prose is read); a token with a host of
 *   no site names nothing, whatever follows its "#" (a route of another web
 *   application); a token with a host of a site names what the reader reads
 *   in it. Outside the tokens: every root-relative address (START: a "/"
 *   after a character that stands in no address and continues no word),
 *   its path by the rules of the site and its query; and every query from a
 *   "?" or "&" wherever it stands (index.php?p=12, ./?p=12, a?p=12), read
 *   as the query of an address relative to the page (locked by the reader:
 *   "?"). An address without a digit from 1 to 9 names no object and is not
 *   asked; a site's address without an id names nothing.
 * - credentials(): every name AB_MCP_Sync_Credentials::names_credential()
 *   names with a value beside it: name=value (also name']=value, name =>
 *   value, with ", ' or ` around the name), "name":value, 'name':value,
 *   `name`:value, in code (name:value right after "{", or after "," or ";"
 *   where a "{" is open before it: the object of a script, a block of CSS,
 *   also --name), at the start of the text name:"value" with the value in
 *   quotes, and a name as the value of a field name or key with a field
 *   value or secret within WINDOW bytes. A value is what is not empty after
 *   its white space is trimmed (" demo" is one). A name without a value, a
 *   word in prose ("design tokens", "Token: x", «Name, Token: "abc"» outside
 *   of braces) counts for nothing.
 *
 * COMPARE (check()): the structured readers of the unit noted every (space,
 * id) pair of an address they read (AB_MCP_Sync_Canon::text():
 * $acc['addressed'], rewritten or locked) and every field of a credential
 * (credentials.mixed). In the raw text and its decodings, a pair counted
 * more often than they read it locks the unit (*:unread_reference, at
 * <unit>:net:<layer>); in an extracted layer every pair locks it, as no
 * reader read there (a reference read in the text never covers one in a
 * data: address). Where a layer holds the name of a credential they did not
 * name, and the unit is readable, it locks (*:unread_credential), the name
 * noted among the credentials, never a value. A unit the readers locked is
 * not taken over: there the net adds nothing for the references. A unit
 * they could not read (an object, a script, HTML outside the list of kses,
 * nested deeper than read) is never clean: there it adds nothing for the
 * credentials.
 *
 * The net only locks. Rewriting stays with the structured readers, in the
 * spellings they read as the core reads them (the JSON of a data-*
 * attribute among them, by its round trip: AB_MCP_Sync_Urls::ids()).
 *
 * KNOWN LIMITS (group C of the rounds: rare spellings that lock, never
 * free):
 * - a key twice in JSON whose dropped value holds an address with an id or
 *   a credential: locked;
 * - the JSON of a data-* attribute that no producer of the core writes so
 *   (indented, "&" literal) with an address with an id: locked, not
 *   rewritten;
 * - a string of CSS (a custom property) with an address with an id: locked,
 *   rewritten only in url(), image-set() and @import;
 * - an address with an id in a title, an alt, a tooltip, a comment of HTML,
 *   a query relative to the page (index.php?p=12, also in prose) or in prose
 *   as a root-relative path the rules of the site route to an object:
 *   locked, not rewritten;
 * - a credential with its value in a layer no reader of the core reads
 *   (base64, a data: address of any type, an SVG image, a script of an
 *   image, in another charset): locked;
 * - a body of a data: address of a text type in a charset it does not
 *   declare and that no BOM gives: not read, locked.
 * And what is neither read nor locked: a script of an image that builds the
 * name of a credential at run time (a template literal with ${…}, a
 * concatenation): only literal spellings are read, an image runs no
 * script; a span of base64 whose bytes are no text in UTF-8 and carry no
 * BOM (a binary, a text in a charset it does not declare) is bytes.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-urls.php';
require_once __DIR__ . '/class-sync-credentials.php';
require_once __DIR__ . '/class-sync-css.php';

/**
 * Class AB_MCP_Sync_Net
 */
final class AB_MCP_Sync_Net {

	/** How many derivations from the raw text are followed. */
	const MAX_DEPTH = 4;

	/** How many layers one unit may unfold to. */
	const MAX_LAYERS = 64;

	/** The shortest span of base64 that is decoded (9 bytes; a shorter one hides nothing a reader of the core reads). */
	const MIN_BASE64 = 12;

	/** How far from a name as the value of name/key a field value/secret may stand. */
	const WINDOW = 256;

	/**
	 * Where a query may begin: a "?" or "&", whatever stands before it,
	 * except the "/" JSON escapes (\/?p=12: a root-relative address, read
	 * in the layer of the escapes of JSON decoded).
	 */
	const QUERY = '#(?<!\\\\/)[?&]#';

	/** A span of base64: the alphabet and its padding, MIN_BASE64 or more in all (base64_spans()). */
	const BASE64 = '#(?<![A-Za-z0-9+/=])[A-Za-z0-9+/]{8,}={0,2}(?![A-Za-z0-9+/=])#';

	/** How long the header of a data: address may be (data_header()). */
	const HEAD = 512;

	/** The keywords of JavaScript after which a "/" begins a RegExp literal, not a division (regexp_literal()). */
	const KEYWORDS = array( 'return', 'typeof', 'void', 'delete', 'in', 'instanceof', 'new', 'throw', 'case', 'do', 'else', 'yield', 'await', 'of' );

	/**
	 * Where a data: address stands as an address (Codex, round 24, B-1): in
	 * the value of an attribute, in url(), @import or image-set() of CSS, or
	 * as a string value of JSON; "data:" in a text node or in prose begins
	 * none (a header that is none locks only here).
	 */
	const DATA_CONTEXT = '#(?:url\(\s*|@import\s+|image-set\(\s*|=\s*|[:\[,]\s*(?=["\']))["\']?\s*\z#i';

	/** The types of a data: address whose body is a text. */
	const TEXT_TYPES = '#^(?:text/|[^/]+/(?:[^+]+\+)?(?:xml|json|javascript|ecmascript|css|html)\z)#';

	/**
	 * The labels of a charset (the MIME parameter, the XML declaration) and
	 * the charset each names: UTF-16 and UTF-32 without an order are
	 * little-endian (the Encoding Standard).
	 */
	const LABELS = array(
		'utf-8'        => 'UTF-8',
		'utf8'         => 'UTF-8',
		'us-ascii'     => 'UTF-8',
		'ascii'        => 'UTF-8',
		'utf-16'       => 'UTF-16LE',
		'utf-16le'     => 'UTF-16LE',
		'utf-16be'     => 'UTF-16BE',
		'utf-32'       => 'UTF-32LE',
		'utf-32le'     => 'UTF-32LE',
		'utf-32be'     => 'UTF-32BE',
		'iso-8859-1'   => 'ISO-8859-1',
		'latin1'       => 'ISO-8859-1',
		'l1'           => 'ISO-8859-1',
		'windows-1252' => 'Windows-1252',
		'cp1252'       => 'Windows-1252',
	);

	/**
	 * The first bytes of an XML document without a BOM and the charset they
	 * guess (Appendix F of the XML specification): the declaration "<?xm"
	 * in UTF-32, UTF-16 and in an encoding compatible with ASCII (then the
	 * declaration itself names it).
	 */
	const XML_STARTS = array(
		"\x00\x00\x00\x3C" => 'UTF-32BE',
		"\x3C\x00\x00\x00" => 'UTF-32LE',
		"\x00\x3C\x00\x3F" => 'UTF-16BE',
		"\x3C\x00\x3F\x00" => 'UTF-16LE',
	);

	/** A name as a key is spelled: as FLAT_KEYS of the credential check reads one. */
	const NAME = '[A-Za-z0-9_\x80-\xFF][A-Za-z0-9_\-.\x80-\xFF]{0,255}';

	/**
	 * A value beside a name: in quotes (", ' or `) a character after the
	 * quote and the white space after it, else a character that ends no
	 * value (a space, "&", ";", ",", a bracket, ">").
	 */
	const VALUE = '(?:["\'`]\s*[^"\'`\s]|[^\s"\'`&;,)}\]>])';

	/**
	 * The locks of the readers that make a unit one that is never clean
	 * (the credential check, the depth, an object): the net adds nothing to
	 * it. Beside them the readers lock a reference in another spelling
	 * (reference_spelling); every other lock of an object (the table of
	 * the options, a builder, a type) is no lock of the readers.
	 */
	const UNREADABLE = array( 'php_object', 'unreadable_value', 'script', 'html_outside_kses', 'credential_field', 'password_protected' );

	/**
	 * The counts of an accumulator before a unit is read, for check().
	 *
	 * @param array $acc Accumulator.
	 * @return array<string,int>
	 */
	public static function mark( array $acc ) {
		return array(
			'addressed' => isset( $acc['addressed'] ) ? count( $acc['addressed'] ) : 0,
			'spelling'  => count( $acc['spelling'] ),
			'locks'     => count( $acc['locks'] ),
			'mixed'     => count( $acc['credentials']['mixed'] ),
		);
	}

	/**
	 * The net over one unit, after its structured readers: see the header.
	 *
	 * @param array  $acc  Accumulator, after the unit was read.
	 * @param array  $mark mark() before it was read.
	 * @param string $kind Kind, for the locks.
	 * @param string $at   The unit (meta:_key, post_content, option_value …).
	 * @param mixed  $raw  The stored value: a text, or an unpacked structure
	 *                     (a widget instance) whose texts are read.
	 * @param array  $ctx  AB_MCP_Sync_Canon::context().
	 * @return void
	 */
	public static function check( array &$acc, array $mark, $kind, $at, $raw, array $ctx ) {
		$text = self::text( $raw );
		// What the readers of the unit found: their locks (a reference in
		// another spelling, noted for finish() or locked at once; a unit
		// never clean), the pairs of the addresses they read, the names of
		// the credentials they named.
		$locked     = count( $acc['spelling'] ) > $mark['spelling'];
		$unreadable = false;
		foreach ( array_slice( $acc['locks'], $mark['locks'] ) as $lock ) {
			$op = (string) substr( (string) $lock['key'], strpos( (string) $lock['key'], ':' ) + 1 );
			if ( in_array( $op, self::UNREADABLE, true ) ) {
				$unreadable = true;
			} elseif ( 'reference_spelling' === $op ) {
				$locked = true;
			}
		}
		if ( $unreadable ) {
			return;
		}
		$read  = array_count_values( array_slice( $acc['addressed'], $mark['addressed'] ) );
		$named = array();
		foreach ( array_slice( $acc['credentials']['mixed'], $mark['mixed'] ) as $mixed ) {
			foreach ( $mixed['fields'] as $field ) {
				foreach ( self::field_names( (string) $field ) as $name ) {
					$named[ $name ] = true;
				}
			}
		}
		$extra  = AB_MCP_Redactor::extra_keys();
		$deep   = false;
		$unread = array();
		$layers = self::layers( $text, $deep, $unread );
		foreach ( $layers as $layer ) {
			list( $path, $one, $extracted ) = $layer;
			if ( ! $locked ) {
				foreach ( array_count_values( self::references( $one, $ctx ) ) as $pair => $count ) {
					// Taken out of the text, no reader read it: every pair is
					// unread; in the text and its decodings, every pair beyond
					// what they read.
					if ( $extracted || $count > ( isset( $read[ $pair ] ) ? $read[ $pair ] : 0 ) ) {
						AB_MCP_Sync_Canon::lock( $acc, (string) $kind, 'unread_reference', $at . ':net:' . $path );
						$locked = true;
						break;
					}
				}
			}
			if ( $unreadable ) {
				continue;
			}
			$new         = array();
			$undecidable = false;
			foreach ( self::credentials( $one, $extra, $undecidable, '/code' === substr( $path, -5 ) ) as $name ) {
				if ( ! isset( $named[ $name ] ) ) {
					$new[ $name ] = '~' . $name;
				}
			}
			// The braces of code cannot be counted (a RegExp literal, more
			// closed than opened; Codex, round 23, A-4): never clean.
			if ( $undecidable ) {
				AB_MCP_Sync_Canon::lock( $acc, (string) $kind, 'unreadable_value', $at . ':net:' . $path );
				$acc['unread'][] = 'code:' . $at . ':net:' . $path;
				$unreadable      = true;
			}
			if ( array() !== $new ) {
				$acc['credentials']['mixed'][] = array(
					'unit'   => (string) $at,
					'fields' => array_values( $new ),
				);
				AB_MCP_Sync_Canon::lock( $acc, (string) $kind, 'unread_credential', $at . ':net:' . $path );
				$unreadable = true;
			}
		}
		// A body that is no text in UTF-8: not read, never clean.
		foreach ( $unread as $path ) {
			AB_MCP_Sync_Canon::lock( $acc, (string) $kind, 'unreadable_value', $at . ':net:' . $path );
			$acc['unread'][] = 'charset:' . $at . ':net:' . $path;
		}
		if ( $deep ) {
			AB_MCP_Sync_Canon::too_deep( $acc, $at . ':net', (string) $kind );
		}
	}

	/* ------------------------------------------------------------ unfold */

	/**
	 * The layers of text a stored value holds (see the header), each with
	 * the path of its derivation (raw, raw/html, raw/data/base64 …) and
	 * whether it is extracted (taken out of the text: data:, base64).
	 *
	 * @param string   $text   The raw text.
	 * @param bool     $deep   Set where a derivation beyond MAX_DEPTH or
	 *                         beyond MAX_LAYERS was not followed.
	 * @param string[] $unread The paths of the layers that hold a body that
	 *                         is no text in UTF-8 (not read).
	 * @return array<int,array{0:string,1:string,2:bool}>
	 */
	public static function layers( $text, &$deep = false, &$unread = array() ) {
		$deep   = false;
		$unread = array();
		$seen   = array();
		$out    = array();
		self::unfold( (string) $text, 'raw', false, 0, $seen, $out, $deep, $unread );
		return $out;
	}

	/**
	 * @param string   $text      A layer.
	 * @param string   $path      Its derivation.
	 * @param bool     $extracted Whether it is taken out of the text.
	 * @param int      $depth     Derivations from the raw text.
	 * @param array    $seen      The layers read, by their hash.
	 * @param array    $out       The layers.
	 * @param bool     $deep      See layers().
	 * @param string[] $unread    See layers().
	 */
	private static function unfold( $text, $path, $extracted, $depth, array &$seen, array &$out, &$deep, array &$unread ) {
		if ( count( $out ) >= self::MAX_LAYERS ) {
			$deep = true;
			return;
		}
		$seen[ hash( 'sha256', $text ) ] = true;
		$out[]                           = array( $path, $text, $extracted );
		// What the text holds first (a data: address, base64), its decodings
		// after: a lock names the nearer layer.
		$more = array();
		$bad  = false;
		foreach ( self::data_bodies( $text, $bad ) as $body ) {
			$more[] = array( $path . '/data', $body, true );
		}
		foreach ( self::base64_spans( $text, $bad ) as $span ) {
			$more[] = array( $path . '/base64', $span, true );
		}
		foreach ( self::decoded( $text ) as $name => $variant ) {
			$more[] = array( $path . '/' . $name, $variant, $extracted );
		}
		if ( $bad ) {
			$unread[] = $path;
		}
		// A layer read already (a decoding that changed nothing among them)
		// is read once, and is no derivation at the depth. The layer of code
		// of a text is read as code even where no comment changed it (the
		// braces counted with the rule of code there; Codex, round 24, A-1),
		// as a leaf: nothing is derived from it.
		foreach ( $more as $next ) {
			if ( isset( $seen[ hash( 'sha256', $next[1] ) ] ) ) {
				if ( '/code' === substr( $next[0], -5 ) && count( $out ) < self::MAX_LAYERS ) {
					$out[] = array( $next[0], $next[1], $extracted );
				}
				continue;
			}
			if ( $depth >= self::MAX_DEPTH ) {
				$deep = true;
				return;
			}
			self::unfold( $next[1], $next[0], $next[2], $depth + 1, $seen, $out, $deep, $unread );
		}
	}

	/**
	 * A text as each decoding the core, JSON, JavaScript, C and CSS know
	 * gives it, by its name; and without the comments of code.
	 *
	 * @param string $text Text.
	 * @return array<string,string>
	 */
	private static function decoded( $text ) {
		$out = array();
		if ( false !== strpos( $text, '&' ) && class_exists( 'WP_HTML_Decoder' ) ) {
			$out['html'] = (string) WP_HTML_Decoder::decode_text_node( $text );
		}
		if ( false !== strpos( $text, '\\' ) ) {
			$out['json']    = AB_MCP_Sync_Urls::json_unescaped( $text );
			$out['js']      = self::js_unescaped( $text );
			$out['slashes'] = stripcslashes( $text );
			$out['css']     = AB_MCP_Sync_Css::unescaped( $text );
		}
		if ( false !== strpos( $text, '%' ) ) {
			$out['percent'] = rawurldecode( $text );
		}
		// Code: the text without its comments; made for a text with braces
		// as well (Codex, round 24, A-1: the braces of code are counted there
		// with every "/" that may begin a RegExp literal).
		if ( false !== strpos( $text, '/*' ) || false !== strpos( $text, '//' ) || false !== strpos( $text, '{' ) ) {
			$out['code'] = self::uncommented( $text );
		}
		return $out;
	}

	/**
	 * A text with the escapes of JavaScript decoded: \u{XXXX}, \uXXXX (any
	 * code point), \xHH.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function js_unescaped( $text ) {
		return (string) preg_replace_callback(
			'/\\\\u\{([0-9a-fA-F]{1,6})\}|\\\\u([0-9a-fA-F]{4})|\\\\x([0-9a-fA-F]{2})/',
			static function ( $m ) {
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return chr( hexdec( $m[3] ) );
				}
				$code = hexdec( '' !== $m[1] ? $m[1] : $m[2] );
				if ( ( $code >= 0xD800 && $code <= 0xDFFF ) || $code > 0x10FFFF ) {
					return $m[0];
				}
				return $code < 0x80 ? chr( $code ) : (string) mb_chr( (int) $code, 'UTF-8' );
			},
			$text
		);
	}

	/**
	 * A text without the comments of code (/*…*‍/, //… to the end of the
	 * line), its strings respected (between ", ' or `, an escaped character
	 * part of one, " and ' ended by a line break as well), as
	 * AB_MCP_Sync_Css::read() reads the comments of CSS.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function uncommented( $text ) {
		$out   = '';
		$quote = '';
		$n     = strlen( $text );
		for ( $i = 0; $i < $n; ) {
			$c = $text[ $i ];
			if ( '\\' === $c ) {
				$out .= substr( $text, $i, 2 );
				$i   += 2;
				continue;
			}
			if ( '' !== $quote ) {
				if ( $c === $quote || ( '`' !== $quote && ( "\n" === $c || "\r" === $c ) ) ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c || '`' === $c ) {
				$quote = $c;
			} elseif ( '/' === $c && '*' === substr( $text, $i + 1, 1 ) ) {
				$end = strpos( $text, '*/', $i + 2 );
				$i   = false === $end ? $n : $end + 2;
				continue;
			} elseif ( '/' === $c && '/' === substr( $text, $i + 1, 1 ) ) {
				$i += strcspn( $text, "\n\r", $i );
				continue;
			}
			$out .= $c;
			++$i;
		}
		return $out;
	}

	/**
	 * Whether a text of code (its strings blanked) holds a RegExp literal of
	 * JavaScript (the strings and comments blanked), closed by a "/" on
	 * the same line: in a layer of code every "/" that is not a division
	 * after an operand – an identifier that is no keyword, a number, ")",
	 * "]" or the end of a string right before it (Codex, round 24, A-1);
	 * elsewhere a "/" after =, (, ",", :, [, !, &, |, ?, {, }, ; or return,
	 * or at the start of a line (Codex, round 23, A-4). Its braces are
	 * none; the count cannot tell.
	 *
	 * @param string $text Text.
	 * @param bool   $code Whether the text is a layer of code.
	 * @return bool
	 */
	private static function regexp_literal( $text, $code = false ) {
		if ( ! $code ) {
			return 1 === preg_match( '#(?:^|[=(,:\[!&|?{};]|\breturn)\s*/(?:[^/\\\\\n]|\\\\.)+/#m', $text );
		}
		if ( ! preg_match_all( '#(^|\S)\s*/(?:[^/\\\\\n]|\\\\.)+/#m', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		foreach ( $m[1] as $before ) {
			$c = (string) $before[0];
			if ( '' === $c || ! preg_match( '/[A-Za-z0-9_$)\]"\'`]/', $c ) ) {
				return true;
			}
			// An identifier right before it: a keyword is no operand.
			if ( preg_match( '/[A-Za-z0-9_$]/', $c ) && preg_match( '/([A-Za-z_$][A-Za-z0-9_$]*)\z/', substr( $text, 0, (int) $before[1] + 1 ), $w ) && in_array( $w[1], self::KEYWORDS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A text with the content of its strings and its comments blanked (a
	 * space for each byte: the same length), bounded as uncommented()
	 * bounds them, for the braces of code to be counted outside of strings
	 * and comments (Codex, round 22 A-4, round 23 A-4).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function blanked( $text ) {
		$out   = '';
		$quote = '';
		$n     = strlen( $text );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = $text[ $i ];
			if ( '' === $quote && '/' === $c && '*' === substr( $text, $i + 1, 1 ) ) {
				$end  = strpos( $text, '*/', $i + 2 );
				$end  = false === $end ? $n : $end + 2;
				$out .= str_repeat( ' ', $end - $i );
				$i    = $end - 1;
				continue;
			}
			if ( '' === $quote && '/' === $c && '/' === substr( $text, $i + 1, 1 ) ) {
				$end  = $i + strcspn( $text, "\n\r", $i );
				$out .= str_repeat( ' ', $end - $i );
				$i    = $end - 1;
				continue;
			}
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					$out .= '  ';
					++$i;
					continue;
				}
				if ( $c === $quote || ( '`' !== $quote && ( "\n" === $c || "\r" === $c ) ) ) {
					$quote = '';
					$out  .= $c;
					continue;
				}
				$out .= ' ';
				continue;
			}
			if ( '"' === $c || "'" === $c || '`' === $c ) {
				$quote = $c;
			}
			$out .= $c;
		}
		return $out;
	}

	/**
	 * What the data: addresses of a text hold, decoded as the browser
	 * decodes them (the body percent-decoded, then base64 where its
	 * parameters say so), of any type: a text type, or one with a charset,
	 * as UTF-8 by its BOM or charset (utf8()); a body that is then no text
	 * is not read ($bad: the unit locks). Any other type is bytes. A body
	 * that does not decode is no layer (its bytes stand in the text). The
	 * body ends at the quote the address stands in, else at a space, a quote
	 * or ")".
	 *
	 * @param string $text Text.
	 * @param bool   $bad  Set where a body of a text type is no text in UTF-8.
	 * @return string[]
	 */
	private static function data_bodies( $text, &$bad ) {
		if ( ! preg_match_all( '/data:/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $hit ) {
			$head = self::data_header( $text, (int) $hit[1] + 5 );
			// No header (Codex, round 23, A-2: a bracket, no "," within HEAD
			// bytes), a parameter twice (A-3) or a type that is none after
			// the tabs and line breaks are removed (round 24, A-2): not read,
			// and where the address stands as an address (DATA_CONTEXT;
			// round 24, B-1) the unit locks.
			if ( null === $head || false === $head ) {
				if ( preg_match( self::DATA_CONTEXT, (string) substr( $text, 0, (int) $hit[1] ) ) ) {
					$bad = true;
				}
				continue;
			}
			$start = $head['body'];
			$quote = (int) $hit[1] > 0 ? $text[ (int) $hit[1] - 1 ] : '';
			if ( '"' === $quote || "'" === $quote ) {
				$end = strpos( $text, $quote, $start );
				$end = false === $end ? strlen( $text ) : $end;
			} else {
				$end = $start + strcspn( $text, " \t\n\r\f\"')", $start );
			}
			$body = rawurldecode( (string) substr( $text, $start, $end - $start ) );
			if ( $head['base64'] ) {
				$body = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- what the browser decodes, read.
			}
			if ( ! is_string( $body ) || '' === $body ) {
				continue;
			}
			// A text: in UTF-8, or not read.
			$charset = isset( $head['params']['charset'] ) ? strtolower( $head['params']['charset'] ) : null;
			if ( null !== $charset || 1 === preg_match( self::TEXT_TYPES, $head['type'] ) ) {
				$body = self::utf8( $body, $charset, 1 === preg_match( '#^(?:text/|image/svg\+xml\z|[^/]+/(?:[^+]+\+)?xml\z)#', $head['type'] ) );
				if ( null === $body ) {
					$bad = true;
					continue;
				}
			}
			$out[ hash( 'sha256', $body ) ] = $body;
		}
		return array_values( $out );
	}

	/**
	 * The header of a data: address as the MIME Sniffing Standard parses a
	 * MIME type (Codex, round 22, A-2): type/subtype, then parameters at
	 * ";", each name=value with the value as a token or a quoted string (a
	 * backslash escapes a character in it), white space around them
	 * allowed, in any order; "base64" as the last token says the body is
	 * base64 (the fetch standard); an empty type is text/plain. The header
	 * ends at the first "," outside a quoted string; a bracket or a quote
	 * of HTML in it, or no "," within HEAD bytes, is no header (null). Tabs
	 * and line breaks are removed first, as the URL parser removes them
	 * (Codex, round 24, A-2); a type that is then none (white space, a
	 * control character, no "/") is false, as is a parameter twice (Codex,
	 * round 23, A-3: the standard keeps the first, the net decides
	 * nothing). Names and the type in lower case, a value as written.
	 *
	 * @param string $text Text.
	 * @param int    $at   Where the header begins (after "data:").
	 * @return array{type:string,params:array<string,string>,base64:bool,body:int}|null|false
	 */
	private static function data_header( $text, $at ) {
		$n      = min( strlen( $text ), $at + self::HEAD );
		$quoted = false;
		$end    = null;
		$head   = '';
		for ( $i = $at; $i < $n; $i++ ) {
			$c = $text[ $i ];
			if ( "\t" === $c || "\n" === $c || "\r" === $c ) {
				continue;
			}
			$head .= $c;
			if ( $quoted ) {
				if ( '\\' === $c ) {
					$head .= (string) substr( $text, ++$i, 1 );
				} elseif ( '"' === $c ) {
					$quoted = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$quoted = true;
			} elseif ( ',' === $c ) {
				$end = $i;
				break;
			} elseif ( false !== strpos( "<>()'", $c ) ) {
				return null;
			}
		}
		if ( null === $end ) {
			return null;
		}
		$head   = (string) substr( $head, 0, -1 );
		$parts  = array();
		$part   = '';
		$quoted = false;
		$m      = strlen( $head );
		for ( $i = 0; $i < $m; $i++ ) {
			$c = $head[ $i ];
			if ( $quoted ) {
				if ( '\\' === $c && $i + 1 < $m ) {
					$part .= $head[ ++$i ];
					continue;
				}
				$quoted = '"' !== $c;
				if ( $quoted ) {
					$part .= $c;
				}
				continue;
			}
			if ( '"' === $c ) {
				$quoted = true;
			} elseif ( ';' === $c ) {
				$parts[] = $part;
				$part    = '';
			} else {
				$part .= $c;
			}
		}
		$parts[] = $part;
		$type    = strtolower( trim( (string) array_shift( $parts ) ) );
		if ( '' !== $type && ! preg_match( '#^[A-Za-z0-9!\#$&^_.+\-]+/[A-Za-z0-9!\#$&^_.+\-]+\z#', $type ) ) {
			return false;
		}
		$params  = array();
		$base64  = false;
		foreach ( $parts as $i => $param ) {
			$param = trim( $param );
			$eq    = strpos( $param, '=' );
			if ( false === $eq ) {
				$base64 = $base64 || ( $i === count( $parts ) - 1 && 'base64' === strtolower( $param ) );
				continue;
			}
			$key = strtolower( trim( substr( $param, 0, $eq ) ) );
			if ( isset( $params[ $key ] ) ) {
				return false;
			}
			$params[ $key ] = trim( substr( $param, $eq + 1 ) );
		}
		return array(
			'type'   => '' === $type ? 'text/plain' : $type,
			'params' => $params,
			'base64' => $base64,
			'body'   => $end + 1,
		);
	}

	/**
	 * The spans of base64 in a text (BASE64) whose bytes are a text: UTF-8,
	 * or UTF-16 or UTF-32 by a BOM (utf8()); one with a BOM that is then no
	 * text is not read ($bad); bytes that are no text (a binary) are no
	 * layer.
	 *
	 * @param string $text Text.
	 * @param bool   $bad  Set where a span with a BOM is no text in UTF-8.
	 * @return string[]
	 */
	private static function base64_spans( $text, &$bad ) {
		if ( ! preg_match_all( self::BASE64, $text, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $span ) {
			if ( strlen( $span ) < self::MIN_BASE64 ) {
				continue;
			}
			$bytes = base64_decode( $span, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a layer of text, read.
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				continue;
			}
			$utf8 = self::utf8( $bytes, null );
			if ( null === $utf8 ) {
				if ( null !== self::bom( $bytes ) ) {
					$bad = true;
				}
				continue;
			}
			$out[ hash( 'sha256', $utf8 ) ] = $utf8;
		}
		return array_values( $out );
	}

	/**
	 * Bytes as a text in UTF-8: by their BOM (UTF-8, UTF-16LE/BE,
	 * UTF-32LE/BE), else by the charset given (UTF-16 as the Encoding
	 * Standard reads the label: little-endian; ISO-8859-1, Windows-1252, the
	 * labels of UTF-8 and ASCII as they are), else as they are; null where
	 * they are no text in UTF-8 after that (a null byte, invalid bytes, a
	 * charset that is not known), or where no function transcodes
	 * (mb_convert_encoding(), iconv(): fail closed).
	 *
	 * For XML (an SVG, a text type; Codex, round 22, A-2) the first bytes of
	 * a document without a BOM as Appendix F of the XML specification
	 * guesses them (XML_STARTS) come before the charset given, and the
	 * encoding="…" of its declaration after it.
	 *
	 * @param string      $bytes   Bytes.
	 * @param string|null $charset The charset declared, in lower case, or null.
	 * @param bool        $xml     Whether the bytes are XML (a text type, an SVG).
	 * @return string|null
	 */
	public static function utf8( $bytes, $charset, $xml = false ) {
		$from = null;
		$bom  = self::bom( $bytes );
		if ( null !== $bom ) {
			$from  = $bom[0];
			$bytes = (string) substr( $bytes, $bom[1] );
		} elseif ( $xml ) {
			foreach ( self::XML_STARTS as $start => $guess ) {
				if ( 0 === strncmp( $bytes, $start, strlen( $start ) ) ) {
					$from = $guess;
					break;
				}
			}
		}
		if ( null === $from && null !== $charset && '' !== $charset ) {
			if ( ! isset( self::LABELS[ $charset ] ) ) {
				return null;
			}
			$from = self::LABELS[ $charset ];
		}
		if ( null === $from && $xml && preg_match( '/^<\?xml[^>]*?\sencoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/', $bytes, $m ) ) {
			$label = strtolower( $m[1] );
			if ( ! isset( self::LABELS[ $label ] ) ) {
				return null;
			}
			$from = self::LABELS[ $label ];
		}
		if ( null === $from || 'UTF-8' === $from ) {
			return self::is_text( $bytes ) ? $bytes : null;
		}
		$text = self::transcoded( $bytes, $from );
		return null !== $text && self::is_text( $text ) ? $text : null;
	}

	/**
	 * @param string $bytes Bytes.
	 * @return array{0:string,1:int}|null The charset a BOM gives and its length.
	 */
	private static function bom( $bytes ) {
		foreach ( array(
			"\xFF\xFE\x00\x00" => 'UTF-32LE',
			"\x00\x00\xFE\xFF" => 'UTF-32BE',
			"\xFF\xFE"         => 'UTF-16LE',
			"\xFE\xFF"         => 'UTF-16BE',
			"\xEF\xBB\xBF"     => 'UTF-8',
		) as $mark => $charset ) {
			if ( 0 === strncmp( $bytes, $mark, strlen( $mark ) ) ) {
				return array( $charset, strlen( $mark ) );
			}
		}
		return null;
	}

	/**
	 * @param string $bytes Bytes.
	 * @param string $from  Charset.
	 * @return string|null In UTF-8; null where no function transcodes or it fails.
	 */
	private static function transcoded( $bytes, $from ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			// Bytes that are no text of the charset give no text (the
			// conversion would put a "?" in their place).
			if ( ! mb_check_encoding( $bytes, $from ) ) {
				return null;
			}
			$out = @mb_convert_encoding( $bytes, 'UTF-8', $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a charset the extension does not know gives null below.
			return is_string( $out ) ? $out : null;
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( $from, 'UTF-8', $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			return is_string( $out ) ? $out : null;
		}
		return null;
	}

	/**
	 * @param string $bytes Bytes.
	 * @return bool Whether they are a text: UTF-8 without a null byte.
	 */
	private static function is_text( $bytes ) {
		return false === strpos( $bytes, "\0" ) && 1 === preg_match( '//u', $bytes );
	}

	/**
	 * The text of a stored value: the value itself, or the texts of an
	 * unpacked structure, one per line.
	 *
	 * @param mixed $raw Value.
	 * @return string
	 */
	private static function text( $raw ) {
		if ( is_string( $raw ) ) {
			return $raw;
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$out = array();
		array_walk_recursive(
			$raw,
			static function ( $leaf ) use ( &$out ) {
				if ( is_string( $leaf ) && '' !== $leaf ) {
					$out[] = $leaf;
				}
			}
		);
		return implode( "\n", $out );
	}

	/* ------------------------------------------------------------- count */

	/**
	 * The (space, id) pairs the addresses of a layer name (see the header),
	 * as space:id, an id in a spelling that is not read as space:?; a pair
	 * as often as it is named.
	 *
	 * @param string $text A layer.
	 * @param array  $ctx  Context.
	 * @return string[]
	 */
	public static function references( $text, array $ctx ) {
		$text = (string) $text;
		// Without a digit from 1 to 9 no address names an object: not asked
		// (the readers answer nothing for each; this spares the asking).
		if ( ! preg_match( '/[1-9]/', $text ) ) {
			return array();
		}
		$pairs = array();
		$spans = array();
		// The address tokens first: from every host to the end of an
		// address, read whole (a host of no site names nothing, whatever
		// follows its "#").
		// Every host, and an address of SCHEMES (mailto:?body=…; Codex, round
		// 23, A-1): a host of no site names the values of its query.
		foreach ( array( AB_MCP_Sync_Urls::HOST, AB_MCP_Sync_Urls::SCHEMES ) as $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $host ) {
				$at = (int) $host[1];
				if ( self::inside( $at, $spans ) ) {
					continue;
				}
				$url     = self::extent( $text, $at );
				$spans[] = array( $at, $at + strlen( $url ) );
				$pairs   = array_merge( $pairs, self::pairs( $url, $ctx ) );
			}
		}
		// Outside the tokens: every root-relative address (as a text node is
		// read, AB_MCP_Sync_Urls::ROOT), with its path and its query.
		if ( preg_match_all( AB_MCP_Sync_Urls::ROOT, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $start ) {
				$at = (int) $start[1];
				if ( self::inside( $at, $spans ) ) {
					continue;
				}
				$url     = self::extent( $text, $at );
				$spans[] = array( $at, $at + strlen( $url ) );
				$pairs   = array_merge( $pairs, self::pairs( $url, $ctx ) );
			}
		}
		// And every query, whatever stands before its "?" or "&": read as the
		// query of an address relative to the page.
		if ( preg_match_all( self::QUERY, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $start ) {
				$at = (int) $start[1];
				if ( self::inside( $at, $spans ) ) {
					continue;
				}
				$query = self::extent( $text, $at );
				$pairs = array_merge( $pairs, self::pairs( '?' . substr( $query, 1 ), $ctx ) );
			}
		}
		return $pairs;
	}

	/**
	 * @param string $url An address, to its end.
	 * @param array  $ctx Context.
	 * @return string[] Its pairs (AB_MCP_Sync_Urls::pairs()); none without a digit from 1 to 9.
	 */
	private static function pairs( $url, array $ctx ) {
		return preg_match( '/[1-9]/', $url ) ? AB_MCP_Sync_Urls::pairs( $url, $ctx ) : array();
	}

	/**
	 * An address from an offset to its end (AB_MCP_Sync_Urls::EXTENT), the
	 * punctuation after it none of it (as the prose is read).
	 *
	 * @param string $text Text.
	 * @param int    $at   Offset.
	 * @return string
	 */
	private static function extent( $text, $at ) {
		preg_match( AB_MCP_Sync_Urls::EXTENT, $text, $m, 0, $at );
		return (string) preg_replace( '/[.,;:!)\]]+\z/', '', $m[0] );
	}

	/**
	 * @param int   $at    Offset.
	 * @param array $spans Spans (start, end).
	 * @return bool Whether it lies in one.
	 */
	private static function inside( $at, array $spans ) {
		foreach ( $spans as $span ) {
			if ( $at >= $span[0] && $at < $span[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The names of credentials with a value beside them in a layer (see the
	 * header), normalised, each once.
	 *
	 * @param string   $text  A layer.
	 * @param string[] $extra AB_MCP_Redactor::extra_keys().
	 * @return string[]
	 */
	public static function credentials( $text, array $extra, &$undecidable = null, $code = false ) {
		$text  = (string) $text;
		$found = array();
		foreach ( array(
			'/(?<![A-Za-z0-9_\-.\x80-\xFF])(' . self::NAME . ')["\'`]?\s*\]?\s*=(?!=)>?\s*' . self::VALUE . '/',
			'/["\'`](' . self::NAME . ')["\'`]\s*:\s*' . self::VALUE . '/',
			'/\{\s*-{0,2}(' . self::NAME . ')\s*:\s*' . self::VALUE . '/',
			'/^\s*(' . self::NAME . ')\s*:\s*["\'`]\s*[^"\'`\s]/',
		) as $pattern ) {
			if ( preg_match_all( $pattern, $text, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
		}
		// In code a name after "," or ";" as well: where a "{" is open before
		// it (in prose, «Name, Token: "abc"» is none), the braces counted
		// with the strings blanked (a "}" in a string closes none; Codex,
		// round 22, A-4). Where the count cannot decide – more closed than
		// opened, or a RegExp literal before the name, whose braces are
		// none (Codex, round 23, A-4) – it is undecidable: the net locks.
		if ( preg_match_all( '/[,;]\s*-{0,2}(' . self::NAME . ')\s*:\s*' . self::VALUE . '/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$blanked = self::blanked( $text );
			foreach ( $m[1] as $hit ) {
				$before = (string) substr( $blanked, 0, (int) $hit[1] );
				$open   = substr_count( $before, '{' ) - substr_count( $before, '}' );
				if ( $open > 0 ) {
					$found[] = $hit[0];
				} elseif ( $open < 0 || self::regexp_literal( $before, $code ) ) {
					$undecidable = true;
				}
			}
		}
		// A name as the value of a field name or key, a field value or
		// secret beside it.
		if ( preg_match_all( '/(?<![A-Za-z0-9_])(?:name|key)["\'`]?\s*[:=]>?\s*["\'`]?(' . self::NAME . ')/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$from   = max( 0, (int) $hit[1] - self::WINDOW );
				$window = (string) substr( $text, $from, (int) $hit[1] - $from + strlen( $hit[0] ) + self::WINDOW );
				if ( preg_match( '/(?<![A-Za-z0-9_])(?:value|secret)["\'`]?\s*[:=]>?\s*' . self::VALUE . '/i', $window ) ) {
					$found[] = $hit[0];
				}
			}
		}
		$out = array();
		foreach ( $found as $name ) {
			if ( AB_MCP_Sync_Credentials::names_credential( (string) $name, $extra ) ) {
				$out[ AB_MCP_Redactor::normalize_key( (string) $name ) ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * The names a field path of the credential check names, normalised:
	 * the path as a whole (an extra name with a dot) and each part at "."
	 * and "]", without the "~" of a flat key.
	 *
	 * @param string $field Path (0.api_key, ~token, raw.nested.clientSecret).
	 * @return string[]
	 */
	private static function field_names( $field ) {
		$names = array( $field );
		foreach ( preg_split( '/[.\]]/', $field ) as $part ) {
			$names[] = $part;
		}
		$out = array();
		foreach ( $names as $name ) {
			$name = ltrim( $name, '~' );
			if ( '' !== $name ) {
				$out[] = AB_MCP_Redactor::normalize_key( $name );
			}
		}
		return array_values( array_unique( $out ) );
	}
}
