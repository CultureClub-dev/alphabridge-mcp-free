<?php
/**
 * The two sites of the integration suite: where they are, a request to them
 * as their administrator, their rows, and a reset to the state they had
 * right after setup.
 *
 * Site A is the WordPress the suite runs in (bootstrap.php loads it into the
 * test process) and is served by `php -S` as well; site B is only served.
 * Both are WordPress 7.1.3 with the plugin of the working tree active, in
 * one MariaDB database under two table prefixes (wpa_, wpb_), so the
 * $wpdb of site A reads the rows of both. bin/setup.php writes the state
 * file this class reads (AB_IT_STATE, or state.json in AB_IT_ROOT); the
 * credentials in it are made for each setup and live only there.
 *
 * The scripts in bin/ use this class too, each in a process of its own:
 * WordPress loads once per process.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_IT_Sites
 */
final class AB_MCP_IT_Sites {

	/** The keys of the two sites. */
	const SITES = array( 'a', 'b' );

	/**
	 * The prefix of the copy setup keeps of every table of a site, for
	 * reset(). Not the site's own prefix, so SHOW TABLES of the site does not
	 * see the copies.
	 */
	const SNAPSHOT = 'itsnap_';

	/** The plugin as WordPress names it on both sites. */
	const PLUGIN = 'alphabridge-mcp/alphabridge-mcp.php';

	/** The route of the plugin's MCP endpoint. */
	const MCP_ROUTE = '/alphabridge/v1/mcp';

	/** @var array|null The state, once read. */
	private static $state = null;

	/* ------------------------------------------------------------ state */

	/**
	 * The path of the state file: AB_IT_STATE, else state.json in AB_IT_ROOT.
	 *
	 * @return string
	 */
	public static function state_file() {
		$file = (string) getenv( 'AB_IT_STATE' );
		if ( '' === $file && '' !== (string) getenv( 'AB_IT_ROOT' ) ) {
			$file = rtrim( (string) getenv( 'AB_IT_ROOT' ), '/' ) . '/state.json';
		}
		if ( '' === $file ) {
			throw new RuntimeException( 'Neither AB_IT_STATE nor AB_IT_ROOT is set: they name the state of the integration sites (tests/integration/README.md).' );
		}
		return $file;
	}

	/**
	 * The state as setup wrote it.
	 *
	 * @param bool $reload Read the file again (after install()).
	 * @return array
	 */
	public static function state( $reload = false ) {
		if ( null === self::$state || $reload ) {
			$file = self::state_file();
			$json = is_file( $file ) ? file_get_contents( $file ) : false;
			$data = false === $json ? null : json_decode( $json, true );
			if ( ! is_array( $data ) || ! isset( $data['sites']['a'], $data['sites']['b'], $data['db'], $data['repo'] ) ) {
				throw new RuntimeException( "No state of the integration sites in $file: run tests/integration/bin/setup.php first." );
			}
			self::$state = $data;
		}
		return self::$state;
	}

	/**
	 * Write the state back, whole and at once (another process may read it).
	 *
	 * @param array $state State.
	 */
	public static function save( array $state ) {
		$file = self::state_file();
		$tmp  = $file . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) || ! rename( $tmp, $file ) ) {
			throw new RuntimeException( "The state could not be written to $file." );
		}
		chmod( $file, 0600 );
		self::$state = $state;
	}

	/**
	 * One site's entry of the state.
	 *
	 * @param string $key 'a' or 'b'.
	 * @return array
	 */
	public static function site( $key ) {
		if ( ! in_array( $key, self::SITES, true ) ) {
			throw new InvalidArgumentException( "No integration site '$key': the sites are 'a' and 'b'." );
		}
		return self::state()['sites'][ $key ];
	}

	/**
	 * The address of a site, with a path.
	 *
	 * @param string $key  Site.
	 * @param string $path Path, starting with '/'.
	 * @return string
	 */
	public static function url( $key, $path = '' ) {
		return self::site( $key )['url'] . $path;
	}

	/**
	 * @param string $key Site.
	 * @return string The table prefix of the site.
	 */
	public static function prefix( $key ) {
		return self::site( $key )['prefix'];
	}

	/**
	 * @param string $key Site.
	 * @return string The document root of the site.
	 */
	public static function docroot( $key ) {
		return self::site( $key )['docroot'];
	}

	/**
	 * A credential setup made for the site's administrator: 'login',
	 * 'password', 'app_password' (REST, Basic) or 'mcp_token' (the plugin's
	 * connection token, scope full).
	 *
	 * @param string $key  Site.
	 * @param string $name Which.
	 * @return string
	 */
	public static function credential( $key, $name ) {
		$site = self::site( $key );
		if ( ! isset( $site['admin'][ $name ] ) ) {
			throw new RuntimeException( "Site $key has no credential '$name' yet: its setup did not finish." );
		}
		return (string) $site['admin'][ $name ];
	}

	/**
	 * What $_SERVER holds for a request to the site, for a process that loads
	 * its WordPress from the command line.
	 *
	 * @param string $key Site.
	 * @return array<string,string>
	 */
	public static function server_vars( $key ) {
		$url = parse_url( self::url( $key ) );
		return array(
			'HTTP_HOST'       => $url['host'] . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ),
			'SERVER_NAME'     => $url['host'],
			'SERVER_PORT'     => (string) ( isset( $url['port'] ) ? $url['port'] : 80 ),
			'REQUEST_URI'     => '/',
			'REQUEST_METHOD'  => 'GET',
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'REMOTE_ADDR'     => '127.0.0.1',
			'PHP_SELF'        => '/index.php',
			'SCRIPT_NAME'     => '/index.php',
		);
	}

	/* ------------------------------------------------------------- rows */

	/**
	 * The database of both sites, through the $wpdb of the WordPress loaded
	 * in this process: one database, so it reaches the tables of either
	 * prefix.
	 *
	 * @return wpdb
	 */
	private static function db() {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			throw new RuntimeException( 'No WordPress is loaded in this process; the rows are read through its $wpdb.' );
		}
		return $GLOBALS['wpdb'];
	}

	/**
	 * The full name of a table of a site.
	 *
	 * @param string $key   Site.
	 * @param string $table Name without the prefix, such as 'options'.
	 * @return string
	 */
	public static function table( $key, $table ) {
		if ( ! preg_match( '/^[a-z0-9_]+$/', (string) $table ) ) {
			throw new InvalidArgumentException( "Not a table name: '$table'." );
		}
		return self::prefix( $key ) . $table;
	}

	/**
	 * The rows of a table of a site, read from the database, past every
	 * cache of WordPress. Each condition compares the bytes (BINARY), not the
	 * collation: 'Abc' does not find 'abc', and 'a ' does not find 'a'.
	 *
	 * @param string               $key   Site.
	 * @param string               $table Table without the prefix.
	 * @param array<string,scalar> $where Column => value, all must hold.
	 * @return array<int,array<string,string|null>>
	 */
	public static function rows( $key, $table, array $where = array() ) {
		$db     = self::db();
		$sql    = 'SELECT * FROM `' . self::table( $key, $table ) . '`';
		$conds  = array();
		$values = array();
		foreach ( $where as $column => $value ) {
			if ( ! preg_match( '/^[a-z0-9_]+$/i', (string) $column ) ) {
				throw new InvalidArgumentException( "Not a column name: '$column'." );
			}
			$conds[]  = "`$column` = BINARY %s";
			$values[] = (string) $value;
		}
		if ( array() !== $conds ) {
			$sql = $db->prepare( $sql . ' WHERE ' . implode( ' AND ', $conds ), $values );
		}
		$rows = $db->get_results( $sql, ARRAY_A );
		if ( '' !== $db->last_error ) {
			throw new RuntimeException( 'Reading ' . $table . ' of site ' . $key . ' failed: ' . $db->last_error );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The row of one option of a site, or null.
	 *
	 * @param string $key  Site.
	 * @param string $name Option name, exactly.
	 * @return array<string,string|null>|null
	 */
	public static function option_row( $key, $name ) {
		$rows = self::rows( $key, 'options', array( 'option_name' => $name ) );
		return array() === $rows ? null : $rows[0];
	}

	/* --------------------------------------------------------- requests */

	/**
	 * A request to the REST API of a site over HTTP (its `php -S`), through
	 * ?rest_route= (plain permalinks).
	 *
	 * @param string     $key    Site.
	 * @param string     $method HTTP method.
	 * @param string     $route  Route, such as '/wp/v2/users/me'.
	 * @param array      $query  Query arguments.
	 * @param mixed|null $body   Sent as JSON when not null.
	 * @param string     $auth   'admin' (application password of the
	 *                           administrator, Basic), 'token' (the plugin's
	 *                           connection token, Bearer) or 'none'.
	 * @return array{status:int,headers:array,body:mixed,raw:string}
	 */
	public static function request( $key, $method, $route, array $query = array(), $body = null, $auth = 'admin' ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( 'admin' === $auth ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( self::credential( $key, 'login' ) . ':' . self::credential( $key, 'app_password' ) );
		} elseif ( 'token' === $auth ) {
			$headers['Authorization'] = 'Bearer ' . self::credential( $key, 'mcp_token' );
		} elseif ( 'none' !== $auth ) {
			throw new InvalidArgumentException( "Unknown authentication '$auth'." );
		}
		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => 60,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = json_encode( $body );
		}
		$url      = self::url( $key, '/?' . http_build_query( array_merge( array( 'rest_route' => $route ), $query ), '', '&', PHP_QUERY_RFC3986 ) );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( "Site $key did not answer $method $route: " . $response->get_error_message() );
		}
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
			'body'    => null === $decoded && 'null' !== trim( $raw ) ? $raw : $decoded,
			'raw'     => $raw,
		);
	}

	/**
	 * One JSON-RPC message to the plugin's MCP endpoint of a site, with the
	 * administrator's connection token.
	 *
	 * @param string $key    Site.
	 * @param string $method Such as 'tools/list' or 'tools/call'.
	 * @param array  $params Parameters.
	 * @return array{status:int,headers:array,body:mixed,raw:string}
	 */
	public static function mcp( $key, $method, array $params = array() ) {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);
		if ( array() !== $params ) {
			$message['params'] = $params;
		}
		return self::request( $key, 'POST', self::MCP_ROUTE, array(), $message, 'token' );
	}

	/* --------------------------------------------- install and reset */

	/**
	 * Install a site anew: every table of its prefix dropped, WordPress
	 * installed, the plugin of the working tree activated, new credentials,
	 * a new copy for reset(). Two processes of bin/install-site.php.
	 *
	 * For site A, the WordPress of this process keeps what it loaded before
	 * (the plugin's classes, registered types); only the caches are emptied.
	 * A test that needs a clean site A takes reset(), which is what the
	 * snapshot is for.
	 *
	 * @param string $key Site.
	 */
	public static function install( $key ) {
		self::site( $key );
		foreach ( array( 'install', 'activate' ) as $step ) {
			$command = array( PHP_BINARY, dirname( __DIR__ ) . '/bin/install-site.php', self::state_file(), $key, $step );
			$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( "Could not start the $step of site $key." );
			}
			$output = stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			if ( 0 !== proc_close( $process ) ) {
				throw new RuntimeException( "The $step of site $key failed:\n$output" );
			}
		}
		self::state( true );
		self::flush_caches( $key );
	}

	/**
	 * Keep a copy of every table of a site (for reset()). Called by
	 * bin/install-site.php once the site is set up.
	 *
	 * @param string $key Site.
	 */
	public static function snapshot( $key ) {
		$prefix = self::prefix( $key );
		foreach ( self::tables( self::SNAPSHOT . $prefix ) as $copy ) {
			self::ddl( "DROP TABLE `$copy`" );
		}
		$tables = self::tables( $prefix );
		if ( array() === $tables ) {
			throw new RuntimeException( "Site $key has no tables to keep." );
		}
		foreach ( $tables as $table ) {
			$copy = self::SNAPSHOT . $table;
			self::ddl( "CREATE TABLE `$copy` LIKE `$table`" );
			self::ddl( "INSERT INTO `$copy` SELECT * FROM `$table`" );
		}
	}

	/**
	 * Put a site back to the state right after setup: every table of its
	 * prefix dropped (also those a test or the plugin created since) and the
	 * kept copies written back, then the caches of this process emptied
	 * when its WordPress is that site.
	 *
	 * @param string $key Site.
	 */
	public static function reset( $key ) {
		$prefix = self::prefix( $key );
		$copies = self::tables( self::SNAPSHOT . $prefix );
		if ( array() === $copies ) {
			throw new RuntimeException( "Site $key has no kept copy: run tests/integration/bin/setup.php." );
		}
		foreach ( self::tables( $prefix ) as $table ) {
			self::ddl( "DROP TABLE `$table`" );
		}
		foreach ( $copies as $copy ) {
			$table = substr( $copy, strlen( self::SNAPSHOT ) );
			self::ddl( "CREATE TABLE `$table` LIKE `$copy`" );
			self::ddl( "INSERT INTO `$table` SELECT * FROM `$copy`" );
		}
		self::flush_caches( $key );
	}

	/**
	 * The tables whose names begin with a prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	private static function tables( $prefix ) {
		$db     = self::db();
		$tables = $db->get_col( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $prefix ) . '%' ) );
		if ( '' !== $db->last_error ) {
			throw new RuntimeException( 'Listing the tables failed: ' . $db->last_error );
		}
		return array_values( array_map( 'strval', (array) $tables ) );
	}

	/**
	 * One statement that must succeed.
	 *
	 * @param string $sql Statement (names are checked by the callers).
	 */
	private static function ddl( $sql ) {
		$db = self::db();
		if ( false === $db->query( $sql ) ) {
			throw new RuntimeException( "Failed: $sql — " . $db->last_error );
		}
	}

	/**
	 * Empty the caches of this process when its WordPress is the site.
	 *
	 * @param string $key Site.
	 */
	private static function flush_caches( $key ) {
		if ( ! isset( $GLOBALS['wpdb'] ) || self::prefix( $key ) !== $GLOBALS['wpdb']->prefix ) {
			return;
		}
		wp_cache_flush();
		if ( function_exists( 'wp_roles' ) ) {
			wp_roles()->for_site();
		}
	}
}
