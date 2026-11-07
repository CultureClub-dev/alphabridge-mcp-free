<?php
/**
 * Steps on one integration site in a process of its own, for the tests of
 * the canonical form (AB_MCP_IT_Sync_Steps): each site computes its own
 * canonical form with its own WordPress, as the takeover does.
 *
 *   php sync-site.php <state.json> <a|b>     steps as JSON on stdin
 *
 * A step is { "op": …, "as": name, … }; a string "$name" anywhere in a step
 * is the result of that earlier step, "{$name}" inside a text the same as
 * text. Ops: insert_post (args), update_post (args), add_meta and update_meta (post, key, value), insert_term
 * (name, taxonomy, args), set_terms (post, terms, taxonomy), update_option
 * (name, value), canonical (kind, ref, addresses), inventory (addresses);
 * for the trust of the takeover (Z8 branch 5): staging_save (role,
 * partner_url, partner_role, source; as the administrator), sync_key (the
 * key of the site), pairing_code, key_replace («Renew the key»), anchor_code
 * (code: prepared and confirmed
 * as a person does), sign_manifest (package); for its packages: export
 * (purpose, cursor, objects; slow_index: out of time after each page of the
 * index of identities; index_slice: its slices this many bytes; redact_keys,
 * env_clock; constants: name => value defined for it where not defined),
 * bulk_attachments (count: rows of attachments by SQL), import (the arguments of wp_site_import_chunk,
 * relay), export_again (package_id, now), export_chunk
 * (package_id, chunk, now), grants (package_id, count, now), store_cleanup
 * (now), store_hold_lock (seconds), store_remove (area, gid), add_admin (login), as_user (user), for the report delete_post
 * (post, for good), trash_post (post), add_term_meta (term, key, value), push (args of wp_site_push,
 * exit_at: a hook at which the process exits inside a boundary, exit_key: only for this meta key), raw_post (post, set: columns written
 * by SQL) and wp_version (version: the
 * $wp_version the steps after it see), and write_access (the site mode
 * Full, as a person switches it on), staging_raw and staging_raw_set
 * (value: the record of role and pair as stored, past save()); for what the
 * route loads: loaded
 * (whether export, inventory and kinds are loaded), menu_css_mods (a menu
 * with an item, the additional CSS and two theme mods), nav_menu (name) and
 * nav_menu_item (menu, args of wp_update_nav_menu_item()), serve (query: the route
 * of the chunks asked through the REST server of this process, its status). The process
 * loads only what WordPress and the plugin load and what an op needs. A step with "expect": "error"
 * gives { code, message } of its WP_Error instead of ending the steps.
 * Prints { name: result, … } as JSON.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 3 !== count( $argv ) ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';

putenv( 'AB_IT_STATE=' . $argv[1] );
$ab_it_key = $argv[2];
// The document root as the web server of the site has it, so the protected
// store chooses its place as in a request to the site.
$_SERVER   = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( $ab_it_key ), array( 'DOCUMENT_ROOT' => AB_MCP_IT_Sites::docroot( $ab_it_key ) ) );
define( 'WP_USE_THEMES', false );
require AB_MCP_IT_Sites::docroot( $ab_it_key ) . '/wp-load.php';

wp_set_current_user( (int) AB_MCP_IT_Sites::site( $ab_it_key )['admin']['user_id'] );

$ab_it_steps = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $ab_it_steps ) ) {
	fwrite( STDERR, "No steps on stdin.\n" );
	exit( 2 );
}

/**
 * A step with the results of the steps before put in.
 *
 * @param mixed $value Value.
 * @param array $vars  Results by name.
 * @return mixed
 */
function ab_it_sync_fill( $value, array $vars ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = ab_it_sync_fill( $v, $vars );
		}
		return $value;
	}
	if ( is_string( $value ) && '$' === substr( $value, 0, 1 ) && array_key_exists( substr( $value, 1 ), $vars ) ) {
		return $vars[ substr( $value, 1 ) ];
	}
	if ( is_string( $value ) ) {
		return (string) preg_replace_callback(
			'/\{\$([a-z0-9_]+)\}/',
			static function ( $m ) use ( $vars ) {
				return array_key_exists( $m[1], $vars ) && is_scalar( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : $m[0];
			},
			$value
		);
	}
	return $value;
}

$ab_it_vars = array();
foreach ( $ab_it_steps as $ab_it_i => $ab_it_step ) {
	$ab_it_step = ab_it_sync_fill( $ab_it_step, $ab_it_vars );
	switch ( $ab_it_step['op'] ) {
		case 'insert_post':
			$ab_it_out = wp_insert_post( wp_slash( $ab_it_step['args'] ), true );
			break;
		case 'add_meta':
			$ab_it_out = add_post_meta( (int) $ab_it_step['post'], $ab_it_step['key'], wp_slash( $ab_it_step['value'] ) );
			break;
		case 'update_post':
			// As wp-admin saves a change of some fields (wp_update_post()
			// keeps the others).
			$ab_it_out = wp_update_post( wp_slash( $ab_it_step['args'] ), true );
			break;
		case 'update_meta':
			$ab_it_out = update_post_meta( (int) $ab_it_step['post'], $ab_it_step['key'], wp_slash( $ab_it_step['value'] ) );
			break;
		case 'insert_term':
			$ab_it_out = wp_insert_term( $ab_it_step['name'], $ab_it_step['taxonomy'], isset( $ab_it_step['args'] ) ? $ab_it_step['args'] : array() );
			$ab_it_out = is_array( $ab_it_out ) ? (int) $ab_it_out['term_id'] : $ab_it_out;
			break;
		case 'set_terms':
			$ab_it_out = wp_set_object_terms( (int) $ab_it_step['post'], array_map( 'intval', $ab_it_step['terms'] ), $ab_it_step['taxonomy'] );
			break;
		case 'update_option':
			$ab_it_out = update_option( $ab_it_step['name'], $ab_it_step['value'] );
			break;
		case 'delete_option':
			$ab_it_out = delete_option( $ab_it_step['name'] );
			break;
		case 'canonical':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-db-kinds.php';
			$ab_it_kind = null;
			foreach ( AB_MCP_Sync_Db_Kinds::all() as $ab_it_candidate ) {
				if ( $ab_it_candidate->kind() === $ab_it_step['kind'] ) {
					$ab_it_kind = $ab_it_candidate;
				}
			}
			$ab_it_out = $ab_it_kind->canonical(
				array(
					'ref'    => (string) $ab_it_step['ref'],
					'source' => AB_MCP_Sync_Inventory::source( $ab_it_step['addresses'] ),
				)
			);
			break;
		case 'inventory':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-db-kinds.php';
			$ab_it_source = AB_MCP_Sync_Inventory::source( $ab_it_step['addresses'] );
			$ab_it_cursor = null;
			$ab_it_out    = array();
			do {
				$ab_it_part = AB_MCP_Sync_Inventory::run( $ab_it_source, $ab_it_cursor );
				if ( is_wp_error( $ab_it_part ) ) {
					$ab_it_out = $ab_it_part;
					break;
				}
				$ab_it_out    = array_merge( $ab_it_out, $ab_it_part['objects'] );
				$ab_it_cursor = $ab_it_part['cursor'];
			} while ( null !== $ab_it_cursor );
			break;
		case 'staging_save':
			$ab_it_out = AB_MCP_Staging::save( $ab_it_step['role'], $ab_it_step['partner_url'], isset( $ab_it_step['partner_role'] ) ? $ab_it_step['partner_role'] : null, get_current_user_id(), $ab_it_step['source'] );
			break;
		case 'sync_key':
			$ab_it_out = AB_MCP_Sync_Key::current();
			break;
		case 'pairing_code':
			$ab_it_out = AB_MCP_Sync_Key::pairing_code();
			break;
		case 'key_replace':
			$ab_it_out = AB_MCP_Sync_Key::replace();
			break;
		case 'sign_manifest':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-manifest.php';
			$ab_it_out = AB_MCP_Sync_Manifest::sign( $ab_it_step['package'], isset( $ab_it_step['now'] ) ? (int) $ab_it_step['now'] : null );
			break;
		case 'export':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
			AB_MCP_Sync_Identity::$slice = isset( $ab_it_step['index_slice'] ) ? (int) $ab_it_step['index_slice'] : null;
			if ( ! empty( $ab_it_step['slow_index'] ) ) {
				// Out of time after each page of the index of identities, and
				// only there: the index goes on over the calls of a series.
				AB_MCP_Sync_Inventory::$clock = static function () {
					foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $ab_it_frame ) {
						if ( 'AB_MCP_Sync_Identity' === ( $ab_it_frame['class'] ?? '' ) && 'build' === $ab_it_frame['function'] ) {
							return microtime( true ) + 3600;
						}
					}
					return microtime( true );
				};
			}
			if ( isset( $ab_it_step['redact_keys'] ) ) {
				// The rules of this site only (ab_mcp_redact_keys), for this export.
				$ab_it_keys = (array) $ab_it_step['redact_keys'];
				add_filter( 'ab_mcp_redact_keys', static fn( $keys ) => array_merge( (array) $keys, $ab_it_keys ) );
			}
			if ( isset( $ab_it_step['constants'] ) ) {
				// Constants of CONSTANTS this site defines for this export only
				// (one WordPress does not define itself).
				foreach ( (array) $ab_it_step['constants'] as $ab_it_name => $ab_it_value ) {
					if ( ! defined( $ab_it_name ) ) {
						define( $ab_it_name, $ab_it_value );
					}
				}
			}
			if ( isset( $ab_it_step['env_clock'] ) ) {
				// Each look at the clock of the environment that many seconds
				// later: a part of it stops within a few looks of its walk.
				$ab_it_now              = 0.0;
				$ab_it_tick             = (float) $ab_it_step['env_clock'];
				AB_MCP_Sync_Env::$clock = static function () use ( &$ab_it_now, $ab_it_tick ) {
					$ab_it_now += $ab_it_tick;
					return $ab_it_now;
				};
			}
			$ab_it_out = AB_MCP_Sync_Export::make( $ab_it_step['purpose'], isset( $ab_it_step['cursor'] ) ? $ab_it_step['cursor'] : null, isset( $ab_it_step['objects'] ) ? (int) $ab_it_step['objects'] : null, array_intersect_key( $ab_it_step, array( 'report_hash' => true, 'refs' => true ) ) );
			break;
		case 'push':
			// wp_site_push in a process of its own (Z8 branch 8): a request
			// that ends inside a write boundary (exit_at: a hook of WordPress
			// at which the process exits) leaves the step begun.
			require_once AB_MCP_DIR . 'includes/sync/class-sync-push.php';
			if ( isset( $ab_it_step['exit_at'] ) ) {
				// exit_key: only where the third argument of the hook (the meta
				// key of updated_post_meta, added_post_meta) is this key.
				$ab_it_key_at = isset( $ab_it_step['exit_key'] ) ? (string) $ab_it_step['exit_key'] : null;
				add_action(
					(string) $ab_it_step['exit_at'],
					static function ( ...$args ) use ( $ab_it_key_at ) {
						if ( null === $ab_it_key_at || ( isset( $args[2] ) && $ab_it_key_at === (string) $args[2] ) ) {
							exit( 0 );
						}
					},
					1,
					4
				);
			}
			$_SERVER['DOCUMENT_ROOT'] = AB_MCP_IT_Sites::docroot( $ab_it_key );
			$ab_it_out                = AB_MCP_Sync_Push::call( $ab_it_step['args'] );
			break;
		case 'raw_post':
			// Columns of a post written past every hook (the way a plugin
			// with its own SQL writes), for the tests of the way back.
			$ab_it_out = $GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, $ab_it_step['set'], array( 'ID' => (int) $ab_it_step['post'] ) );
			clean_post_cache( (int) $ab_it_step['post'] );
			break;
		case 'import':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-import.php';
			$ab_it_out = AB_MCP_Sync_Import::call( $ab_it_step['args'] );
			break;
		case 'anchor_code':
			$ab_it_out = AB_MCP_Sync_Anchor::prepare_from_code( $ab_it_step['code'], get_current_user_id() );
			if ( is_array( $ab_it_out ) ) {
				$ab_it_out = AB_MCP_Sync_Anchor::confirm( $ab_it_out['fingerprint'], get_current_user_id() );
			}
			break;
		case 'export_again':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
			$ab_it_out = AB_MCP_Sync_Export::again( $ab_it_step['package_id'], isset( $ab_it_step['now'] ) ? (int) $ab_it_step['now'] : null );
			break;
		case 'export_chunk':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
			$ab_it_out = AB_MCP_Sync_Export::chunk( $ab_it_step['package_id'], $ab_it_step['chunk'], isset( $ab_it_step['now'] ) ? (int) $ab_it_step['now'] : null );
			break;
		case 'grants':
			$ab_it_out = AB_MCP_Sync_Grant::grants( $ab_it_step['package_id'], (int) $ab_it_step['count'], (int) $ab_it_step['now'] );
			break;
		case 'menu_css_mods':
			$ab_it_menu = wp_create_nav_menu( 'Every menu' );
			$ab_it_item = wp_update_nav_menu_item(
				$ab_it_menu,
				0,
				array(
					'menu-item-title'  => 'Every item',
					'menu-item-url'    => home_url( '/' ),
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
				)
			);
			$ab_it_css  = wp_update_custom_css_post( 'body { color: #123; }' );
			set_theme_mod( 'header_textcolor', '333333' );
			set_theme_mod( 'nav_menu_locations', array( 'primary' => $ab_it_menu ) );
			$ab_it_out = array(
				'menu' => $ab_it_menu,
				'item' => $ab_it_item,
				'css'  => $ab_it_css instanceof WP_Post ? $ab_it_css->ID : 0,
			);
			break;
		case 'nav_menu':
			$ab_it_out = wp_create_nav_menu( $ab_it_step['name'] );
			break;
		case 'nav_menu_item':
			$ab_it_out = wp_update_nav_menu_item( (int) $ab_it_step['menu'], 0, $ab_it_step['args'] );
			break;
		case 'register_taxonomy':
			// A taxonomy of a plugin this site does not run (the data of Polylang, nachgebildet).
			$ab_it_out = register_taxonomy( $ab_it_step['name'], $ab_it_step['types'], array( 'public' => false ) ) instanceof WP_Taxonomy;
			break;
		case 'raw_slug':
			// A slug as the plugin writes it past wp_unique_post_slug() (shared slugs of translations).
			$ab_it_out = $GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_name' => $ab_it_step['slug'] ), array( 'ID' => (int) $ab_it_step['post'] ) );
			clean_post_cache( (int) $ab_it_step['post'] );
			break;
		case 'staging_raw':
			$ab_it_out = AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
			break;
		case 'staging_raw_set':
			AB_MCP_Settings::set( AB_MCP_Staging::KEY, $ab_it_step['value'] );
			$ab_it_out = AB_MCP_Settings::get( AB_MCP_Staging::KEY, array() );
			break;
		case 'loaded':
			$ab_it_out = array(
				'export'    => class_exists( 'AB_MCP_Sync_Export', false ),
				'inventory' => class_exists( 'AB_MCP_Sync_Inventory', false ),
				'kinds'     => class_exists( 'AB_MCP_Sync_Db_Kinds', false ),
			);
			break;
		case 'serve':
			$ab_it_request = new WP_REST_Request( 'GET', '/' . AB_MCP_REST_NAMESPACE . AB_MCP_Sync_Grant::ROUTE );
			$ab_it_request->set_query_params( $ab_it_step['query'] );
			$ab_it_out = rest_do_request( $ab_it_request )->get_status();
			break;
		case 'bulk_attachments':
			// Attachment rows past wp_insert_post(), for an index of identities of some MiB.
			$ab_it_digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
			$ab_it_out    = $GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( "INSERT INTO {$GLOBALS['wpdb']->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type) SELECT 1, '2026-10-01 10:00:00', '2026-10-01 10:00:00', '', CONCAT('Bild ', n), '', 'inherit', 'open', 'closed', CONCAT('bild-', n), '', '', '2026-10-01 10:00:00', '2026-10-01 10:00:00', '', 0, '', 0, 'attachment', 'image/jpeg' FROM ( SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d AS n FROM {$ab_it_digits} a CROSS JOIN {$ab_it_digits} b CROSS JOIN {$ab_it_digits} c CROSS JOIN {$ab_it_digits} e CROSS JOIN {$ab_it_digits} f ) AS numbers WHERE n < %d", (int) $ab_it_step['count'] ) ); // phpcs:ignore WordPress.DB
			break;
		case 'store_remove':
			$ab_it_out = AB_MCP_Protected_Store::remove( $ab_it_step['area'], $ab_it_step['gid'] );
			break;
		case 'store_cleanup':
			$ab_it_out = AB_MCP_Protected_Store::cleanup( (int) $ab_it_step['now'] );
			break;
		case 'store_hold_lock':
			// The lock of the protected store held for a while (seconds), as a
			// clean-up in another request holds it (A6).
			$ab_it_out = AB_MCP_Protected_Store::under_lock(
				static function () use ( $ab_it_step ) {
					usleep( (int) ( 1000000 * (float) $ab_it_step['seconds'] ) );
					return true;
				}
			);
			break;
		case 'add_admin':
			$ab_it_out = wp_insert_user(
				array(
					'user_login' => $ab_it_step['login'],
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => $ab_it_step['login'] . '@example.invalid',
					'role'       => 'administrator',
				)
			);
			break;
		case 'write_access':
			AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY, AB_MCP_Site_Mode::FULL );
			$ab_it_out = AB_MCP_Site_Mode::get();
			break;
		case 'delete_post':
			// Deleted for good, as wp-admin does past the trash (the report, branch 6).
			$ab_it_out = wp_delete_post( (int) $ab_it_step['post'], true ) instanceof WP_Post;
			break;
		case 'trash_post':
			$ab_it_out = wp_trash_post( (int) $ab_it_step['post'] ) instanceof WP_Post;
			break;
		case 'add_term_meta':
			$ab_it_out = add_term_meta( (int) $ab_it_step['term'], $ab_it_step['key'], wp_slash( $ab_it_step['value'] ) );
			break;
		case 'wp_version':
			// The version of WordPress the steps after it see, as version.php would set it.
			$GLOBALS['wp_version'] = (string) $ab_it_step['version'];
			$ab_it_out             = $GLOBALS['wp_version'];
			break;
		case 'as_user':
			wp_set_current_user( (int) $ab_it_step['user'] );
			$ab_it_out = get_current_user_id();
			break;
		default:
			fwrite( STDERR, "Unknown op {$ab_it_step['op']}.\n" );
			exit( 2 );
	}
	if ( is_wp_error( $ab_it_out ) && isset( $ab_it_step['expect'] ) && 'error' === $ab_it_step['expect'] ) {
		$ab_it_out = array(
			'code'    => $ab_it_out->get_error_code(),
			'message' => $ab_it_out->get_error_message(),
		);
	} elseif ( is_wp_error( $ab_it_out ) ) {
		fwrite( STDERR, "Step $ab_it_i ({$ab_it_step['op']}): " . $ab_it_out->get_error_message() . "\n" );
		exit( 1 );
	}
	$ab_it_vars[ isset( $ab_it_step['as'] ) ? $ab_it_step['as'] : 'step' . $ab_it_i ] = $ab_it_out;
}
echo json_encode( $ab_it_vars, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR );
