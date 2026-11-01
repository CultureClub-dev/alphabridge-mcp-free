<?php
/**
 * A request to the protected store of an integration site over HTTP, for
 * ProtectedStoreTest: copied into the document root of a site as
 * ab-it-store-probe.php for the class's cases and removed after them. Test
 * code only: it ships nowhere (tests/ is export-ignore), and the plugin
 * knows nothing of it.
 *
 * POST, JSON: { action, user, area?, meta?, gid?, name?, payload? (base64),
 * sha256?, now?, delay?: { filter, us } }. delay hooks a filter the store
 * reads under its lock (the limits of AB_MCP_Store_Budget, read after the
 * count) and sleeps there, so that two requests at the same time overlap
 * where only the lock keeps them apart.
 *
 * Answers JSON: { ok: result } or { error: code, message, data }.
 *
 * @package AlphaBridge_MCP
 */

$ab_it_in = json_decode( (string) file_get_contents( 'php://input' ), true );
if ( ! is_array( $ab_it_in ) || empty( $ab_it_in['action'] ) ) {
	http_response_code( 400 );
	exit;
}

define( 'WP_USE_THEMES', false );
require __DIR__ . '/wp-load.php';

wp_set_current_user( isset( $ab_it_in['user'] ) ? (int) $ab_it_in['user'] : 0 );
if ( ! empty( $ab_it_in['delay']['filter'] ) ) {
	$ab_it_us = (int) $ab_it_in['delay']['us'];
	add_filter(
		(string) $ab_it_in['delay']['filter'],
		static function ( $value ) use ( $ab_it_us ) {
			usleep( $ab_it_us );
			return $value;
		}
	);
}

$ab_it_area = isset( $ab_it_in['area'] ) ? (string) $ab_it_in['area'] : '';
$ab_it_gid  = isset( $ab_it_in['gid'] ) ? (string) $ab_it_in['gid'] : '';
switch ( (string) $ab_it_in['action'] ) {
	case 'id':
		$ab_it_out = AB_MCP_Protected_Store::install_id( ! empty( $ab_it_in['make'] ) );
		break;
	case 'proof':
		$ab_it_out = AB_MCP_Protected_Store::proof();
		if ( $ab_it_out['available'] ) {
			$ab_it_out['root'] = AB_MCP_Protected_Store::root();
		}
		break;
	case 'open':
		$ab_it_out = AB_MCP_Protected_Store::open( $ab_it_area, isset( $ab_it_in['meta'] ) ? (array) $ab_it_in['meta'] : array() );
		break;
	case 'put':
		$ab_it_out = AB_MCP_Protected_Store::put( $ab_it_area, $ab_it_gid, (string) $ab_it_in['name'], (string) base64_decode( (string) $ab_it_in['payload'] ) );
		break;
	case 'get':
		$ab_it_out = AB_MCP_Protected_Store::get( $ab_it_area, $ab_it_gid, (string) $ab_it_in['name'], (string) $ab_it_in['sha256'] );
		$ab_it_out = is_wp_error( $ab_it_out ) ? $ab_it_out : base64_encode( $ab_it_out );
		break;
	case 'groups':
		$ab_it_out = AB_MCP_Protected_Store::groups( $ab_it_area );
		break;
	case 'cleanup':
		$ab_it_out = AB_MCP_Protected_Store::cleanup( isset( $ab_it_in['now'] ) ? (int) $ab_it_in['now'] : null );
		break;
	default:
		http_response_code( 400 );
		exit;
}

header( 'Content-Type: application/json' );
echo wp_json_encode(
	is_wp_error( $ab_it_out )
		? array(
			'error'   => $ab_it_out->get_error_code(),
			'message' => $ab_it_out->get_error_message(),
			'data'    => $ab_it_out->get_error_data(),
		)
		: array( 'ok' => $ab_it_out )
);
