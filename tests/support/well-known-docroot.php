<?php
/**
 * A web directory on disk for the tests of the well-known relay: other
 * WordPress sites are directories with a wp-load.php under it. The relay
 * fetches only where such a file is (AB_MCP_OAuth::wordpress_on_disk()).
 *
 * Every directory gets a name of its own (process and random part) and is
 * removed again by the test, and at the latest when the process ends; the
 * removal stays under the temp directory or the tests folder (ab-wk-*,
 * ignored by git) and does not follow symlinks.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/**
 * A fresh directory under the temp directory (or under $base, the tests
 * folder, where a test in this process needs the sites under ABSPATH), with
 * a wp-load.php under each of $sites (paths like /staging/123).
 *
 * @param string[] $sites
 */
function ab_test_wk_dir( array $sites = array(), string $base = '' ): string {
	$dir = rtrim( '' !== $base ? $base : sys_get_temp_dir(), '/' ) . '/ab-wk-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir, 0700 );
	register_shutdown_function( 'ab_test_wk_remove', $dir );
	foreach ( $sites as $site ) {
		ab_test_wk_site( $dir, $site );
	}
	return $dir;
}

/** A WordPress (its wp-load.php) at $dir$site. */
function ab_test_wk_site( string $dir, string $site ): void {
	if ( ! is_dir( $dir . $site ) ) {
		mkdir( $dir . $site, 0700, true );
	}
	file_put_contents( $dir . $site . '/wp-load.php', "<?php\n// Stand-in for a WordPress installation.\n" );
}

/** Remove a directory of ab_test_wk_dir(), not following symlinks. */
function ab_test_wk_remove( string $dir ): void {
	$abs     = (string) realpath( $dir );
	$allowed = array(
		rtrim( (string) realpath( sys_get_temp_dir() ), '/' ) . '/ab-wk-',
		rtrim( (string) realpath( dirname( __DIR__ ) ), '/' ) . '/ab-wk-',
	);
	if ( '' === $abs || is_link( $dir ) || ( 0 !== strpos( $abs, $allowed[0] ) && 0 !== strpos( $abs, $allowed[1] ) ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		if ( $item->isLink() || $item->isFile() ) {
			unlink( $item->getPathname() );
		} elseif ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		}
	}
	rmdir( $abs );
}
