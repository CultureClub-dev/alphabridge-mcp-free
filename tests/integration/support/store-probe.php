<?php
/**
 * A request to the protected store of an integration site over HTTP, for
 * ProtectedStoreTest: copied into the document root of a site as
 * ab-it-store-probe.php for the class's cases and removed after them. Test
 * code only: it ships nowhere (tests/ is export-ignore), and the plugin
 * knows nothing of it.
 *
 * POST, JSON: { action, user, area?, meta?, gid?, name?, payload? (base64),
 * sha256?, now?, sync?: { dir, me, other, filter } } (sync: below).
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

// Two requests that open a group at the same time, made to overlap where
// only the store's lock keeps them apart. Each notes when it began (right
// before it calls open(), its proof already in hand, so it goes straight
// to the lock), and hooks the limit the store reads after its count and
// before it creates (AB_MCP_Store_Budget reads its limits there, as Pro
// does): there it notes when it counted, waits until the other has begun
// (at most SYNC_BEGUN seconds) and then until the other has counted as
// well (at most SYNC_COUNTED seconds), and notes when it went on to
// create. Each note is a file named <me>-<what> in the folder of the sync
// holding hrtime( true ) (the monotonic clock of the machine, the same for
// both processes); the test reads the order from them.
//
// The lock itself is proved directly, not by the race: at the same point,
// between the count and the creation, a child process tries to take the
// lock file of the store without waiting (flock LOCK_EX | LOCK_NB). It fails
// exactly when this request holds the lock; that is noted as <me>-held
// (1 or 0). A process of its own, because flock() between processes is the
// same on Linux and macOS, whatever PHP uses for a second handle in one.
const AB_IT_SYNC_BEGUN   = 30;
const AB_IT_SYNC_COUNTED = 10;
$ab_it_note = static function ( array $sync, $what, $value = null ) {
	file_put_contents( $sync['dir'] . '/' . $sync['me'] . '-' . $what, null === $value ? (string) hrtime( true ) : (string) $value );
};
$ab_it_held = static function () {
	$root = AB_MCP_Protected_Store::root();
	if ( is_wp_error( $root ) ) {
		return null;
	}
	$try = proc_open(
		// Exit 0: taken, so not held; 1: it would block, so held; 2: the file
		// could not be opened or flock() failed otherwise, which proves nothing.
		array( PHP_BINARY, '-n', '-r', '$h = @fopen( $argv[1], "r" ); if ( ! $h ) { exit( 2 ); } $b = 0; if ( flock( $h, LOCK_EX | LOCK_NB, $b ) ) { exit( 0 ); } exit( 1 === $b ? 1 : 2 );', $root . '/' . AB_MCP_Protected_Store::LOCK_FILE ),
		array(),
		$pipes
	);
	if ( ! is_resource( $try ) ) {
		return null;
	}
	$code = proc_close( $try );
	return 2 === $code ? null : 1 === $code;
};
if ( ! empty( $ab_it_in['sync']['dir'] ) ) {
	$ab_it_sync = $ab_it_in['sync'];
	add_filter(
		(string) $ab_it_sync['filter'],
		static function ( $value ) use ( $ab_it_sync, $ab_it_note, $ab_it_held ) {
			$ab_it_note( $ab_it_sync, 'counted' );
			$held = $ab_it_held();
			$ab_it_note( $ab_it_sync, 'held', null === $held ? 'unknown' : ( $held ? '1' : '0' ) );
			foreach ( array( 'begun' => AB_IT_SYNC_BEGUN, 'counted' => AB_IT_SYNC_COUNTED ) as $what => $seconds ) {
				$until = microtime( true ) + $seconds;
				while ( ! file_exists( $ab_it_sync['dir'] . '/' . $ab_it_sync['other'] . '-' . $what ) && microtime( true ) < $until ) {
					usleep( 10000 );
					clearstatcache();
				}
			}
			$ab_it_note( $ab_it_sync, 'proceeded' );
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
		if ( ! empty( $ab_it_in['sync']['dir'] ) ) {
			// The proof of this request first, so that open() goes straight
			// to the lock after the note.
			AB_MCP_Protected_Store::root();
			$ab_it_note( $ab_it_in['sync'], 'begun' );
		}
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
