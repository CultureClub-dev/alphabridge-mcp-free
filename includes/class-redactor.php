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
 * `redacted`, as a path of keys, never with the value.
 *
 * One rule for both: is_credential_key() is the meta guard's rule, kept here
 * so that the guard (AB_MCP_Tools_Base::is_sensitive_meta_key()), the SQL
 * mirror of the search-and-replace scan and this class read one list.
 * Redaction adds a few names of its own (EXTRA_NAMES) and the keys sites
 * add with the filter ab_mcp_redact_keys; the guard stays as it is.
 *
 * Applied in one place, AB_MCP_REST_Controller::run_tool(), to the result of
 * every tool the registry marks read-only (tools/call and AlphaBridge's own
 * abilities alike, Pro's readers included), and to the part of wp_run_ability's
 * answer that comes from the other plugin. A writing tool's answer is left
 * as it is: wp_create_user names its generated password once, on purpose.
 *
 * Free text is searched in the error log only (wp_get_error_log): headers
 * (Authorization: Bearer …), name=value pairs in queries and print_r() or
 * var_export() lines whose name is a credential's. Never by the look of a
 * value: a long random string is as often an id or a hash.
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

	/** At most this many paths in FIELD; the rest are counted in FIELD_COUNT. */
	const MAX_PATHS = 50;

	/** The whole number of paths, set only where FIELD lists fewer. */
	const FIELD_COUNT = 'redacted_count';

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
	 * Matched as written, on purpose. Normalising the key first — removing
	 * separators, or treating camelCase as a word boundary — would catch
	 * accessToken as well, but it also turns co_author into "coauthor",
	 * which contains "oauth". Ordinary fields must not be refused, so the
	 * guard stays literal.
	 */
	const CREDENTIAL_NEEDLES = array( 'api_key', 'apikey', 'api-key', 'api_token', 'api_secret', 'access_key', 'access_token', 'secret_key', 'secret_token', 'private_key', 'client_secret', 'consumer_key', 'consumer_secret', 'refresh_token', 'auth_token', 'bearer_token', 'license_key', 'oauth', '_token', '_secret', '_password', 'passwd', 'smtp_pass' );

	/**
	 * Names redacted on top of the meta rule, as whole keys: the HTTP headers
	 * that carry a credential, as a plugin's result or a log line may hold
	 * them, a bare "auth", and the two columns of wp_users that hold a
	 * password hash and a reset key, as a database row shows them. Not part
	 * of the meta guard: refusing a meta key of that name is not what these
	 * are for.
	 */
	const EXTRA_NAMES = array( 'authorization', 'proxy-authorization', 'auth', 'user_pass', 'user_activation_key' );

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
	 * Keys whose value is a JSON schema, not data: an ability's input and
	 * output schema name a property "password" without holding one. Not
	 * searched.
	 */
	const SCHEMA_KEYS = array( 'input_schema', 'output_schema', 'inputSchema', 'outputSchema' );

	/**
	 * Places in a reading tool's own answer that look like a credential by
	 * name and are none: the undo tokens wp_undo_list hands out for wp_undo,
	 * the list of application passwords, which names them and never holds
	 * one. A path of keys, '*' for any one key.
	 */
	const KEEP = array(
		'wp_undo_list'                  => array( 'undo_points.*.token' ),
		'wp_list_application_passwords' => array( 'passwords' ),
	);

	/** Tools whose text is searched for credentials, not only their keys. */
	const TEXT_TOOLS = array( 'wp_get_error_log' );

	/**
	 * Tools that hand out a file as it is stored: their text is not taken
	 * apart as serialized data or JSON, because the answer is how the file
	 * gets edited, and a value put back as REDACTED would be written back.
	 */
	const FILE_TOOLS = array( 'wp_read_theme_file', 'wp_read_plugin_file', 'wp_read_upload_file', 'wp_deploy_read' );

	/**
	 * Writing tools of which one part of the answer is another plugin's data,
	 * and which part.
	 */
	const PARTS = array( 'wp_run_ability' => array( 'result' ) );

	/** Nesting deeper than this is left as it is. */
	const MAX_DEPTH = 100;

	/** Serialized data or JSON inside a value inside a value: this many layers. */
	const MAX_LAYERS = 3;

	/**
	 * Whether a key looks like a stored credential: the rule of the meta
	 * guard, without the names that only redaction adds.
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
	 * The names a site redacts on top of the built-in ones, lower case.
	 *
	 * @return string[]
	 */
	public static function extra_keys() {
		/**
		 * Filters the keys whose values the reading tools redact, on top of
		 * the built-in credential names. Each entry is matched as the whole
		 * key, letter case aside: add "my_plugin_license" to have it read as
		 * [REDACTED] wherever it appears. The built-in names cannot be taken
		 * out here.
		 *
		 * @since 4.13.0
		 *
		 * @param string[] $keys Additional key names, empty by default.
		 */
		$keys = apply_filters( 'ab_mcp_redact_keys', array() );
		$out  = array();
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			if ( is_string( $key ) && '' !== trim( $key ) ) {
				$out[] = strtolower( trim( $key ) );
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
		$name = strtolower( trim( (string) $key ) );
		return self::is_credential_key( $name ) || in_array( $name, self::EXTRA_NAMES, true ) || in_array( $name, $extra, true );
	}

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
		$name  = (string) $name;
		$ctx   = self::context( $name );
		$paths = array();
		if ( AB_MCP_Tool_Registry::is_read_only( $name, $def ) ) {
			$result = self::walk( $result, array(), $ctx, $paths, 0, 0 );
		} elseif ( isset( self::PARTS[ $name ] ) && is_array( $result ) ) {
			foreach ( self::PARTS[ $name ] as $part ) {
				if ( array_key_exists( $part, $result ) ) {
					$result[ $part ] = self::walk( $result[ $part ], array( (string) $part ), $ctx, $paths, 1, 0 );
				}
			}
		}
		return self::with_paths( $result, $paths );
	}

	/**
	 * Redact one value with the rules every reading tool gets.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $paths Paths of what was redacted (appended).
	 * @param string   $tool  Tool name, for its own rules (KEEP, TEXT_TOOLS,
	 *                        FILE_TOOLS); '' for none.
	 * @return mixed
	 */
	public static function redact( $value, array &$paths, $tool = '' ) {
		return self::walk( $value, array(), self::context( (string) $tool ), $paths, 0, 0 );
	}

	/**
	 * The result with FIELD set, where something was redacted and the result
	 * is an object with keys to add it to. A list or a text has none: the
	 * REDACTED in place is all it says.
	 *
	 * @param mixed    $result Result.
	 * @param string[] $paths  Paths.
	 * @return mixed
	 */
	public static function with_paths( $result, array $paths ) {
		if ( array() === $paths || ! is_array( $result ) || ( array() !== $result && array_keys( $result ) === range( 0, count( $result ) - 1 ) ) ) {
			return $result;
		}
		if ( isset( $result[ self::FIELD ] ) && is_array( $result[ self::FIELD ] ) ) {
			$paths = array_merge( $result[ self::FIELD ], $paths );
		}
		$paths                  = array_values( array_unique( $paths ) );
		$result[ self::FIELD ] = array_slice( $paths, 0, self::MAX_PATHS );
		if ( count( $paths ) > self::MAX_PATHS ) {
			$result[ self::FIELD_COUNT ] = count( $paths );
		}
		return $result;
	}

	/**
	 * Credentials in free text replaced with REDACTED: the value of an
	 * Authorization header (its scheme stays), a Bearer token, and the value
	 * of a name=value pair, a "name": "value" or 'name' => 'value' pair or a
	 * print_r() line "[name] => value" whose name redacts_key() takes for a
	 * credential's.
	 *
	 * @param string   $text  Text.
	 * @param int      $hits  Number of replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @return string
	 */
	public static function text( $text, &$hits, array $extra = array() ) {
		$text = (string) $text;
		$mark = self::MARK;

		// The value of an Authorization header, its scheme kept.
		$text = (string) preg_replace_callback(
			'/\b((?:proxy-)?authorization["\']?\s*[:=]\s*["\']?)(?:(bearer|basic|digest|negotiate|token)\s+)?([^\s"\',;&]+)/i',
			static function ( $m ) use ( &$hits, $mark ) {
				if ( $mark === $m[3] || ( '' === $m[2] && preg_match( '/^(?:bearer|basic|digest|negotiate)$/i', $m[3] ) ) ) {
					return $m[0];
				}
				++$hits;
				return $m[1] . ( '' !== $m[2] ? $m[2] . ' ' : '' ) . $mark;
			},
			$text
		);

		// A Bearer token anywhere else, as curl's verbose output shows it.
		$text = (string) preg_replace_callback(
			'/\b(bearer)\s+([A-Za-z0-9\-._~+\/]{8,}=*)/i',
			static function ( $m ) use ( &$hits, $mark ) {
				++$hits;
				return $m[1] . ' ' . $mark;
			},
			$text
		);

		// print_r(): "[name] => value", the value up to the end of the line.
		// A nested array or object ("Array", "stdClass Object") goes on in
		// the lines below, each of which is judged by its own name.
		$text = (string) preg_replace_callback(
			'/^([ \t]*\[([^\]\r\n]+)\][ \t]*=>[ \t]*)(\S[^\r\n]*?)[ \t]*$/m',
			static function ( $m ) use ( &$hits, $mark, $extra ) {
				if ( $mark === $m[3] || ! self::redacts_key( $m[2], $extra ) || preg_match( '/^(?:Array|\w+ Object)$/', $m[3] ) ) {
					return $m[0];
				}
				++$hits;
				return $m[1] . $mark;
			},
			$text
		);

		// name=value (a query), name: value, "name": "value", 'name' => 'value'.
		$text = (string) preg_replace_callback(
			'/(?<![\w\-\[$])(["\']?)([A-Za-z_][\w\-]*)\1(\s*(?:=>|:|=)\s*)(?:(["\'])((?:\\\\.|(?!\4)[^\\\\])*)\4|([^\s"\'&,;<>()\[\]{}]+))/',
			static function ( $m ) use ( &$hits, $mark, $extra ) {
				if ( ! self::redacts_key( $m[2], $extra ) ) {
					return $m[0];
				}
				$quoted = isset( $m[4] ) && '' !== $m[4];
				$value  = $quoted ? $m[5] : ( isset( $m[6] ) ? $m[6] : '' );
				if ( '' === $value || $mark === $value || preg_match( '/^(?:true|false|null|bearer|basic|digest|negotiate)$/i', $value ) ) {
					return $m[0];
				}
				++$hits;
				return $m[1] . $m[2] . $m[1] . $m[3] . ( $quoted ? $m[4] . $mark . $m[4] : $mark );
			},
			$text
		);

		return $text;
	}

	/**
	 * The rules for one tool.
	 *
	 * @param string $name Tool name.
	 * @return array{keep: array<int,string[]>, text: bool, parse: bool, extra: string[]}
	 */
	private static function context( $name ) {
		$keep = array();
		foreach ( isset( self::KEEP[ $name ] ) ? self::KEEP[ $name ] : array() as $path ) {
			$keep[] = explode( '.', $path );
		}
		return array(
			'keep'  => $keep,
			'text'  => in_array( $name, self::TEXT_TOOLS, true ),
			'parse' => ! in_array( $name, self::FILE_TOOLS, true ),
			'extra' => self::extra_keys(),
		);
	}

	/**
	 * Whether a path is one KEEP names for the tool.
	 *
	 * @param string[] $path Path.
	 * @param array    $ctx  context().
	 * @return bool
	 */
	private static function kept( array $path, array $ctx ) {
		foreach ( $ctx['keep'] as $keep ) {
			if ( count( $keep ) !== count( $path ) ) {
				continue;
			}
			$match = true;
			foreach ( $keep as $i => $segment ) {
				if ( '*' !== $segment && $segment !== $path[ $i ] ) {
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
	 * false, an empty string or an empty array say only whether one is set,
	 * and stay as they are.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function holds( $value ) {
		if ( null === $value || is_bool( $value ) || '' === $value || array() === $value ) {
			return false;
		}
		return ! ( is_object( $value ) && ! $value instanceof JsonSerializable && array() === get_object_vars( $value ) );
	}

	/**
	 * Redact a value: arrays and objects by their keys, text by the rules of
	 * the tool.
	 *
	 * @param mixed    $value  Value.
	 * @param string[] $path   Its path.
	 * @param array    $ctx    context().
	 * @param string[] $found  Paths redacted (appended).
	 * @param int      $depth  Nesting so far.
	 * @param int      $layers Serialized or JSON layers so far.
	 * @return mixed The value, the same where nothing was redacted.
	 */
	private static function walk( $value, array $path, array $ctx, array &$found, $depth, $layers ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return self::walk_string( $value, $path, $ctx, $found, $depth, $layers );
		}
		if ( is_object( $value ) ) {
			// What wp_json_encode() writes: the public properties, or what
			// jsonSerialize() returns. Rebuilt only where something changed.
			$json = $value instanceof JsonSerializable;
			$vars = $json ? $value->jsonSerialize() : get_object_vars( $value );
			$seen = count( $found );
			$new  = self::walk( $vars, $path, $ctx, $found, $depth, $layers );
			if ( count( $found ) === $seen ) {
				return $value;
			}
			return ( ! $json && is_array( $new ) ) ? (object) $new : $new;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		// A pair: the name in one field, the value in the other.
		foreach ( self::PAIRS as $name_key => $value_key ) {
			if ( isset( $value[ $name_key ] ) && is_string( $value[ $name_key ] ) && array_key_exists( $value_key, $value )
				&& self::redacts_key( $value[ $name_key ], $ctx['extra'] ) && self::holds( $value[ $value_key ] ) ) {
				$p = array_merge( $path, array( (string) $value_key ) );
				if ( ! self::kept( $p, $ctx ) && self::MARK !== $value[ $value_key ] ) {
					$value[ $value_key ] = self::MARK;
					$found[]             = implode( '.', $p );
				}
			}
		}

		foreach ( $value as $key => $item ) {
			if ( in_array( $key, self::SCHEMA_KEYS, true ) ) {
				continue;
			}
			$p = array_merge( $path, array( (string) $key ) );
			if ( ! self::kept( $p, $ctx ) && self::redacts_key( $key, $ctx['extra'] ) ) {
				if ( self::holds( $item ) && self::MARK !== $item ) {
					$value[ $key ] = self::MARK;
					$found[]       = implode( '.', $p );
				}
				continue;
			}
			$seen = count( $found );
			$new  = self::walk( $item, $p, $ctx, $found, $depth + 1, $layers );
			if ( count( $found ) !== $seen ) {
				$value[ $key ] = $new;
			}
		}
		return $value;
	}

	/**
	 * Redact a text: by the free-text rules where the tool's text is
	 * searched, and inside serialized data or JSON it holds.
	 *
	 * @param string   $value  Text.
	 * @param string[] $path   Its path.
	 * @param array    $ctx    context().
	 * @param string[] $found  Paths redacted (appended).
	 * @param int      $depth  Nesting so far.
	 * @param int      $layers Serialized or JSON layers so far.
	 * @return string
	 */
	private static function walk_string( $value, array $path, array $ctx, array &$found, $depth, $layers ) {
		if ( $ctx['text'] ) {
			$hits  = 0;
			$value = self::text( $value, $hits, $ctx['extra'] );
			if ( $hits > 0 ) {
				$found[] = implode( '.', $path );
			}
		}
		if ( ! $ctx['parse'] || $layers >= self::MAX_LAYERS || strlen( $value ) < 2 ) {
			return $value;
		}

		// A serialized array or object, as get_post_meta() returns a value
		// that was serialized twice, or a database row holds one. Objects come
		// back as __PHP_Incomplete_Class: no class is loaded, no code runs.
		if ( preg_match( '/^(?:a|O|C):\d+:/', $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			$data = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( is_array( $data ) || is_object( $data ) ) {
				$seen = count( $found );
				$new  = self::walk( $data, $path, $ctx, $found, $depth, $layers + 1 );
				if ( count( $found ) !== $seen ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					return serialize( $new );
				}
			}
			return $value;
		}

		// A JSON document, as plugins store settings in a meta value.
		$first = substr( ltrim( $value ), 0, 1 );
		if ( '{' === $first || '[' === $first ) {
			$data = json_decode( $value, true );
			if ( is_array( $data ) ) {
				$seen = count( $found );
				$new  = self::walk( $data, $path, $ctx, $found, $depth, $layers + 1 );
				if ( count( $found ) !== $seen ) {
					$json = wp_json_encode( $new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					return is_string( $json ) ? $json : self::MARK;
				}
			}
		}
		return $value;
	}
}
