<?php
/**
 * Set up the two integration sites, in CI and on a Mac alike.
 *
 *   php tests/integration/bin/setup.php
 *
 * Reads (environment):
 *   AB_IT_ROOT         Directory for the two sites, their logs and the state
 *                      (created; the sites in it are replaced by each setup).
 *   AB_IT_WP_CORE      A WordPress 7.1.3 core (CI: the checkout of the pinned
 *                      commit; Mac: ~/Entwicklung/wp-core-7.1.3).
 *   AB_IT_DB_HOST      Default 127.0.0.1:3306; a socket as localhost:/path.
 *   AB_IT_DB_USER      Default root.
 *   AB_IT_DB_PASSWORD  Default empty.
 *   AB_IT_DB_NAME      Default ab_it (created when missing).
 *   AB_IT_PORT_A       Default 8781 (site A, http://127.0.0.1:<port>).
 *   AB_IT_PORT_B       Default 8782 (site B).
 *
 * Writes AB_IT_ROOT/state.json (the paths, the database and, per site, the
 * credentials its setup made), copies the core twice (site-a, site-b,
 * without .git), and installs each site in two processes of
 * bin/install-site.php. It starts no server: bin/serve.sh does.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';

/**
 * Stop with a message.
 *
 * @param string $message What went wrong.
 */
function ab_it_fail( $message ) {
	fwrite( STDERR, "setup: $message\n" );
	exit( 1 );
}

/**
 * Whether $dir is $path or one of its parents, by identity (device and
 * inode), so that no spelling or alias of a path gets past the check.
 *
 * @param string $dir  Directory that might hold $path.
 * @param string $path Path to look for.
 * @return bool
 */
function ab_it_holds( $dir, $path ) {
	$target = @stat( $dir );
	$at     = realpath( $path );
	if ( false === $target || false === $at ) {
		return false;
	}
	while ( true ) {
		$here = @stat( $at );
		if ( false !== $here && $here['dev'] === $target['dev'] && $here['ino'] === $target['ino'] ) {
			return true;
		}
		if ( '/' === $at ) {
			return false;
		}
		$at = dirname( $at );
	}
}

/**
 * An environment variable, or its default.
 *
 * @param string      $name    Name.
 * @param string|null $default Default; null when it must be set.
 * @return string
 */
function ab_it_env( $name, $default = null ) {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		if ( null === $default ) {
			ab_it_fail( "$name is not set (tests/integration/README.md)." );
		}
		return $default;
	}
	return $value;
}

$started = microtime( true );
$repo    = realpath( dirname( __DIR__, 3 ) );
$root    = rtrim( ab_it_env( 'AB_IT_ROOT' ), '/' );
$core    = rtrim( ab_it_env( 'AB_IT_WP_CORE' ), '/' );

if ( '' === $root || '/' === $root || ! preg_match( '#^/.+#', $root ) ) {
	ab_it_fail( 'AB_IT_ROOT must be an absolute directory other than /.' );
}
if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
	ab_it_fail( "Cannot create $root." );
}
$root = realpath( $root );
if ( ab_it_holds( $repo, $root ) ) {
	ab_it_fail( 'AB_IT_ROOT lies inside the working tree; the plugin directory would contain the sites.' );
}
// rsync --delete replaces whatever a site directory holds, so neither may
// hold the working tree or the home directory (checked before anything runs).
foreach ( array( 'a', 'b' ) as $key ) {
	// A link would carry rsync --delete to wherever it points.
	if ( is_link( "$root/site-$key" ) ) {
		ab_it_fail( "$root/site-$key is a symbolic link; the site directories must be plain directories in AB_IT_ROOT." );
	}
	foreach ( array( $repo, (string) getenv( 'HOME' ) ) as $kept ) {
		if ( '' !== $kept && ab_it_holds( "$root/site-$key", $kept ) ) {
			ab_it_fail( "$root/site-$key holds $kept; choose another AB_IT_ROOT." );
		}
	}
}

// The core must be the release the plan names, read from its own version file.
$version_file = $core . '/wp-includes/version.php';
$version      = is_file( $version_file ) && preg_match( "/\\\$wp_version = '([^']+)'/", (string) file_get_contents( $version_file ), $m ) ? $m[1] : '';
if ( '7.1.3' !== $version ) {
	ab_it_fail( "AB_IT_WP_CORE ($core) is not WordPress 7.1.3 (found '$version')." );
}

$db = array(
	'host'     => ab_it_env( 'AB_IT_DB_HOST', '127.0.0.1:3306' ),
	'user'     => ab_it_env( 'AB_IT_DB_USER', 'root' ),
	'password' => ab_it_env( 'AB_IT_DB_PASSWORD', '' ),
	'name'     => ab_it_env( 'AB_IT_DB_NAME', 'ab_it' ),
);
if ( ! preg_match( '/^[a-z0-9_]+$/i', $db['name'] ) ) {
	ab_it_fail( 'AB_IT_DB_NAME may hold letters, digits and _ only.' );
}

// The database, created when missing. DB_HOST as WordPress reads it:
// host[:port] or host:/path/to/socket.
$host   = $db['host'];
$port   = null;
$socket = null;
if ( preg_match( '#^([^:]*):(/.+)$#', $host, $m ) ) {
	$host   = '' === $m[1] ? 'localhost' : $m[1];
	$socket = $m[2];
} elseif ( preg_match( '#^([^:]+):(\d+)$#', $host, $m ) ) {
	$host = $m[1];
	$port = (int) $m[2];
}
mysqli_report( MYSQLI_REPORT_OFF );
$link = @mysqli_connect( $host, $db['user'], $db['password'], '', (int) $port, $socket );
if ( ! $link ) {
	ab_it_fail( 'No connection to MariaDB at ' . $db['host'] . ': ' . mysqli_connect_error() );
}
if ( ! mysqli_query( $link, 'CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) ) {
	ab_it_fail( 'Cannot create the database: ' . mysqli_error( $link ) );
}
$server = mysqli_get_server_info( $link );
mysqli_close( $link );

foreach ( array( 'log', 'run' ) as $dir ) {
	if ( ! is_dir( "$root/$dir" ) && ! mkdir( "$root/$dir", 0700 ) ) {
		ab_it_fail( "Cannot create $root/$dir." );
	}
}

$sites = array();
foreach ( array(
	'a' => (int) ab_it_env( 'AB_IT_PORT_A', '8781' ),
	'b' => (int) ab_it_env( 'AB_IT_PORT_B', '8782' ),
) as $key => $site_port ) {
	$sites[ $key ] = array(
		'url'       => 'http://127.0.0.1:' . $site_port,
		'port'      => $site_port,
		'docroot'   => "$root/site-$key",
		'prefix'    => "wp{$key}_",
		'debug_log' => "$root/log/site-$key-debug.log",
		'server_log' => "$root/log/site-$key-server.log",
	);
}
if ( $sites['a']['port'] === $sites['b']['port'] ) {
	ab_it_fail( 'AB_IT_PORT_A and AB_IT_PORT_B must differ.' );
}

putenv( "AB_IT_STATE=$root/state.json" );
AB_MCP_IT_Sites::save(
	array(
		'repo'     => $repo,
		'root'     => $root,
		'wp_core'  => realpath( $core ),
		'mariadb'  => $server,
		'db'       => $db,
		'sites'    => $sites,
	)
);

foreach ( $sites as $key => $site ) {
	// The core, copied whole (a link would make WordPress look for
	// wp-config.php beside the shared core); rsync --delete leaves nothing
	// of a former setup but what WordPress itself writes anew.
	if ( ! is_dir( $site['docroot'] ) && ! mkdir( $site['docroot'], 0700 ) ) {
		ab_it_fail( 'Cannot create ' . $site['docroot'] . '.' );
	}
	// The link to the working tree goes first, so that nothing below can
	// reach through it into the tree.
	$plugin_link = $site['docroot'] . '/wp-content/plugins/alphabridge-mcp';
	if ( is_link( $plugin_link ) && ! unlink( $plugin_link ) ) {
		ab_it_fail( "Cannot remove the link $plugin_link." );
	}
	$copy = array( 'rsync', '-a', '--delete', '--exclude=.git', '--exclude=/wp-config.php', $core . '/', $site['docroot'] . '/' );
	passthru( implode( ' ', array_map( 'escapeshellarg', $copy ) ), $code );
	if ( 0 !== $code ) {
		ab_it_fail( "Copying the core to site $key failed." );
	}
	foreach ( array( 'install', 'activate' ) as $step ) {
		passthru( implode( ' ', array_map( 'escapeshellarg', array( PHP_BINARY, __DIR__ . '/install-site.php', "$root/state.json", $key, $step ) ) ), $code );
		if ( 0 !== $code ) {
			ab_it_fail( "The $step step of site $key failed." );
		}
	}
}

printf( "setup: two sites in %s on MariaDB %s, %.1f s\n", $root, $server, microtime( true ) - $started );
