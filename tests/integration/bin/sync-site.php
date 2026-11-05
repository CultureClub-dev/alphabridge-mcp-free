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
 * text. Ops: insert_post (args), add_meta (post, key, value), insert_term
 * (name, taxonomy, args), set_terms (post, terms, taxonomy), update_option
 * (name, value), canonical (kind, ref, addresses), inventory (addresses);
 * for the trust of the takeover (Z8 branch 5): staging_save (role,
 * partner_url, partner_role, source; as the administrator), sync_key (the
 * key of the site), pairing_code, sign_manifest (package); for its packages:
 * export (purpose, cursor), export_again (package_id, now), export_chunk
 * (package_id, chunk, now), grants (package_id, count, now), store_cleanup
 * (now), add_admin (login), as_user (user) and write_access (the site mode
 * Full, as a person switches it on). A step with "expect": "error"
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
require_once AB_MCP_DIR . 'includes/sync/class-sync-db-kinds.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-inventory.php';

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
		case 'canonical':
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
		case 'sign_manifest':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-manifest.php';
			$ab_it_out = AB_MCP_Sync_Manifest::sign( $ab_it_step['package'] );
			break;
		case 'export':
			require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
			$ab_it_out = AB_MCP_Sync_Export::make( $ab_it_step['purpose'], isset( $ab_it_step['cursor'] ) ? $ab_it_step['cursor'] : null );
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
			require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
			$ab_it_out = AB_MCP_Sync_Export::grants( $ab_it_step['package_id'], (int) $ab_it_step['count'], (int) $ab_it_step['now'] );
			break;
		case 'store_cleanup':
			$ab_it_out = AB_MCP_Protected_Store::cleanup( (int) $ab_it_step['now'] );
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
