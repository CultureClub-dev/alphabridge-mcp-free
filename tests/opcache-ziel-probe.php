<?php
/**
 * Probe for PHP 8.0 (finding 4623266599, option A; GRENZEN-CODE.md, G-5-26):
 * after a swap by two rename(), OPcache answers invalidated for each PHP
 * file that lies at the target, through
 * AB_MCP_Own_Files::invalidate_opcache(), also where one version has PHP
 * files the other lacks (.php and .inc, each never loaded and loaded with
 * opcache_compile_file()). PHPUnit does not run on 8.0, so this is a script
 * in the syntax of 8.0, without WordPress, run by the lint job on every
 * version:
 *
 *   php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 tests/opcache-ziel-probe.php
 *
 * Its limit: it proves which files are asked and what OPcache answers, not
 * the way through the swap (AB_MCP_Sync_Code_Swap), which the integration
 * suite proves on 8.3 (SyncCodeRunTest). ABSPATH is an empty folder, so
 * .php takes the general branch of invalidate_opcache() as .inc does, and
 * apply_filters() is a stand-in that gives the value back, as WordPress
 * does without a filter. For comparison it prints what OPcache answers for
 * the paths that are gone (before 8.1.25 and 8.2.12 not_possible, also for
 * a loaded file). Status 0 only where every file at the target is
 * invalidated and OPcache is on.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

$ab_root = sys_get_temp_dir() . '/ab-opcache-probe-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$ab_put  = static function ( string $dir, array $subs ): void {
	foreach ( $subs as $sub ) {
		mkdir( $dir . '/' . $sub, 0700, true );
		foreach ( array( 'geladen.inc', 'geladen.php', 'nie.inc', 'nie.php' ) as $name ) {
			file_put_contents( $dir . '/' . $sub . '/' . $name, "<?php\n// " . basename( $dir ) . "/$sub/$name\n" );
		}
	}
};
mkdir( $ab_root . '/abspath', 0700, true );
define( 'ABSPATH', $ab_root . '/abspath/' );

/**
 * The stand-in for apply_filters(): the value as given.
 *
 * @param string $hook  The filter.
 * @param mixed  $value Its value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return $value;
}
require dirname( __DIR__ ) . '/includes/lifecycle/class-own-files.php';

// The old version at the target (alt/ only it has), the new one staged
// (neu/ only it has), beide/ in both; the loaded files in the cache.
$ab_target = $ab_root . '/ziel';
$ab_put( $ab_target, array( 'alt', 'beide' ) );
$ab_put( $ab_root . '/stage', array( 'neu', 'beide' ) );
$ab_status = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
$ab_on     = is_array( $ab_status ) && ! empty( $ab_status['opcache_enabled'] );
printf( "PHP_VERSION %s, opcache_enabled %s\n", PHP_VERSION, var_export( $ab_on, true ) );
foreach ( array( 'alt', 'beide' ) as $ab_sub ) {
	foreach ( array( 'geladen.inc', 'geladen.php' ) as $ab_name ) {
		printf( "compiled %s/%s: %s\n", $ab_sub, $ab_name, var_export( $ab_on && opcache_compile_file( $ab_target . '/' . $ab_sub . '/' . $ab_name ), true ) );
	}
}
$ab_ok = rename( $ab_target, $ab_root . '/backup' ) && rename( $ab_root . '/stage', $ab_target ) && $ab_on;
clearstatcache();
foreach ( array( 'beide', 'neu' ) as $ab_sub ) {
	foreach ( (array) scandir( $ab_target . '/' . $ab_sub ) as $ab_name ) {
		if ( '.' !== $ab_name && '..' !== $ab_name ) {
			$ab_answer = AB_MCP_Own_Files::invalidate_opcache( $ab_target . '/' . $ab_sub . '/' . $ab_name );
			$ab_ok     = $ab_ok && 'invalidated' === $ab_answer;
			printf( "at the target %s/%s: %s\n", $ab_sub, $ab_name, $ab_answer );
		}
	}
}
foreach ( array( 'geladen.inc', 'geladen.php', 'nie.inc', 'nie.php' ) as $ab_name ) {
	$ab_path = $ab_target . '/alt/' . $ab_name;
	printf( "gone alt/%s: cached %s, asked all the same: %s\n", $ab_name, var_export( $ab_on && opcache_is_script_cached( $ab_path ), true ), AB_MCP_Own_Files::invalidate_opcache( $ab_path ) );
}
foreach ( array( 'backup/alt', 'backup/beide', 'backup', 'ziel/neu', 'ziel/beide', 'ziel', 'abspath' ) as $ab_dir ) {
	array_map( 'unlink', (array) glob( $ab_root . '/' . $ab_dir . '/*.*' ) );
	rmdir( $ab_root . '/' . $ab_dir );
}
rmdir( $ab_root );
echo $ab_ok ? "OK: every PHP file at the target is invalidated\n" : "FAILED\n";
exit( $ab_ok ? 0 : 1 );
