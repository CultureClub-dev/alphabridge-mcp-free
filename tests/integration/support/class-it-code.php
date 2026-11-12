<?php
/**
 * The test plugins and themes on the two integration sites (Z8b branch 1,
 * point 2; plan E21): put a version of a unit on a site, take it away, bring
 * both sites to the state at the start, read counters, marks and the theme
 * options a case compares. The units and their versions are listed in
 * fixtures/code/katalog.php.
 *
 * Every unit is copied, never linked (V5 of the pre-check): a plugin that
 * writes into its own folder (ab-it-schreiber, F46) writes into the site,
 * not into the checkout. Every copied file gets the same time stamp
 * (MTIME), so two versions differ in their bytes only (F39).
 *
 * The test process (site A, loaded at the start of the suite) never loads a
 * test plugin: activation, deactivation and the switch of the theme run on
 * the site in a process of its own (bin/site-call.php). AB_MCP_IT_Sites::reset()
 * puts the tables back; files are this class's: start() in setUp(), clear()
 * in tearDown(). The bootstrap clears both sites before WordPress loads, so
 * a run that ended early leaves nothing to the next one.
 *
 * Typical case, in a few lines:
 *
 *     AB_MCP_IT_Sites::reset( 'a' );
 *     AB_MCP_IT_Sites::reset( 'b' );
 *     AB_MCP_IT_Code::start();
 *     AB_MCP_IT_Code::put( 'b', 'plugin', 'ab-it-aktiv', '2.0.0' );
 *     … the operation, maybe with an abort (AB_MCP_IT_Process::abort()) …
 *     AB_MCP_IT_Code::clear();
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-it-sites.php';
require_once __DIR__ . '/class-it-process.php';

/**
 * Class AB_MCP_IT_Code
 */
final class AB_MCP_IT_Code {

	/** Prefix of every test unit; clear() removes only names with it. */
	const PREFIX = 'ab-it-';

	/** The time stamp of every copied file (2026-01-01 00:00 UTC). */
	const MTIME = 1767225600;

	/** The folders of wp-content a type lives in. */
	const FOLDERS = array(
		'plugin'    => 'plugins',
		'theme'     => 'themes',
		'mu-plugin' => 'mu-plugins',
	);

	/** @var array|null The list, once read. */
	private static $katalog = null;

	/** @var string|null The folder of the ZIPs of the test process (removed at its end). */
	private static $own_dir = null;

	/**
	 * The folder of the fixtures.
	 *
	 * @return string
	 */
	public static function fixtures() {
		return dirname( __DIR__ ) . '/fixtures/code';
	}

	/**
	 * The list of units (fixtures/code/katalog.php).
	 *
	 * @return array
	 */
	public static function katalog() {
		if ( null === self::$katalog ) {
			self::$katalog = require self::fixtures() . '/katalog.php';
		}
		return self::$katalog;
	}

	/**
	 * The entry of a unit.
	 *
	 * @param string $type plugin or theme.
	 * @param string $slug Slug.
	 * @return array
	 */
	public static function unit( $type, $slug ) {
		$list = 'plugin' === $type ? 'plugins' : ( 'theme' === $type ? 'themes' : '' );
		if ( '' === $list || ! isset( self::katalog()[ $list ][ $slug ] ) ) {
			throw new InvalidArgumentException( "No test $type $slug in katalog.php." );
		}
		return self::katalog()[ $list ][ $slug ];
	}

	/**
	 * The folder of a version in the fixtures.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $slug    Slug.
	 * @param string $fassung Version as katalog.php names it.
	 * @return string
	 */
	public static function source( $type, $slug, $fassung ) {
		$unit = self::unit( $type, $slug );
		if ( ! isset( $unit['fassungen'][ $fassung ] ) ) {
			throw new InvalidArgumentException( "The test $type $slug has no version $fassung; it has " . implode( ', ', array_keys( $unit['fassungen'] ) ) . '.' );
		}
		return self::fixtures() . '/' . self::FOLDERS[ $type ] . '/' . $slug . '/' . $fassung;
	}

	/**
	 * Where a unit lives on a site: its folder, or its file for a plugin of
	 * one file or a must-use plugin.
	 *
	 * @param string $site Site.
	 * @param string $type plugin, theme or mu-plugin.
	 * @param string $slug Slug (for a must-use plugin: its file name).
	 * @return string
	 */
	public static function path( $site, $type, $slug ) {
		if ( ! isset( self::FOLDERS[ $type ] ) || 0 !== strpos( $slug, self::PREFIX ) || ! preg_match( '/^[a-z0-9.-]+$/', $slug ) ) {
			throw new InvalidArgumentException( "No test unit $type $slug: its name begins with " . self::PREFIX . '.' );
		}
		$base = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/' . self::FOLDERS[ $type ] . '/';
		if ( 'plugin' === $type && ! empty( self::unit( 'plugin', $slug )['single'] ) ) {
			return $base . $slug . '.php';
		}
		return $base . $slug;
	}

	/**
	 * Put a version of a unit on a site, in place of what is there.
	 *
	 * @param string $site    Site.
	 * @param string $type    plugin or theme.
	 * @param string $slug    Slug.
	 * @param string $fassung Version.
	 * @return string The path on the site.
	 */
	public static function put( $site, $type, $slug, $fassung ) {
		$source = self::source( $type, $slug, $fassung );
		$target = self::path( $site, $type, $slug );
		self::remove_path( $target );
		if ( 'plugin' === $type && ! empty( self::unit( 'plugin', $slug )['single'] ) ) {
			self::copy_file( $source . '/' . $slug . '.php', $target );
		} else {
			self::copy_tree( $source, $target );
		}
		return $target;
	}

	/**
	 * Take a unit off a site (its folder or file; nothing else).
	 *
	 * @param string $site Site.
	 * @param string $type plugin, theme or mu-plugin.
	 * @param string $slug Slug.
	 */
	public static function remove( $site, $type, $slug ) {
		self::remove_path( self::path( $site, $type, $slug ) );
	}

	/**
	 * Which version of a unit a site has, by its bytes; null when none of
	 * them, '' when the unit is not there.
	 *
	 * @param string $site Site.
	 * @param string $type plugin or theme.
	 * @param string $slug Slug.
	 * @return string|null
	 */
	public static function fassung( $site, $type, $slug ) {
		$path = self::path( $site, $type, $slug );
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$single = 'plugin' === $type && ! empty( self::unit( 'plugin', $slug )['single'] );
		$have   = $single ? array( basename( $path ) => hash_file( 'sha256', $path ) ) : self::digest( $path );
		foreach ( array_keys( self::unit( $type, $slug )['fassungen'] ) as $fassung ) {
			$source = self::source( $type, $slug, $fassung );
			$want   = $single ? array( $slug . '.php' => hash_file( 'sha256', $source . '/' . $slug . '.php' ) ) : self::digest( $source );
			if ( $want === $have ) {
				return (string) $fassung;
			}
		}
		return null;
	}

	/**
	 * Both sites (or the ones named) as at the start: every test unit of
	 * the site removed, the units of the list in their start version, the
	 * must-use plugins, then on the site in a process of its own each start
	 * plugin activated as WordPress does (activate_plugin(), with its
	 * activation hook), a menu «AB IT Haupt» put in the location haupt of the
	 * theme before, and the theme of the list switched to (switch_theme()).
	 * Call it after AB_MCP_IT_Sites::reset() of the same sites.
	 *
	 * @param string[] $sites Sites.
	 * @return array<string,array> What the activations returned, by site.
	 */
	public static function start( array $sites = array( 'a', 'b' ) ) {
		$katalog = self::katalog();
		$out     = array();
		foreach ( $sites as $site ) {
			self::clear( array( $site ) );
			// A new site has no folder for must-use plugins; clear() removes
			// it again when nothing else is in it.
			$mu = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/mu-plugins';
			if ( ! is_dir( $mu ) && ! mkdir( $mu, 0755 ) ) {
				throw new RuntimeException( "Cannot create $mu." );
			}
			foreach ( $katalog['mu-plugins'] as $file ) {
				self::copy_file( self::fixtures() . '/mu-plugins/' . $file, self::path( $site, 'mu-plugin', $file ) );
			}
			$calls = array();
			foreach ( $katalog['plugins'] as $slug => $unit ) {
				self::put( $site, 'plugin', $slug, $unit['start']['fassung'] );
				foreach ( $unit['start']['aktiv'] as $main ) {
					$calls[] = array(
						'call'   => 'activate_plugin',
						'args'   => array( $main ),
						'as'     => $main,
						'expect' => 'result',
					);
				}
			}
			foreach ( $katalog['themes'] as $slug => $unit ) {
				self::put( $site, 'theme', $slug, $unit['start'] );
			}
			// A menu in a location of the theme before, so that the switch
			// leaves a value in theme_switch_menu_locations (F26 compares it).
			$calls[]      = array(
				'call' => 'wp_create_nav_menu',
				'args' => array( 'AB IT Haupt' ),
				'as'   => 'menu',
			);
			$calls[]      = array(
				'call' => 'set_theme_mod',
				'args' => array( 'nav_menu_locations', array( 'haupt' => '$menu' ) ),
				'as'   => 'locations',
			);
			$calls[]      = array(
				'call' => 'switch_theme',
				'args' => array( $katalog['theme'] ),
				'as'   => 'theme',
			);
			$calls[]      = array(
				'call' => 'get_stylesheet',
				'as'   => 'stylesheet',
			);
			$done         = AB_MCP_IT_Process::calls( $site, array( 'calls' => $calls ) );
			$out[ $site ] = $done['results'];
			if ( $katalog['theme'] !== $done['results']['stylesheet'] ) {
				throw new RuntimeException( "Site $site did not switch to the test theme {$katalog['theme']}: " . json_encode( $done['results'] ) );
			}
			if ( ! is_int( $done['results']['menu'] ) ) {
				throw new RuntimeException( "Site $site: no menu for the start: " . json_encode( $done['results']['menu'] ) );
			}
			foreach ( $done['results'] as $name => $result ) {
				if ( ! in_array( $name, array( 'theme', 'stylesheet', 'menu', 'locations' ), true ) && null !== $result ) {
					throw new RuntimeException( "Site $site: activating $name gave " . json_encode( $result ) . '.' );
				}
			}
		}
		return $out;
	}

	/**
	 * Remove every test unit (a name beginning with PREFIX) from the
	 * plugins, themes and must-use plugins of the sites: files only, the
	 * tables are AB_MCP_IT_Sites::reset()'s. Nothing else is touched.
	 *
	 * @param string[] $sites Sites.
	 */
	public static function clear( array $sites = array( 'a', 'b' ) ) {
		foreach ( $sites as $site ) {
			foreach ( self::FOLDERS as $folder ) {
				$dir = AB_MCP_IT_Sites::docroot( $site ) . '/wp-content/' . $folder;
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( (array) scandir( $dir ) as $name ) {
					if ( 0 === strpos( (string) $name, self::PREFIX ) ) {
						self::remove_path( $dir . '/' . $name );
					}
				}
				if ( 'mu-plugins' === $folder && array( '.', '..' ) === array_values( (array) scandir( $dir ) ) ) {
					rmdir( $dir );
				}
			}
		}
	}

	/**
	 * The counters of a plugin's routines on a site (option
	 * ab_it_zaehler_<slug>, read past every cache).
	 *
	 * @param string $site Site.
	 * @param string $slug Slug (for ab-it-zwei: ab-it-zwei-eins or ab-it-zwei-zwei).
	 * @return array{aktiviert:int,deaktiviert:int,deinstalliert:int}
	 */
	public static function counters( $site, $slug ) {
		$row  = AB_MCP_IT_Sites::option_row( $site, 'ab_it_zaehler_' . $slug );
		$seen = null === $row ? array() : unserialize( (string) $row['option_value'], array( 'allowed_classes' => false ) );
		$seen = is_array( $seen ) ? $seen : array();
		return array(
			'aktiviert'     => isset( $seen['aktiviert'] ) ? (int) $seen['aktiviert'] : 0,
			'deaktiviert'   => isset( $seen['deaktiviert'] ) ? (int) $seen['deaktiviert'] : 0,
			'deinstalliert' => isset( $seen['deinstalliert'] ) ? (int) $seen['deinstalliert'] : 0,
		);
	}

	/**
	 * What the must-use observer saw of switch_theme on a site.
	 *
	 * @param string $site Site.
	 * @return array{switch_theme:int,letzter:array|null}
	 */
	public static function observed( $site ) {
		$row  = AB_MCP_IT_Sites::option_row( $site, 'ab_it_beobachter' );
		$seen = null === $row ? array() : unserialize( (string) $row['option_value'], array( 'allowed_classes' => false ) );
		$seen = is_array( $seen ) ? $seen : array();
		return array(
			'switch_theme' => isset( $seen['switch_theme'] ) ? (int) $seen['switch_theme'] : 0,
			'letzter'      => isset( $seen['letzter'] ) ? $seen['letzter'] : null,
		);
	}

	/**
	 * The rows of the four options a switch of the theme writes (F26):
	 * template, stylesheet, current_theme, theme_switch_menu_locations, as
	 * stored (null where there is none).
	 *
	 * @param string $site Site.
	 * @return array<string,string|null>
	 */
	public static function theme_options( $site ) {
		$out = array();
		foreach ( array( 'template', 'stylesheet', 'current_theme', 'theme_switch_menu_locations' ) as $name ) {
			$row          = AB_MCP_IT_Sites::option_row( $site, $name );
			$out[ $name ] = null === $row ? null : (string) $row['option_value'];
		}
		return $out;
	}

	/**
	 * The mark of a version in the answer of a site (header
	 * X-AB-IT-Fassung-<slug>), or null.
	 *
	 * @param string $site Site.
	 * @param string $slug Slug of the plugin or theme.
	 * @param string $path Path of the request.
	 * @return string|null
	 */
	public static function mark( $site, $slug, $path = '/' ) {
		$response = wp_remote_get(
			AB_MCP_IT_Sites::url( $site, $path ),
			array(
				'timeout'     => 30,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( "Site $site did not answer $path: " . $response->get_error_message() );
		}
		$mark = wp_remote_retrieve_header( $response, 'x-ab-it-fassung-' . $slug );
		return '' === $mark ? null : (string) $mark;
	}

	/**
	 * A ZIP of a version, as wordpress.org packs a plugin or theme: one
	 * folder named after the slug. Built once per process in zip_dir(),
	 * every entry with MTIME.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $slug    Slug.
	 * @param string $fassung Version.
	 * @return string Path of the ZIP.
	 */
	public static function zip( $type, $slug, $fassung ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'The packages of the test plugins need the zip extension.' );
		}
		if ( 'plugin' === $type && ! empty( self::unit( 'plugin', $slug )['single'] ) ) {
			throw new InvalidArgumentException( "$slug is a plugin of one file; wordpress.org packs folders." );
		}
		$source = self::source( $type, $slug, $fassung );
		$zip    = self::zip_dir() . '/ab-it-code-' . $type . '-' . $slug . '-' . $fassung . '.zip';
		if ( is_file( $zip ) ) {
			return $zip;
		}
		$tmp     = $zip . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$archive = new ZipArchive();
		if ( true !== $archive->open( $tmp, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
			throw new RuntimeException( "Cannot write $tmp." );
		}
		// The folder with MTIME as well (under its name as ZipArchive keeps it,
		// with a slash), so that a ZIP built again is the same byte for byte
		// (Z8b branch 2, Behebung 8: two builds in other seconds differed).
		if ( ! $archive->addEmptyDir( $slug ) || ! $archive->setMtimeName( $slug . '/', self::MTIME ) ) {
			throw new RuntimeException( "Cannot write the folder $slug into $tmp." );
		}
		foreach ( self::files( $source ) as $relative ) {
			$name = $slug . '/' . $relative;
			$archive->addFromString( $name, (string) file_get_contents( $source . '/' . $relative ) );
			$archive->setMtimeName( $name, self::MTIME );
		}
		if ( ! $archive->close() || ! rename( $tmp, $zip ) ) {
			throw new RuntimeException( "Cannot write $zip." );
		}
		return $zip;
	}

	/**
	 * Where this process puts its ZIPs (Codex round 1, B7): in a child
	 * process of AB_MCP_IT_Process the folder of its run (AB_IT_TMP), which
	 * the parent removes after the child, however it ended; in the test
	 * process a folder of its own, removed at its end.
	 *
	 * @return string
	 */
	public static function zip_dir() {
		$run = (string) getenv( 'AB_IT_TMP' );
		if ( '' !== $run && is_dir( $run ) ) {
			return $run;
		}
		if ( null === self::$own_dir ) {
			self::$own_dir = sys_get_temp_dir() . '/ab-it-code-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
			if ( ! mkdir( self::$own_dir, 0700 ) ) {
				throw new RuntimeException( 'Cannot create ' . self::$own_dir . '.' );
			}
			register_shutdown_function(
				static function () {
					self::remove_path( self::$own_dir );
				}
			);
		}
		return self::$own_dir;
	}

	/**
	 * The files below a folder, relative, sorted.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	public static function files( $dir ) {
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$out[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * The hash of every file below a folder.
	 *
	 * @param string $dir Folder.
	 * @return array<string,string>
	 */
	private static function digest( $dir ) {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( self::files( $dir ) as $relative ) {
			$out[ $relative ] = hash_file( 'sha256', $dir . '/' . $relative );
		}
		return $out;
	}

	/**
	 * Copy a folder, as plain files and folders (no link), every file with
	 * MTIME.
	 *
	 * @param string $from Folder.
	 * @param string $to   New folder.
	 */
	private static function copy_tree( $from, $to ) {
		if ( ! mkdir( $to, 0755, true ) && ! is_dir( $to ) ) {
			throw new RuntimeException( "Cannot create $to." );
		}
		foreach ( self::files( $from ) as $relative ) {
			$dir = dirname( $to . '/' . $relative );
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
				throw new RuntimeException( "Cannot create $dir." );
			}
			self::copy_file( $from . '/' . $relative, $to . '/' . $relative );
		}
	}

	/**
	 * Copy one file with MTIME.
	 *
	 * @param string $from File.
	 * @param string $to   New file.
	 */
	private static function copy_file( $from, $to ) {
		if ( ! copy( $from, $to ) || ! touch( $to, self::MTIME ) ) {
			throw new RuntimeException( "Cannot copy $from to $to." );
		}
		clearstatcache( true, $to );
	}

	/**
	 * Remove a file, a link or a folder with what is in it; a link is
	 * removed, never followed.
	 *
	 * @param string $path Path.
	 */
	private static function remove_path( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! unlink( $path ) ) {
				throw new RuntimeException( "Cannot remove $path." );
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::remove_path( $path . '/' . $name );
			}
		}
		if ( ! rmdir( $path ) ) {
			throw new RuntimeException( "Cannot remove $path." );
		}
	}
}
