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
	 * post, a revision or a comment, raw and rendered, the caption, alt text
	 * and description of a file or a term, and the text of a text widget.
	 * Their text is not decoded (a tutorial with "password": "…" in it would
	 * lose its example when saved again), and neither run_tool() nor
	 * value_back() looks for the placeholder in them. A key the reader
	 * redacts (redacts_key(), the filter's names included) is redacted first,
	 * so it is no such field (is_content_field()). Keys of an array below
	 * such a field are judged as everywhere.
	 */
	const CONTENT_FIELDS = array( 'content', 'content_raw', 'post_content', 'post_content_filtered', 'excerpt', 'post_excerpt', 'title', 'post_title', 'comment_content', 'rendered', 'raw', 'caption', 'alt', 'description', 'text' );

	/**
	 * Writing tools of which one part of the answer is another plugin's data,
	 * and which part: the result of an ability, and the read-back of what a
	 * writer stored (saved_fields), which holds the credential a value sent
	 * with [REDACTED] kept (restore_placeholders()). Terms, menus and
	 * widgets answer with saved_fields too, and wp_update_widget with the
	 * whole stored instance in settings.
	 */
	const PARTS = array(
		'wp_run_ability'      => array( 'result' ),
		'wp_update_post_meta' => array( 'saved_fields' ),
		'wp_update_term_meta' => array( 'saved_fields' ),
		'wp_update_user_meta' => array( 'saved_fields' ),
		'wp_update_media'     => array( 'saved_fields' ),
		'wp_create_post'      => array( 'saved_fields', 'meta' ),
		'wp_update_post'      => array( 'saved_fields', 'meta' ),
		'wp_create_term'      => array( 'saved_fields' ),
		'wp_update_term'      => array( 'saved_fields' ),
		'wp_create_menu'      => array( 'saved_fields' ),
		'wp_add_menu_item'    => array( 'saved_fields' ),
		'wp_add_widget'       => array( 'saved_fields' ),
		'wp_update_widget'    => array( 'saved_fields', 'settings' ),
	);

	/**
	 * Writing tools that put the stored value back for the placeholder
	 * themselves, and the core's handler that does it: the meta writers
	 * (value_back()) and wp_update_widget, from the stored instance
	 * (restore_placeholders()). run_tool() lets their arguments through only
	 * where the tool runs that very handler (restores()); another one
	 * registered for the name (AlphaBridge MCP Pro 4.12 brings its own
	 * copies, which put nothing back) is guarded as every writer.
	 */
	const RESTORES = array(
		'wp_update_post_meta' => array( 'AB_MCP_Tools_Content', 'update_post_meta_tool' ),
		'wp_update_term_meta' => array( 'AB_MCP_Tools_Meta_Auth', 'update_term_meta_tool' ),
		'wp_update_user_meta' => array( 'AB_MCP_Tools_Meta_Auth', 'update_user_meta_tool' ),
		'wp_update_widget'    => array( 'AB_MCP_Tools_Widgets', 'update_widget' ),
	);

	/**
	 * Arguments of a writing tool that map names to texts authors wrote, each
	 * taken as a field people write (CONTENT_FIELDS) by run_tool(): the
	 * fields of a page-builder element, which wp_get_builder_layout hands out
	 * as stored (NO_DECODE). A field the reader redacts is guarded as
	 * everywhere.
	 */
	const TEXT_ARGS = array( 'wp_update_builder_element' => array( 'fields' ) );

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

	/**
	 * Lines a value in brackets under a credential's name is followed over
	 * (JSON_PRETTY_PRINT puts it on the next lines); beyond, it is redacted
	 * to the end of its log entry.
	 */
	const BLOCK_LINES = 200;

	/** The first line of a log entry: [30-Oct-2026 10:00:00 UTC] … */
	const ENTRY = '/^\[\d{1,2}-[A-Za-z]{3}-\d{4}/';

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
		$extra = $st['extra'];
		$point = static function ( $path ) use ( $extra ) {
			return self::pointer( $path, $extra );
		};
		$result[ self::FIELD ] = array_map( $point, $st['found'] );
		if ( $st['found_n'] > self::MAX_PATHS ) {
			$result[ self::FIELD_COUNT ] = $st['found_n'];
		}
		if ( $st['unchecked_n'] > 0 ) {
			$result[ self::FIELD_UNCHECKED ] = array_map( $point, $st['unchecked'] );
		}
		return $result;
	}

	/**
	 * Whether run_tool() looks for the placeholder in a tool's arguments:
	 * for every tool that writes, but those that put the stored value back
	 * themselves (restores()) and those that take patterns (PATTERN_TOOLS).
	 *
	 * @param string $name Tool name.
	 * @param array  $def  The tool's definition.
	 * @return bool
	 */
	public static function guards( $name, array $def ) {
		return ! AB_MCP_Tool_Registry::is_read_only( $name, $def ) && ! self::restores( $name, $def ) && ! in_array( $name, self::PATTERN_TOOLS, true );
	}

	/**
	 * Whether a tool puts the stored value back itself: one of RESTORES whose
	 * definition runs the core's own handler for it, compared as a callback
	 * (its class and method, regardless of case as PHP resolves them). A
	 * subclass's method of the same name is another handler.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  The tool's definition.
	 * @return bool
	 */
	public static function restores( $name, array $def ) {
		$name = (string) $name;
		if ( ! isset( self::RESTORES[ $name ], $def['callback'] ) ) {
			return false;
		}
		$callback = is_string( $def['callback'] ) ? explode( '::', $def['callback'] ) : $def['callback'];
		if ( ! is_array( $callback ) || array( 0, 1 ) !== array_keys( $callback ) || ! is_string( $callback[1] ) ) {
			return false;
		}
		$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : ( is_string( $callback[0] ) ? ltrim( $callback[0], '\\' ) : '' );
		return 0 === strcasecmp( $class, self::RESTORES[ $name ][0] ) && 0 === strcasecmp( $callback[1], self::RESTORES[ $name ][1] );
	}

	/**
	 * Where an argument of a writing tool carries the placeholder back as an
	 * answer holds it (carries_mark()): a text that is [REDACTED], JSON or
	 * serialized data that holds it once decoded, a key read as
	 * name=[REDACTED]. A text that only mentions or quotes it passes, as it
	 * did before 4.13.0. In the reader's order (walk_array()): a key the
	 * reader redacts is looked at; a text directly under a field people write
	 * (is_content_field()) is not, and the texts below such a field are not
	 * decoded; an array under it is looked at, as the reader redacts keys
	 * there too. The values under a tool's TEXT_ARGS are such fields.
	 *
	 * @param mixed  $args Arguments.
	 * @param string $name Tool name.
	 * @return string|null The JSON pointer of the first such value; null for none.
	 */
	public static function placeholder_in( $args, $name = '' ) {
		$budget = self::BUDGET;
		$extra  = self::extra_keys();
		foreach ( isset( self::TEXT_ARGS[ $name ] ) && is_array( $args ) ? self::TEXT_ARGS[ $name ] : array() as $arg ) {
			if ( isset( $args[ $arg ] ) ) {
				$hit = self::find_mark( $args[ $arg ], array( $arg ), 1, $budget, $extra, false, true );
				if ( null !== $hit ) {
					return $hit;
				}
				unset( $args[ $arg ] );
			}
		}
		return self::find_mark( $args, array(), 0, $budget, $extra, true );
	}

	/**
	 * placeholder_in() one level.
	 *
	 * @param mixed    $value  Value.
	 * @param array    $path   Its path.
	 * @param int      $depth  Nesting so far.
	 * @param int      $budget Visits left.
	 * @param string[] $extra  extra_keys().
	 * @param bool     $parse  Whether texts here are decoded, as the reader decodes them.
	 * @param bool     $texts  Whether each key here is a field people write (TEXT_ARGS).
	 * @return string|null
	 */
	private static function find_mark( $value, array $path, $depth, &$budget, array $extra, $parse, $texts = false ) {
		if ( is_string( $value ) ) {
			return self::marked( $value, $depth, $budget, $extra, $parse ) ? self::pointer( $path, $extra ) : null;
		}
		if ( ( ! is_array( $value ) && ! $value instanceof stdClass && ! $value instanceof __PHP_Incomplete_Class ) || --$budget < 0 || $depth > self::MAX_DEPTH ) {
			return null;
		}
		foreach ( is_array( $value ) ? $value : get_object_vars( $value ) as $k => $v ) {
			if ( self::is_masked_key( $k, $extra ) ) {
				return self::pointer( array_merge( $path, array( $k ) ), $extra );
			}
			$content = $texts ? ! self::redacts_key( $k, $extra ) : self::is_content_field( $k, $extra );
			if ( $content && is_string( $v ) ) {
				continue;
			}
			$hit = self::find_mark( $v, array_merge( $path, array( $k ) ), $depth + 1, $budget, $extra, $parse && ! $content );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/**
	 * Whether a text carries the placeholder as an answer holds it: the
	 * whole text, or a JSON document or serialized data that holds it, decoded
	 * as the reader decodes texts (decode()): a value that is [REDACTED] or a
	 * key read as name=[REDACTED], at any depth, in texts encoded inside it
	 * too. A text that only mentions or quotes it, as a person writes it
	 * ('Name: "[REDACTED]"'), does not.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function carries_mark( $text ) {
		$budget = self::BUDGET;
		return self::marked( (string) $text, 0, $budget, self::extra_keys(), true );
	}

	/**
	 * carries_mark() one level. A text the reader would not decode here
	 * (below a field people write) carries it only as a whole; one too deep
	 * or too large to decode and look through carries it where it holds it
	 * at all (fail-closed).
	 *
	 * @param string   $text   Text.
	 * @param int      $depth  Nesting so far.
	 * @param int      $budget Visits left.
	 * @param string[] $extra  extra_keys().
	 * @param bool     $parse  Whether the reader decodes this text.
	 * @return bool
	 */
	private static function marked( $text, $depth, &$budget, array $extra, $parse ) {
		if ( self::MARK === $text ) {
			return true;
		}
		if ( ! $parse || false === strpos( $text, self::MARK ) ) {
			return false;
		}
		$kind = self::decode( $text, $data );
		if ( null === $kind ) {
			return false;
		}
		if ( 'deep' === $kind || --$budget < 0 || $depth > self::MAX_DEPTH ) {
			return true;
		}
		return null !== self::find_mark( $data, array(), $depth + 1, $budget, $extra, true );
	}

	/**
	 * Whether a key reads as the answer shows a key that holds a
	 * credential's name=value or name: value (masked()): a credential's
	 * name, its separator, [REDACTED].
	 *
	 * @param string|int $key   Key.
	 * @param string[]   $extra extra_keys().
	 * @return bool
	 */
	private static function is_masked_key( $key, array $extra ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		$name = strlen( $key ) - strlen( self::MARK );
		return $name > 0 && self::MARK === substr( $key, $name ) && strcspn( $key, '=:' ) === $name - 1
			&& self::redacts_key( substr( $key, 0, $name - 1 ), $extra );
	}

	/**
	 * Whether a key is a field people write (CONTENT_FIELDS) and not one the
	 * reader redacts: the reader redacts first (walk_array()), so a filter
	 * that adds "description" makes it a credential's name.
	 *
	 * @param string|int $key   Key.
	 * @param string[]   $extra extra_keys().
	 * @return bool
	 */
	private static function is_content_field( $key, array $extra ) {
		return is_string( $key ) && in_array( strtolower( $key ), self::CONTENT_FIELDS, true ) && ! self::redacts_key( $key, $extra );
	}

	/**
	 * The refusal of a value that carries the placeholder back.
	 *
	 * @param string $pointer Where, as a JSON pointer.
	 * @return WP_Error
	 */
	public static function refusal( $pointer ) {
		return new WP_Error(
			'ab_mcp_redacted_value',
			sprintf(
				/* translators: 1: where in the arguments, as a JSON pointer such as /value/api_key; 2: the placeholder [REDACTED] */
				__( 'The argument at %1$s holds %2$s, the placeholder that stands for a credential in the answer of a reading tool. Written back, it would replace the stored credential, so nothing was changed. Send the real value, or leave this field out so that the stored one stays.', 'alphabridge-mcp' ),
				'' === (string) $pointer ? '/' : (string) $pointer,
				self::MARK
			)
		);
	}

	/**
	 * A meta value to be written, with the stored credential back where it
	 * holds [REDACTED] (restore_placeholders()); the refusal where a
	 * placeholder is left. The three meta writers ask this after their
	 * rights checks and before the shared write (AB_MCP_Undo_Meta::write()),
	 * so the value written, compared in saved_fields and kept in the undo
	 * point is the restored one. Compared with the first stored value of the
	 * key: the one wp_get_post_meta shows for the key, and the one value
	 * update_*_meta() leaves in every row. A stored object put back is
	 * written with slash(), not wp_slash(); one slash() cannot copy is
	 * refused.
	 *
	 * @param string $type post, term or user.
	 * @param int    $id   Object id.
	 * @param string $key  Meta key.
	 * @param mixed  $value Value sent.
	 * @return mixed|WP_Error The value to write.
	 */
	public static function value_back( $type, $id, $key, $value ) {
		if ( is_string( $value ) ) {
			return self::carries_mark( $value ) ? self::refusal( '/value' ) : $value;
		}
		if ( ! is_array( $value ) && ! $value instanceof stdClass ) {
			return $value;
		}
		$get    = 'get_' . $type . '_meta';
		$stored = function_exists( $get ) ? $get( (int) $id, (string) $key, true ) : '';
		$back   = self::restore_placeholders( $value, $stored, $left );
		if ( $left ) {
			return self::refusal( '/value' );
		}
		// A stored object put back that slash() cannot copy would lose its
		// backslashes on the way in: not written at all.
		$lost = false;
		self::slash( $back, $lost );
		if ( $lost ) {
			return new WP_Error(
				'ab_mcp_redacted_value',
				sprintf(
					/* translators: %1$s: the placeholder [REDACTED] */
					__( 'Nothing was changed: where the value holds %1$s, the stored value is an object that cannot be written back unchanged. Send the real value in place of %1$s, or change it in the plugin that stores it.', 'alphabridge-mcp' ),
					self::MARK
				)
			);
		}
		return $back;
	}

	/**
	 * Put the stored values back where a value to be written is exactly
	 * REDACTED, at the same path in arrays and stdClass objects, a stored
	 * object read as the reader shows it (vars()); and the stored key where a
	 * key reads name=REDACTED, the one stored key the answer showed so. A
	 * text under a field people write (is_content_field()) is taken as sent,
	 * in the reader's order: a key the reader redacts is restored.
	 * $left says whether a placeholder is left after that (carries_mark()):
	 * one without a stored value at its path, a key without its one stored
	 * key, one inside JSON or serialized data, or a value too deep or too
	 * large to look through. The writer refuses then.
	 *
	 * @param mixed $new    Value to be written.
	 * @param mixed $stored Value stored now.
	 * @param bool  $left   Set to whether a placeholder is left.
	 * @return mixed
	 */
	public static function restore_placeholders( $new, $stored, &$left = false ) {
		$left   = false;
		$budget = self::BUDGET;
		return self::restore( $new, true, $stored, 0, $budget, $left, self::extra_keys(), true );
	}

	/**
	 * restore_placeholders() one level.
	 *
	 * @param mixed    $new    Value to be written.
	 * @param bool     $has    Whether something is stored at this path.
	 * @param mixed    $stored What is stored there.
	 * @param int      $depth  Nesting so far.
	 * @param int      $budget Visits left.
	 * @param bool     $left   Set where a placeholder is left.
	 * @param string[] $extra  extra_keys().
	 * @param bool     $parse  Whether the reader decodes texts here.
	 * @return mixed
	 */
	private static function restore( $new, $has, $stored, $depth, &$budget, &$left, array $extra, $parse ) {
		if ( is_string( $new ) ) {
			if ( self::MARK === $new && $has ) {
				return $stored;
			}
			$left = $left || self::marked( $new, $depth, $budget, $extra, $parse );
			return $new;
		}
		if ( ! is_array( $new ) && ! $new instanceof stdClass ) {
			return $new;
		}
		if ( --$budget < 0 || $depth > self::MAX_DEPTH ) {
			$left = true;
			return $new;
		}
		$vars = $has ? self::vars( $stored ) : array();
		$out  = is_array( $new ) ? array() : new stdClass();
		foreach ( is_array( $new ) ? $new : get_object_vars( $new ) as $k => $v ) {
			if ( self::is_masked_key( $k, $extra ) && ! array_key_exists( $k, $vars ) ) {
				$key  = self::stored_key( $k, $vars, $extra );
				$left = $left || null === $key;
				$k    = null === $key ? $k : $key;
			}
			$content = self::is_content_field( $k, $extra );
			if ( $content && is_string( $v ) ) {
				// What people wrote, written as sent: placeholder_in() does
				// not look at it either.
				$back = $v;
			} else {
				$here = array_key_exists( $k, $vars );
				$back = self::restore( $v, $here, $here ? $vars[ $k ] : null, $depth + 1, $budget, $left, $extra, $parse && ! $content );
			}
			if ( is_array( $out ) ) {
				$out[ $k ] = $back;
			} else {
				$out->$k = $back;
			}
		}
		return $out;
	}

	/**
	 * What a stored value holds under its keys, as the reader shows it
	 * (walk()): an array as it is, a stdClass and an object unserialize()
	 * could not load by their properties, any other object by its JSON form
	 * (ArrayObject's content, what JsonSerializable answers), its objects
	 * read as arrays.
	 *
	 * @param mixed $stored Stored value.
	 * @return array
	 */
	private static function vars( $stored ) {
		if ( is_array( $stored ) ) {
			return $stored;
		}
		if ( $stored instanceof stdClass || $stored instanceof __PHP_Incomplete_Class ) {
			return get_object_vars( $stored );
		}
		if ( ! is_object( $stored ) ) {
			return array();
		}
		try {
			$json = wp_json_encode( $stored );
		} catch ( \Throwable $e ) {
			return array();
		}
		$data = is_string( $json ) ? json_decode( $json, true, 512, JSON_BIGINT_AS_STRING ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * The stored key a key read as name=REDACTED stands for: the one of
	 * these keys the reader shows so (masked()). Null where none does, or
	 * more than one (the answer showed them as one).
	 *
	 * @param string   $key   Key sent.
	 * @param array    $vars  Stored keys and values.
	 * @param string[] $extra extra_keys().
	 * @return string|null
	 */
	private static function stored_key( $key, array $vars, array $extra ) {
		$found = null;
		foreach ( array_keys( $vars ) as $stored ) {
			if ( self::masked( $stored, $extra ) === $key ) {
				if ( null !== $found ) {
					return null;
				}
				$found = $stored;
			}
		}
		return $found;
	}

	/**
	 * A value to be written with update_*_meta(): slashed as wp_slash()
	 * does, and in the public properties of an object too. value_back() can
	 * put a stored object back; wp_slash() leaves it as it is, but
	 * update_metadata() unslashes it (map_deep()), so each backslash in its
	 * texts would be lost. An object is copied, not changed in place. $lost
	 * says whether one could not be (a class that allows no copy, a property
	 * that cannot be set, a class that is not loaded, objects nested beyond
	 * MAX_DEPTH); value_back() refuses such a value.
	 *
	 * @param mixed $value Value.
	 * @param bool  $lost  Set where an object could not be slashed.
	 * @param int   $depth Objects nested so far.
	 * @return mixed
	 */
	public static function slash( $value, &$lost = false, $depth = 0 ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::slash( $v, $lost, $depth );
			}
			return $value;
		}
		if ( ! is_object( $value ) ) {
			return is_string( $value ) ? wp_slash( $value ) : $value;
		}
		if ( $depth >= self::MAX_DEPTH ) {
			$lost = true;
			return $value;
		}
		try {
			$copy = clone $value;
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$copy->$k = self::slash( $v, $lost, $depth + 1 );
			}
			return $copy;
		} catch ( \Throwable $e ) {
			$lost = true;
			return $value;
		}
	}

	/**
	 * A path as a JSON pointer (RFC 6901): "/" before each key, "~" and "/"
	 * in a key as "~0" and "~1". A key that reads like a credential's
	 * name=value or name: value is cut after the name, by the same names as
	 * the key itself (extra_keys() included).
	 *
	 * @param array      $path  Keys.
	 * @param array|null $extra extra_keys(); null to ask the filter.
	 * @return string
	 */
	public static function pointer( array $path, ?array $extra = null ) {
		$out = '';
		foreach ( $path as $segment ) {
			$segment = (string) $segment;
			$cut     = strcspn( $segment, '=:' );
			if ( $cut < strlen( $segment ) ) {
				$extra = null === $extra ? self::extra_keys() : $extra;
				if ( self::redacts_key( substr( $segment, 0, $cut ), $extra ) ) {
					$segment = substr( $segment, 0, $cut + 1 ) . '…';
				}
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
		$masked = self::masked( $key, $st['extra'] );
		if ( null === $masked ) {
			return $key;
		}
		self::note( $st, 'found', $path );
		return $masked;
	}

	/**
	 * A key that reads like a credential's name=value or name: value as the
	 * answer shows it: the name, its separator, REDACTED. Null for any other
	 * key.
	 *
	 * @param string|int $key   Key.
	 * @param string[]   $extra extra_keys().
	 * @return string|null
	 */
	private static function masked( $key, array $extra ) {
		if ( ! is_string( $key ) ) {
			return null;
		}
		$cut = strcspn( $key, '=:' );
		if ( $cut >= strlen( $key ) || '' === trim( substr( $key, $cut + 1 ) ) || ! self::redacts_key( substr( $key, 0, $cut ), $extra ) ) {
			return null;
		}
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
	 *   (Bearer, Basic, Digest) kept; a value whose brackets ([ or {) are
	 *   still open there on to the line that closes them (bracket_end());
	 * - a pair in JSON encoded inside the line (\"name\":\"value\", at any
	 *   level of escaping): the value to its closing quote of the same
	 *   level (escaped_pairs());
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
			$open = 0;
			$new  = self::line( $line, $hits, $extra, $open );
			if ( null === $new ) {
				return null;
			}
			$out[] = $new;
			if ( $open > 0 ) {
				$i = self::bracket_end( $lines, $i, $open );
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * The last line of a value in brackets that a credential's name opens on
	 * line $i: the line that closes them, counted in JSON's way (brackets in
	 * a string do not count). Fail-closed where none does within
	 * BLOCK_LINES: the line before the next log entry, or the last line.
	 *
	 * @param string[] $lines Lines.
	 * @param int      $i     The line with the name.
	 * @param int      $open  Brackets open at its end.
	 * @return int
	 */
	private static function bracket_end( array $lines, $i, $open ) {
		$count = count( $lines );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( preg_match( self::ENTRY, $lines[ $j ] ) ) {
				return $j - 1;
			}
			if ( $j - $i <= self::BLOCK_LINES ) {
				$open += self::brackets( $lines[ $j ] );
				if ( $open <= 0 ) {
					return $j;
				}
			}
		}
		return $count - 1;
	}

	/**
	 * Brackets a line opens ([ and {), less those it closes, outside of
	 * double-quoted strings.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function brackets( $text ) {
		$depth = 0;
		$in    = false;
		$len   = strlen( $text );
		for ( $k = 0; $k < $len; $k++ ) {
			$c = $text[ $k ];
			if ( $in ) {
				if ( '\\' === $c ) {
					++$k;
				} elseif ( '"' === $c ) {
					$in = false;
				}
			} elseif ( '"' === $c ) {
				$in = true;
			} elseif ( '[' === $c || '{' === $c ) {
				++$depth;
			} elseif ( ']' === $c || '}' === $c ) {
				--$depth;
			}
		}
		return $depth;
	}

	/**
	 * Every private key in PEM form, whatever name stands before it: from
	 * -----BEGIN … PRIVATE KEY----- to its -----END …----- line, or to the
	 * end of the text where none follows; and a key whose BEGIN line was cut
	 * off, as the last lines of a log cut it, from the start of the text to
	 * its -----END … PRIVATE KEY----- line.
	 *
	 * @param string $text Text.
	 * @param int    $hits Replacements (added to).
	 * @return string
	 */
	private static function pem( $text, &$hits ) {
		$out   = '';
		$pos   = 0;
		$end   = self::private_key_line( $text, '-----END ', 0 );
		$begin = self::private_key_line( $text, '-----BEGIN ', 0 );
		if ( null !== $end && ( null === $begin || $begin[0] > $end[0] ) ) {
			$out = self::MARK;
			$pos = $end[1];
			++$hits;
		}
		while ( null !== ( $head = self::private_key_line( $text, '-----BEGIN ', $pos ) ) ) {
			$end  = strpos( $text, '-----END ', $head[1] );
			$stop = false === $end ? false : strpos( $text, '-----', $end + 9 );
			$out .= substr( $text, $pos, $head[0] - $pos ) . self::MARK;
			$pos  = false === $stop ? strlen( $text ) : $stop + 5;
			++$hits;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * The first -----BEGIN … PRIVATE KEY----- or -----END … PRIVATE
	 * KEY----- line from an offset on (a certificate's is passed over):
	 * where it starts and where its closing dashes end.
	 *
	 * @param string $text   Text.
	 * @param string $marker '-----BEGIN ' or '-----END '.
	 * @param int    $from   Offset.
	 * @return int[]|null
	 */
	private static function private_key_line( $text, $marker, $from ) {
		while ( false !== ( $at = strpos( $text, $marker, $from ) ) ) {
			$close = strpos( $text, '-----', $at + strlen( $marker ) );
			if ( false === $close ) {
				return null;
			}
			if ( false !== strpos( substr( $text, $at, $close - $at ), 'PRIVATE KEY' ) ) {
				return array( $at, $close + 5 );
			}
			$from = $at + strlen( $marker );
		}
		return null;
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
	 * the line (an Authorization header's scheme kept), and before it every
	 * pair in encoded JSON (escaped_pairs()) and every Bearer token. All
	 * rules read every line.
	 *
	 * @param string   $line  Line.
	 * @param int      $hits  Replacements (added to).
	 * @param string[] $extra extra_keys().
	 * @param int      $open  Set to the brackets the redacted value leaves open.
	 * @return string|null
	 */
	private static function line( $line, &$hits, array $extra, &$open = 0 ) {
		$n = preg_match_all( '/(?<![\w\-$])(["\']?)([A-Za-z_][\w\-]{0,127})\1[ \t]{0,16}(=>|:|=)[ \t]{0,16}/', $line, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		// The first credential's pair: its value to the end of the line.
		$cut  = null;
		$tail = '';
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
			$cut    = $from;
			$tail   = $scheme . self::MARK . ( "\r" === substr( $line, -1 ) ? "\r" : '' );
			$open   = self::brackets( $rest );
			break;
		}
		// Every pair in encoded JSON and every Bearer token in what stays of
		// the line.
		$head = self::escaped_pairs( null === $cut ? $line : substr( $line, 0, $cut ), $hits, $extra, $to_end );
		$head = null === $head ? null : self::bearer( $head, $hits );
		if ( null === $head ) {
			return null;
		}
		if ( $to_end ) {
			// A value without quotes in encoded JSON: redacted to the end;
			// brackets a pair after it left open are still followed.
			return $head . ( "\r" === substr( $line, -1 ) ? "\r" : '' );
		}
		if ( null === $cut ) {
			return $head;
		}
		++$hits;
		return $head . $tail;
	}

	/**
	 * Every pair under a credential's name in JSON encoded inside a text, at
	 * any level of escaping: \"name\":\"value\" (wp_json_encode() of
	 * wp_json_encode()), \\\"name\\\":\\\"value\\\" and so on. A text value is
	 * redacted to its closing quote of the same level, the quotes kept; any
	 * other value (a number, an object) to the end of the text, and so is
	 * one whose closing quote is not found (fail-closed). An empty text,
	 * true, false and null stay.
	 *
	 * @param string   $text   Text.
	 * @param int      $hits   Replacements (added to).
	 * @param string[] $extra  extra_keys().
	 * @param bool     $to_end Set where the rest of the text was redacted.
	 * @return string|null Null where the pattern gave up.
	 */
	private static function escaped_pairs( $text, &$hits, array $extra, &$to_end ) {
		$to_end = false;
		if ( false === strpos( $text, '\\"' ) ) {
			return $text;
		}
		$n = preg_match_all( '/(?<!\\\\)(\\\\+)"([A-Za-z_][\w\-]{0,127})\1"[ \t]{0,16}:[ \t]{0,16}/', $text, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $found as $m ) {
			if ( $m[0][1] < $pos || ! self::redacts_key( $m[2][0], $extra ) ) {
				continue;
			}
			$quote = $m[1][0] . '"';
			$from  = $m[0][1] + strlen( $m[0][0] );
			if ( $quote === substr( $text, $from, strlen( $quote ) ) ) {
				$start = $from + strlen( $quote );
				$end   = self::escaped_close( $text, $start, strlen( $m[1][0] ) );
				if ( $start === $end ) {
					continue;
				}
				++$hits;
				if ( null === $end ) {
					$to_end = true;
					return $out . substr( $text, $pos, $start - $pos ) . self::MARK;
				}
				$out .= substr( $text, $pos, $start - $pos ) . self::MARK;
				$pos  = $end;
				continue;
			}
			if ( preg_match( '/^(?:true|false|null)\b/i', substr( $text, $from, 5 ) ) ) {
				continue;
			}
			++$hits;
			$to_end = true;
			return $out . substr( $text, $pos, $from - $pos ) . self::MARK;
		}
		return $out . substr( $text, $pos );
	}

	/**
	 * Where the closing quote of an encoded text value begins: the first "
	 * from an offset on with exactly as many backslashes before it as the
	 * opening one had. A quote of a deeper level has more.
	 *
	 * @param string $text Text.
	 * @param int    $from Offset of the value's first character.
	 * @param int    $run  Backslashes before the opening quote.
	 * @return int|null Offset of the closing quote's first backslash; null for none.
	 */
	private static function escaped_close( $text, $from, $run ) {
		for ( $at = strpos( $text, '"', $from ); false !== $at; $at = strpos( $text, '"', $at + 1 ) ) {
			$back = 0;
			while ( $at - $back - 1 >= $from && '\\' === $text[ $at - $back - 1 ] ) {
				++$back;
			}
			if ( $back === $run ) {
				return $at - $run;
			}
		}
		return null;
	}

	/**
	 * Every Bearer token of a text.
	 *
	 * @param string $line Text.
	 * @param int    $hits Replacements (added to).
	 * @return string|null Null where the pattern gave up.
	 */
	private static function bearer( $line, &$hits ) {
		$n = preg_match_all( '/\bbearer[ \t]{1,16}/i', $line, $found, PREG_OFFSET_CAPTURE );
		if ( false === $n ) {
			return null;
		}
		$out = '';
		$pos = 0;
		foreach ( $found[0] as $m ) {
			$from = $m[1] + strlen( $m[0] );
			$len  = strspn( $line, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~+/', $from );
			if ( $m[1] >= $pos && $len >= 8 ) {
				++$hits;
				$out .= substr( $line, $pos, $from - $pos ) . self::MARK;
				$pos  = $from + $len + strspn( $line, '=', $from + $len );
			}
		}
		return $out . substr( $line, $pos );
	}
}
