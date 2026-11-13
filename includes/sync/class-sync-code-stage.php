<?php
/**
 * The stage of a unit of code on the production site (Z8b branch 4; plan
 * E3, E11, 3.1 Z4 to Z6): a plugin or theme of the staging site, built
 * before anything is swapped, put into the area stage of the protected
 * store, checked, and only then «ready» for the swap (branch 5,
 * AB_MCP_Sync_Code_Swap::prepare()). Nothing of the site changes here.
 *
 * THE SIGNED MANIFEST (signed()): the unit as the staging site read it, from
 * the packages of its environment that the report of this site compared
 * (AB_MCP_Sync_Report::environment(): each package checked again against
 * its signature, AB_MCP_Sync_Import::taken()): its line and the lines of
 * its files (path, size, SHA-256), its version and the host its head names
 * for updates (update_uri, D1). The integrity of a stage hangs on it, never
 * on the source (E3).
 *
 * THE SOURCE (stage()): a unit live has bytewise needs none. Else
 *  (a) wordpress.org in the exact version (from_org(), the one place of Z5):
 *      only for a plugin or theme whose head names no other host for its
 *      updates (an unknown host counts as another), over https from
 *      downloads.wordpress.org at the exact address, through AB_MCP_Outbound,
 *      no redirect, at most ORG_MAX bytes, unpacked with unzip_file() of the
 *      core in a building folder of the store (the file system method
 *      direct), and taken only where its manifest is the signed one;
 *  (b) packages of code of the staging site (AB_MCP_Sync_Code_Package),
 *      with only the files whose SHA-256 the live unit lacks (need()): the
 *      rest is copied from the live unit, every file checked against the
 *      signed manifest, the whole only where its manifest is the signed one.
 * The unit is built in one call in a building folder of the store
 * (AB_MCP_Protected_Store::build_dir(); D4) and then goes into the area
 * stage through put_tree() (one call; the measurement of 13.11.2026 on a
 * hosted server, GRENZEN-CODE.md G-3-8), in a group bound to the run.
 *
 * THE CHECK (complete(), the one place of Z6 and of «ready»; E11): over as
 * many calls as it takes, each within CHECK_BUDGET seconds, its progress in
 * the state of the stage (state files in the group, the row stage.state):
 * the tree read back with its hash and that hash the signed one (Z4, the
 * early refusal; the swap checks it again); every PHP file the stage brings
 * new or changed (PHP_EXTENSIONS) through the parser of the PHP of this site
 * (parse_check(), a port of Pro's, without its filters): failed is
 * code:syntax with file and line, unavailable (no tokenizer) is
 * code:syntax_unavailable, skipped (over PARSE_MAX_BYTES, or too little
 * memory) is code:syntax_unchecked (E11 allows a skip only where the same
 * bytes ran on the staging site, which nothing proves here: D6); where this
 * host has a PHP command line of the same version, php -l
 * (compile_check(), a port of Pro's): failed is code:syntax, unavailable
 * gives no result and is named; then Requires PHP and Requires at least of
 * the heads of the new version against this site (code:requirements). Only
 * complete() writes the state ready.
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 4: the download, unzip_file(),
 * the pieces of packages and their state, the tokenizer, the PHP command
 * line, the files of a building) go through AB_MCP_Sync_Code_Stage_Calls,
 * held by the seam of this class; only the tests put another object there.
 * No filter and no option reaches it.
 *
 * Messages are in gettext, in the language of the site; their translations
 * come with text/z8b. Codes stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once dirname( __DIR__ ) . '/class-outbound.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-env.php';
require_once __DIR__ . '/class-sync-file-manifest.php';
require_once __DIR__ . '/class-sync-import.php';
require_once __DIR__ . '/class-sync-report.php';
require_once __DIR__ . '/class-sync-code-package.php';

/**
 * The calls of the stage to the outside: the real ones.
 */
final class AB_MCP_Sync_Code_Stage_Calls {

	/** Seconds php -l may take before its answer is given up on (Pro: LINT_TIMEOUT). */
	const LINT_TIMEOUT = 5;

	/**
	 * GET an address through AB_MCP_Outbound (the addresses of its host
	 * checked and the connection tied to them).
	 *
	 * @param string $url  Address.
	 * @param array  $args Arguments.
	 * @return array|WP_Error
	 */
	public function get( $url, array $args ) {
		return AB_MCP_Outbound::get( $url, $args );
	}

	/**
	 * The file system method WordPress would use here; null where it names
	 * none (unknown: from_org() refuses).
	 *
	 * @return string|null
	 */
	public function method() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$method = get_filesystem_method();
		return is_string( $method ) ? $method : null;
	}

	/**
	 * Unpack a ZIP with unzip_file() of the core (it checks names and room).
	 *
	 * @param string $zip ZIP.
	 * @param string $to  Folder.
	 * @return true|WP_Error
	 */
	public function unzip( $zip, $to ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'ab_mcp_code_filesystem', 'The file system of WordPress could not be set up. Ask the host to give PHP write access to wp-content, then try again.' );
		}
		return unzip_file( $zip, $to );
	}

	/**
	 * The bytes the files of a ZIP hold unpacked, by its directory; null
	 * where it cannot be read.
	 *
	 * @param string $zip ZIP.
	 * @return int|null
	 */
	public function unpacked_size( $zip ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$z = new ZipArchive();
		if ( ! is_string( $zip ) || true !== $z->open( $zip, ZipArchive::RDONLY ) ) {
			return null;
		}
		$sum = 0;
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$stat = $z->statIndex( $i );
			if ( ! is_array( $stat ) || ! is_int( $stat['size'] ) || $stat['size'] < 0 ) {
				$z->close();
				return null;
			}
			$sum += $stat['size'];
		}
		$z->close();
		return $sum;
	}

	/**
	 * Whether a tree of these paths could go into the protected store as it
	 * lies (AB_MCP_Protected_Store::web_ready(): in the uploads no file that
	 * configures the web server or PHP, and only with the PHP proof).
	 *
	 * @param string[] $paths Paths of the unit.
	 * @return true|WP_Error
	 */
	public function web_ready( array $paths ) {
		return AB_MCP_Protected_Store::web_ready( $paths );
	}

	/**
	 * The names in the directory of a ZIP, read before anything of it is
	 * unpacked; null where it cannot be read.
	 *
	 * @param string $zip ZIP.
	 * @return string[]|null
	 */
	public function zip_names( $zip ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$z = new ZipArchive();
		if ( ! is_string( $zip ) || true !== $z->open( $zip, ZipArchive::RDONLY ) ) {
			return null;
		}
		$names = array();
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$name = $z->getNameIndex( $i );
			if ( ! is_string( $name ) ) {
				$z->close();
				return null;
			}
			$names[] = $name;
		}
		$z->close();
		return $names;
	}

	/**
	 * A package of code this account took over completely, with its index.
	 *
	 * @param string $package_id Package.
	 * @return array|WP_Error
	 */
	public function taken( $package_id ) {
		return AB_MCP_Sync_Import::taken( $package_id, 'code' );
	}

	/**
	 * One file of a package of code into a file of its own.
	 *
	 * @param array  $taken taken().
	 * @param array  $file  An entry of its index.
	 * @param string $to    The file to make.
	 * @return true|WP_Error
	 */
	public function extract( array $taken, array $file, $to ) {
		return AB_MCP_Sync_Code_Package::extract( $taken, $file, $to );
	}

	/**
	 * Copy a file of the live unit into a building folder.
	 *
	 * @param string $from From.
	 * @param string $to   To (new).
	 * @return bool
	 */
	public function copy( $from, $to ) {
		return is_string( $from ) && is_string( $to ) && ! file_exists( $to ) && @copy( $from, $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Make a folder and its parents in a building folder.
	 *
	 * @param string $path Folder.
	 * @return bool
	 */
	public function mkdir( $path ) {
		return is_string( $path ) && ( is_dir( $path ) || @mkdir( $path, 0700, true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}

	/**
	 * The bytes of a file of a tree (for the parser).
	 *
	 * @param string $path File.
	 * @return string|false
	 */
	public function read( $path ) {
		return is_string( $path ) ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Whether the PHP tokenizer with TOKEN_PARSE is there.
	 *
	 * @return bool
	 */
	public function tokenizer() {
		return function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' );
	}

	/**
	 * Parse code with the PHP of this site, without running it; a
	 * CompileError where it does not parse.
	 *
	 * @param string $code Bytes.
	 */
	public function parse_php( $code ) {
		token_get_all( $code, TOKEN_PARSE );
	}

	/**
	 * memory_limit in bytes (-1 for none; null where PHP gives none to read)
	 * and the memory PHP has taken.
	 *
	 * @return array{0:int|null,1:int}
	 */
	public function memory() {
		$limit = ini_get( 'memory_limit' );
		return array( is_string( $limit ) && '' !== trim( $limit ) ? wp_convert_hr_to_bytes( $limit ) : null, memory_get_usage( true ) );
	}

	/**
	 * php -l over a file of a tree, in a process of its own (the port of
	 * Pro's compile_check(), AB_MCP_Pro_Code_File_Guard, without its filter
	 * of the binary): a command line of the same major and minor version as
	 * the PHP that runs the site, verified by its own -v, never searched for
	 * on PATH.
	 *
	 * @param string $file  The file.
	 * @param string $label Its path in its unit, for the message.
	 * @return array{status:string,line?:int,message?:string,note?:string}
	 */
	public function lint( $file, $label ) {
		$cli = $this->cli();
		if ( '' === $cli ) {
			/* translators: %s: PHP version. */
			return $this->unavailable( sprintf( __( 'no PHP %s command line was found next to the PHP that runs this site, or this server does not allow PHP to start processes.', 'alphabridge-mcp' ), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) );
		}
		// The settings of this site the file is read with, never a guess.
		$limit = $this->memory()[0];
		$short = ini_get( 'short_open_tag' );
		if ( null === $limit || ! is_string( $short ) ) {
			return $this->unavailable( __( 'memory_limit or short_open_tag of this PHP could not be read, so php -l would not read the file as this site does.', 'alphabridge-mcp' ) );
		}
		$run = $this->run( array( $cli, '-n', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'short_open_tag=' . ( in_array( strtolower( trim( $short ) ), array( '1', 'on', 'yes', 'true' ), true ) ? '1' : '0' ), '-d', 'memory_limit=' . ( $limit < 0 ? '-1' : (string) max( $limit, 134217728 ) ), '-l', $file ), self::LINT_TIMEOUT );
		if ( null === $run ) {
			return $this->unavailable( __( 'php -l could not be started.', 'alphabridge-mcp' ) );
		}
		if ( $run['timed_out'] ) {
			/* translators: %d: seconds */
			return $this->unavailable( sprintf( __( 'php -l did not finish within %d seconds.', 'alphabridge-mcp' ), self::LINT_TIMEOUT ) );
		}
		if ( null === $run['exit'] ) {
			return $this->unavailable( __( 'php -l ended without an exit code PHP could read.', 'alphabridge-mcp' ) );
		}
		if ( 0 === $run['exit'] ) {
			return array( 'status' => 'passed' );
		}
		$first = ltrim( '' !== trim( $run['stderr'] ) ? $run['stderr'] : $run['stdout'] );
		if ( preg_match( '/^(?:PHP )?Fatal error:\s*(?:Allowed memory size of \d+ bytes exhausted|Out of memory)/', ltrim( $run['stderr'] ) ) || ( 137 === $run['exit'] && preg_match( '/^Hard memory limit of \d+ bytes exhausted/', $first ) ) ) {
			return $this->unavailable( __( 'php -l ran out of memory before it finished.', 'alphabridge-mcp' ) );
		}
		$text = str_replace( $file, $label, $run['stderr'] . "\n" . $run['stdout'] );
		if ( preg_match( '/(?:Parse|Fatal) error:\s*(.+) in ' . preg_quote( $label, '/' ) . ' on line (\d+)/', $text, $m ) ) {
			return array(
				'status'  => 'failed',
				'line'    => (int) $m[2],
				'message' => trim( $m[1] ),
			);
		}
		/* translators: %d: exit code */
		return $this->unavailable( sprintf( __( 'php -l ended with exit code %d and no error message.', 'alphabridge-mcp' ), $run['exit'] ) );
	}

	/**
	 * @param string $why Reason.
	 * @return array
	 */
	private function unavailable( $why ) {
		return array(
			'status' => 'unavailable',
			'note'   => $why,
		);
	}

	/**
	 * The PHP command line php -l runs with, or '' (Pro: php_cli(), without
	 * the filter ab_mcp_php_cli_binary).
	 *
	 * @return string
	 */
	private function cli() {
		foreach ( array( 'proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return '';
			}
		}
		$ver  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$exe  = ( '\\' === DIRECTORY_SEPARATOR ) ? '.exe' : '';
		$out  = array();
		$dirs = array();
		if ( '' !== (string) PHP_BINARY ) {
			$out[]  = (string) PHP_BINARY;
			$dir    = dirname( (string) PHP_BINARY );
			$dirs[] = $dir;
			if ( 'sbin' === strtolower( basename( $dir ) ) ) {
				$dirs[] = dirname( $dir ) . DIRECTORY_SEPARATOR . 'bin';
			}
			if ( preg_match( '/^php-(?:fpm|cgi)([0-9.]+)(?:\.exe)?$/i', basename( (string) PHP_BINARY ), $m ) ) {
				foreach ( $dirs as $d ) {
					$out[] = $d . DIRECTORY_SEPARATOR . 'php' . $m[1] . $exe;
				}
			}
		}
		if ( '' !== (string) PHP_BINDIR ) {
			$dirs[] = (string) PHP_BINDIR;
		}
		foreach ( $dirs as $d ) {
			$out[] = $d . DIRECTORY_SEPARATOR . 'php' . $ver . $exe;
			$out[] = $d . DIRECTORY_SEPARATOR . 'php' . $exe;
		}
		foreach ( array_values( array_unique( $out ) ) as $candidate ) {
			if ( ! preg_match( '/^php(?:-cli)?[0-9.]*(?:\.exe)?$/i', basename( $candidate ) ) || ! @is_file( $candidate ) || ! @is_executable( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may hide it; then it is not used.
				continue;
			}
			$run = $this->run( array( $candidate, '-n', '-v' ), self::LINT_TIMEOUT );
			if ( is_array( $run ) && ! $run['timed_out'] && 0 === $run['exit'] && 1 === preg_match( '/^PHP (\d+)\.(\d+)\.\S*\s+\(cli\)/', ltrim( $run['stdout'] ), $v ) && PHP_MAJOR_VERSION === (int) $v[1] && PHP_MINOR_VERSION === (int) $v[2] ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Run a command without a shell, with a time limit (Pro: run()).
	 *
	 * @param string[] $cmd     Binary and arguments.
	 * @param int      $timeout Seconds.
	 * @return array|null exit (null where PHP gives none), stdout, stderr, timed_out; null where it did not start.
	 */
	private function run( array $cmd, $timeout ) {
		$pipes = array();
		$proc  = @proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, null, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out      = '';
		$err      = '';
		$exit     = null;
		$timed    = false;
		$deadline = microtime( true ) + $timeout;
		while ( true ) {
			$status = proc_get_status( $proc );
			$out    = substr( $out . (string) stream_get_contents( $pipes[1] ), 0, 65536 );
			$err    = substr( $err . (string) stream_get_contents( $pipes[2] ), 0, 65536 );
			if ( ! is_array( $status ) || ! $status['running'] ) {
				$exit = is_array( $status ) && is_int( $status['exitcode'] ) && -1 !== $status['exitcode'] ? $status['exitcode'] : null;
				break;
			}
			if ( microtime( true ) > $deadline ) {
				$timed = true;
				proc_terminate( $proc, 9 );
				break;
			}
			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			@stream_select( $read, $write, $except, 0, 50000 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$out = substr( $out . (string) stream_get_contents( $pipes[1] ), 0, 65536 );
		$err = substr( $err . (string) stream_get_contents( $pipes[2] ), 0, 65536 );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$closed = proc_close( $proc );
		if ( null === $exit ) {
			$exit = is_int( $closed ) && -1 !== $closed ? $closed : null;
		}
		return array(
			'exit'      => $exit,
			'stdout'    => $out,
			'stderr'    => $err,
			'timed_out' => $timed,
		);
	}
}

/**
 * Class AB_MCP_Sync_Code_Stage
 */
final class AB_MCP_Sync_Code_Stage {

	/** The format of a state of a stage. */
	const STATE_FORMAT = 'ab-code-stage/1';

	/** The states of a stage; only complete() writes READY. */
	const CHECKING = 'checking';
	const READY    = 'ready';
	const REFUSED  = 'refused';

	/** The beginning of the name of a state file in the group (then its number and its SHA-256 in base32). */
	const STATE = 'st-';

	/**
	 * Bytes of a package of wordpress.org at most (proposal of branch 4,
	 * checked by Codex): 64 MiB, the bytes of a package of the takeover
	 * (AB_MCP_Sync_Manifest::MAX_BYTES). The largest of the ten common
	 * packages measured on 13.11.2026 is Jetpack 16.4 with 33'067'984 bytes,
	 * about half of it.
	 */
	const ORG_MAX = 67108864;

	/** Bytes a package of wordpress.org may hold unpacked at most: those of a package of the takeover (AB_MCP_Sync_Manifest::MAX_UNPACKED). */
	const UNPACKED_MAX = 268435456;

	/** Seconds the download may take. */
	const ORG_TIMEOUT = 30;

	/** The hosts and the address of the packages of wordpress.org. */
	const ORG_URL = 'https://downloads.wordpress.org/';

	/** A version as wordpress.org names the ZIP of it (as AB_MCP_Update_Guard::previous_package()). */
	const ORG_VERSION = '/^[0-9A-Za-z._-]{1,64}\z/';

	/** A slug of wordpress.org. */
	const ORG_SLUG = '/^[a-z0-9-]{1,191}\z/';

	/**
	 * Largest file the parse check runs on (E11; O1, decided 13.11.2026:
	 * 1 MiB, with the check of the memory below; Pro keeps 512 KiB,
	 * AB_MCP_Pro_Code_File_Guard::PARSE_MAX_BYTES). Above, skipped.
	 */
	const PARSE_MAX_BYTES = 1048576;

	/** Free memory the parse check wants per byte of code (Pro: PARSE_MEMORY_FACTOR). */
	const PARSE_MEMORY_FACTOR = 100;

	/** The fields of a head the check of the requirements reads (requirements()). */
	const HEAD_FIELDS = array( 'Plugin Name', 'Requires at least', 'Requires PHP' );

	/** Extensions PHP runs (Pro: PHP_EXTENSIONS); .l10n.php is one of .php. */
	const PHP_EXTENSIONS = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phar', 'inc' );

	/** Seconds one call of complete() checks at most; the next call goes on. */
	const CHECK_BUDGET = 20;

	/** The clock of the budget of complete(); a callable returning seconds, null for microtime(). Set by tests only. */
	public static $clock = null;

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_code_stage = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_Code_Stage_Calls
	 */
	private static function calls() {
		return self::$naht_sync_code_stage ?? new AB_MCP_Sync_Code_Stage_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_code_stage = null;
	}

	/* ------------------------------------------------- the signed manifest */

	/**
	 * The signed manifest of a unit of the staging site: its line and its
	 * files from the packages of its environment (AB_MCP_Sync_Report::
	 * environment(), each package checked again), or the refusal.
	 *
	 * @param string[] $env  The packages of the environment of the report, in order.
	 * @param string   $kind plugin or theme.
	 * @param string   $ref  The unit.
	 * @return array|WP_Error kind, ref, form, hash, files, bytes, version, update_uri (string|null), entries (path as the lines name it, size, sha256).
	 */
	public static function signed( array $env, $kind, $ref ) {
		if ( ! AB_MCP_Sync_Code_Package::unit_ok( $kind, $ref ) ) {
			return self::refusal( 'code:unit', __( 'The unit is not a plugin or theme as the stage takes it (kind plugin or theme, ref the name of its folder or file). Pass it as the report names the staging side.', 'alphabridge-mcp' ) );
		}
		$read = AB_MCP_Sync_Report::environment( $env );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$line  = null;
		$parts = array();
		foreach ( $read['lines'] as $one ) {
			if ( $kind === $one['env'] && $ref === $one['ref'] ) {
				$line = $one;
			} elseif ( 'entries' === $one['env'] && $kind . ':' . $ref === $one['unit'] ) {
				$parts[ $one['seq'] ] = $one['entries'];
			}
		}
		if ( null === $line || isset( $line['held'] ) || true !== $line['complete'] || null === $line['hash'] || null !== $line['lock'] || true === $line['own'] || ! in_array( $line['form'], array( 'folder', 'file' ), true ) ) {
			return self::refusal( 'code:unsigned', __( 'The packages of the environment of the report name no unit of that name read whole on the staging site (it is not there, AlphaBridge itself, locked, or held), so nothing is staged for it. Take the environment of the staging site over anew and make the report again (wp_site_diff).', 'alphabridge-mcp' ) );
		}
		$entries = array();
		if ( 'file' === $line['form'] ) {
			$entries[] = array( $ref, $line['bytes'], $line['sha256'] );
		} else {
			ksort( $parts );
			$n = 0;
			foreach ( $parts as $seq => $list ) {
				++$n;
				if ( $seq !== $n ) {
					$entries = null;
					break;
				}
				foreach ( $list as $entry ) {
					$entries[] = array( $entry[0], $entry[1], $entry[2] );
				}
			}
		}
		if ( null === $entries || count( $entries ) !== $line['files'] ) {
			return self::refusal( 'code:unsigned', __( 'The packages of the environment do not hold every file of the unit (a part of its list is missing), so nothing is staged for it. Take the environment of the staging site over anew and make the report again (wp_site_diff).', 'alphabridge-mcp' ) );
		}
		return array(
			'kind'       => $kind,
			'ref'        => $ref,
			'form'       => $line['form'],
			'hash'       => $line['hash'],
			'files'      => $line['files'],
			'bytes'      => $line['bytes'],
			'version'    => $line['version'],
			'update_uri' => self::update_host( $line ),
			'entries'    => $entries,
		);
	}

	/**
	 * The host the head of a unit names for its updates, as its signed line
	 * holds it, in the form of its row stage.update_uri of
	 * AB_MCP_Store_Inputs::TABLE: '' for none, a host, or null (unknown: a
	 * line of a version before, or a value not of the form; from_org()
	 * then fetches nothing, D1).
	 *
	 * @param array $line The line of the unit.
	 * @return string|null
	 */
	public static function update_host( array $line ) {
		return AB_MCP_Store_Inputs::read( 'stage.update_uri', array_key_exists( 'update_uri', $line ) ? $line['update_uri'] : null );
	}

	/**
	 * What the stage of a unit needs from a package of code: the files of the
	 * signed manifest whose SHA-256 the live unit lacks (E3 (b)), by their
	 * paths as the lines name them; same where the live unit is the signed
	 * one already (no source needed).
	 *
	 * @param string[] $env  The packages of the environment of the report.
	 * @param string   $kind plugin or theme.
	 * @param string   $ref  The unit.
	 * @return array|WP_Error kind, ref, same, paths, bytes.
	 */
	public static function need( array $env, $kind, $ref ) {
		$signed = self::signed( $env, $kind, $ref );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}
		$live = self::live( $kind, $ref );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$have = array();
		foreach ( null === $live ? array() : $live['entries'] as $entry ) {
			$have[ $entry[2] ] = true;
		}
		$paths = array();
		$bytes = 0;
		foreach ( $signed['entries'] as $entry ) {
			if ( ! isset( $have[ $entry[2] ] ) ) {
				$paths[] = $entry[0];
				$bytes  += $entry[1];
			}
		}
		return array(
			'kind'  => $kind,
			'ref'   => $ref,
			'same'  => null !== $live && hash_equals( $signed['hash'], $live['hash'] ),
			'paths' => $paths,
			'bytes' => $bytes,
		);
	}

	/* ------------------------------------------------------------ stage */

	/**
	 * Build a unit of the staging site and put it into the area stage, in one
	 * call (D4): from wordpress.org (from_org()) where no packages are given,
	 * else from the packages of code and the live unit (from_packages()).
	 * Nothing of the site changes. complete() checks it then.
	 *
	 * Before anything is fetched or written (Codex round 1 of branch 4, A1 and
	 * B1): the run a whole number of 1 or more, the protected store ready for
	 * a tree of these paths (web_ready()), and the group of the stage open,
	 * bound to the run.
	 *
	 * @param int      $run_id   The run the stage belongs to (a whole number of 1 or more).
	 * @param string[] $env      The packages of the environment of the report.
	 * @param string   $kind     plugin or theme.
	 * @param string   $ref      The unit.
	 * @param string[] $packages Packages of code taken over for the report, or none.
	 * @return array|WP_Error same (nothing to stage), or gid, name, hash, source, files, bytes, state.
	 */
	public static function stage( $run_id, array $env, $kind, $ref, array $packages = array() ) {
		if ( ! is_int( $run_id ) || $run_id < 1 ) {
			return self::refusal( 'code:run', __( 'A stage belongs to a run of the takeover: pass its run_id, a whole number of 1 or more. Nothing was fetched or written.', 'alphabridge-mcp' ) );
		}
		$signed = self::signed( $env, $kind, $ref );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}
		$target = self::target( $kind, $ref );
		if ( null === $target ) {
			return self::refusal( 'code:unit', __( 'This site has no folder for plugins or themes WordPress names, so nothing is staged.', 'alphabridge-mcp' ) );
		}
		// AlphaBridge itself is never staged (E12; the swap holds Z11).
		require_once dirname( __DIR__ ) . '/lifecycle/class-own-files.php';
		if ( is_wp_error( AB_MCP_Own_Files::own_file_refusal( $target ) ) ) {
			return self::refusal( 'code:own', __( 'This is AlphaBridge itself, which is shown, never swapped. Bring both sites to the same version of it by hand.', 'alphabridge-mcp' ) );
		}
		$live = self::live( $kind, $ref );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		if ( null !== $live && hash_equals( $signed['hash'], $live['hash'] ) ) {
			return array(
				'same' => true,
				'hash' => $live['hash'],
			);
		}
		// The unit built is the signed one, so the store must take a tree of
		// its paths before any of it is written, even into a building folder.
		$web = self::calls()->web_ready( array_column( $signed['entries'], 0 ) );
		if ( true !== $web ) {
			return $web;
		}
		$gid = AB_MCP_Protected_Store::open(
			'stage',
			array(
				'run_id' => $run_id,
				'label'  => 'code',
			)
		);
		if ( is_wp_error( $gid ) ) {
			return $gid;
		}
		$build = AB_MCP_Protected_Store::build_dir();
		if ( is_wp_error( $build ) ) {
			return $build;
		}
		try {
			$source = array() === $packages ? self::from_org( $signed, $build ) : self::from_packages( $signed, $live, $packages, $build );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			return self::take_in( $gid, $signed, $target, $source, array() === $packages ? 'wordpress.org' : 'packages' );
		} finally {
			AB_MCP_Protected_Store::drop_build( $build );
		}
	}

	/**
	 * A package of wordpress.org in the exact version of the staging site,
	 * unpacked in a building folder (Z5: the one place a package of
	 * wordpress.org is fetched for a stage): only a folder of a plugin or
	 * theme, a slug and a version as wordpress.org names them, a head that
	 * names no other host for its updates (update_uri '' or a host of
	 * wordpress.org; unknown refuses, D1), the file system method direct
	 * (D5), over https from ORG_URL at the exact address without redirect
	 * (fetch_zip()), unpacked with unzip_file(); the result counts only where
	 * its manifest is the signed one.
	 *
	 * @param array  $signed signed().
	 * @param string $build  A building folder (build_dir()).
	 * @return string|WP_Error The folder of the unit in the building folder.
	 */
	public static function from_org( array $signed, $build ) {
		$host = $signed['update_uri'];
		if ( null === $host ) {
			return self::refusal( 'code:update_uri_unknown', __( 'Which host the head of this plugin or theme names for its updates is not known (the staging site runs a version of AlphaBridge before 4.19), so it is not fetched from wordpress.org. Update AlphaBridge MCP on the staging site, take its environment over anew, or send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ) );
		}
		if ( '' !== $host && ! in_array( $host, AB_MCP_Sync_Env::WPORG_HOSTS, true ) ) {
			/* translators: %s: the host the head names. */
			return self::refusal( 'code:update_uri', sprintf( __( 'The head of this plugin or theme on the staging site names %s for its updates (Update URI), so it is not fetched from wordpress.org. Send it as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ), $host ) );
		}
		$version = AB_MCP_Store_Inputs::read( 'stage.version', $signed['version'] );
		if ( 'folder' !== $signed['form'] || null === $version || 1 !== preg_match( self::ORG_VERSION, $version ) || 'trunk' === $version || 1 !== preg_match( self::ORG_SLUG, $signed['ref'] ) ) {
			return self::refusal( 'code:org_unit', __( 'This unit is no folder of a plugin or theme with a slug and a version as wordpress.org names its packages, so it is not fetched from there. Send it as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ) );
		}
		if ( 'direct' !== self::calls()->method() ) {
			return self::refusal( 'code:file_mods', __( 'WordPress writes files on this site not directly (the file system method is not direct), and a package is unpacked and swapped by renaming only. Ask the host to give PHP write access to wp-content, or update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ) );
		}
		$url = self::ORG_URL . ( 'theme' === $signed['kind'] ? 'theme' : 'plugin' ) . '/' . $signed['ref'] . '.' . $version . '.zip';
		$zip = $build . '/package.zip';
		$got = self::fetch_zip( $url, $zip, self::ORG_MAX );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		// What the ZIP would lay into the building folder, by its directory,
		// before anything of it is unpacked (Codex round 1, A1).
		$names = self::calls()->zip_names( $zip );
		$web   = is_array( $names ) ? self::calls()->web_ready( $names ) : null;
		if ( null !== $web && true !== $web ) {
			return $web;
		}
		$size = self::calls()->unpacked_size( $zip );
		if ( null === $web || ! is_int( $size ) || $size > self::UNPACKED_MAX ) {
			return self::refusal( 'code:org_too_large', __( 'The package of wordpress.org does not read as a ZIP, or holds more than a stage takes unpacked (256 MiB), so it is not unpacked. Update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ) );
		}
		$unzipped = self::calls()->unzip( $zip, $build . '/org' );
		if ( true !== $unzipped ) {
			return self::refusal( 'code:org_unzip', __( 'The package of wordpress.org could not be unpacked here (unzip_file() refused it: no ZipArchive, no room, or a broken archive). Try again later, or send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ) );
		}
		$read = self::read_built( $build . '/org', $signed['ref'] );
		if ( null === $read ) {
			return self::clock_refusal();
		}
		if ( ! $read['complete'] || null === $read['hash'] || ! hash_equals( $signed['hash'], $read['hash'] ) ) {
			return self::refusal( 'code:org_mismatch', __( 'The package of wordpress.org in this version is not the unit of the staging site (another file, a file more or less), so it is not used. Send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ) );
		}
		return $build . '/org/' . $signed['ref'];
	}

	/**
	 * The shared downloader of a ZIP (D2; from_org() and the version of
	 * wp_install_plugin and wp_install_theme): only an https address, through
	 * AB_MCP_Outbound, no redirect, into $to; refused where the answer is not
	 * 200, holds more than $max bytes (it reads $max + 1 and refuses more
	 * than $max: V2), is empty or is no ZIP.
	 *
	 * @param string $url Address (https).
	 * @param string $to  The file to write.
	 * @param int    $max Bytes at most.
	 * @return true|WP_Error
	 */
	public static function fetch_zip( $url, $to, $max ) {
		if ( ! is_string( $url ) || ! is_string( $to ) || 0 !== strpos( $url, 'https://' ) || ! is_int( $max ) || $max < 1 ) {
			return self::refusal( 'code:org_address', __( 'A package is fetched only over https, with a limit of its size.', 'alphabridge-mcp' ) );
		}
		$answer = self::calls()->get(
			$url,
			array(
				'timeout'             => self::ORG_TIMEOUT,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $to,
				'limit_response_size' => $max + 1,
			)
		);
		if ( is_wp_error( $answer ) ) {
			/* translators: %s: why the download failed. */
			return self::refusal( 'code:org_fetch', sprintf( __( 'The package could not be fetched from wordpress.org (%s). Try again later, or send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ), $answer->get_error_message() ) );
		}
		$status = wp_remote_retrieve_response_code( $answer );
		if ( 200 !== $status ) {
			/* translators: %s: HTTP status. */
			return self::refusal( 'code:org_fetch', sprintf( __( 'wordpress.org answered %s for the package in this version (it does not keep it, or it redirects). Send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ), is_scalar( $status ) ? (string) $status : '?' ), array( 'status' => $status ) );
		}
		clearstatcache( true, $to );
		$size = @filesize( $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no file is answered.
		if ( ! is_int( $size ) || $size < 1 || $size > $max ) {
			/* translators: %d: the most bytes a package may have. */
			return self::refusal( 'code:org_too_large', sprintf( __( 'The package is empty or larger than %d bytes, so it is not used. Update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ), $max ), array( 'max' => $max ) );
		}
		$head = @file_get_contents( $to, false, null, 0, 4 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the magic bytes.
		if ( "PK\x03\x04" !== $head && "PK\x05\x06" !== $head ) {
			return self::refusal( 'code:org_fetch', __( 'The answer is no ZIP archive, so it is not used. Try again later, or send the unit as a package of code (wp_site_export with purpose=code).', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * Build a unit from packages of code and the live unit (E3 (b)): each
	 * file of the signed manifest from the live unit where its SHA-256 is
	 * there, else from a package that holds it at its path with that SHA-256
	 * (AB_MCP_Sync_Code_Package::extract()); every file is the one of the
	 * signed manifest, and the whole only where its manifest is the signed
	 * one. Files missing: code:stage_incomplete with them (need()).
	 *
	 * @param array      $signed   signed().
	 * @param array|null $live     live(), or null.
	 * @param string[]   $packages Packages of code taken over.
	 * @param string     $build    A building folder.
	 * @return string|WP_Error The folder or file of the unit in the building folder.
	 */
	public static function from_packages( array $signed, $live, array $packages, $build ) {
		$offered = array();
		foreach ( array_values( array_unique( $packages ) ) as $id ) {
			$taken = self::calls()->taken( $id );
			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
			foreach ( $taken['files'] as $file ) {
				if ( $signed['kind'] === $file['kind'] && $signed['ref'] === $file['ref'] ) {
					$offered[ AB_MCP_Sync_Env::path_name( $file['path'] ) . "\0" . $file['sha256'] ] = array( $taken, $file );
				}
			}
		}
		$copies = array();
		$names  = array();
		foreach ( null === $live ? array() : $live['entries'] as $entry ) {
			$copies[ $entry[2] ]                                   = $live['path'] . ( 'file' === $live['form'] ? '' : '/' . $entry[0] );
			$names[ AB_MCP_Sync_Env::path_name( $entry[0] ) ] = $entry[0];
		}
		$unit    = $build . '/unit/' . $signed['ref'];
		$missing = array();
		$plan    = array();
		foreach ( $signed['entries'] as $entry ) {
			$key  = $entry[0] . "\0" . $entry[2];
			$real = isset( $offered[ $key ] ) ? $offered[ $key ][1]['path'] : ( isset( $names[ $entry[0] ] ) ? $names[ $entry[0] ] : ( $entry[0] === AB_MCP_Sync_Env::path_name( $entry[0] ) ? $entry[0] : null ) );
			if ( null === $real || ! AB_MCP_Sync_Code_Package::path_ok( $real ) || ( ! isset( $offered[ $key ] ) && ! isset( $copies[ $entry[2] ] ) ) ) {
				$missing[] = $entry[0];
				continue;
			}
			$plan[] = array( 'file' === $signed['form'] ? $unit : $unit . '/' . $real, isset( $offered[ $key ] ) ? $offered[ $key ] : null, isset( $copies[ $entry[2] ] ) ? $copies[ $entry[2] ] : null );
		}
		if ( array() !== $missing ) {
			/* translators: %d: how many files. */
			return self::refusal( 'code:stage_incomplete', sprintf( __( 'The packages of code and the live unit do not hold %d files of the unit as the staging site has them, so it is not staged. Export them on the staging site (wp_site_export with purpose=code, this unit and the paths named here), take the package over here, then stage again with every package of it.', 'alphabridge-mcp' ), count( $missing ) ), array( 'paths' => array_slice( $missing, 0, 2000 ) ) );
		}
		$made = self::calls()->mkdir( 'file' === $signed['form'] ? $build . '/unit' : $unit );
		foreach ( $plan as $step ) {
			$made = $made && self::calls()->mkdir( dirname( $step[0] ) ) && ( null !== $step[1] ? true === self::calls()->extract( $step[1][0], $step[1][1], $step[0] ) : self::calls()->copy( $step[2], $step[0] ) );
		}
		$read = $made ? self::read_built( $build . '/unit', $signed['ref'] ) : false;
		if ( null === $read ) {
			return self::clock_refusal();
		}
		if ( false === $read || ! $read['complete'] || null === $read['hash'] || ! hash_equals( $signed['hash'], $read['hash'] ) ) {
			return self::refusal( 'code:stage_mismatch', __( 'The unit built from the packages of code and the live unit is not the unit of the staging site (a file could not be written, or one changed while it was copied), so it is not staged. Stage it again; if it keeps failing, export the whole unit on the staging site (wp_site_export with purpose=code, this unit without paths).', 'alphabridge-mcp' ) );
		}
		return $unit;
	}

	/**
	 * The built unit into the area stage (put_tree(), the one way a tree
	 * comes into the store), in the group stage() opened for the run, with
	 * its first state.
	 *
	 * @param string $gid    The group of the stage.
	 * @param array  $signed signed().
	 * @param string $target The folder or file of the unit in the site.
	 * @param string $source The built unit.
	 * @param string $from   wordpress.org or packages.
	 * @return array|WP_Error
	 */
	private static function take_in( $gid, array $signed, $target, $source, $from ) {
		// The built unit is the signed one (from_org(), from_packages()); that
		// the tree in the store is too, complete() checks (Z4, early).
		$put = AB_MCP_Protected_Store::put_tree( 'stage', $gid, $signed['ref'], $target, $source );
		$state = is_wp_error( $put ) ? $put : self::write_state(
			$gid,
			array(
				'format'  => self::STATE_FORMAT,
				'unit'    => array(
					'kind' => $signed['kind'],
					'ref'  => $signed['ref'],
				),
				'signed'  => $signed['hash'],
				'tree'    => array(
					'hash' => $put['hash'],
					'name' => $signed['ref'],
				),
				'source'  => $from,
				'state'   => self::CHECKING,
				'seq'     => 1,
				'todo'    => array(),
				'done'    => 0,
				'lint'    => true,
				'notes'   => array(),
				'refusal' => null,
			)
		);
		if ( is_wp_error( $state ) ) {
			// The group stays bound to its run: the retention lets it go (Z8;
			// no shipped code removes a group of stage), never ready without
			// its state.
			return $state;
		}
		return array(
			'gid'    => $gid,
			'name'   => $signed['ref'],
			'hash'   => $put['hash'],
			'source' => $from,
			'files'  => $put['files'],
			'bytes'  => $put['bytes'],
			'state'  => self::CHECKING,
		);
	}

	/* ------------------------------------------------------------ check */

	/**
	 * Check a stage (Z6, the one place that writes ready; E11), over as many
	 * calls as it takes, each within CHECK_BUDGET seconds: the tree read back
	 * and its hash the signed one (Z4, early), every PHP file it brings new
	 * or changed through the parser and, where there is a command line,
	 * php -l, then Requires PHP and Requires at least. Each call writes the
	 * next state; a stage refused stays refused.
	 *
	 * @param string   $gid  The group of the stage.
	 * @param string   $name The tree.
	 * @param string   $hash The hash stage() gave.
	 * @param string[] $env  The packages of the environment of the report.
	 * @return array|WP_Error The state: state checking (call again), ready or refused (with refusal).
	 */
	public static function complete( $gid, $name, $hash, array $env ) {
		$state = self::state( $gid );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( $name !== $state['tree']['name'] || ! is_string( $hash ) || ! hash_equals( $state['tree']['hash'], $hash ) ) {
			return self::refusal( 'code:stage_unknown', __( 'The stage named is not the one its group holds. Stage the unit again.', 'alphabridge-mcp' ) );
		}
		if ( self::CHECKING !== $state['state'] ) {
			return self::answer( $state );
		}
		$tree   = AB_MCP_Protected_Store::tree( 'stage', $gid, $name, $hash );
		$signed = is_wp_error( $tree ) ? $tree : self::signed( $env, $state['unit']['kind'], $state['unit']['ref'] );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}
		if ( ! hash_equals( $signed['hash'], $tree['hash'] ) || ! hash_equals( $state['signed'], $signed['hash'] ) ) {
			return self::answer( self::refuse( $gid, $state, 'code:stage_mismatch', __( 'The stage is not the unit the report names on the staging site (the staging site changed it since, or the stage is another), so it is never ready. Make the report again, then stage the unit anew.', 'alphabridge-mcp' ) ) );
		}
		$start = self::clock();
		if ( null === $start ) {
			// No time to measure this call by: it ends as it is, the next call
			// goes on (never done, never ready).
			return self::answer( $state );
		}
		$until = $start + self::CHECK_BUDGET;
		if ( 1 === $state['seq'] && 0 === $state['done'] ) {
			$todo = self::to_check( $tree['path'], $tree['hash'], $state['unit']['kind'], $state['unit']['ref'] );
			if ( false === $todo ) {
				return self::answer( $state );
			}
			if ( null === $todo ) {
				return self::answer( self::refuse( $gid, $state, 'code:syntax_unchecked', __( 'The stage could not be read whole within one call to list its PHP files, so they are not checked and it is never ready. Stage the unit again; if it keeps failing, update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ) ) );
			}
			$state['todo'] = $todo;
		}
		$base = 'file' === $tree['form'] ? dirname( $tree['path'] ) : $tree['path'];
		while ( $state['done'] < count( $state['todo'] ) ) {
			$now = self::clock();
			if ( null === $now || $now > $until ) {
				return self::answer( self::next( $gid, $state ) );
			}
			$item  = $state['todo'][ $state['done'] ];
			$found = self::check_file( $base . '/' . $item['path'], $item, $state['lint'] );
			if ( isset( $found['refusal'] ) ) {
				return self::answer( self::refuse( $gid, $state, $found['refusal'][0], $found['refusal'][1], $item['path'], $found['refusal'][2] ) );
			}
			if ( isset( $found['note'] ) && $state['lint'] ) {
				$state['lint']    = false;
				$state['notes'][] = $found['note'];
			}
			++$state['done'];
		}
		$requires = self::requirements( $tree['path'], $tree['form'], $state['unit']['kind'] );
		if ( null !== $requires ) {
			return self::answer( self::refuse( $gid, $state, 'code:requirements', $requires ) );
		}
		$state['state'] = self::READY;
		return self::answer( self::next( $gid, $state ) );
	}

	/**
	 * The state of a stage that is ready (for the swap, branch 5), or null:
	 * the last state of its group, of the form of its row stage.state, ready,
	 * for this tree and its hash.
	 *
	 * @param string $gid  The group.
	 * @param string $name The tree.
	 * @param string $hash Its hash.
	 * @return array|null
	 */
	public static function ready( $gid, $name, $hash ) {
		$state = self::state( $gid );
		return is_array( $state ) && self::READY === $state['state'] && $name === $state['tree']['name'] && is_string( $hash ) && hash_equals( $state['tree']['hash'], $hash ) ? $state : null;
	}

	/**
	 * The PHP files of a tree the stage brings new or changed: those whose
	 * path and SHA-256 the live unit has not, by PHP_EXTENSIONS, in the order
	 * of the manifest.
	 *
	 * @param string $path The tree.
	 * @param string $hash Its hash (tree()).
	 * @param string $kind Kind.
	 * @param string $ref  Unit.
	 * @return array[]|null|false Each path in the unit with its size and SHA-256 by the manifest of the tree (the bytes the check takes are bound to them, Codex round 1, A3); null where the tree does not read whole as that hash (unknown: nothing is passed over); false where the time of the call could not be measured (the call ends without progress, Codex confirmation 1, B-2).
	 */
	private static function to_check( $path, $hash, $kind, $ref ) {
		$tree = self::read_built( dirname( $path ), basename( $path ) );
		if ( null === $tree ) {
			return false;
		}
		if ( ! $tree['complete'] || null === $tree['hash'] || ! hash_equals( $hash, $tree['hash'] ) ) {
			return null;
		}
		$live = self::live( $kind, $ref );
		if ( is_wp_error( $live ) ) {
			return false;
		}
		$have = array();
		foreach ( null === $live ? array() : $live['entries'] as $entry ) {
			$have[ $entry[0] . "\0" . $entry[2] ] = true;
		}
		$out = array();
		foreach ( $tree['entries'] as $entry ) {
			if ( self::is_php( $entry[0] ) && ! isset( $have[ $entry[0] . "\0" . $entry[2] ] ) ) {
				$out[] = array(
					'path'   => $entry[0],
					'sha256' => $entry[2],
					'size'   => $entry[1],
				);
			}
		}
		return $out;
	}

	/**
	 * One PHP file of a stage through both checks, only with the bytes the
	 * manifest of the tree names for it (its size and SHA-256 from the state,
	 * the row stage.state): a read that ends short or differs checks nothing.
	 *
	 * @param string $file The file in the tree.
	 * @param array  $item path (in the unit), size, sha256.
	 * @param bool   $lint Whether php -l is still asked.
	 * @return array refusal (code, message, line), or note, or nothing.
	 */
	private static function check_file( $file, array $item, $lint ) {
		$label = $item['path'];
		$code  = self::calls()->read( $file );
		if ( ! is_string( $code ) ) {
			return array( 'refusal' => array( 'code:stage_mismatch', __( 'A file of the stage could not be read, so the stage is never ready. Stage the unit again.', 'alphabridge-mcp' ), null ) );
		}
		if ( strlen( $code ) !== $item['size'] || ! hash_equals( $item['sha256'], hash( 'sha256', $code ) ) ) {
			/* translators: %s: file. */
			return array( 'refusal' => array( 'code:syntax_unchecked', sprintf( __( '%s did not read whole as the manifest of the stage names it (its size and SHA-256), so it is not checked and the stage is never ready. Stage the unit again.', 'alphabridge-mcp' ), $label ), null ) );
		}
		$parse = self::parse_check( $code );
		unset( $code );
		if ( 'failed' === $parse['status'] ) {
			/* translators: 1: file, 2: line, 3: PHP's message, 4: PHP version. */
			return array( 'refusal' => array( 'code:syntax', sprintf( __( '%1$s does not parse with the PHP of this site — line %2$d: %3$s (PHP %4$s). Nothing is swapped; fix the code on the staging site and take it over again.', 'alphabridge-mcp' ), $label, $parse['line'], $parse['message'], PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ), $parse['line'] ) );
		}
		if ( 'unavailable' === $parse['status'] ) {
			return array( 'refusal' => array( 'code:syntax_unavailable', __( 'This server has no PHP tokenizer, so no PHP file of a plugin or theme can be checked before a swap, and none is swapped. Ask the host to enable the PHP extension tokenizer, then stage again.', 'alphabridge-mcp' ), null ) );
		}
		if ( 'skipped' === $parse['status'] ) {
			/* translators: 1: file, 2: why. */
			return array( 'refusal' => array( 'code:syntax_unchecked', sprintf( __( '%1$s could not be checked with the parser of this site (%2$s), and nothing shows that the same bytes ran on the staging site, so it is not swapped. Update the plugin or theme by hand in wp-admin.', 'alphabridge-mcp' ), $label, $parse['why'] ), null ) );
		}
		if ( ! $lint ) {
			return array();
		}
		$compile = self::calls()->lint( $file, $label );
		if ( 'failed' === $compile['status'] ) {
			/* translators: 1: file, 2: line, 3: PHP's message, 4: PHP version. */
			return array( 'refusal' => array( 'code:syntax', sprintf( __( 'php -l finds an error in %1$s — line %2$d: %3$s (PHP %4$s, the version of this site). Nothing is swapped; fix the code on the staging site and take it over again.', 'alphabridge-mcp' ), $label, $compile['line'], $compile['message'], PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ), $compile['line'] ) );
		}
		if ( 'passed' !== $compile['status'] ) {
			/* translators: %s: why. */
			return array( 'note' => sprintf( __( 'compile_check unavailable: %s The parser checked every file; php -l did not.', 'alphabridge-mcp' ), isset( $compile['note'] ) ? $compile['note'] : '' ) );
		}
		return array();
	}

	/**
	 * Parse code with the PHP of this site, without running it (the port of
	 * Pro's parse_check(), without its filter): passed, failed with line and
	 * message, unavailable (no tokenizer), skipped (over PARSE_MAX_BYTES, or
	 * less memory left than PARSE_MEMORY_FACTOR times its size) with why.
	 *
	 * @param string $code Bytes.
	 * @return array{status:string,line?:int,message?:string,why?:string}
	 */
	public static function parse_check( $code ) {
		$calls = self::calls();
		if ( ! $calls->tokenizer() ) {
			return array( 'status' => 'unavailable' );
		}
		if ( ! is_string( $code ) ) {
			return array(
				'status' => 'skipped',
				'why'    => __( 'its bytes could not be read', 'alphabridge-mcp' ),
			);
		}
		$size = strlen( $code );
		if ( $size > self::PARSE_MAX_BYTES ) {
			return array(
				'status' => 'skipped',
				/* translators: 1: file size in bytes, 2: limit in bytes. */
				'why'    => sprintf( __( 'it has %1$d bytes, over the %2$d the parser checks in a request', 'alphabridge-mcp' ), $size, self::PARSE_MAX_BYTES ),
			);
		}
		$memory = $calls->memory();
		if ( null === $memory[0] ) {
			return array(
				'status' => 'skipped',
				'why'    => __( 'memory_limit of this PHP could not be read', 'alphabridge-mcp' ),
			);
		}
		if ( $memory[0] > 0 && $memory[0] - $memory[1] < $size * self::PARSE_MEMORY_FACTOR ) {
			return array(
				'status' => 'skipped',
				/* translators: %d: free memory in bytes. */
				'why'    => sprintf( __( 'only %d bytes of memory are left in this request (memory_limit)', 'alphabridge-mcp' ), max( 0, $memory[0] - $memory[1] ) ),
			);
		}
		// Warnings the lexer raises on odd but valid code stay out of the answer.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function () {
				return true;
			}
		);
		try {
			$calls->parse_php( $code );
		} catch ( CompileError $e ) {
			// ParseError is a CompileError; both mean the file cannot load.
			return array(
				'status'  => 'failed',
				'line'    => $e->getLine(),
				'message' => $e->getMessage(),
			);
		} finally {
			restore_error_handler();
		}
		return array( 'status' => 'passed' );
	}

	/**
	 * Requires PHP and Requires at least of the heads of the new version
	 * against this site, as WordPress checks them (validate_plugin_requirements(),
	 * validate_theme_requirements(): the head of each PHP file in the folder
	 * of a plugin, style.css of a theme): null where they hold, else why not.
	 * A head is read whole or not at all (AB_MCP_Sync_Env::head_read()), and
	 * one not read is unknown and refuses (head_refusal(); Codex round 1,
	 * A4), never taken as a head without requirements.
	 *
	 * @param string $path The tree.
	 * @param string $form folder or file.
	 * @param string $kind Kind.
	 * @return string|null
	 */
	private static function requirements( $path, $form, $kind ) {
		if ( 'theme' === $kind ) {
			$files = array( $path . '/style.css' );
		} else {
			$names = 'file' === $form ? array( basename( $path ) ) : @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder that cannot be read is answered.
			if ( ! is_array( $names ) ) {
				return __( 'The folder of the new version could not be read for its heads, so it is not swapped. Stage the unit again.', 'alphabridge-mcp' );
			}
			$files = array();
			foreach ( $names as $n ) {
				$file = 'file' === $form ? $path : $path . '/' . $n;
				// A folder of that name has no head; any other entry is read.
				if ( ! is_string( $n ) || '.php' !== substr( $n, -4 ) || is_dir( $file ) ) {
					continue;
				}
				$files[] = $file;
			}
		}
		foreach ( $files as $file ) {
			$why = self::head_refusal( AB_MCP_Sync_Env::head_read( $file, self::HEAD_FIELDS ), basename( $file ), $kind );
			if ( null !== $why ) {
				return $why;
			}
		}
		return null;
	}

	/**
	 * Whether one head lets the new version run on this site
	 * (requirements()): the head in the form of its row stage.head of
	 * AB_MCP_Store_Inputs::TABLE; unknown refuses; a PHP file of a plugin
	 * without Plugin Name is no main file and holds.
	 *
	 * @param mixed  $raw  The head as AB_MCP_Sync_Env::head_read() gave it.
	 * @param string $name The file, for the message.
	 * @param string $kind plugin or theme.
	 * @return string|null Why not, or null.
	 */
	public static function head_refusal( $raw, $name, $kind ) {
		$head = AB_MCP_Store_Inputs::read( 'stage.head', $raw );
		if ( null === $head ) {
			/* translators: %s: file. */
			return sprintf( __( 'The head of %s in the new version could not be read whole, so what it requires is not known and it is not swapped. Stage the unit again.', 'alphabridge-mcp' ), $name );
		}
		if ( 'theme' !== $kind && '' === $head['Plugin Name'] ) {
			return null;
		}
		if ( ! is_php_version_compatible( $head['Requires PHP'] ) || ! is_wp_version_compatible( $head['Requires at least'] ) ) {
			/* translators: 1: Requires PHP, 2: Requires at least, 3: PHP version here, 4: WordPress version here. */
			return sprintf( __( 'The new version needs PHP %1$s and WordPress %2$s (its head: Requires PHP, Requires at least); this site runs PHP %3$s and WordPress %4$s, so it is not swapped. Update PHP or WordPress here first, or keep the version this site has.', 'alphabridge-mcp' ), '' === $head['Requires PHP'] ? '-' : $head['Requires PHP'], '' === $head['Requires at least'] ? '-' : $head['Requires at least'], PHP_VERSION, get_bloginfo( 'version' ) );
		}
		return null;
	}

	/* ------------------------------------------------------------ states */

	/**
	 * The last state of a stage, read in the form of its row stage.state of
	 * AB_MCP_Store_Inputs::TABLE, or the refusal (unknown: never ready).
	 *
	 * @param string $gid The group.
	 * @return array|WP_Error
	 */
	private static function state( $gid ) {
		$files = AB_MCP_Protected_Store::files( 'stage', $gid );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$last = null;
		foreach ( array_keys( $files ) as $file ) {
			if ( is_string( $file ) && 1 === preg_match( '/^' . self::STATE . '(\d{4})-([a-z2-7]{52})\z/', $file, $m ) && ( null === $last || $m[1] > $last[0] ) ) {
				$last = array( $m[1], $file, $m[2] );
			}
		}
		$sha  = null === $last ? null : AB_MCP_Sync_Import::held_sha( AB_MCP_Sync_Import::HELD . $last[2] );
		$text = null === $sha ? null : AB_MCP_Protected_Store::get( 'stage', $gid, $last[1], $sha );
		$read = is_string( $text ) ? self::state_of( $text ) : null;
		if ( null === $read || sprintf( '%04d', $read['seq'] ) !== $last[0] ) {
			return self::refusal( 'code:stage_unknown', __( 'The state of the stage does not read back as this version writes it, so it is never ready. Stage the unit again.', 'alphabridge-mcp' ) );
		}
		return $read;
	}

	/**
	 * A state as write_state() writes it, in the form of its row
	 * stage.state, or null (unknown).
	 *
	 * @param mixed $text The state as read.
	 * @return array|null
	 */
	public static function state_of( $text ) {
		$state = AB_MCP_Store_Inputs::read( 'stage.state', $text );
		if ( ! is_array( $state ) || ! AB_MCP_Sync_Code_Package::unit_ok( $state['unit']['kind'], $state['unit']['ref'] ) || $state['done'] > count( $state['todo'] ) || ( self::REFUSED === $state['state'] ) !== ( null !== $state['refusal'] ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * Write the next state of a stage (seq one more).
	 *
	 * @param string $gid   The group.
	 * @param array  $state The state.
	 * @return array|WP_Error The state written.
	 */
	private static function next( $gid, array $state ) {
		++$state['seq'];
		$written = self::write_state( $gid, $state );
		return is_wp_error( $written ) ? $written : $state;
	}

	/**
	 * Refuse a stage: its next state refused, with the code and why.
	 *
	 * @param string      $gid     The group.
	 * @param array       $state   The state.
	 * @param string      $code    Code.
	 * @param string      $message Why, with the way.
	 * @param string|null $path    The file it is about.
	 * @param int|null    $line    Its line.
	 * @return array|WP_Error
	 */
	private static function refuse( $gid, array $state, $code, $message, $path = null, $line = null ) {
		$state['state']   = self::REFUSED;
		$state['refusal'] = array(
			'code'    => $code,
			'line'    => is_int( $line ) ? $line : null,
			'message' => $message,
			'path'    => $path,
		);
		return self::next( $gid, $state );
	}

	/**
	 * Write a state into the group of its stage, once (a file of the store is
	 * written once): named by its number and its SHA-256.
	 *
	 * @param string $gid   The group.
	 * @param array  $state The state.
	 * @return true|WP_Error
	 */
	private static function write_state( $gid, array $state ) {
		$text = AB_MCP_Sync_Canon::encode( $state );
		if ( null === self::state_of( $text ) ) {
			return self::refusal( 'code:stage_unknown', __( 'The state of the stage could not be written as this version reads it. Stage the unit again.', 'alphabridge-mcp' ) );
		}
		$name = self::STATE . sprintf( '%04d', $state['seq'] ) . '-' . substr( AB_MCP_Sync_Import::held_name( hash( 'sha256', $text ) ), strlen( AB_MCP_Sync_Import::HELD ) );
		$put  = AB_MCP_Protected_Store::put( 'stage', $gid, $name, $text );
		return is_wp_error( $put ) ? $put : true;
	}

	/**
	 * The answer of complete() for a state.
	 *
	 * @param array|WP_Error $state The state.
	 * @return array|WP_Error
	 */
	private static function answer( $state ) {
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return array(
			'state'   => $state['state'],
			'checked' => $state['done'],
			'files'   => count( $state['todo'] ),
			'notes'   => $state['notes'],
			'refusal' => $state['refusal'],
		);
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * The folder or file of a unit in this site.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  Unit.
	 * @return string|null
	 */
	private static function target( $kind, $ref ) {
		$base = 'theme' === $kind ? AB_MCP_Sync_File_Manifest::theme_root() : ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) ? rtrim( WP_PLUGIN_DIR, '/' ) : null );
		return null === $base ? null : $base . '/' . $ref;
	}

	/**
	 * The live unit read whole, or null (not there, or not read whole); the
	 * refusal where the time of the call could not be measured: unknown,
	 * never taken as a site without the unit (Codex confirmation 1, B-2).
	 *
	 * @param string $kind plugin or theme.
	 * @param string $ref  Unit.
	 * @return array|null|WP_Error form, hash, entries, path.
	 */
	private static function live( $kind, $ref ) {
		$target = self::target( $kind, $ref );
		if ( null === $target ) {
			return null;
		}
		$now = self::clock();
		if ( null === $now ) {
			return self::clock_refusal();
		}
		$read = AB_MCP_Sync_File_Manifest::unit( dirname( $target ), $ref, 'live', $now + AB_MCP_Sync_Env::UNIT_BUDGET, true, $kind );
		return $read['complete'] && null !== $read['hash'] ? array(
			'form'    => $read['form'],
			'hash'    => $read['hash'],
			'entries' => $read['entries'],
			'path'    => $target,
		) : null;
	}

	/**
	 * A unit built in a building folder, read whole (the kind plugin: a
	 * building folder is no root of themes, and the kind decides nothing else
	 * of a reading, AB_MCP_Sync_File_Manifest::unit()).
	 *
	 * @param string $base The folder it lies in.
	 * @param string $name The unit.
	 * @return array|null As AB_MCP_Sync_File_Manifest::unit(); null where the time of the call could not be measured.
	 */
	private static function read_built( $base, $name ) {
		$now = self::clock();
		if ( null === $now ) {
			// No time for the budget of the reading: unknown, nothing read.
			return null;
		}
		return AB_MCP_Sync_File_Manifest::unit( $base, $name, 'staging', $now + AB_MCP_Sync_Env::UNIT_BUDGET, true, 'plugin' );
	}

	/**
	 * Does PHP run a file of this name (Pro: is_php_name())? A name that is
	 * no text counts as one (it is checked, never passed over).
	 *
	 * @param string $name Path.
	 * @return bool
	 */
	public static function is_php( $name ) {
		if ( ! is_string( $name ) ) {
			return true;
		}
		$base = strtolower( rtrim( basename( str_replace( '\\', '/', $name ) ), ". \t" ) );
		$dot  = strrpos( $base, '.' );
		return false !== $dot && in_array( substr( $base, $dot + 1 ), self::PHP_EXTENSIONS, true );
	}

	/**
	 * The time within one call, for its budgets only (CHECK_BUDGET,
	 * UNIT_BUDGET): microtime( true ), or the clock of a test. A value that
	 * is no finite number is unknown (null): the slice ends there, never
	 * done or ready (a named exception of the time() guard, decision of the
	 * lead 13.11.2026: budgets within a request; times across requests only
	 * through AB_MCP_Sync_Run::now()).
	 *
	 * @return float|null Seconds.
	 */
	private static function clock() {
		$now = null !== self::$clock ? call_user_func( self::$clock ) : microtime( true );
		return is_float( $now ) && is_finite( $now ) ? $now : null;
	}

	/**
	 * @return WP_Error The time of the call could not be measured: nothing is read or taken.
	 */
	private static function clock_refusal() {
		return self::refusal( 'code:clock', __( 'The time of this call could not be measured, so nothing of the unit was read or taken. Call again.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param string $code    Code.
	 * @param string $message Why, with the way.
	 * @param array  $data    More.
	 * @return WP_Error
	 */
	private static function refusal( $code, $message, array $data = array() ) {
		return new WP_Error( $code, $message, $data );
	}
}
