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
 * One rule for both: is_credential_key() is the meta guard's rule, kept here
 * so that the guard (AB_MCP_Tools_Base::is_sensitive_meta_key()), the SQL
 * mirror of the search-and-replace scan and this class read one list. The
 * guard matches the name as written; redaction also reads the usual other
 * spellings of it (normalize_key(): accessToken, client-secret, "api key",
 * private PHP properties) and adds a few names of its own and the keys sites
 * add with the filter ab_mcp_redact_keys.
 *
 * Applied in one place, AB_MCP_REST_Controller::run_tool(), to the result of
 * every tool the registry marks read-only (tools/call and AlphaBridge's own
 * abilities alike, Pro's readers included), and to the part of wp_run_ability's
 * answer that comes from the other plugin. A writing tool's answer is left
 * as it is: wp_create_user names its generated password once, on purpose.
 *
 * Text is never decoded and encoded again: a JSON document or serialized
 * data inside a value is redacted where it stands, by pattern, so its
 * formatting, its empty objects and its large numbers stay as they were.
 * Free text (headers, name=value pairs, print_r() and var_export() dumps) is
 * searched in the error log only (wp_get_error_log). Never by the look of a
 * value: a long random string is as often an id or a hash.
 *
 * Fail-closed where a value cannot be judged: a structure too deep or too
 * large for the node budget, a text a pattern gives up on, an object whose
 * jsonSerialize() throws. Such a value reads as REDACTED as a whole, and the
 * answer lists it in `redacted_unchecked` as well.
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
	const CREDENTIAL_EXACT = array( 'token', 'tokens', 'secret', 'secrets', 'password', 'passwords', 'passphrase', 'passphrases', 'passcode', 'passcodes', 'pwd', 'pwds', 'otp', 'otps', 'credential', 'credentials' );

	/**
	 * High-precision compound credential patterns, matched anywhere in the
	 * key: these virtually never occur in legitimate content meta, so they
	 * avoid false positives on keys like token_count, email_signature,
	 * password_hint or credential_type while still catching real API keys
	 * and tokens.
	 *
	 * The meta guard matches them as written. Removing separators would turn
	 * co_author into "coauthor", which contains "oauth"; normalize_key()
	 * therefore only splits camelCase and turns dashes and blanks into "_".
	 */
	const CREDENTIAL_NEEDLES = array( 'api_key', 'apikey', 'api-key', 'api_token', 'api_secret', 'access_key', 'access_token', 'secret_key', 'secret_token', 'private_key', 'client_secret', 'consumer_key', 'consumer_secret', 'refresh_token', 'auth_token', 'bearer_token', 'license_key', 'oauth', '_token', '_secret', '_password', 'passwd', 'smtp_pass' );

	/**
	 * Names redacted on top of the meta rule, as whole keys after
	 * normalize_key(): the HTTP headers that carry a credential, as a
	 * plugin's result or a log line may hold them, a bare "auth", and the two
	 * columns of wp_users that hold a password hash and a reset key, as a
	 * database row shows them. Not part of the meta guard: refusing a meta key
	 * of that name is not what these are for.
	 */
	const EXTRA_NAMES = array( 'authorization', 'proxy_authorization', 'auth', 'user_pass', 'user_activation_key' );

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
	 * data: an ability's input and output schema name a property "password"
	 * without holding one. Not searched. A path of keys, '*' for any one key.
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
	 * Tools that hand out a file as it is stored: their text is not searched
	 * for JSON or serialized data, because the answer is how the file gets
	 * edited, and a value put back as REDACTED would be written back.
	 */
	const FILE_TOOLS = array( 'wp_read_theme_file', 'wp_read_plugin_file', 'wp_read_upload_file', 'wp_deploy_read' );

	/**
	 * Writing tools of which one part of the answer is another plugin's data,
	 * and which part.
	 */
	const PARTS = array( 'wp_run_ability' => array( 'result' ) );

	/** Nesting deeper than this is not judged (fail-closed). */
	const MAX_DEPTH = 100;

	/**
	 * Arrays, objects and serialized items one answer may visit; texts are
	 * not counted, each is read once. A large page-builder layout comes to a
	 * few thousand; a cycle of references, as unserialize() builds from R:,
	 * never ends without it. What lies beyond is not judged (fail-closed).
	 */
	const BUDGET = 20000;

	/** Authentication schemes an Authorization header names before its credential. */
	const SCHEMES = array( 'bearer', 'basic', 'digest', 'negotiate', 'ntlm', 'token', 'hoba', 'mutual', 'aws4-hmac-sha256' );

	/* ------------------------------------------------------------ the rule */

	/**
	 * Whether a key looks like a stored credential: the rule of the meta
	 * guard, as written, without the names that only redaction adds.
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
	 * private or protected property ("\0*\0token", "\0Class\0token"),
	 * without blanks around it (Unicode ones too), camelCase split
	 * (accessToken → access_token, APIKey → api_key), dashes and blanks as
	 * "_", in lower case.
	 *
	 * @param string|int $key Key.
	 * @return string
	 */
	public static function normalize_key( $key ) {
		static $seen = array();
		$key = (string) $key;
		if ( isset( $seen[ $key ] ) ) {
			return $seen[ $key ];
		}
		if ( count( $seen ) > 5000 ) {
			$seen = array();
		}
		$seen[ $key ] = self::normalized( $key );
		return $seen[ $key ];
	}

	/**
	 * normalize_key() without its memory.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function normalized( $key ) {
		if ( "\0" === substr( $key, 0, 1 ) ) {
			$end = strpos( $key, "\0", 1 );
			$key = false === $end ? substr( $key, 1 ) : substr( $key, $end + 1 );
		}
		$trimmed = preg_replace( '/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $key );
		$key     = is_string( $trimmed ) ? $trimmed : trim( $key );
		$key     = (string) preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key );
		$key     = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		$key     = strtolower( $key );
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
		 * key, in the spellings normalize_key() reads alike: add
		 * "my_plugin_license" to have it read as [REDACTED] wherever it
		 * appears. The built-in names cannot be taken out here.
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
	 * Whether a value under this key is redacted.
	 *
	 * @param string|int $key   Key.
	 * @param string[]   $extra extra_keys().
	 * @return bool
	 */
	public static function redacts_key( $key, array $extra = array() ) {
		if ( is_int( $key ) ) {
			return false;
		}
		// As written (the meta guard's reading) and normalised: every key the
		// guard refuses is redacted too.
		$name = self::normalize_key( $key );
		return self::is_credential_key( $key ) || self::is_credential_key( $name ) || in_array( $name, self::EXTRA_NAMES, true ) || in_array( $name, $extra, true );
	}

	/* ------------------------------------------------------- entry points */

	/**
	 * A tool's result as its answer carries it: redacted where the tool reads
	 * (or, for PARTS, in the other plugin's part), with FIELD naming what.
	 * Anything else is returned as it came.
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
		return self::with_paths( $result, $st );
	}

	/**
	 * Redact one value with the rules every reading tool gets.
	 *
	 * @param mixed    $value     Value.
	 * @param string[] $paths     JSON pointers of what was redacted (appended).
	 * @param string   $tool      Tool name, for its own rules; '' for none.
	 * @param string[] $unchecked JSON pointers of what was replaced unjudged (appended).
	 * @return mixed
	 */
	public static function redact( $value, array &$paths, $tool = '', array &$unchecked = array() ) {
		$st        = self::state( (string) $tool );
		$value     = self::walk( $value, array(), $st, 0 );
		$paths     = array_merge( $paths, array_map( array( __CLASS__, 'pointer' ), $st['found'] ) );
		$unchecked = array_merge( $unchecked, array_map( array( __CLASS__, 'pointer' ), $st['unchecked'] ) );
		return $value;
	}

	/**
	 * The result with FIELD (and FIELD_UNCHECKED) set, where something was
	 * redacted and the result is an object with keys to add it to. A list or
	 * a text has none: the REDACTED in place is all it says.
	 *
	 * @param mixed $result Result.
	 * @param array $st     State of the run.
	 * @return mixed
	 */
	private static function with_paths( $result, array $st ) {
		if ( 0 === $st['found_n'] || ! is_array( $result ) || ( array() !== $result && array_keys( $result ) === range( 0, count( $result ) - 1 ) ) ) {
			return $result;
		}
		$paths = array_map( array( __CLASS__, 'pointer' ), $st['found'] );
		$total = $st['found_n'];
		if ( isset( $result[ self::FIELD ] ) && is_array( $result[ self::FIELD ] ) ) {
			$paths  = array_merge( array_values( $result[ self::FIELD ] ), $paths );
			$total += count( $result[ self::FIELD ] );
		}
		$result[ self::FIELD ] = array_slice( $paths, 0, self::MAX_PATHS );
		if ( $total > self::MAX_PATHS ) {
			$result[ self::FIELD_COUNT ] = $total;
		}
		if ( $st['unchecked_n'] > 0 ) {
			$result[ self::FIELD_UNCHECKED ] = array_map( array( __CLASS__, 'pointer' ), $st['unchecked'] );
		}
		return $result;
	}

	/**
	 * Note a path found or replaced unjudged: the first MAX_PATHS are kept,
	 * the rest only counted, so a large answer cannot fill the memory with
	 * paths.
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
	 * A path as a JSON pointer (RFC 6901): "/" before each key, "~" and "/"
	 * in a key as "~0" and "~1". A key that itself reads like name=value or
	 * name: value is cut after the name, so a value never travels in a path.
	 *
	 * @param array $path Keys.
	 * @return string
	 */
	public static function pointer( array $path ) {
		$out = '';
		foreach ( $path as $segment ) {
			$segment = (string) $segment;
			$cut     = strcspn( $segment, '=:' );
			if ( $cut < strlen( $segment ) ) {
				$segment = substr( $segment, 0, $cut + 1 ) . '…';
			}
			if ( strlen( $segment ) > 128 ) {
				$segment = substr( $segment, 0, 128 ) . '…';
			}
			$out .= '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), $segment );
		}
		return $out;
	}

	/**
	 * Put the stored values back where a value sent for writing is exactly
	 * REDACTED: an answer read with REDACTED in it and written back unchanged
	 * keeps the credential instead of storing the placeholder. In arrays and
	 * stdClass objects, at the same path; where the stored value has nothing
	 * at that path, the REDACTED stays (the writer decides what to do with it).
	 *
	 * @param mixed $new    Value to be written.
	 * @param mixed $stored Value stored now.
	 * @return mixed
	 */
	public static function restore_placeholders( $new, $stored ) {
		return self::restore( $new, $stored, 0 );
	}

	/**
	 * restore_placeholders() one level.
	 *
	 * @param mixed $new    Value to be written.
	 * @param mixed $stored Value stored now.
	 * @param int   $depth  Nesting so far.
	 * @return mixed
	 */
	private static function restore( $new, $stored, $depth ) {
		if ( self::MARK === $new ) {
			return $stored;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return $new;
		}
		if ( is_array( $new ) ) {
			foreach ( $new as $k => $v ) {
				if ( self::has_child( $stored, $k ) ) {
					$new[ $k ] = self::restore( $v, self::child( $stored, $k ), $depth + 1 );
				}
			}
			return $new;
		}
		if ( $new instanceof stdClass ) {
			$copy = clone $new;
			foreach ( get_object_vars( $new ) as $k => $v ) {
				if ( self::has_child( $stored, $k ) ) {
					$copy->$k = self::restore( $v, self::child( $stored, $k ), $depth + 1 );
				}
			}
			return $copy;
		}
		return $new;
	}

	/**
	 * Whether a stored value has something under this key.
	 *
	 * @param mixed      $stored Stored value.
	 * @param string|int $key    Key.
	 * @return bool
	 */
	private static function has_child( $stored, $key ) {
		if ( is_array( $stored ) ) {
			return array_key_exists( $key, $stored );
		}
		return $stored instanceof stdClass && array_key_exists( (string) $key, get_object_vars( $stored ) );
	}

	/**
	 * The stored value under this key.
	 *
	 * @param mixed      $stored Stored value.
	 * @param string|int $key    Key.
	 * @return mixed
	 */
	private static function child( $stored, $key ) {
		if ( is_array( $stored ) ) {
			return $stored[ $key ];
		}
		return get_object_vars( $stored )[ (string) $key ];
	}

	/* ------------------------------------------------------ structured data */

	/**
	 * The state of one run: the rules of the tool, what was found, the budget.
	 *
	 * @param string $name Tool name.
	 * @return array
	 */
	private static function state( $name ) {
		$split = static function ( $paths ) {
			$out = array();
			foreach ( $paths as $path ) {
				$out[] = explode( '/', $path );
			}
			return $out;
		};
		return array(
			'keep'        => $split( isset( self::KEEP[ $name ] ) ? self::KEEP[ $name ] : array() ),
			'skip'        => $split( isset( self::SCHEMAS[ $name ] ) ? self::SCHEMAS[ $name ] : array() ),
			'text'        => in_array( $name, self::TEXT_TOOLS, true ),
			'parse'       => ! in_array( $name, self::FILE_TOOLS, true ),
			'extra'       => self::extra_keys(),
			'budget'      => self::BUDGET,
			'found'       => array(),
			'found_n'     => 0,
			'unchecked'   => array(),
			'unchecked_n' => 0,
			'error'       => false,
		);
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
	 * Replace a value that cannot be judged: a text, an array or an object
	 * reads as REDACTED, and the path is listed as unchecked. A number,
	 * true, false or null carries no credential under a key already judged.
	 *
	 * @param mixed $value Value.
	 * @param array $path  Its path.
	 * @param array $st    State.
	 * @return mixed
	 */
	private static function unchecked( $value, array $path, array &$st ) {
		if ( ! is_string( $value ) && ! is_array( $value ) && ! is_object( $value ) ) {
			return $value;
		}
		self::note( $st, 'found', $path );
		self::note( $st, 'unchecked', $path );
		return self::MARK;
	}

	/**
	 * Redact a value: arrays and objects by their keys, text by pattern.
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
		if ( is_string( $value ) ) {
			return $depth > self::MAX_DEPTH ? self::unchecked( $value, $path, $st ) : self::walk_text( $value, $path, $st );
		}
		if ( --$st['budget'] < 0 || $depth > self::MAX_DEPTH ) {
			return self::unchecked( $value, $path, $st );
		}
		if ( $value instanceof JsonSerializable ) {
			// Called once: what it returns takes the object's place, so the
			// answer carries what was judged, not a second call's result.
			try {
				$data = $value->jsonSerialize();
			} catch ( \Throwable $e ) {
				return self::unchecked( $value, $path, $st );
			}
			return self::walk( $data, $path, $st, $depth + 1 );
		}
		if ( is_object( $value ) ) {
			// What wp_json_encode() writes of an object: its public
			// properties. As a stdClass, so {} stays {}.
			$vars = array();
			foreach ( get_object_vars( $value ) as $k => $v ) {
				if ( ! ( is_string( $k ) && "\0" === substr( $k, 0, 1 ) ) ) {
					$vars[ $k ] = $v;
				}
			}
			return (object) self::walk_array( $vars, $path, $st, $depth );
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
				&& self::redacts_key( $value[ $name_key ], $st['extra'] ) && self::holds( $value[ $value_key ] ) && self::MARK !== $value[ $value_key ] ) {
				$p = array_merge( $path, array( $value_key ) );
				if ( ! self::matches( $p, $st['keep'] ) ) {
					$value[ $value_key ] = self::MARK;
					self::note( $st, 'found', $p );
				}
			}
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$p = $path;
			$p[] = $key;
			$k   = self::safe_key( $key, $p, $st );
			if ( self::matches( $p, $st['skip'] ) ) {
				$out[ $k ] = $item;
				continue;
			}
			if ( ! self::matches( $p, $st['keep'] ) && self::redacts_key( $key, $st['extra'] ) ) {
				if ( self::holds( $item ) && self::MARK !== $item ) {
					$out[ $k ]     = self::MARK;
					self::note( $st, 'found', $p );
				} else {
					$out[ $k ] = $item;
				}
				continue;
			}
			$out[ $k ] = self::walk( $item, $p, $st, $depth + 1 );
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
		if ( $cut >= strlen( $key ) || $cut > 128 || ! self::redacts_key( substr( $key, 0, $cut ), $st['extra'] ) ) {
			return $key;
		}
		$rest = ltrim( substr( $key, $cut + 1 ) );
		if ( '' === $rest || self::MARK === $rest ) {
			return $key;
		}
		self::note( $st, 'found', $path );
		return substr( $key, 0, $cut + 1 ) . self::MARK;
	}

	/* ----------------------------------------------------------------- text */

	/**
	 * Redact a text: JSON and serialized data in it by pattern, where they
	 * stand, and in the error log free text too. Fail-closed where a pattern
	 * gives up.
	 *
	 * @param string $text Text.
	 * @param array  $path Its path.
	 * @param array  $st   State.
	 * @return string
	 */
	private static function walk_text( $text, array $path, array &$st ) {
		if ( $st['parse'] && false !== strpos( $text, '"' ) ) {
			$st['error'] = false;
			$new         = self::structured( $text, $path, $st, 0 );
			if ( null === $new || ! empty( $st['error'] ) ) {
				return self::unchecked( $text, $path, $st );
			}
			$text = $new;
		}
		if ( $st['text'] ) {
			$hits = 0;
			$new  = self::text( $text, $hits, $st['extra'] );
			if ( null === $new ) {
				return self::unchecked( $text, $path, $st );
			}
			if ( $hits > 0 ) {
				self::note( $st, 'found', $path );
			}
			$text = $new;
		}
		return $text;
	}

	/**
	 * JSON and serialized data inside a text, redacted where they stand. A
	 * text that is one serialized value is rewritten with correct lengths,
	 * texts nested in it included; any other text is searched for serialized
	 * items and JSON members whose name is a credential's.
	 *
	 * @param string $text  Text.
	 * @param array  $path  Its path.
	 * @param array  $st    State; 'error' set where a pattern gave up.
	 * @param int    $depth Texts nested so far.
	 * @return string|null Null where a pattern gave up.
	 */
	private static function structured( $text, array $path, array &$st, $depth ) {
		if ( preg_match( '/^(?:a|O|s):\d/', $text ) ) {
			$found = array( $st['found'], $st['found_n'] );
			$whole = self::ser_value( $text, 0, $path, $st, $depth );
			if ( null !== $whole && strlen( $text ) === $whole[1] ) {
				return $whole[0];
			}
			list( $st['found'], $st['found_n'] ) = $found;
		}
		$text = self::ser_scan( $text, $path, $st );
		if ( null === $text ) {
			return null;
		}
		return self::json_scan( $text, $path, $st );
	}

	/**
	 * The nesting level of a JSON quote preceded by this many backslashes:
	 * 0 for ", 1 for \", 2 for \\\", … (2^level - 1 backslashes); null for
	 * a count that is no level's.
	 *
	 * @param int $backslashes Backslashes.
	 * @return int|null
	 */
	private static function level( $backslashes ) {
		$n = $backslashes + 1;
		if ( $n > 128 || 0 !== ( $n & ( $n - 1 ) ) ) {
			return null;
		}
		return (int) round( log( $n, 2 ) );
	}

	/**
	 * Whether the quote at $i closes or opens a string of this level: the
	 * backslashes before it are 2^level - 1 more than a multiple of
	 * 2^(level + 1).
	 *
	 * @param string $s     Text.
	 * @param int    $i     Offset of a '"'.
	 * @param int    $level Level.
	 * @return bool
	 */
	private static function quote_of( $s, $i, $level ) {
		$run = 0;
		while ( $i - $run - 1 >= 0 && '\\' === $s[ $i - $run - 1 ] ) {
			++$run;
		}
		return ( $run % ( 2 << $level ) ) === ( ( 1 << $level ) - 1 );
	}

	/**
	 * Where a JSON value of this level that holds something ends: a string
	 * that is not empty, a number, an object or array that is not empty.
	 * Null for true, false, null, "", {}, [] and anything that is no value.
	 *
	 * @param string $s     Text.
	 * @param int    $i     Offset of the value.
	 * @param int    $level Level of its quotes.
	 * @return int|null Offset after it.
	 */
	private static function json_value_end( $s, $i, $level ) {
		$len = strlen( $s );
		$bs  = ( 1 << $level ) - 1;
		if ( $i >= $len ) {
			return null;
		}
		$c = $s[ $i ];
		if ( ( 0 === $bs && '"' === $c ) || ( $bs > 0 && str_repeat( '\\', $bs ) . '"' === substr( $s, $i, $bs + 1 ) ) ) {
			$from = $i + $bs + 1;
			$j    = $from;
			while ( false !== ( $q = strpos( $s, '"', $j ) ) ) {
				if ( self::quote_of( $s, $q, $level ) ) {
					return ( $q - $bs ) === $from ? null : $q + 1;
				}
				$j = $q + 1;
			}
			return null;
		}
		if ( '{' === $c || '[' === $c ) {
			$depth = 0;
			$in    = false;
			for ( $j = $i; $j < $len; $j++ ) {
				$ch = $s[ $j ];
				if ( '"' === $ch ) {
					if ( self::quote_of( $s, $j, $level ) ) {
						$in = ! $in;
					}
				} elseif ( ! $in && ( '{' === $ch || '[' === $ch ) ) {
					++$depth;
				} elseif ( ! $in && ( '}' === $ch || ']' === $ch ) ) {
					--$depth;
					if ( 0 === $depth ) {
						return '' === trim( substr( $s, $i + 1, $j - $i - 1 ) ) ? null : $j + 1;
					}
				}
			}
			return null;
		}
		if ( '-' === $c || ctype_digit( $c ) ) {
			return $i + strspn( $s, '0123456789+-.eE', $i );
		}
		return null;
	}

	/**
	 * JSON members whose name is a credential's, their value replaced by
	 * "[REDACTED]" where it stands, at any level of escaping (JSON in a JSON
	 * string: \"token\":\"…\").
	 *
	 * @param string $s    Text.
	 * @param array  $path Its path.
	 * @param array  $st   State.
	 * @return string|null Null where the pattern gave up.
	 */
	private static function json_scan( $s, array $path, array &$st ) {
		if ( false === strpos( $s, ':' ) ) {
			return $s;
		}
		$n = preg_match_all( '/(?<!\\\\)(\\\\{0,127})"([^"\\\\\r\n]{1,128})\1"[ \t\r\n]{0,64}:[ \t\r\n]{0,64}/', $s, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $hits as $hit ) {
			if ( $hit[0][1] < $pos ) {
				continue;
			}
			$level = self::level( strlen( $hit[1][0] ) );
			if ( null === $level || ! self::redacts_key( $hit[2][0], $st['extra'] ) ) {
				continue;
			}
			$from = $hit[0][1] + strlen( $hit[0][0] );
			$end  = self::json_value_end( $s, $from, $level );
			if ( null === $end || false !== strpos( substr( $s, $from, $end - $from ), self::MARK ) ) {
				continue;
			}
			$q             = $hit[1][0] . '"';
			$out          .= substr( $s, $pos, $from - $pos ) . $q . self::MARK . $q;
			$pos           = $end;
			self::note( $st, 'found', array_merge( $path, array( $hit[2][0] ) ) );
		}
		return $out . substr( $s, $pos );
	}

	/**
	 * Where the serialized value at $i ends, without judging it.
	 *
	 * @param string $s     Text.
	 * @param int    $i     Offset.
	 * @param array  $st    State (budget).
	 * @param int    $depth Nesting so far.
	 * @return int|null
	 */
	private static function ser_skip( $s, $i, array &$st, $depth ) {
		if ( --$st['budget'] < 0 || $depth > self::MAX_DEPTH ) {
			$st['error'] = true;
			return null;
		}
		$t = isset( $s[ $i ] ) ? $s[ $i ] : '';
		if ( 'N' === $t ) {
			return ';' === substr( $s, $i + 1, 1 ) ? $i + 2 : null;
		}
		if ( in_array( $t, array( 'b', 'i', 'd', 'r', 'R' ), true ) ) {
			if ( ':' !== substr( $s, $i + 1, 1 ) ) {
				return null;
			}
			$e = strpos( $s, ';', $i + 2 );
			return ( false === $e || $e - $i > 400 ) ? null : $e + 1;
		}
		if ( 's' === $t || 'E' === $t ) {
			$m = self::ser_head( $s, $i, '/\G[sE]:(\d{1,10}):"/' );
			if ( null === $m ) {
				return null;
			}
			$end = $i + strlen( $m[0] ) + (int) $m[1];
			return '";' === substr( $s, $end, 2 ) ? $end + 2 : null;
		}
		$open = self::ser_open( $s, $i );
		if ( null === $open ) {
			return null;
		}
		$j = $open[1];
		for ( $k = 0; $k < 2 * $open[0]; $k++ ) {
			$j = self::ser_skip( $s, $j, $st, $depth + 1 );
			if ( null === $j ) {
				return null;
			}
		}
		return '}' === substr( $s, $j, 1 ) ? $j + 1 : null;
	}

	/**
	 * The head of a serialized item matched at $i.
	 *
	 * @param string $s       Text.
	 * @param int    $i       Offset.
	 * @param string $pattern Pattern starting with \G.
	 * @return array|null The matches.
	 */
	private static function ser_head( $s, $i, $pattern ) {
		return 1 === preg_match( $pattern, $s, $m, 0, $i ) ? $m : null;
	}

	/**
	 * The opening of a serialized array or object at $i: how many pairs it
	 * holds and where they start.
	 *
	 * @param string $s Text.
	 * @param int    $i Offset.
	 * @return array|null array( pairs, offset of the first, head text ).
	 */
	private static function ser_open( $s, $i ) {
		$t = isset( $s[ $i ] ) ? $s[ $i ] : '';
		if ( 'a' === $t ) {
			$m = self::ser_head( $s, $i, '/\Ga:(\d{1,9}):\{/' );
			return null === $m ? null : array( (int) $m[1], $i + strlen( $m[0] ), $m[0] );
		}
		if ( 'O' === $t ) {
			$m = self::ser_head( $s, $i, '/\GO:(\d{1,4}):"/' );
			if ( null === $m ) {
				return null;
			}
			$after = $i + strlen( $m[0] ) + (int) $m[1];
			$n     = self::ser_head( $s, $after, '/\G":(\d{1,9}):\{/' );
			return null === $n ? null : array( (int) $n[1], $after + strlen( $n[0] ), substr( $s, $i, $after - $i ) . $n[0] );
		}
		return null;
	}

	/**
	 * Whether a serialized value says only whether one is set: N;, b:…;,
	 * s:0:"";, a:0:{}.
	 *
	 * @param string $token The value as serialized.
	 * @return bool
	 */
	private static function ser_holds( $token ) {
		return ! ( 'N;' === $token || 'b:' === substr( $token, 0, 2 ) || 's:0:"";' === $token || 'a:0:{}' === $token );
	}

	/**
	 * The serialized value at $i, redacted: in an array or object the value
	 * under a credential's key becomes s:10:"[REDACTED]";, a text inside is
	 * judged as any text and written back with its new length.
	 *
	 * @param string $s     Text.
	 * @param int    $i     Offset.
	 * @param array  $path  Its path.
	 * @param array  $st    State.
	 * @param int    $depth Nesting so far.
	 * @return array|null array( text, offset after it ), null where it is no
	 *                    serialized value.
	 */
	private static function ser_value( $s, $i, array $path, array &$st, $depth ) {
		$t = isset( $s[ $i ] ) ? $s[ $i ] : '';
		if ( 's' === $t ) {
			if ( --$st['budget'] < 0 ) {
				$st['error'] = true;
				return null;
			}
			$m = self::ser_head( $s, $i, '/\Gs:(\d{1,10}):"/' );
			if ( null === $m ) {
				return null;
			}
			$from = $i + strlen( $m[0] );
			$len  = (int) $m[1];
			if ( '";' !== substr( $s, $from + $len, 2 ) ) {
				return null;
			}
			$inner = substr( $s, $from, $len );
			if ( $st['parse'] && false !== strpos( $inner, '"' ) && $depth < self::MAX_DEPTH ) {
				$new = self::structured( $inner, $path, $st, $depth + 1 );
				if ( null === $new ) {
					return null;
				}
				$inner = $new;
			}
			return array( 's:' . strlen( $inner ) . ':"' . $inner . '";', $from + $len + 2 );
		}
		$open = self::ser_open( $s, $i );
		if ( null === $open ) {
			$end = self::ser_skip( $s, $i, $st, $depth );
			return null === $end ? null : array( substr( $s, $i, $end - $i ), $end );
		}
		if ( --$st['budget'] < 0 || $depth > self::MAX_DEPTH ) {
			$st['error'] = true;
			return null;
		}
		$out = $open[2];
		$j   = $open[1];
		for ( $k = 0; $k < $open[0]; $k++ ) {
			$key_end = self::ser_skip( $s, $j, $st, $depth + 1 );
			if ( null === $key_end ) {
				return null;
			}
			$key_token = substr( $s, $j, $key_end - $j );
			$name      = 's' === $key_token[0] ? substr( $key_token, strpos( $key_token, '"' ) + 1, -2 ) : (int) substr( $key_token, 2, -1 );
			$p         = array_merge( $path, array( $name ) );
			$out      .= $key_token;
			$j         = $key_end;
			if ( ! self::matches( $p, $st['keep'] ) && self::redacts_key( $name, $st['extra'] ) ) {
				$end = self::ser_skip( $s, $j, $st, $depth + 1 );
				if ( null === $end ) {
					return null;
				}
				$token = substr( $s, $j, $end - $j );
				if ( self::ser_holds( $token ) && 's:10:"' . self::MARK . '";' !== $token ) {
					$out          .= 's:10:"' . self::MARK . '";';
					self::note( $st, 'found', $p );
				} else {
					$out .= $token;
				}
				$j = $end;
				continue;
			}
			$value = self::ser_value( $s, $j, $p, $st, $depth + 1 );
			if ( null === $value ) {
				return null;
			}
			$out .= $value[0];
			$j    = $value[1];
		}
		if ( '}' !== substr( $s, $j, 1 ) ) {
			return null;
		}
		return array( $out . '}', $j + 1 );
	}

	/**
	 * Serialized items in a text that is not one serialized value as a whole
	 * (cut short, or text around it): each s:N:"name"; whose name is a
	 * credential's has the value after it replaced. A length around it may
	 * no longer fit; the text was not valid serialized data to begin with.
	 *
	 * @param string $s    Text.
	 * @param array  $path Its path.
	 * @param array  $st   State.
	 * @return string|null Null where the pattern gave up.
	 */
	private static function ser_scan( $s, array $path, array &$st ) {
		if ( false === strpos( $s, 's:' ) ) {
			return $s;
		}
		$n = preg_match_all( '/s:(\d{1,4}):"/', $s, $hits, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $hits as $hit ) {
			$at = $hit[0][1];
			if ( $at < $pos ) {
				continue;
			}
			$from = $at + strlen( $hit[0][0] );
			$len  = (int) $hit[1][0];
			if ( '";' !== substr( $s, $from + $len, 2 ) ) {
				continue;
			}
			$name = substr( $s, $from, $len );
			if ( ! self::redacts_key( $name, $st['extra'] ) ) {
				continue;
			}
			$vstart = $from + $len + 2;
			$end    = self::ser_skip( $s, $vstart, $st, 0 );
			if ( null === $end ) {
				if ( ! empty( $st['error'] ) ) {
					return null;
				}
				continue;
			}
			$token = substr( $s, $vstart, $end - $vstart );
			if ( ! self::ser_holds( $token ) || 's:10:"' . self::MARK . '";' === $token ) {
				continue;
			}
			$out          .= substr( $s, $pos, $vstart - $pos ) . 's:10:"' . self::MARK . '";';
			$pos           = $end;
			self::note( $st, 'found', array_merge( $path, array( $name ) ) );
		}
		return $out . substr( $s, $pos );
	}

	/* ------------------------------------------------------------ free text */

	/**
	 * Credentials in free text replaced with REDACTED: the value of an
	 * Authorization header (its scheme stays), a Bearer token, a print_r()
	 * entry under a credential's name (a nested array to the end of its
	 * block), and the value of a name=value pair, a name: value, a
	 * "name": "value" or a 'name' => value pair (a var_export() array to its
	 * closing bracket) whose name redacts_key() takes for a credential's.
	 *
	 * @param string   $text  Text.
	 * @param int      $hits  Number of replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string|null Null where a pattern gave up: the caller treats the
	 *                     text as not judged.
	 */
	public static function text( $text, &$hits, array $extra = array() ) {
		$text = self::headers( (string) $text, $hits );
		if ( null === $text ) {
			return null;
		}
		$text = self::bearer( $text, $hits );
		if ( null === $text ) {
			return null;
		}
		$text = self::print_r_blocks( $text, $hits, $extra );
		if ( null === $text ) {
			return null;
		}
		return self::pairs( $text, $hits, $extra );
	}

	/**
	 * A Bearer token anywhere, as curl's verbose output shows it: eight
	 * characters of a token's alphabet or more after the word.
	 *
	 * @param string $text Text.
	 * @param int    $hits Replacements (added to).
	 * @return string|null
	 */
	private static function bearer( $text, &$hits ) {
		$n = preg_match_all( '/\bbearer[ \t]{1,16}/i', $text, $found, PREG_OFFSET_CAPTURE );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $found[0] as $m ) {
			$from = $m[1] + strlen( $m[0] );
			$len  = strspn( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~+/', $from );
			if ( $m[1] < $pos || $len < 8 ) {
				continue;
			}
			$len += strspn( $text, '=', $from + $len );
			$out .= substr( $text, $pos, $from - $pos ) . self::MARK;
			$pos  = $from + $len;
			++$hits;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * The value of each Authorization or Proxy-Authorization header, after
	 * its scheme: to the end of the line, to the closing quote where the
	 * header stands in quotes, to the next & or blank after "=".
	 *
	 * @param string $text Text.
	 * @param int    $hits Replacements (added to).
	 * @return string|null
	 */
	private static function headers( $text, &$hits ) {
		$n = preg_match_all( '/\b(?:proxy-)?authorization(["\']?)[ \t]{0,16}([:=])[ \t]{0,16}(["\']?)/i', $text, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $found as $m ) {
			if ( $m[0][1] < $pos ) {
				continue;
			}
			$from  = $m[0][1] + strlen( $m[0][0] );
			$quote = '' !== $m[3][0] ? $m[3][0] : $m[1][0];
			if ( '' !== $m[3][0] ) {
				$end = self::quoted_end( $text, $from, $quote );
			} elseif ( '=' === $m[2][0] ) {
				$end = $from + strcspn( $text, " \t\r\n&\"'", $from );
			} else {
				$end = $from + strcspn( $text, "\r\n", $from );
			}
			$value  = substr( $text, $from, $end - $from );
			$scheme = '';
			if ( preg_match( '/^([A-Za-z0-9\-]{1,32})[ \t]+/', $value, $s ) && in_array( strtolower( $s[1] ), self::SCHEMES, true ) ) {
				$scheme = $s[0];
			}
			$rest = trim( substr( $value, strlen( $scheme ) ) );
			if ( '' === $rest || self::MARK === $rest || in_array( strtolower( $rest ), self::SCHEMES, true ) ) {
				continue;
			}
			++$hits;
			$out .= substr( $text, $pos, $from - $pos ) . $scheme . self::MARK;
			$pos  = $end;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * Where a quoted text that starts at $from (after its opening quote)
	 * ends: at the closing quote, a backslash escaping the next character.
	 * The end of the text where none follows.
	 *
	 * @param string $text  Text.
	 * @param int    $from  Offset after the opening quote.
	 * @param string $quote The quote.
	 * @return int Offset of the closing quote.
	 */
	private static function quoted_end( $text, $from, $quote ) {
		$len = strlen( $text );
		for ( $j = $from; $j < $len; $j++ ) {
			if ( '\\' === $text[ $j ] ) {
				++$j;
			} elseif ( $quote === $text[ $j ] ) {
				return $j;
			}
		}
		return $len;
	}

	/**
	 * print_r() entries under a credential's name: "[token] => value" loses
	 * the value to the end of the line, "[token] => Array" and
	 * "[token] => Foo Object" lose the whole block that follows, to its
	 * closing parenthesis.
	 *
	 * @param string   $text  Text.
	 * @param int      $hits  Replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string|null
	 */
	private static function print_r_blocks( $text, &$hits, array $extra ) {
		if ( false === strpos( $text, '=>' ) ) {
			return $text;
		}
		$lines = explode( "\n", $text );
		$out   = array();
		$count = count( $lines );
		for ( $i = 0; $i < $count; $i++ ) {
			$line = $lines[ $i ];
			$m    = array();
			$ok   = preg_match( '/^([ \t]*\[([^\]\r\n]{1,128})\][ \t]{0,4}=>[ \t]?)(.*?)(\r?)$/', $line, $m );
			if ( false === $ok ) {
				return null;
			}
			if ( 1 !== $ok || ! self::redacts_key( $m[2], $extra ) || '' === trim( $m[3] ) || self::MARK === trim( $m[3] ) ) {
				$out[] = $line;
				continue;
			}
			++$hits;
			$out[] = $m[1] . self::MARK . $m[4];
			if ( preg_match( '/^(?:Array|[\w\\\\]{1,200} Object)$/', trim( $m[3] ) ) && $i + 1 < $count && '(' === trim( $lines[ $i + 1 ] ) ) {
				$depth = 0;
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$t = trim( $lines[ $j ] );
					if ( '(' === $t ) {
						++$depth;
					} elseif ( ')' === $t ) {
						--$depth;
					}
					if ( 0 === $depth ) {
						break;
					}
				}
				$i = $j;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * name=value, name: value, "name": "value" and 'name' => value pairs
	 * whose name is a credential's lose the value: a quoted one to its
	 * closing quote, an array (var_export()'s "array (", "(object) array(",
	 * "[", "{") to its closing bracket, anything else to the next blank or
	 * separator.
	 *
	 * @param string   $text  Text.
	 * @param int      $hits  Replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string|null
	 */
	private static function pairs( $text, &$hits, array $extra ) {
		$n = preg_match_all( '/(?<![\w\-\[$\\\\])(["\']?)([A-Za-z_][\w\-]{0,127})\1[ \t]{0,16}(=>|:|=)[ \t]{0,16}/', $text, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $found as $m ) {
			if ( $m[0][1] < $pos || ! self::redacts_key( $m[2][0], $extra ) ) {
				continue;
			}
			$from = $m[0][1] + strlen( $m[0][0] );
			$c    = substr( $text, $from, 1 );
			if ( self::MARK === substr( $text, $from, strlen( self::MARK ) ) ) {
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$end   = self::quoted_end( $text, $from + 1, $c );
				$value = substr( $text, $from + 1, $end - $from - 1 );
				if ( '' === $value || false !== strpos( $value, self::MARK ) ) {
					continue;
				}
				$out .= substr( $text, $pos, $from + 1 - $pos ) . self::MARK;
				$pos  = $end;
				++$hits;
				continue;
			}
			// var_export() puts a nested array on the next line.
			$at   = $from + strspn( $text, " \t\r\n", $from );
			$open = preg_match( '/^(?:\(object\)[ \t]*)?(?:array[ \t\r\n]*\(|\\\\?[\w\\\\]{1,200}::__set_state\(|\[|\{)/i', substr( $text, $at, 300 ), $o );
			if ( false === $open ) {
				return null;
			}
			if ( 1 === $open ) {
				$end = self::bracket_end( $text, $at + strlen( $o[0] ) - 1 );
				if ( null === $end || '' === trim( substr( $text, $at + strlen( $o[0] ), $end - $at - strlen( $o[0] ) - 1 ) ) ) {
					continue;
				}
			} else {
				$end   = $from + strcspn( $text, " \t\r\n\"'&,;<>()[]{}", $from );
				$value = substr( $text, $from, $end - $from );
				if ( '' === $value || preg_match( '/^(?:true|false|null)$/i', $value ) || in_array( strtolower( $value ), self::SCHEMES, true ) ) {
					continue;
				}
			}
			$out .= substr( $text, $pos, $from - $pos ) . self::MARK;
			$pos  = $end;
			++$hits;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * Where the bracket opened at $at closes, quoted text skipped.
	 *
	 * @param string $text Text.
	 * @param int    $at   Offset of the opening bracket.
	 * @return int|null Offset after the closing one; null where it never closes.
	 */
	private static function bracket_end( $text, $at ) {
		$len   = strlen( $text );
		$depth = 0;
		for ( $j = $at; $j < $len; $j++ ) {
			$c = $text[ $j ];
			if ( '"' === $c || "'" === $c ) {
				$j = self::quoted_end( $text, $j + 1, $c );
			} elseif ( '(' === $c || '[' === $c || '{' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c || '}' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return $j + 1;
				}
			}
		}
		return null;
	}
}
