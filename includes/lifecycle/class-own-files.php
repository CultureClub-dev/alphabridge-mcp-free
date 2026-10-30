<?php
/**
 * The parts of AlphaBridge MCP Pro's code-file guard (AB_MCP_Pro_Code_File_Guard
 * there) that the install, update, activation and delete tools need, free
 * since 4.11.0: recognising AlphaBridge's own files by identity, the result
 * of the test requests in one line, comparing two failures, and clearing the
 * OPcache entry of a file an update replaced. Taken over without a change of
 * behaviour; the file tools of Pro that write and delete plugin and theme
 * files stay in Pro.
 *
 * AlphaBridge's own files are never touched through AlphaBridge: a broken
 * write cuts the very connection needed to repair it, and an AI-written
 * AlphaBridge is not the one that was reviewed.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-php-probe.php';

/**
 * Class AB_MCP_Own_Files
 */
class AB_MCP_Own_Files {

	/**
	 * Does the previous file fail the same way? The same fatal error (file,
	 * line and the first line of the message), the same early end (status
	 * and, at this MCP endpoint, error code), or not reached either. Public:
	 * the PHP snippet tools judge a snippet's previous state by the same rule.
	 *
	 * @param array $now  Row with the new file.
	 * @param array $then Row with the previous file.
	 * @return bool
	 */
	public static function same_failure( array $now, array $then ) {
		if ( $now['result'] !== $then['result'] ) {
			return false;
		}
		switch ( $now['result'] ) {
			case 'fatal':
				$a = $now['error'];
				$b = $then['error'];
				return (string) $a['file'] === (string) $b['file']
					&& (int) $a['line'] === (int) $b['line']
					&& strtok( (string) $a['message'], "\n" ) === strtok( (string) $b['message'], "\n" );
			case 'stopped':
				return (int) $now['http_status'] === (int) $then['http_status']
					&& ( isset( $now['code'] ) ? (string) $now['code'] : '' ) === ( isset( $then['code'] ) ? (string) $then['code'] : '' );
			case 'unreachable':
				return true;
		}
		return false;
	}

	/**
	 * The result per target in one line, for the messages: the MCP client
	 * sees the message of an error, not its data. Public for the PHP snippet
	 * tools, so both kinds of answer read alike.
	 *
	 * @param array $rows Rows of AB_MCP_Php_Probe::run().
	 * @return string
	 */
	public static function summary( array $rows ) {
		$parts = array();
		foreach ( $rows as $row ) {
			$label = AB_MCP_Php_Probe::label( (string) $row['target'] );
			switch ( $row['result'] ) {
				case 'ok':
					$parts[] = empty( $row['redirects'] )
						/* translators: 1: target, 2: HTTP status */
						? sprintf( __( '%1$s: ok (HTTP %2$d)', 'alphabridge-mcp' ), $label, (int) $row['http_status'] )
						/* translators: 1: target, 2: HTTP status, 3: where the redirects led */
						: sprintf( __( '%1$s: ok (HTTP %2$d, after a redirect to %3$s)', 'alphabridge-mcp' ), $label, (int) $row['http_status'], (string) end( $row['redirects'] ) );
					break;
				case 'fatal':
					$e = $row['error'];
					if ( ! empty( $row['early'] ) && '' === (string) $e['file'] ) {
						// AB_MCP_Php_Probe::early_fatal(): WordPress's page only.
						$parts[] = sprintf(
							/* translators: 1: target, 2: whether the PHP error log was read */
							__( '%1$s: a fatal error before WordPress started its test report, in code that loads before the plugins of this site (a must-use plugin, a network-active plugin, a drop-in or wp-config.php); WordPress answered with its page "There has been a critical error" without file and line, %2$s', 'alphabridge-mcp' ),
							$label,
							isset( $row['log'] ) && 'none' === $row['log']
								? __( 'and this site can read no PHP error log that names them', 'alphabridge-mcp' )
								: __( 'and the PHP error log of this server has no entry from the test', 'alphabridge-mcp' )
						);
						break;
					}
					$parts[] = sprintf(
						/* translators: 1: target, 2: PHP's message, 3: file, 4: line */
						__( '%1$s: fatal error "%2$s" in %3$s on line %4$d', 'alphabridge-mcp' ),
						$label,
						$e['message'],
						'' !== $e['file'] ? $e['file'] : '?',
						(int) $e['line']
					) . ( ! empty( $row['logged'] ) ? ' ' . __( '(read from the PHP error log: it struck before WordPress started its test report)', 'alphabridge-mcp' ) : '' );
					break;
				case 'stopped':
					/* translators: 1: target, 2: reason */
					$parts[] = sprintf( __( '%1$s: did not run through (%2$s)', 'alphabridge-mcp' ), $label, rtrim( (string) $row['reason'], '.' ) );
					break;
				case 'unreachable':
					/* translators: 1: target, 2: reason */
					$parts[] = sprintf( __( '%1$s: not reached (%2$s)', 'alphabridge-mcp' ), $label, rtrim( (string) $row['reason'], '.' ) );
					break;
				default:
					$parts[] = empty( $row['out_of_time'] )
						/* translators: %s: target */
						? sprintf( __( '%s: not asked', 'alphabridge-mcp' ), $label )
						/* translators: %s: target */
						: sprintf( __( '%s: not checked, this call ran out of time', 'alphabridge-mcp' ), $label );
			}
		}
		return implode( '; ', $parts );
	}

	/**
	 * Refuse AlphaBridge's own files: the free plugin, Pro (with the core it
	 * bundles), the owner drop-in. A broken write there cuts the very
	 * connection needed to repair it, and an AI-written AlphaBridge is not the
	 * one that was reviewed.
	 *
	 * Compared by identity (device and inode where the file system gives one)
	 * on the resolved path and every directory above it, so a symlinked plugin
	 * folder, a renamed one or another spelling of the case all count.
	 *
	 * @param string $path Absolute path (need not exist yet).
	 * @return true|WP_Error
	 */
	public static function own_file_refusal( $path ) {
		$chain = self::identity_chain( (string) $path );
		$own   = self::own_identities();
		foreach ( $chain as $id ) {
			if ( isset( $own[ $id ] ) ) {
				return self::own_file_error();
			}
		}
		// A drop-in that does not exist yet has no identity: judged by name in
		// the two folders WordPress loads single plugin files from.
		$parent = self::identity( dirname( (string) $path ) );
		if ( '' !== $parent && preg_match( '/^alphabridge-mcp/i', basename( (string) $path ) ) ) {
			foreach ( self::loader_dirs() as $dir ) {
				if ( self::identity( $dir ) === $parent ) {
					return self::own_file_error();
				}
			}
		}
		return true;
	}

	/**
	 * @return WP_Error
	 */
	private static function own_file_error() {
		return new WP_Error(
			'ab_mcp_own_file',
			__( 'This is a file of AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro or the owner drop-in). The file tools never write or delete these: a broken write would cut the connection needed to repair it. Update AlphaBridge in wp-admin under Plugins, or over SFTP.', 'alphabridge-mcp' )
		);
	}

	/**
	 * The plugin folders WordPress loads from.
	 *
	 * @return string[]
	 */
	private static function loader_dirs() {
		$dirs = array();
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$dirs[] = WP_PLUGIN_DIR;
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$dirs[] = WPMU_PLUGIN_DIR;
		}
		return $dirs;
	}

	/**
	 * Identities of everything that is AlphaBridge's: the running free core
	 * and Pro by their constants (whatever their folders are called), and
	 * every folder or single file named alphabridge-mcp… in the plugin
	 * folders — an inactive copy of the free plugin, the owner drop-in.
	 *
	 * @return array<string,true>
	 */
	private static function own_identities() {
		$out = array();
		foreach ( self::own_paths() as $p ) {
			$id = self::identity( $p );
			if ( '' !== $id ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Paths of everything that is AlphaBridge's: the running free core and Pro
	 * by their constants (whatever their folders are called), and every
	 * folder or single file named alphabridge-mcp… in the plugin folders — an
	 * inactive copy of the free plugin, the owner drop-in.
	 *
	 * @return string[]
	 */
	private static function own_paths() {
		$paths = array();
		foreach ( array( 'AB_MCP_PRO_DIR', 'AB_MCP_DIR' ) as $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				$paths[] = (string) constant( $const );
			}
		}
		foreach ( self::loader_dirs() as $dir ) {
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing mu-plugins folder is normal.
			foreach ( (array) $entries as $entry ) {
				if ( is_string( $entry ) && preg_match( '/^alphabridge-mcp(?:[-_.].*)?$/i', $entry ) ) {
					$paths[] = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $entry;
				}
			}
		}
		return $paths;
	}

	/**
	 * Identities of a path and every directory above it. A path that does not
	 * exist yet starts at its deepest existing ancestor.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	private static function identity_chain( $path ) {
		$probe = $path;
		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				break;
			}
			$probe = $parent;
		}
		$real = @realpath( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $real ) {
			return array();
		}
		$chain = array();
		for ( $i = 0; $i < 64; $i++ ) {
			$id = self::identity( $real );
			if ( '' !== $id ) {
				$chain[] = $id;
			}
			$up = dirname( $real );
			if ( $up === $real ) {
				break;
			}
			$real = $up;
		}
		return $chain;
	}

	/**
	 * What the file system calls this place: device and inode where it has
	 * them (case and symlinks do not matter then), else the resolved path,
	 * folded to lower case on Windows. '' when it does not exist.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function identity( $path ) {
		$real = @realpath( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $real ) {
			return '';
		}
		$st = @stat( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $st ) && ! empty( $st['ino'] ) ) {
			return 'ino:' . $st['dev'] . ':' . $st['ino'];
		}
		$real = rtrim( str_replace( '\\', '/', $real ), '/' );
		return 'path:' . ( ( '\\' === DIRECTORY_SEPARATOR ) ? strtolower( $real ) : $real );
	}

	/**
	 * @param string $key ini name.
	 * @return bool
	 */
	private static function ini_on( $key ) {
		$v = strtolower( trim( (string) ini_get( $key ) ) );
		return in_array( $v, array( '1', 'on', 'yes', 'true' ), true );
	}

	/* -------------------------------------------------------------- OPcache */

	/**
	 * Clear the file's OPcache entry, as WordPress's own file editor does
	 * after it writes. Without it a host that does not check file times keeps
	 * running the old code — and an undo would not take effect either.
	 *
	 * @param string $path Absolute path.
	 * @return string invalidated | not_active (no OPcache to clear) | not_possible (OPcache runs, but the host does not let this request clear it)
	 */
	public static function invalidate_opcache( $path ) {
		$path = (string) $path;
		if ( '.php' === strtolower( substr( $path, -4 ) ) ) {
			if ( ! function_exists( 'wp_opcache_invalidate' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( function_exists( 'wp_opcache_invalidate' ) ) {
				if ( wp_opcache_invalidate( $path, true ) ) {
					return 'invalidated';
				}
				return self::opcache_active() ? 'not_possible' : 'not_active';
			}
		}
		// WordPress clears ".php" only; the other extensions PHP runs get the
		// same call, under the same opcache.restrict_api rule and filter.
		if ( ! self::opcache_active() ) {
			return 'not_active';
		}
		$restrict = (string) ini_get( 'opcache.restrict_api' );
		if ( '' !== $restrict ) {
			$script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a path only.
			if ( ! $script || 0 !== stripos( $script, $restrict ) ) {
				return 'not_possible';
			}
		}
		if ( ! apply_filters( 'wp_opcache_invalidate_file', true, $path ) ) {
			return 'not_possible';
		}
		return @opcache_invalidate( $path, true ) ? 'invalidated' : 'not_possible'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Is OPcache caching scripts in this request, in a way a write cannot
	 * get past without clearing the entry?
	 *
	 * With only its file cache (opcache.file_cache_only, as on WordPress
	 * Playground) there is no memory cache to clear, and the file cache
	 * compiles a file anew once its time changed — unless file times are not
	 * checked. Measured on Playground (02.10.2026): every invalidation there
	 * answered false, while the test requests ran the new code at once.
	 * opcache_get_status() answers false where opcache.restrict_api keeps
	 * this request out; the switch alone decides then.
	 *
	 * @return bool
	 */
	private static function opcache_active() {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return false;
		}
		if ( ! self::ini_on( ( 'cli' === PHP_SAPI ) ? 'opcache.enable_cli' : 'opcache.enable' ) ) {
			return false;
		}
		$status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restrict_api warns; false is the answer then.
		if ( is_array( $status ) && empty( $status['opcache_enabled'] ) ) {
			return ! self::ini_on( 'opcache.validate_timestamps' );
		}
		return true;
	}
}
