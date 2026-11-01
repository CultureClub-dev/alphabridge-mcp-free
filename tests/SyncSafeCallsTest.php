<?php
/**
 * The rule of includes/sync/, read from the code (Z8, decision 11): the
 * canonical form, the inventory and the checks read stored values only as
 * raw rows through $wpdb, and of WordPress they call only what has no
 * access to data, parsers and calculators. A gate in front of a reading
 * function is not tight: the function reads rows the gate did not check
 * (wp_upload_dir() the time zone through current_time()) and prefers the
 * options cache to the rows (alloptions), and maybe_unserialize() makes an
 * object of any class it finds there, running its __wakeup().
 *
 * So every call under includes/sync/ that is not one of PHP itself or of a
 * class declared there stands on ALLOWED, with the reason it reads no
 * stored value, or on EXCEPTIONS with its count and the reason: functions,
 * static methods (self:: and parent:: of a class that extends another of
 * this plugin as that class's), methods by name, constructors. Of PHP's
 * own functions only unserialize() is an exception. A text that names a
 * function of READING (a callback such as array_map( 'get_post', … )) fails
 * as well. ALLOWED is kept true in both directions: an entry no code calls
 * fails too.
 *
 * The integration suite measures the same with a class that counts its
 * __wakeup() (tests/integration/SyncSafeTest.php).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;

final class SyncSafeCallsTest extends TestCase {

	/**
	 * What the code under includes/sync/ may call, with the reason it reads
	 * no stored value: name => reason (functions), Class::method => reason,
	 * ->method => reason, new Class => reason.
	 */
	private const ALLOWED = array(
		// Functions of WordPress: parsers and calculators.
		'is_serialized'                        => 'Reads the text it is given (wp-includes/functions.php).',
		'is_wp_error'                          => 'instanceof WP_Error.',
		'wp_basename'                          => 'A string function (wp-includes/formatting.php).',
		'serialize_blocks'                     => 'Writes the blocks it is given.',
		'get_shortcode_regex'                  => 'With the tag names given, the pattern only; $shortcode_tags is not read.',
		'get_shortcode_atts_regex'             => 'The pattern only.',
		'shortcode_parse_atts'                 => 'Parses the text it is given (stripcslashes(), the pattern of get_shortcode_atts_regex()).',
		'has_filter'                           => 'Asks the hooks registered in this request, code, no stored value.',
		'is_multisite'                         => 'The constants MULTISITE and SUBDOMAIN_INSTALL.',
		// Static methods of this plugin outside includes/sync/.
		'AB_MCP_Builders::signatures'          => 'signatures.php and the filter ab_mcp_builder_signatures; no post and no option.',
		'AB_MCP_Builders::signature'           => 'One entry of signatures().',
		'AB_MCP_Builders::adapters'            => 'Makes the readers (shortcode profiles from files) and the filter ab_mcp_builder_adapters; asks no post.',
		'AB_MCP_Redactor::extra_keys'          => 'The filter ab_mcp_redact_keys, names only.',
		'AB_MCP_Redactor::redacts_key'         => 'Compares a name with the lists of names.',
		'AB_MCP_Replace_Engine::encode'        => 'A string in one of the forms of the replace engine.',
		'AB_MCP_Replace_Engine::needles'       => 'The forms of a string.',
		'AB_MCP_Undo::option_row'              => 'SELECT option_name, autoload through $wpdb, never the value.',
		'AB_MCP_Undo_Meta::rows'               => 'SELECT of the meta rows through $wpdb, the stored bytes.',
		'AB_MCP_Undo_Widgets::row'             => 'SELECT of one option row through $wpdb, the stored bytes.',
		'AB_MCP_Option_Guard::real_option_name' => 'trim() and the two legacy names of the core.',
		'AB_MCP_Option_Guard::too_long_for_column' => 'Counts characters.',
		'AB_MCP_Option_Guard::has_invisible_chars' => 'A pattern over the name.',
		'AB_MCP_Option_Guard::is_blocked'      => 'Compares the name with the lists of names.',
		'AB_MCP_Option_Guard::folds_onto_blocked' => 'Compares the folded name with the lists (remove_accents() with the locale en_US given).',
		// Methods: $wpdb (raw rows), the HTML API, the block parser, WP_Error, the replace engine.
		'->prepare'                            => '$wpdb: escapes the query.',
		'->query'                              => '$wpdb: the raw rows.',
		'->get_results'                        => '$wpdb: the raw rows.',
		'->get_row'                            => '$wpdb: the raw row.',
		'->get_var'                            => '$wpdb: the raw value.',
		'->get_col'                            => '$wpdb: the raw column.',
		'->insert'                             => '$wpdb: a row of the takeover\'s own tables.',
		'->suppress_errors'                    => '$wpdb: the error output.',
		'->esc_like'                           => '$wpdb: escapes a LIKE pattern.',
		'->get_charset_collate'                => '$wpdb: the collation of the connection.',
		'->next_tag'                           => 'WP_HTML_Tag_Processor: parses the text it is given.',
		'->get_attribute'                      => 'WP_HTML_Tag_Processor.',
		'->set_attribute'                      => 'WP_HTML_Tag_Processor.',
		'->class_list'                         => 'WP_HTML_Tag_Processor.',
		'->set_bookmark'                       => 'WP_HTML_Tag_Processor.',
		'->release_bookmark'                   => 'WP_HTML_Tag_Processor.',
		'->get_updated_html'                   => 'WP_HTML_Tag_Processor.',
		'->parse'                              => 'WP_Block_Parser: parses the text it is given.',
		'->get_error_code'                     => 'WP_Error.',
		'->get_error_message'                  => 'WP_Error.',
		'->carries_record'                     => 'AB_MCP_Replace_Engine: walks the serialized text without unserialize().',
		// Constructors.
		'new WP_Block_Parser'                  => 'The parser of the core, without the filter block_parser_class.',
		'new WP_Error'                         => 'An error object.',
		'new AB_MCP_Replace_Engine'            => 'Compiles its patterns.',
		'new stdClass'                         => 'Stands for a stored object, made without its class.',
	);

	/**
	 * The calls outside ALLOWED, with the reason: file => call => array( count, reason ).
	 */
	private const EXCEPTIONS = array(
		'class-sync-canon.php'  => array(
			'unserialize' => array( 1, 'unserialized(): after carries_record() refused every object, enum, reference and record, with allowed_classes => false.' ),
		),
		'class-sync-schema.php' => array(
			'get_option'    => array( 2, 'maybe_upgrade() and status(): the version of the tables, a string this plugin writes itself and compares with VERSION; no value of a site the takeover compares.' ),
			'update_option' => array( 2, 'install(): writes that version (or that it failed).' ),
			'delete_option' => array( 1, 'uninstall: removes that version.' ),
			'dbDelta'       => array( 1, 'install(): makes the tables of the takeover.' ),
		),
	);

	/**
	 * Functions that read stored values and unpack them (or call what
	 * does), refused as text as well, where they would be a callback.
	 */
	private const READING = array(
		'get_option', 'get_site_option', 'get_network_option', 'get_blog_option', 'wp_load_alloptions',
		'get_post_meta', 'get_metadata', 'get_metadata_raw', 'get_term_meta', 'get_user_meta', 'get_comment_meta', 'get_post_custom',
		'get_theme_mod', 'get_theme_mods', 'get_stylesheet', 'get_template', 'wp_get_theme',
		'wp_get_upload_dir', 'wp_upload_dir', '_wp_upload_dir', 'wp_get_attachment_metadata', 'get_attached_file',
		'current_time', 'wp_timezone', 'wp_timezone_string', 'wp_date', 'date_i18n', 'mysql2date', 'get_locale', 'determine_locale',
		'__', '_e', '_x', '_n', 'esc_html__', 'esc_attr__',
		'get_transient', 'get_site_transient', 'get_post', 'get_posts', 'get_term', 'get_terms', 'get_term_by',
		'wp_get_object_terms', 'get_the_terms', 'wp_get_nav_menu_items', 'get_userdata', 'get_user_by',
		'home_url', 'site_url', 'get_home_url', 'get_site_url', 'get_bloginfo', 'is_plugin_active', 'maybe_unserialize', 'unserialize',
		'resolve_option', 'option_snapshot', 'option_write_refused', 'detect_all', 'markers_found', 'signature_active', 'signature_data_version', 'primary',
	);

	/** @return array{0:array<string,array<string,int>>,1:array<string,string[]>} file => call => count; file => texts of READING. */
	private static function scan(): array {
		$files    = glob( dirname( __DIR__ ) . '/includes/sync/*.php' );
		$declared = array();
		$methods  = array();
		$parents  = array();
		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_CLASS === $token[0] ) {
					$name              = self::next_name( $tokens, $i );
					$declared[ $name ] = true;
					$at                = $i;
					while ( isset( $tokens[ $at ] ) && '{' !== $tokens[ $at ] ) {
						if ( is_array( $tokens[ $at ] ) && T_EXTENDS === $tokens[ $at ][0] ) {
							$parents[ basename( $file ) ][] = self::next_name( $tokens, $at );
						}
						++$at;
					}
				}
				if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
					$methods[ self::next_name( $tokens, $i ) ] = true;
				}
			}
		}
		$calls = array();
		$texts = array();
		foreach ( $files as $file ) {
			$base   = basename( $file );
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && in_array( substr( $token[1], 1, -1 ), self::READING, true ) ) {
					$texts[ $base ][] = substr( $token[1], 1, -1 );
					continue;
				}
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) || '(' !== self::after( $tokens, $i ) ) {
					continue;
				}
				$name = ltrim( $token[1], '\\' );
				$prev = self::before( $tokens, $i );
				if ( is_array( $prev ) && T_FUNCTION === $prev[0] ) {
					continue;
				}
				if ( is_array( $prev ) && T_NEW === $prev[0] ) {
					$call = isset( $declared[ $name ] ) || 'self' === $name ? '' : 'new ' . $name;
				} elseif ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					$call = isset( $methods[ $name ] ) ? '' : '->' . $name;
				} elseif ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					$class = self::before( $tokens, self::index_before( $tokens, $i ) );
					$class = is_array( $class ) ? ltrim( $class[1], '\\' ) : '';
					if ( in_array( $class, array( 'self', 'static', 'parent' ), true ) ) {
						$call = isset( $methods[ $name ] ) ? '' : ( $parents[ $base ][0] ?? '?' ) . '::' . $name;
					} else {
						$call = isset( $declared[ $class ] ) ? '' : $class . '::' . $name;
					}
				} else {
					$call = function_exists( $name ) && ( new \ReflectionFunction( $name ) )->isInternal() && 'unserialize' !== $name ? '' : $name;
				}
				if ( '' !== $call ) {
					$calls[ $base ][ $call ] = ( $calls[ $base ][ $call ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $calls );
		return array( $calls, $texts );
	}

	/** @param array $tokens Tokens. */
	private static function next_name( array $tokens, int $i ): string {
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				return ltrim( $tokens[ $j ][1], '\\' );
			}
		}
		return '';
	}

	/** @param array $tokens Tokens. */
	private static function after( array $tokens, int $i ) {
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
				return $tokens[ $j ];
			}
		}
		return null;
	}

	/** @param array $tokens Tokens. */
	private static function index_before( array $tokens, int $i ): int {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
				return $j;
			}
		}
		return 0;
	}

	/** @param array $tokens Tokens. */
	private static function before( array $tokens, int $i ) {
		return $tokens[ self::index_before( $tokens, $i ) ] ?? null;
	}

	public function testEveryCallIsAllowedWithItsReasonOrAnExceptionWithItsCount(): void {
		list( $calls ) = self::scan();
		$outside       = array();
		$used          = array();
		foreach ( $calls as $file => $names ) {
			foreach ( $names as $call => $count ) {
				if ( isset( self::ALLOWED[ $call ] ) ) {
					$used[ $call ] = true;
					continue;
				}
				$outside[ $file ][ $call ] = $count;
			}
		}
		$want = array();
		foreach ( self::EXCEPTIONS as $file => $functions ) {
			foreach ( $functions as $function => list( $count, $reason ) ) {
				self::assertNotSame( '', $reason );
				$want[ $file ][ $function ] = $count;
			}
		}
		foreach ( array( &$want, &$outside ) as &$list ) {
			ksort( $list );
			foreach ( $list as &$names ) {
				ksort( $names );
			}
			unset( $names );
		}
		unset( $list );
		self::assertSame( $want, $outside, 'A call under includes/sync/ reads no stored value (ALLOWED, with the reason) or stands on EXCEPTIONS with its count and reason.' );
		foreach ( self::ALLOWED as $call => $reason ) {
			self::assertNotSame( '', $reason, $call );
		}
		self::assertSame( array(), array_values( array_diff( array_keys( self::ALLOWED ), array_keys( $used ) ) ), 'Every entry of ALLOWED is called.' );
	}

	public function testNoTextNamesAFunctionThatReads(): void {
		list( , $texts ) = self::scan();
		self::assertSame( array(), $texts );
	}

	public function testTheScanSeesTheCodeAtAll(): void {
		list( $calls ) = self::scan();
		self::assertGreaterThan( 10, count( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) ) );
		self::assertSame( 1, $calls['class-sync-media.php']['has_filter'] ?? 0, 'A function call.' );
		self::assertArrayHasKey( 'AB_MCP_Undo_Meta::rows', $calls['class-sync-rows.php'], 'A static call.' );
		self::assertArrayHasKey( 'AB_MCP_Option_Guard::is_blocked', $calls['class-sync-option-schema.php'], 'A call through self:: of a parent.' );
		self::assertArrayHasKey( '->next_tag', $calls['class-sync-html.php'], 'A method.' );
		self::assertArrayHasKey( 'new WP_Block_Parser', $calls['class-sync-refs.php'], 'A constructor.' );
		self::assertArrayNotHasKey( 'strpos', $calls['class-sync-refs.php'], 'PHP\'s own functions are not counted.' );
	}
}
