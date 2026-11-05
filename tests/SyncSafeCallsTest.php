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
		'get_post_status_object'               => 'The post statuses registered in this request, code, no stored value.',
		'is_multisite'                         => 'The constants MULTISITE and SUBDOMAIN_INSTALL.',
		// Static methods of this plugin outside includes/sync/.
		'AB_MCP_Builders::signatures'          => 'signatures.php and the filter ab_mcp_builder_signatures; no post and no option.',
		'AB_MCP_Builders::signature'           => 'One entry of signatures().',
		'AB_MCP_Builders::adapters'            => 'Makes the readers (shortcode profiles from files) and the filter ab_mcp_builder_adapters; asks no post.',
		'AB_MCP_Redactor::extra_keys'          => 'The filter ab_mcp_redact_keys, names only.',
		'AB_MCP_Redactor::redacts_key'         => 'Compares a name with the lists of names.',
		'AB_MCP_Redactor::normalize_key'       => 'A string function over a name (accessToken, client-secret and API_KEY alike); no stored value.',
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
		'->get_tag'                            => 'WP_HTML_Tag_Processor.',
		'->get_attribute_names_with_prefix'    => 'WP_HTML_Tag_Processor.',
		'->next_token'                         => 'WP_HTML_Tag_Processor: parses the text it is given.',
		'->get_token_type'                     => 'WP_HTML_Tag_Processor.',
		'->is_tag_closer'                      => 'WP_HTML_Tag_Processor.',
		'->get_modifiable_text'                => 'WP_HTML_Tag_Processor.',
		'->paused_at_incomplete_token'         => 'WP_HTML_Tag_Processor.',
		'WP_HTML_Decoder::decode_attribute'    => 'The HTML API: decodes the text it is given as an attribute value.',
		'WP_HTML_Decoder::decode_text_node'    => 'The HTML API: decodes the text it is given as text.',
		'WP_MatchesMapRegex::apply'            => 'Puts the matches it is given into the query of a rewrite rule.',
		'->parse'                              => 'WP_Block_Parser: parses the text it is given.',
		'->get_error_code'                     => 'WP_Error.',
		'->get_error_message'                  => 'WP_Error.',
		'->carries_record'                     => 'AB_MCP_Replace_Engine: walks the serialized text without unserialize().',
		// The trust of the takeover (Z8, decisions 4 and 5): encoders, the
		// answer of another site, the answer of a route.
		'AB_MCP_Staging::normalize_url'        => 'A string function over the address it is given (wp_parse_url()); no stored value.',
		'wp_json_encode'                       => 'Encodes the data it is given.',
		'wp_remote_retrieve_body'              => 'The body of the answer it is given.',
		'wp_remote_retrieve_response_code'     => 'The status of the answer it is given.',
		'AB_MCP_Outbound::get_partner'         => 'Asks the other site over the network, tied to its checked addresses, on the port of its stored address; no stored value of this site.',
		'->hide_errors'                        => '$wpdb: no error shown while the private key of a staging site is written (AB_MCP_Sync_Key::store_secret()).',
		'->show_errors'                        => '$wpdb: errors shown again as before, afterwards.',
		'->header'                             => 'WP_REST_Response: a header of the answer.',
		'new WP_REST_Response'                 => 'An answer object.',
		// The packages of the takeover (Z8, decision 6): the protected store,
		// which reads its files and its own rows through $wpdb; the request
		// of the route; the account and the scheme of this request.
		'AB_MCP_Protected_Store::open'         => 'Opens a group in the folder of the store (its id and proof as raw rows through $wpdb).',
		'AB_MCP_Protected_Store::put'          => 'Writes one file of a group.',
		'AB_MCP_Protected_Store::get'          => 'Reads one file of a group against its SHA-256.',
		'AB_MCP_Protected_Store::groups'       => 'The sidecars of an area of the store.',
		'AB_MCP_Protected_Store::files'        => 'The files of a group, by name.',
		'AB_MCP_Protected_Store::remove'       => 'Removes a group of the store.',
		'AB_MCP_Protected_Store::close'        => 'Marks a group of the store as closed (its sidecar).',
		'AB_MCP_Store_Budget::unpack_refusal'  => 'Compares the counts it is given with the limits of unpacking (UNPACK); no stored value.',
		'AB_MCP_Outbound::public_addresses'    => 'Looks the host of this site up in the DNS (or the filter ab_mcp_outbound_addresses); no stored value.',
		'->get_route'                          => 'WP_REST_Request: the path of the request.',
		'->get_method'                         => 'WP_REST_Request: its method.',
		'->get_query_params'                   => 'WP_REST_Request: its query.',
		'->get_body_params'                    => 'WP_REST_Request: its form fields.',
		'->get_body'                           => 'WP_REST_Request: its body.',
		'add_filter'                           => 'Hooks a function into this request.',
		'get_current_user_id'                  => 'The account of this request, as the authentication set it.',
		'is_ssl'                               => 'Whether this request came over https ($_SERVER).',
		'wp_parse_url'                         => 'Parses the address it is given.',
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
		// The trust of the takeover (Z8, decisions 4 and 5) reads no value it
		// compares: its own options, the record of the site and its address.
		'class-sync-anchor.php'   => array(
			'AB_MCP_Staging::record' => array( 2, 'partner() and pair_print(): the confirmed role and pair of this site, which the anchor belongs to.' ),
			'get_option'             => array( 2, 'read() and retired_list(): the anchor and the key ids it retired, options this class writes itself (OPTION, OPTION_RETIRED).' ),
			'update_option'          => array( 2, 'write() and retire(): those options, without autoload.' ),
			'delete_option'          => array( 1, 'forget(): removes it.' ),
			'home_url'               => array( 1, 'pair_print(): the own address of this site, which the print binds.' ),
		),
		'class-sync-key.php'      => array(
			'AB_MCP_Staging::role' => array( 5, 'make(), replace(), sign(), mac(), answer(): only a confirmed staging site has a key.' ),
			'get_option'           => array( 4, 'found(), secret() and mac_secret(): the key and the secret of its MACs, in the three options this class writes itself.' ),
			'update_option'        => array( 2, 'renew() and store_secret(): those three options, without autoload.' ),
			'delete_option'        => array( 3, 'forget(): removes them.' ),
			'home_url'             => array( 1, 'answer(): the own address the key names.' ),
		),
		// What the account that makes a package may read (decision 9): its
		// capabilities and the object as WordPress has it, never a value the
		// package compares.
		'class-sync-export-rights.php' => array(
			'current_user_can'          => array( 4, 'withheld(), post(), term(): the capabilities of the account that makes the package, per object.' ),
			'get_post'                  => array( 1, 'post(): the post whose rights are asked, for read_post, edit_post and whether it is publicly viewable.' ),
			'get_post_type_object'      => array( 1, 'post(): whether WordPress knows its type (no rule of its own otherwise).' ),
			'get_term'                  => array( 1, 'term(): the term whose rights are asked.' ),
			'is_post_publicly_viewable' => array( 1, 'post(): whether the post is public, else edit_post as well.' ),
			'is_taxonomy_viewable'      => array( 1, 'term(): whether its taxonomy is public, else edit_term.' ),
			'taxonomy_exists'           => array( 1, 'term(): whether WordPress knows its taxonomy.' ),
		),
		'class-sync-export.php'   => array(
			'current_user_can'       => array( 1, 'refusal(): only an account with manage_options makes and reads packages (decision 9).' ),
			'AB_MCP_Site_Mode::is_full' => array( 2, 'refusal() and deliver(): only with write access on (the access level Full, decision 9).' ),
			'AB_MCP_Site_Mode::way_text' => array( 1, 'refusal(): the way to switch write access on.' ),
			'AB_MCP_Staging::role'   => array( 1, 'refusal(): only the confirmed staging site makes and gives out packages.' ),
			'AB_MCP_Staging::bind'   => array( 2, 'binding() and refusal(): the random value of the role and pair a package is bound to.' ),
			'AB_MCP_Staging::new_bind' => array( 1, 'refusal(): that value, made where the record has none.' ),
			'AB_MCP_Staging::record' => array( 3, 'refusal(), make() and binding(): the pair, whose production site a package is for, and the confirmations a package is bound to.' ),
			'home_url'               => array( 3, 'make(), binding() and relay_refusal(): the own address, for the inventory, the binding and whether it is public.' ),
		),
		'class-sync-manifest.php' => array(
			'AB_MCP_Staging::record' => array( 1, 'sign(): the pair, whose production site a package is for.' ),
			'AB_MCP_Staging::role'   => array( 1, 'sign(): only the confirmed staging site signs.' ),
			'home_url'               => array( 2, 'sign() and verify(): the own address, as source and target exactly (decision 5).' ),
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
		// Functions of the core that read an option (Codex, round 12:
		// esc_attr() reads blog_charset through the cache) or a stored post.
		'esc_attr', 'esc_html', 'esc_textarea', 'esc_js', 'esc_xml', 'esc_url', 'esc_url_raw', 'esc_attr_e', 'esc_html_e',
		'_wp_specialchars', 'wp_check_invalid_utf8', 'wp_kses', 'wp_kses_post', 'wp_kses_data', 'sanitize_title',
		'sanitize_title_for_query', 'remove_accents', 'url_to_postid', 'get_page_by_path', 'get_permalink',
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
