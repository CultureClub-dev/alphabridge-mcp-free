<?php
/**
 * Credentials out of what the reading tools answer.
 *
 * A reading tool hands on what other plugins stored: meta values, widget
 * settings, cron arguments, the results of their abilities, the lines they
 * wrote into debug.log. Some of that is an API key or a token under an
 * ordinary name, or inside an ordinary value: an array, a serialized array,
 * a JSON document. Where a meta KEY looks like a credential, the meta tools
 * leave it out (is_sensitive_meta_key()); this class looks inside the values
 * that pass and replaces what sits under such a key with REDACTED, the key
 * itself staying visible. The answer names every place in its field
 * `redacted`, as a JSON pointer (RFC 6901), never with the value.
 *
 * One walk for everything: arrays and objects by their keys; a text that is
 * a JSON document or serialized data is decoded and walked the same way, and
 * encoded again only where something in it was redacted (otherwise it stays
 * byte for byte). Texts people wrote (CONTENT_FIELDS) are not decoded: a post
 * read with REDACTED in it and saved again would lose its text for good.
 * Free text (headers, name=value lines, print_r() and var_export() dumps) is
 * searched in the error log only, line by line.
 *
 * Fail-closed where a value cannot be judged: deeper than MAX_DEPTH, beyond
 * the node budget, a pattern that gives up, an object that throws. Such a
 * value reads as REDACTED as a whole and is listed in `redacted_unchecked`.
 *
 * Applied in AB_MCP_REST_Controller::run_tool() to every tool the registry
 * marks read-only and to the plugin's part of wp_run_ability's answer; for
 * every other tool, run_tool() refuses arguments that carry the placeholder
 * back (placeholder_in()), so it never replaces a stored credential.
 *
 * The meta guard (AB_MCP_Tools_Base::is_sensitive_meta_key()) and the SQL
 * mirror of the search-and-replace scan read the two lists of this class.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Redactor
 */
final class AB_MCP_Redactor {

	/** What a redacted value reads as. */
	const MARK = '[REDACTED]';

	/** The field of the answer that names what was redacted. */
	const FIELD = 'redacted';

	/** The whole number of paths, set only where FIELD lists fewer. */
	const FIELD_COUNT = 'redacted_count';

	/** The paths that were replaced whole because they could not be judged. */
	const FIELD_UNCHECKED = 'redacted_unchecked';

	/** At most this many paths in FIELD and FIELD_UNCHECKED each. */
	const MAX_PATHS = 50;

	/**
	 * Bare credential names, matched as the WHOLE key and never as a
	 * substring. A key called exactly "token" or "secret" is a credential in
	 * practice, but the same word inside a longer name usually is not:
	 * matching these as substrings would wrongly refuse token_count,
	 * password_hint or credential_type. Plurals are listed explicitly.
	 */
	const CREDENTIAL_EXACT = array( 'pass', 'token', 'tokens', 'secret', 'secrets', 'password', 'passwords', 'passphrase', 'passphrases', 'passcode', 'passcodes', 'pwd', 'pwds', 'otp', 'otps', 'credential', 'credentials' );

	/**
	 * High-precision compound credential patterns, matched anywhere in the
	 * key as written: these virtually never occur in legitimate content meta.
	 * Removing separators would turn co_author into "coauthor", which
	 * contains "oauth"; the guard therefore stays literal.
	 */
	const CREDENTIAL_NEEDLES = array( 'api_key', 'apikey', 'api-key', 'api_token', 'api_secret', 'access_key', 'access_token', 'secret_key', 'secret_token', 'private_key', 'client_secret', 'consumer_key', 'consumer_secret', 'refresh_token', 'auth_token', 'bearer_token', 'license_key', 'oauth', '_token', '_secret', '_password', 'passwd', 'smtp_pass' );

	/**
	 * Names redacted on top of the meta rule, after normalize_key(): the HTTP
	 * headers that carry a credential, a bare "auth", and the two columns of
	 * wp_users that hold a password hash and a reset key. Not part of the
	 * meta guard.
	 */
	const EXTRA_NAMES = array( 'authorization', 'proxy_authorization', 'auth', 'user_pass', 'user_activation_key' );

	/**
	 * First words of a normalised name that say something ABOUT a credential
	 * rather than hold one: hasPassword, totalTokens, showPassword.
	 */
	const ABOUT = array( 'has', 'is', 'total', 'num', 'count', 'max', 'min', 'use', 'show', 'enable', 'require' );

	/**
	 * Pairs that hold a name in one field and its value in the other:
	 * WooCommerce's meta_data ({key, value}), rows of the meta and options
	 * tables, form fields. The value is redacted where the name is a
	 * credential's.
	 */
	const PAIRS = array(
		'key'         => 'value',
		'name'        => 'value',
		'meta_key'    => 'meta_value',
		'option_name' => 'option_value',
	);

	/**
	 * Places in a reading tool's own answer that hold a JSON schema, not
	 * data: an ability's schema names a property "password" without holding
	 * one. Not searched. A path of keys, '*' for any one key.
	 */
	const SCHEMAS = array(
		'wp_list_abilities' => array( 'ability/input_schema', 'ability/output_schema', 'abilities/*/input_schema', 'abilities/*/output_schema' ),
	);

	/**
	 * Places in a reading tool's own answer that look like a credential by
	 * name and are none: the undo tokens wp_undo_list hands out for wp_undo,
	 * the list of application passwords, which names them and never holds
	 * one. What lies below is judged as everywhere else.
	 */
	const KEEP = array(
		'wp_undo_list'                  => array( 'undo_points/*/token' ),
		'wp_list_application_passwords' => array( 'passwords' ),
	);

	/** Tools whose text is searched for credentials as free text too. */
	const TEXT_TOOLS = array( 'wp_get_error_log' );

	/**
	 * Tools whose texts are not decoded: a file or an export as it is
	 * stored, the answer being how the file gets edited, and a page-builder
	 * layout, which comes as a structure already and whose texts are the
	 * page's content (a text widget with "password": … in it). Keys are
	 * judged there as everywhere.
	 */
	const NO_DECODE = array( 'wp_read_theme_file', 'wp_read_plugin_file', 'wp_read_upload_file', 'wp_deploy_read', 'wp_export_content', 'wp_get_builder_layout' );

	/**
	 * Fields that hold what people wrote: the text, excerpt and title of a
	 * post, a revision or a comment, raw and rendered. Their text is not
	 * decoded (a tutorial with "password": "…" in it would lose its example
	 * when saved again), and run_tool() does not look for the placeholder in
	 * them. Keys of an array below such a field are judged as everywhere.
	 */
	const CONTENT_FIELDS = array( 'content', 'content_raw', 'post_content', 'post_content_filtered', 'excerpt', 'post_excerpt', 'title', 'post_title', 'comment_content', 'rendered', 'raw' );

	/**
	 * Writing tools of which one part of the answer is another plugin's data,
	 * and which part.
	 */
	const PARTS = array( 'wp_run_ability' => array( 'result' ) );

	/**
	 * Writing tools that put the stored value back for the placeholder
	 * themselves (restore_placeholders()); run_tool() lets their arguments
	 * through.
	 */
	const RESTORES = array();

	/**
	 * Writing tools whose arguments are search patterns and replacement text
	 * for content, never a value read back from an answer: replacing a
	 * "[REDACTED]" that stands in a post is a legitimate call.
	 */
	const PATTERN_TOOLS = array( 'wp_search_replace' );

	/** Nesting deeper than this is not judged (fail-closed). */
	const MAX_DEPTH = 100;

	/**
	 * Arrays, objects and decoded texts one answer may visit. A large
	 * page-builder layout comes to a few thousand; a cycle of references, as
	 * unserialize() builds from R:, never ends without it.
	 */
	const BUDGET = 20000;

	/** Authentication schemes an Authorization header names before its credential. */
	const SCHEMES = array( 'bearer', 'basic', 'digest', 'negotiate', 'ntlm', 'token', 'hoba', 'mutual', 'aws4-hmac-sha256' );

	/* ------------------------------------------------------------ the rule */

	/**
	 * Whether a key looks like a stored credential: the rule of the meta
	 * guard, as written.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_credential_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) {
			return false;
		}
		if ( in_array( $key, self::CREDENTIAL_EXACT, true ) ) {
			return true;
		}
		foreach ( self::CREDENTIAL_NEEDLES as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A key in the form redaction compares: without the prefix PHP gives a
	 * private or protected property, without blanks around it (Unicode ones
	 * too), camelCase split (accessToken → access_token, APIKey → api_key),
	 * dashes and blanks as "_", in lower case.
	 *
	 * @param string|int $key Key.
	 * @return string
	 */
	public static function normalize_key( $key ) {
		$key = (string) $key;
		if ( "\0" === substr( $key, 0, 1 ) ) {
			$end = strpos( $key, "\0", 1 );
			$key = false === $end ? substr( $key, 1 ) : substr( $key, $end + 1 );
		}
		$trimmed = preg_replace( '/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $key );
		$key     = is_string( $trimmed ) ? $trimmed : trim( $key );
		$key     = (string) preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key );
		$key     = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) );
		$joined  = preg_replace( '/[\s\p{Z}\-]+/u', '_', $key );
		return is_string( $joined ) ? $joined : str_replace( array( '-', ' ' ), '_', $key );
	}

	/**
	 * The names a site redacts on top of the built-in ones, normalised.
	 *
	 * @return string[]
	 */
	public static function extra_keys() {
		/**
		 * Filters the keys whose values the reading tools redact, on top of
		 * the built-in credential names. Each entry is matched as the whole
		 * key, in the spellings normalize_key() reads alike. The built-in
		 * names cannot be taken out here.
		 *
		 * @since 4.13.0
		 *
		 * @param string[] $keys Additional key names, empty by default.
		 */
		$keys = apply_filters( 'ab_mcp_redact_keys', array() );
		$out  = array();
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			if ( is_string( $key ) && '' !== self::normalize_key( $key ) ) {
				$out[] = self::normalize_key( $key );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a value under this key is redacted: every key the meta guard
	 * refuses as written, and in the normalised form a bare credential name
	 * as the last word (userPassword) or a compound one (accessToken), unless
	 * the first word says something about it (hasPassword, totalTokens).
	 *
	 * @param string|int $key   Key.
	 * @param string[]   $extra extra_keys().
	 * @return bool
	 */
	public static function redacts_key( $key, array $extra = array() ) {
		if ( is_int( $key ) ) {
			return false;
		}
		if ( self::is_credential_key( $key ) ) {
			return true;
		}
		$name = self::normalize_key( $key );
		if ( in_array( $name, self::EXTRA_NAMES, true ) || in_array( $name, $extra, true ) ) {
			return true;
		}
		$words = explode( '_', $name );
		if ( in_array( $words[0], self::ABOUT, true ) ) {
			return false;
		}
		if ( in_array( end( $words ), self::CREDENTIAL_EXACT, true ) ) {
			return true;
		}
		foreach ( self::CREDENTIAL_NEEDLES as $needle ) {
			if ( '_' !== $needle[0] && false !== strpos( $name, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------- entry points */

	/**
	 * A tool's result as its answer carries it: redacted where the tool reads
	 * (or, for PARTS, in the other plugin's part), with FIELD naming what.
	 *
	 * @param string $name   Tool name.
	 * @param array  $def    The tool's definition.
	 * @param mixed  $result What the tool returned.
	 * @return mixed
	 */
	public static function tool_result( $name, array $def, $result ) {
		$name = (string) $name;
		$st   = self::state( $name );
		if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
			$result = self::walk( $result, array(), $st, 0 );
		} elseif ( isset( self::PARTS[ $name ] ) && is_array( $result ) ) {
			foreach ( self::PARTS[ $name ] as $part ) {
				if ( array_key_exists( $part, $result ) ) {
					$result[ $part ] = self::walk( $result[ $part ], array( (string) $part ), $st, 1 );
				}
			}
		}
		if ( 0 === $st['found_n'] || ! is_array( $result ) || ( array() !== $result && array_keys( $result ) === range( 0, count( $result ) - 1 ) ) ) {
			// A list or a text has no field to add: the REDACTED in place is all it says.
			return $result;
		}
		$result[ self::FIELD ] = array_map( array( __CLASS__, 'pointer' ), $st['found'] );
		if ( $st['found_n'] > self::MAX_PATHS ) {
			$result[ self::FIELD_COUNT ] = $st['found_n'];
		}
		if ( $st['unchecked_n'] > 0 ) {
			$result[ self::FIELD_UNCHECKED ] = array_map( array( __CLASS__, 'pointer' ), $st['unchecked'] );
		}
		return $result;
	}

	/**
	 * Whether run_tool() looks for the placeholder in a tool's arguments:
	 * for every tool that writes, but those that put the stored value back
	 * themselves (RESTORES) and those that take patterns (PATTERN_TOOLS).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  The tool's definition.
	 * @return bool
	 */
	public static function guards( $name, array $def ) {
		return ! AB_MCP_Tool_Registry::is_read_only( $name, $def ) && ! in_array( $name, self::RESTORES, true ) && ! in_array( $name, self::PATTERN_TOOLS, true );
	}

	/**
	 * Where an argument of a writing tool carries the placeholder back: a
	 * text with [REDACTED] anywhere in it (a value read from an answer, JSON
	 * or serialized data with it at any level of escaping). A text directly
	 * under a field people write (CONTENT_FIELDS) is not looked at; an array
	 * under such a field is, as the reader redacts keys there too.
	 *
	 * @param mixed $args Arguments.
	 * @return string|null The JSON pointer of the first such value; null for none.
	 */
	public static function placeholder_in( $args ) {
		$budget = self::BUDGET;
		return self::find_mark( $args, array(), 0, $budget );
	}

	/**
	 * placeholder_in() one level.
	 *
	 * @param mixed $value  Value.
	 * @param array $path   Its path.
	 * @param int   $depth  Nesting so far.
	 * @param int   $budget Visits left.
	 * @return string|null
	 */
	private static function find_mark( $value, array $path, $depth, &$budget ) {
		if ( is_string( $value ) ) {
			return false !== strpos( $value, self::MARK ) ? self::pointer( $path ) : null;
		}
		if ( ( ! is_array( $value ) && ! $value instanceof stdClass ) || --$budget < 0 || $depth > self::MAX_DEPTH ) {
			return null;
		}
		foreach ( is_array( $value ) ? $value : get_object_vars( $value ) as $k => $v ) {
			if ( is_string( $v ) && is_string( $k ) && in_array( strtolower( $k ), self::CONTENT_FIELDS, true ) ) {
				continue;
			}
			$hit = self::find_mark( $v, array_merge( $path, array( $k ) ), $depth + 1, $budget );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Put the stored values back where a value to be written is exactly
	 * REDACTED, at the same path in arrays and stdClass objects. $left says
	 * whether a placeholder is left after that: one without a stored value at
	 * its path, one inside a text (JSON or serialized data), or a value too
	 * deep or too large to look through. The writer refuses then.
	 *
	 * @param mixed $new    Value to be written.
	 * @param mixed $stored Value stored now.
	 * @param bool  $left   Set to whether a placeholder is left.
	 * @return mixed
	 */
	public static function restore_placeholders( $new, $stored, &$left = false ) {
		$left   = false;
		$budget = self::BUDGET;
		return self::restore( $new, true, $stored, 0, $budget, $left );
	}

	/**
	 * restore_placeholders() one level.
	 *
	 * @param mixed $new    Value to be written.
	 * @param bool  $has    Whether something is stored at this path.
	 * @param mixed $stored What is stored there.
	 * @param int   $depth  Nesting so far.
	 * @param int   $budget Visits left.
	 * @param bool  $left   Set where a placeholder is left.
	 * @return mixed
	 */
	private static function restore( $new, $has, $stored, $depth, &$budget, &$left ) {
		if ( is_string( $new ) ) {
			if ( self::MARK === $new && $has ) {
				return $stored;
			}
			$left = $left || false !== strpos( $new, self::MARK );
			return $new;
		}
		if ( ! is_array( $new ) && ! $new instanceof stdClass ) {
			return $new;
		}
		if ( --$budget < 0 || $depth > self::MAX_DEPTH ) {
			$left = true;
			return $new;
		}
		$vars = $has && $stored instanceof stdClass ? get_object_vars( $stored ) : ( $has && is_array( $stored ) ? $stored : array() );
		$out  = is_array( $new ) ? $new : clone $new;
		foreach ( is_array( $new ) ? $new : get_object_vars( $new ) as $k => $v ) {
			$here = array_key_exists( $k, $vars );
			$back = self::restore( $v, $here, $here ? $vars[ $k ] : null, $depth + 1, $budget, $left );
			if ( is_array( $out ) ) {
				$out[ $k ] = $back;
			} else {
				$out->$k = $back;
			}
		}
		return $out;
	}

	/**
	 * A path as a JSON pointer (RFC 6901): "/" before each key, "~" and "/"
	 * in a key as "~0" and "~1". A key that reads like a credential's
	 * name=value or name: value is cut after the name.
	 *
	 * @param array $path Keys.
	 * @return string
	 */
	public static function pointer( array $path ) {
		$out = '';
		foreach ( $path as $segment ) {
			$segment = (string) $segment;
			$cut     = strcspn( $segment, '=:' );
			if ( $cut < strlen( $segment ) && self::redacts_key( substr( $segment, 0, $cut ) ) ) {
				$segment = substr( $segment, 0, $cut + 1 ) . '…';
			}
			$out .= '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), $segment );
		}
		return $out;
	}

	/* -------------------------------------------------------------- the walk */

	/**
	 * The state of one run: the rules of the tool, what was found, the budget.
	 *
	 * @param string $name Tool name.
	 * @return array
	 */
	private static function state( $name ) {
		$split = static function ( $paths ) {
			return array_map(
				static function ( $p ) {
					return explode( '/', $p );
				},
				$paths
			);
		};
		return array(
			'keep'        => $split( isset( self::KEEP[ $name ] ) ? self::KEEP[ $name ] : array() ),
			'skip'        => $split( isset( self::SCHEMAS[ $name ] ) ? self::SCHEMAS[ $name ] : array() ),
			'text'        => in_array( $name, self::TEXT_TOOLS, true ),
			'parse'       => ! in_array( $name, self::NO_DECODE, true ),
			'extra'       => self::extra_keys(),
			'budget'      => self::BUDGET,
			'found'       => array(),
			'found_n'     => 0,
			'unchecked'   => array(),
			'unchecked_n' => 0,
		);
	}

	/**
	 * Note a path found or replaced unjudged: the first MAX_PATHS are kept,
	 * the rest only counted.
	 *
	 * @param array  $st   State.
	 * @param string $list 'found' or 'unchecked'.
	 * @param array  $path Path.
	 */
	private static function note( array &$st, $list, array $path ) {
		++$st[ $list . '_n' ];
		if ( count( $st[ $list ] ) < self::MAX_PATHS ) {
			$st[ $list ][] = $path;
		}
	}

	/**
	 * Replace a value that cannot be judged with REDACTED, listed as found
	 * and as unchecked.
	 *
	 * @param array $path Its path.
	 * @param array $st   State.
	 * @return string
	 */
	private static function unchecked( array $path, array &$st ) {
		self::note( $st, 'found', $path );
		self::note( $st, 'unchecked', $path );
		return self::MARK;
	}

	/**
	 * Whether a path is one of these patterns.
	 *
	 * @param array $path     Keys.
	 * @param array $patterns Patterns, as arrays of keys and '*'.
	 * @return bool
	 */
	private static function matches( array $path, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( count( $pattern ) !== count( $path ) ) {
				continue;
			}
			$match = true;
			foreach ( $pattern as $i => $segment ) {
				if ( '*' !== $segment && $segment !== (string) $path[ $i ] ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a value under a credential's key holds anything: null, true,
	 * false, an empty string or an empty array or object say only whether
	 * one is set, and stay as they are.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function holds( $value ) {
		if ( null === $value || is_bool( $value ) || '' === $value || array() === $value ) {
			return false;
		}
		return ! ( $value instanceof stdClass && array() === get_object_vars( $value ) );
	}

	/**
	 * Redact a value: arrays and objects by their keys, texts decoded where
	 * they are JSON or serialized data, the error log by its lines.
	 *
	 * @param mixed $value Value.
	 * @param array $path  Its path.
	 * @param array $st    State.
	 * @param int   $depth Nesting so far.
	 * @return mixed
	 */
	private static function walk( $value, array $path, array &$st, $depth ) {
		if ( ! is_string( $value ) && ! is_array( $value ) && ! is_object( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return self::unchecked( $path, $st );
		}
		if ( is_string( $value ) ) {
			return self::walk_text( $value, $path, $st, $depth );
		}
		if ( --$st['budget'] < 0 ) {
			return self::unchecked( $path, $st );
		}
		if ( $value instanceof JsonSerializable ) {
			// Called once: what it returns takes the object's place, so the
			// answer carries what was judged, not a second call's result.
			try {
				$data = $value->jsonSerialize();
			} catch ( \Throwable $e ) {
				return self::unchecked( $path, $st );
			}
			return self::walk( $data, $path, $st, $depth + 1 );
		}
		if ( $value instanceof stdClass || $value instanceof __PHP_Incomplete_Class ) {
			// As a stdClass, so {} stays {}; of an object unserialize() could
			// not load with its private and protected properties
			// ("\0*\0token"), which serialize() writes back.
			return (object) self::walk_array( get_object_vars( $value ), $path, $st, $depth );
		}
		if ( is_object( $value ) ) {
			// Any other object as the client gets it: its JSON form
			// (DateTime's date and timezone, ArrayObject's content).
			$json = wp_json_encode( $value );
			$data = is_string( $json ) ? json_decode( $json, false, 512, JSON_BIGINT_AS_STRING ) : null;
			return null === $data && 'null' !== $json ? self::unchecked( $path, $st ) : self::walk( $data, $path, $st, $depth + 1 );
		}
		return self::walk_array( $value, $path, $st, $depth );
	}

	/**
	 * Redact an array by its keys.
	 *
	 * @param array $value Array.
	 * @param array $path  Its path.
	 * @param array $st    State.
	 * @param int   $depth Nesting so far.
	 * @return array
	 */
	private static function walk_array( array $value, array $path, array &$st, $depth ) {
		// A pair: the name in one field, the value in the other.
		foreach ( self::PAIRS as $name_key => $value_key ) {
			if ( isset( $value[ $name_key ] ) && is_string( $value[ $name_key ] ) && array_key_exists( $value_key, $value )
				&& self::redacts_key( $value[ $name_key ], $st['extra'] ) && self::holds( $value[ $value_key ] ) ) {
				$p = array_merge( $path, array( $value_key ) );
				if ( ! self::matches( $p, $st['keep'] ) ) {
					$value[ $value_key ] = self::MARK;
					self::note( $st, 'found', $p );
				}
			}
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$p   = $path;
			$p[] = $key;
			$k   = self::safe_key( $key, $p, $st );
			if ( self::matches( $p, $st['skip'] ) ) {
				$out[ $k ] = $item;
			} elseif ( ! self::matches( $p, $st['keep'] ) && self::redacts_key( $key, $st['extra'] ) ) {
				if ( self::holds( $item ) ) {
					$out[ $k ] = self::MARK;
					self::note( $st, 'found', $p );
				} else {
					$out[ $k ] = $item;
				}
			} elseif ( is_string( $key ) && in_array( strtolower( $key ), self::CONTENT_FIELDS, true ) ) {
				$parse       = $st['parse'];
				$st['parse'] = false;
				$out[ $k ]   = self::walk( $item, $p, $st, $depth + 1 );
				$st['parse'] = $parse;
			} else {
				$out[ $k ] = self::walk( $item, $p, $st, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * A key that reads like a credential's name=value or name: value keeps
	 * its name and loses the value.
	 *
	 * @param string|int $key  Key.
	 * @param array      $path Its path.
	 * @param array      $st   State.
	 * @return string|int
	 */
	private static function safe_key( $key, array $path, array &$st ) {
		if ( ! is_string( $key ) ) {
			return $key;
		}
		$cut = strcspn( $key, '=:' );
		if ( $cut >= strlen( $key ) || '' === trim( substr( $key, $cut + 1 ) ) || ! self::redacts_key( substr( $key, 0, $cut ), $st['extra'] ) ) {
			return $key;
		}
		self::note( $st, 'found', $path );
		return substr( $key, 0, $cut + 1 ) . self::MARK;
	}

	/**
	 * Redact a text: the error log by its lines; a JSON document or
	 * serialized data decoded and walked like any structure, encoded again
	 * only where something in it was redacted.
	 *
	 * @param string $text  Text.
	 * @param array  $path  Its path.
	 * @param array  $st    State.
	 * @param int    $depth Nesting so far.
	 * @return string
	 */
	private static function walk_text( $text, array $path, array &$st, $depth ) {
		if ( $st['text'] ) {
			$hits = 0;
			$new  = self::text( $text, $hits, $st['extra'] );
			if ( null === $new ) {
				return self::unchecked( $path, $st );
			}
			if ( $hits > 0 ) {
				self::note( $st, 'found', $path );
			}
			$text = $new;
		}
		if ( ! $st['parse'] ) {
			return $text;
		}
		$kind = self::decode( $text, $data );
		if ( null === $kind ) {
			return $text;
		}
		if ( 'deep' === $kind || --$st['budget'] < 0 ) {
			return self::unchecked( $path, $st );
		}
		$before = array( $st['found'], $st['found_n'], $st['unchecked'], $st['unchecked_n'] );
		$found  = $st['found_n'];
		$new    = self::walk( $data, $path, $st, $depth + 1 );
		if ( $st['unchecked_n'] > $before[3] ) {
			// Part of it could not be judged: none of it goes out, and the
			// answer names the text once, not each place inside it.
			list( $st['found'], $st['found_n'], $st['unchecked'], $st['unchecked_n'] ) = $before;
			return self::unchecked( $path, $st );
		}
		if ( $st['found_n'] === $found ) {
			return $text;
		}
		$out = 'json' === $kind ? wp_json_encode( $new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : serialize( $new ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return is_string( $out ) ? $out : self::unchecked( $path, $st );
	}

	/**
	 * Decode a text that is a JSON array or object, or serialized data.
	 * Objects come back from unserialize() as __PHP_Incomplete_Class: no
	 * class is loaded, no code runs.
	 *
	 * @param string $text Text.
	 * @param mixed  $data Set to what it holds.
	 * @return string|null 'json' (an array, an object or a JSON string),
	 *                     'serialized', 'deep' for JSON nested beyond
	 *                     MAX_DEPTH, null for a text that is neither.
	 */
	private static function decode( $text, &$data ) {
		$trimmed = trim( $text );
		$first   = substr( $trimmed, 0, 1 );
		$last    = substr( $trimmed, -1 );
		if ( ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last ) || ( '"' === $first && '"' === $last && strlen( $trimmed ) > 1 ) ) {
			$data = json_decode( $trimmed, false, self::MAX_DEPTH, JSON_BIGINT_AS_STRING );
			if ( JSON_ERROR_DEPTH === json_last_error() ) {
				return 'deep';
			}
			// A JSON text inside a JSON string ("{\"token\":…}") is a string.
			return JSON_ERROR_NONE === json_last_error() && ( is_array( $data ) || is_object( $data ) || is_string( $data ) ) ? 'json' : null;
		}
		if ( preg_match( '/^(?:a:\d+:\{|O:\d+:"|s:\d+:")/', $text ) && ( '}' === $last || ';' === $last ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			$data = @unserialize( $text, array( 'allowed_classes' => false ) );
			return false === $data ? null : 'serialized';
		}
		return null;
	}

	/* ------------------------------------------------------------- the log */

	/**
	 * Credentials in the lines of a log replaced with REDACTED, line by line:
	 *
	 * - a dump entry under a credential's name ([name] =>, [name:Class:private] =>,
	 *   'name' =>, "name" =>): the value to the end of the line, a nested
	 *   array or object to its closing parenthesis, a value that goes on over
	 *   more lines to the next entry;
	 * - name=value, name: value or name => value anywhere in a line: from the
	 *   separator to the end of the line, an Authorization header's scheme
	 *   (Bearer, Basic, Digest) kept;
	 * - a Bearer token anywhere.
	 *
	 * @param string   $text  Text.
	 * @param int      $hits  Number of replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string|null Null where a pattern gave up.
	 */
	public static function text( $text, &$hits, array $extra = array() ) {
		$lines = explode( "\n", self::pem( (string) $text, $hits ) );
		$count = count( $lines );
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$line = $lines[ $i ];
			$dump = preg_match( '/^([ \t]*(?:\[([^\]:\r\n]{1,200})(?::[^\]\r\n]{0,200})?\]|\'([^\'\r\n]{1,200})\'|"([^"\r\n]{1,200})")[ \t]*=>[ \t]?)(.*)$/', $line, $m );
			if ( false === $dump ) {
				return null;
			}
			$name = 1 === $dump ? $m[2] . $m[3] . $m[4] : '';
			// var_export() puts a nested array on the next line: 'token' =>
			$next = '' === $name || '' !== trim( $m[5] ) || $i + 1 >= $count ? '' : trim( $lines[ $i + 1 ] );
			if ( '' !== $name && self::redacts_key( $name, $extra ) && ( '' !== trim( $m[5] ) || '(' === substr( $next, -1 ) ) ) {
				++$hits;
				$out[] = $m[1] . self::MARK;
				$i     = self::dump_end( $lines, $i, trim( $m[5] ) );
				continue;
			}
			$new = self::line( $line, $hits, $extra );
			if ( null === $new ) {
				return null;
			}
			$out[] = $new;
		}
		return implode( "\n", $out );
	}

	/**
	 * Every private key in PEM form, whatever name stands before it: from
	 * -----BEGIN … PRIVATE KEY----- to its -----END …----- line, or to the
	 * end of the text where none follows.
	 *
	 * @param string $text Text.
	 * @param int    $hits Replacements (added to).
	 * @return string
	 */
	private static function pem( $text, &$hits ) {
		$out = '';
		$pos = 0;
		while ( false !== ( $at = strpos( $text, '-----BEGIN ', $pos ) ) ) {
			$head = strpos( $text, '-----', $at + 11 );
			if ( false === $head || false === strpos( substr( $text, $at, $head - $at ), 'PRIVATE KEY' ) ) {
				$out .= substr( $text, $pos, $at + 11 - $pos );
				$pos  = $at + 11;
				continue;
			}
			$end  = strpos( $text, '-----END ', $head + 5 );
			$stop = false === $end ? false : strpos( $text, '-----', $end + 9 );
			$out .= substr( $text, $pos, $at - $pos ) . self::MARK;
			$pos  = false === $stop ? strlen( $text ) : $stop + 5;
			++$hits;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * The last line of a dump entry whose first line is $i: a nested array
	 * or object to its closing parenthesis (print_r()'s "Array" with "(" on
	 * the next line, var_export()'s "array (" on this or the next line), a
	 * value that goes on over more lines to the line before the next entry,
	 * the end of the block or the next log entry.
	 *
	 * @param string[] $lines Lines.
	 * @param int      $i     First line of the entry.
	 * @param string   $value Its value on that line, trimmed.
	 * @return int
	 */
	private static function dump_end( array $lines, $i, $value ) {
		$count = count( $lines );
		$open  = '(' === substr( $value, -1 ) ? $i : null;
		if ( '' === $value ) {
			$open = $i + 1;
		}
		if ( null === $open && $i + 1 < $count && ( 'Array' === $value || ' Object' === substr( $value, -7 ) ) ) {
			$open = '(' === trim( $lines[ $i + 1 ] ) ? $i + 1 : null;
		}
		if ( null !== $open ) {
			$depth = 0;
			for ( $j = $open; $j < $count; $j++ ) {
				$t      = trim( $lines[ $j ] );
				$depth += ( '(' === substr( $t, -1 ) ? 1 : 0 ) - ( ')' === substr( $t, 0, 1 ) ? 1 : 0 );
				if ( 0 === $depth ) {
					return $j;
				}
			}
			return $count - 1;
		}
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( preg_match( '/^[ \t]*(?:\[[^\]\r\n]{1,200}\]|\'[^\'\r\n]{0,200}\'|"[^"\r\n]{0,200}"|\d+)[ \t]*=>|^[ \t]*\)|^\[\d{1,2}-[A-Za-z]{3}-\d{4}/', $lines[ $j ] ) ) {
				return $j - 1;
			}
		}
		return $count - 1;
	}

	/**
	 * One line: from the separator after a credential's name to the end of
	 * the line (an Authorization header's scheme kept), and a Bearer token.
	 *
	 * @param string   $line  Line.
	 * @param int      $hits  Replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string|null
	 */
	private static function line( $line, &$hits, array $extra ) {
		$n = preg_match_all( '/(?<![\w\-$])(["\']?)([A-Za-z_][\w\-]{0,127})\1[ \t]{0,16}(=>|:|=)[ \t]{0,16}/', $line, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		foreach ( $found as $m ) {
			if ( ! self::redacts_key( $m[2][0], $extra ) ) {
				continue;
			}
			$from = $m[0][1] + strlen( $m[0][0] );
			$rest = rtrim( substr( $line, $from ), "\r" );
			$bare = trim( $rest, " \t\"'" );
			if ( '' === $bare || preg_match( '/^(?:true|false|null)$/i', $bare ) || in_array( strtolower( $bare ), self::SCHEMES, true ) ) {
				continue;
			}
			$scheme = preg_match( '/^["\']?([A-Za-z0-9\-]{1,32})[ \t]+\S/', $rest, $s ) && in_array( strtolower( $s[1] ), self::SCHEMES, true ) ? substr( $rest, 0, strlen( $s[0] ) - 1 ) : '';
			++$hits;
			return substr( $line, 0, $from ) . $scheme . self::MARK . ( "\r" === substr( $line, -1 ) ? "\r" : '' );
		}
		$n = preg_match_all( '/\bbearer[ \t]{1,16}/i', $line, $found, PREG_OFFSET_CAPTURE );
		if ( false === $n ) {
			return null;
		}
		foreach ( $found[0] as $m ) {
			$from = $m[1] + strlen( $m[0] );
			$len  = strspn( $line, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~+/', $from );
			if ( $len >= 8 ) {
				++$hits;
				return substr( $line, 0, $from ) . self::MARK . substr( $line, $from + $len + strspn( $line, '=', $from + $len ) );
			}
		}
		return $line;
	}
}
