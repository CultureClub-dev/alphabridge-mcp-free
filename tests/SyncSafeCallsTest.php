<?php
/**
 * The rule of AB_MCP_Sync_Safe, read from the code: no file under
 * includes/sync/ calls a function of WordPress (or of this plugin) that
 * reads stored values and unpacks them with maybe_unserialize() — unserialize()
 * with every class, which makes an object of a stored record and runs its
 * __wakeup() — except where EXCEPTIONS names it with the reason. Calls
 * through the gate stand there as the gate's own. A call more, or one less
 * than the list says, fails: the list is kept true in both directions.
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
	 * Functions that read stored values and unpack them (or call what does).
	 */
	private const UNPACKING = array(
		'get_option', 'get_site_option', 'get_network_option', 'get_blog_option', 'wp_load_alloptions',
		'get_post_meta', 'get_metadata', 'get_metadata_raw', 'get_term_meta', 'get_user_meta', 'get_comment_meta', 'get_post_custom',
		'get_theme_mod', 'get_theme_mods', 'get_stylesheet', 'get_template', 'wp_get_theme',
		'wp_get_upload_dir', 'wp_upload_dir', 'wp_get_attachment_metadata', 'get_attached_file',
		'get_transient', 'get_site_transient', 'get_post', 'get_posts', 'get_term', 'get_terms', 'get_term_by',
		'wp_get_object_terms', 'get_the_terms', 'wp_get_nav_menu_items', 'get_userdata', 'get_user_by',
		'is_plugin_active', 'maybe_unserialize', 'unserialize',
		// Of this plugin: the guard reads a snapshot with get_option(), the
		// builders read their markers with get_post_meta() and options.
		'resolve_option', 'option_snapshot', 'detect_all', 'markers_found', 'signature_active', 'signature_data_version', 'primary',
	);

	/**
	 * Every call of UNPACKING under includes/sync/, with the reason: file =>
	 * function => array( count, reason ).
	 */
	private const EXCEPTIONS = array(
		'class-sync-safe.php'   => array(
			'detect_all'    => array( 1, 'The gate itself (builders()): the meta rows of the post and the options of READS[detect_all] are checked raw first.' ),
			'wp_upload_dir' => array( 1, 'The gate itself (upload_basedir()): the options of READS[upload_dir] are checked raw first.' ),
		),
		'class-sync-canon.php'  => array(
			'unserialize' => array( 1, 'unserialized(): after carries_record() refused every object, enum, reference and record, with allowed_classes => false.' ),
		),
		'class-sync-schema.php' => array(
			'get_option' => array( 2, 'maybe_upgrade() and status(): the version of the tables, a string this plugin writes itself (install()) and compares with VERSION; no value of a staging site or of another plugin is read through it. Read on every request through the options cache.' ),
		),
	);

	/** @return array<string,array<string,int>> file => function => calls. */
	private static function calls(): array {
		$out = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], self::UNPACKING, true ) ) {
					continue;
				}
				// A call: the name is followed by "(" and is not the name of a
				// function or method declared here.
				$next = $i + 1;
				while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					++$next;
				}
				$prev = $i - 1;
				while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
					--$prev;
				}
				if ( '(' !== ( $tokens[ $next ] ?? null ) || ( is_array( $tokens[ $prev ] ) && T_FUNCTION === $tokens[ $prev ][0] ) ) {
					continue;
				}
				$name                               = basename( $file );
				$out[ $name ][ $tokens[ $i ][1] ] = ( $out[ $name ][ $tokens[ $i ][1] ] ?? 0 ) + 1;
			}
		}
		ksort( $out );
		return $out;
	}

	public function testEveryCallThatUnpacksStoredValuesIsOnTheListWithItsReason(): void {
		$want = array();
		foreach ( self::EXCEPTIONS as $file => $functions ) {
			foreach ( $functions as $function => list( $calls, $reason ) ) {
				self::assertNotSame( '', $reason );
				$want[ $file ][ $function ] = $calls;
			}
		}
		ksort( $want );
		self::assertSame( $want, self::calls(), 'A call of a function that unpacks stored values goes through AB_MCP_Sync_Safe, or onto EXCEPTIONS with its reason.' );
	}

	public function testTheScanSeesTheCodeAtAll(): void {
		$calls = self::calls();
		self::assertArrayHasKey( 'class-sync-safe.php', $calls );
		self::assertGreaterThan( 10, count( glob( dirname( __DIR__ ) . '/includes/sync/*.php' ) ) );
	}
}
