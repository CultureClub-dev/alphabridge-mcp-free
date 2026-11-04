<?php
/**
 * The net over the raw value (Codex, rounds 18 to 20; Thomas, 04.11.2026):
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
 * (\/, \uXXXX), of C (stripcslashes(), what shortcode_parse_atts() takes),
 * of CSS (AB_MCP_Sync_Css::unescaped()), percent escapes); every data:
 * address decoded (base64 and percent, of any type); every span of base64
 * of MIN_BASE64 characters or more whose bytes are UTF-8; each of these
 * again (a decoded string may hold a data: address of its own), to
 * MAX_DEPTH derivations from the raw text and MAX_LAYERS layers of one unit.
 * Beyond, the unit is not read to its end (AB_MCP_Sync_Canon::too_deep():
 * *:unreadable_value, incomplete). Without WP_HTML_Decoder no character
 * reference is decoded here: a text with one fails closed in the credential
 * check already (AB_MCP_Sync_Credentials::decodings()).
 *
 * COUNT, in every layer:
 * - references(): every address of the sites that names an object by its
 *   id, read as AB_MCP_Sync_Urls reads one address (ids_in(): the query
 *   variables of WP_Query through parse_str(), the paths through the
 *   rewrite rules of the site, WP_MatchesMapRegex), rewritable or locked.
 *   An address stands with a host (AB_MCP_Sync_Urls::HOST: with its scheme
 *   or without, also \/\/ of JSON, so every form of a site's address the
 *   replace engine knows and every other spelling of a site's host) or
 *   root-relative (START: a "/" or "?" after a character that stands in no
 *   address: a quote, a space, a bracket, "=", ":", ",", ";" or the start
 *   of the text), each to the end of an address (AB_MCP_Sync_Urls::EXTENT,
 *   as the prose is read). An address without a digit from 1 to 9 names no
 *   object and is not asked; an address of a site without an id counts for
 *   nothing.
 * - credentials(): every name AB_MCP_Sync_Credentials::names_credential()
 *   names with a value beside it: name=value (also name']=value, name =>
 *   value), "name":value, 'name':value, in code (name:value right after
 *   "{", or after "," or ";" where a "{" is open before it: the object of
 *   a script, a block of CSS, also --name), at the start of the text
 *   name:"value" with the value in quotes, and a name as the value of a
 *   field name or key with a field value or secret within WINDOW bytes. A
 *   name without a value, a word in prose ("design tokens", "Token: x",
 *   «Name, Token: "abc"» outside of braces) counts for nothing.
 *
 * COMPARE (check()): the structured readers of the unit noted every id of
 * an address they read (AB_MCP_Sync_Canon::text(): $acc['addressed'],
 * rewritten or locked) and every field of a credential
 * (credentials.mixed). Where a layer counts more ids than they read, and
 * no lock of the unit stands yet, the unit locks (*:unread_reference, at
 * <unit>:net:<layer>); where a layer holds the name of a credential they
 * did not name, and the unit is readable, it locks (*:unread_credential),
 * the name noted among the credentials, never a value. A unit the readers
 * locked is not taken over: there the net adds nothing for the references.
 * A unit they could not read (an object, a script, HTML outside the list
 * of kses, nested deeper than read) is never clean: there it adds nothing
 * for the credentials.
 *
 * The net only locks. Rewriting stays with the structured readers, in the
 * spellings they read as the core reads them.
 *
 * KNOWN LIMITS (group C of the rounds: rare spellings that lock, never
 * free):
 * - the JSON of a plugin in a data-* attribute other than data-wp-* holds
 *   an address with an id (Fancybox data-options): locked, not rewritten
 *   (the core reads no JSON there; which plugin does, only its code says);
 * - a key twice in JSON whose dropped value holds an address with an id or
 *   a credential: locked;
 * - a string of CSS (a custom property) with an address with an id: locked,
 *   rewritten only in url(), image-set() and @import;
 * - an address with an id in a title, an alt, a tooltip, a comment of HTML,
 *   in the value of a query variable (?redirect_to=/?p=12, also
 *   percent-encoded) or in prose as a root-relative path the rules of the
 *   site route to an object: locked, not rewritten;
 * - a credential with its value in a layer no reader of the core reads
 *   (base64, a data: address of any type, an SVG image, a script of an
 *   image): locked.
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
	 * Where a root-relative address or a query may begin: a "/" or "?"
	 * after a character that stands in no address and continues no word
	 * (a backslash escapes it in JSON: that address stands in the layer of
	 * the escapes of JSON decoded).
	 */
	const START = '#(?<![A-Za-z0-9_.~%\-/?\\\\])[/?]#';

	/** A span of base64: the alphabet and its padding, MIN_BASE64 or more in all (base64_spans()). */
	const BASE64 = '#(?<![A-Za-z0-9+/=])[A-Za-z0-9+/]{8,}={0,2}(?![A-Za-z0-9+/=])#';

	/** A data: address up to its ",": its type and parameters before it. */
	const DATA = '#data:([^,"\'()<>\s]{0,256}),#i';

	/** A name as a key is spelled: as FLAT_KEYS of the credential check reads one. */
	const NAME = '[A-Za-z0-9_\x80-\xFF][A-Za-z0-9_\-.\x80-\xFF]{0,255}';

	/**
	 * A value beside a name: in quotes a character after the quote, else a
	 * character that ends no value (a space, "&", ";", ",", a bracket, ">").
	 */
	const VALUE = '(?:["\'][^"\'\s]|[^\s"\'&;,)}\]>])';

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
		// never clean), the ids of the addresses they read, the names of the
		// credentials they named.
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
		$read  = count( $acc['addressed'] ) - $mark['addressed'];
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
		$layers = self::layers( $text, $deep );
		foreach ( $layers as $layer ) {
			list( $path, $one ) = $layer;
			if ( ! $locked && self::references( $one, $ctx ) > $read ) {
				AB_MCP_Sync_Canon::lock( $acc, (string) $kind, 'unread_reference', $at . ':net:' . $path );
				$locked = true;
			}
			if ( $unreadable ) {
				continue;
			}
			$new = array();
			foreach ( self::credentials( $one, $extra ) as $name ) {
				if ( ! isset( $named[ $name ] ) ) {
					$new[ $name ] = '~' . $name;
				}
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
		if ( $deep ) {
			AB_MCP_Sync_Canon::too_deep( $acc, $at . ':net', (string) $kind );
		}
	}

	/* ------------------------------------------------------------ unfold */

	/**
	 * The layers of text a stored value holds (see the header), each with
	 * the path of its derivation (raw, raw/html, raw/data/base64 …).
	 *
	 * @param string $text The raw text.
	 * @param bool   $deep Set where a derivation beyond MAX_DEPTH or beyond
	 *                     MAX_LAYERS was not followed.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function layers( $text, &$deep = false ) {
		$deep = false;
		$seen = array();
		$out  = array();
		self::unfold( (string) $text, 'raw', 0, $seen, $out, $deep );
		return $out;
	}

	/**
	 * @param string $text  A layer.
	 * @param string $path  Its derivation.
	 * @param int    $depth Derivations from the raw text.
	 * @param array  $seen  The layers read, by their hash.
	 * @param array  $out   The layers.
	 * @param bool   $deep  See layers().
	 */
	private static function unfold( $text, $path, $depth, array &$seen, array &$out, &$deep ) {
		if ( count( $out ) >= self::MAX_LAYERS ) {
			$deep = true;
			return;
		}
		$seen[ hash( 'sha256', $text ) ] = true;
		$out[]                           = array( $path, $text );
		// What the text holds first (a data: address, base64), its decodings
		// after: a lock names the nearer layer.
		$more = array();
		foreach ( self::data_bodies( $text ) as $body ) {
			$more[] = array( $path . '/data', $body );
		}
		foreach ( self::base64_spans( $text ) as $span ) {
			$more[] = array( $path . '/base64', $span );
		}
		foreach ( self::decoded( $text ) as $name => $variant ) {
			$more[] = array( $path . '/' . $name, $variant );
		}
		// A layer read already (a decoding that changed nothing among them)
		// is read once, and is no derivation at the depth.
		foreach ( $more as $next ) {
			if ( isset( $seen[ hash( 'sha256', $next[1] ) ] ) ) {
				continue;
			}
			if ( $depth >= self::MAX_DEPTH ) {
				$deep = true;
				return;
			}
			self::unfold( $next[1], $next[0], $depth + 1, $seen, $out, $deep );
		}
	}

	/**
	 * A text as each decoding the core knows gives it, by its name.
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
			$out['slashes'] = stripcslashes( $text );
			$out['css']     = AB_MCP_Sync_Css::unescaped( $text );
		}
		if ( false !== strpos( $text, '%' ) ) {
			$out['percent'] = rawurldecode( $text );
		}
		return $out;
	}

	/**
	 * What the data: addresses of a text hold, decoded as the browser
	 * decodes them (the body percent-decoded, then base64 where its
	 * parameters say so), of any type; a body that does not decode is no
	 * layer (its bytes stand in the text). The body ends at the quote the
	 * address stands in, else at a space, a quote or ")".
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function data_bodies( $text ) {
		if ( false === stripos( $text, 'data:' ) || ! preg_match_all( self::DATA, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $hit ) {
			$start = (int) $hit[0][1] + strlen( $hit[0][0] );
			$quote = (int) $hit[0][1] > 0 ? $text[ (int) $hit[0][1] - 1 ] : '';
			if ( '"' === $quote || "'" === $quote ) {
				$end = strpos( $text, $quote, $start );
				$end = false === $end ? strlen( $text ) : $end;
			} else {
				$end = $start + strcspn( $text, " \t\n\r\f\"')", $start );
			}
			$body   = rawurldecode( (string) substr( $text, $start, $end - $start ) );
			$params = array_map( 'trim', explode( ';', strtolower( (string) $hit[1][0] ) ) );
			if ( in_array( 'base64', $params, true ) ) {
				$body = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- what the browser decodes, read.
			}
			if ( is_string( $body ) && '' !== $body ) {
				$out[ hash( 'sha256', $body ) ] = $body;
			}
		}
		return array_values( $out );
	}

	/**
	 * The spans of base64 in a text (BASE64) whose bytes are UTF-8.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function base64_spans( $text ) {
		if ( ! preg_match_all( self::BASE64, $text, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $span ) {
			if ( strlen( $span ) < self::MIN_BASE64 ) {
				continue;
			}
			$bytes = base64_decode( $span, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a layer of text, read.
			if ( is_string( $bytes ) && '' !== $bytes && 1 === preg_match( '//u', $bytes ) ) {
				$out[ hash( 'sha256', $bytes ) ] = $bytes;
			}
		}
		return array_values( $out );
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
	 * How many ids the addresses of a layer name (see the header).
	 *
	 * @param string $text A layer.
	 * @param array  $ctx  Context.
	 * @return int
	 */
	public static function references( $text, array $ctx ) {
		$text = (string) $text;
		// Without a digit from 1 to 9 no address names an object: not asked
		// (the readers answer 0 for each; this spares the asking).
		if ( ! preg_match( '/[1-9]/', $text ) ) {
			return 0;
		}
		// Every host (HOST: with its scheme or without, also \/\/ of JSON:
		// every form of a site's address the replace engine knows), and
		// every root-relative start (START) outside a host.
		$hosts = array();
		if ( preg_match_all( AB_MCP_Sync_Urls::HOST, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $host ) {
				$hosts[ (int) $host[1] ] = strlen( $host[0] );
			}
		}
		$starts = array_keys( $hosts );
		if ( preg_match_all( self::START, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $start ) {
				$at = (int) $start[1];
				foreach ( $hosts as $host_at => $len ) {
					if ( $at >= $host_at && $at < $host_at + $len ) {
						continue 2;
					}
				}
				$starts[] = $at;
			}
		}
		$count = 0;
		foreach ( $starts as $at ) {
			preg_match( AB_MCP_Sync_Urls::EXTENT, $text, $m, 0, $at );
			$url = (string) preg_replace( '/[.,;:!)\]]+\z/', '', $m[0] );
			if ( ! preg_match( '/[1-9]/', $url ) ) {
				continue;
			}
			$count += AB_MCP_Sync_Urls::ids_in( $url, $ctx );
		}
		return $count;
	}

	/**
	 * The names of credentials with a value beside them in a layer (see the
	 * header), normalised, each once.
	 *
	 * @param string   $text  A layer.
	 * @param string[] $extra AB_MCP_Redactor::extra_keys().
	 * @return string[]
	 */
	public static function credentials( $text, array $extra ) {
		$text  = (string) $text;
		$found = array();
		foreach ( array(
			'/(?<![A-Za-z0-9_\-.\x80-\xFF])(' . self::NAME . ')["\']?\]?\s*=(?!=)>?\s*' . self::VALUE . '/',
			'/["\'](' . self::NAME . ')["\']\s*:\s*' . self::VALUE . '/',
			'/\{\s*-{0,2}(' . self::NAME . ')\s*:\s*' . self::VALUE . '/',
			'/^\s*(' . self::NAME . ')\s*:\s*["\'][^"\'\s]/',
		) as $pattern ) {
			if ( preg_match_all( $pattern, $text, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
		}
		// In code a name after "," or ";" as well: where a "{" is open before
		// it (in prose, «Name, Token: "abc"» is none).
		if ( preg_match_all( '/[,;]\s*-{0,2}(' . self::NAME . ')\s*:\s*' . self::VALUE . '/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$before = (string) substr( $text, 0, (int) $hit[1] );
				if ( substr_count( $before, '{' ) > substr_count( $before, '}' ) ) {
					$found[] = $hit[0];
				}
			}
		}
		// A name as the value of a field name or key, a field value or
		// secret beside it.
		if ( preg_match_all( '/(?<![A-Za-z0-9_])(?:name|key)["\']?\s*[:=]>?\s*["\']?(' . self::NAME . ')/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$from   = max( 0, (int) $hit[1] - self::WINDOW );
				$window = (string) substr( $text, $from, (int) $hit[1] - $from + strlen( $hit[0] ) + self::WINDOW );
				if ( preg_match( '/(?<![A-Za-z0-9_])(?:value|secret)["\']?\s*[:=]>?\s*' . self::VALUE . '/i', $window ) ) {
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
