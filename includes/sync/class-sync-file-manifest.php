<?php
/**
 * The manifest of a unit of code or files (Z8b, decision E1 of the plan of
 * 11.11.2026; branch 2): which files a unit holds, each by its relative
 * path, its size and its SHA-256, and the hash of the unit over all of them.
 *
 * UNITS (E1): a folder of a plugin, a plugin of one file, a folder of a theme
 * (the standard root only), a file or a folder at the top of the must-use
 * folder, a language file, a file of the fonts, a file of the uploads
 * outside the file lists of the attachments. AB_MCP_Sync_Env finds them and
 * reads them one by one, each whole within one call, over the parts of the
 * series of the environment; this class reads one.
 *
 * FORMAT ab-files/1: each file of the unit with its path relative to the
 * folder of the unit (a unit of one file: its own name), its size and its
 * SHA-256, sorted by strcmp() of the path, encoded with
 * AB_MCP_Sync_Canon::encode() together with the name of the format; the
 * hash of the unit is the SHA-256 of that text. The time a file was changed
 * (mtime) stands beside it and never counts for equality (Z1).
 *
 * THE ONE PLACE (plan 3.1, Z1 and Z2): unit() is the only function that
 * gives the hash of a unit. It gives one only where it listed and hashed
 * every file of the unit in this very call and found nothing a manifest
 * cannot hold: a folder .git, .svn or .hg anywhere in it, a symbolic link
 * (the unit itself included) or anything that is no regular file locks the
 * unit (code:live_extra on the production site, code:link on the staging
 * site), and it has no hash. There is no list of names left out within a
 * unit: what a plugin writes into its own folder is part of it. A folder
 * or file that cannot be read, a file that changes while it is read (its
 * size, time or identity differ before and after, or fewer bytes came than
 * it has), a unit with more files than MAX_FILES or one that does not fit
 * into the time of a whole call leave it without a hash, with why: not
 * compared (fail closed). Nothing is kept between two readings: every
 * reading hashes every byte anew.
 *
 * THE CALLS TO THE OUTSIDE (plan 8.3, branch 2: reading and hashing files
 * and folders) go through AB_MCP_Sync_File_Manifest_Calls, held by the seam
 * of this class; only the tests put another object there (Z8b branch 1,
 * the seam helper of the integration suite). No filter and no option
 * reaches this class.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-canon.php';

/**
 * The calls of the manifest to the file system: the real ones.
 */
final class AB_MCP_Sync_File_Manifest_Calls {

	/**
	 * The names in a folder.
	 *
	 * @param string $dir Folder.
	 * @return string[]|false
	 */
	public function names( $dir ) {
		$names = @scandir( (string) $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder that cannot be read leaves its unit without a hash.
		return is_array( $names ) ? $names : false;
	}

	/**
	 * What lstat() says of a path (a link is not followed).
	 *
	 * @param string $path Path.
	 * @return array|false
	 */
	public function lstat( $path ) {
		clearstatcache( true, (string) $path );
		$stat = @lstat( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone or not readable: no hash.
		return is_array( $stat ) ? $stat : false;
	}

	/**
	 * Open a file to read it.
	 *
	 * @param string $path File.
	 * @return resource|false
	 */
	public function open( $path ) {
		return @fopen( (string) $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read in parts to hash it.
	}

	/**
	 * What fstat() says of an open file.
	 *
	 * @param resource $handle File.
	 * @return array|false
	 */
	public function fstat( $handle ) {
		$stat = @fstat( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $stat ) ? $stat : false;
	}

	/**
	 * The next bytes of an open file.
	 *
	 * @param resource $handle File.
	 * @param int      $length Bytes at most.
	 * @return string|false '' at its end.
	 */
	public function read( $handle, $length ) {
		if ( feof( $handle ) ) {
			return '';
		}
		return @fread( $handle, (int) $length ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read in parts to hash it.
	}

	/**
	 * Files of one unit at most (the memory of one call holds them).
	 *
	 * @return int
	 */
	public function limit() {
		return AB_MCP_Sync_File_Manifest::MAX_FILES;
	}

	/**
	 * Close a file.
	 *
	 * @param resource $handle File.
	 */
	public function close( $handle ) {
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}

/**
 * Class AB_MCP_Sync_File_Manifest
 */
final class AB_MCP_Sync_File_Manifest {

	/** The format of a manifest. */
	const FORMAT = 'ab-files/1';

	/** Folders of version control: anywhere in a unit, they lock it. */
	const VCS = array( '.git', '.svn', '.hg' );

	/** Bytes read at a time while a file is hashed. */
	const CHUNK = 1048576;

	/** Files of one unit at most; a unit with more has no hash (too_many). */
	const MAX_FILES = 100000;

	/** Why a unit has no hash. */
	const WHY = array( 'unreadable', 'changed', 'too_large', 'too_many', 'link', 'vcs', 'nonfile' );

	/** What locks a unit, by the side that read it (E1). */
	const LOCK_BY_SIDE = array(
		'live'    => 'code:live_extra',
		'staging' => 'code:link',
	);

	/** The calls to the outside (the seam); only the tests put another object here. */
	private static $naht_sync_file_manifest = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Sync_File_Manifest_Calls
	 */
	private static function calls() {
		return self::$naht_sync_file_manifest ?? new AB_MCP_Sync_File_Manifest_Calls();
	}

	/**
	 * Back to the real calls.
	 */
	public static function forget() {
		self::$naht_sync_file_manifest = null;
	}

	/**
	 * Read one unit whole: list and hash every file of it, as ab-files/1.
	 * The one place that gives the hash of a unit (Z1, Z2).
	 *
	 * @param string $base     The folder the unit lies in (a root).
	 * @param string $name     The unit, relative to $base.
	 * @param string $side     live (this is the production site) or staging.
	 * @param float  $deadline Until when (seconds, the clock of AB_MCP_Sync_Env).
	 * @param bool   $first    The unit had a whole call to itself: where it
	 *                         does not end in time it is too_large, else it
	 *                         stops and a later call reads it anew.
	 * @return array{form:string,complete:bool,stopped:bool,cause:string|null,lock:string|null,hash:string|null,files:int,bytes:int,entries:array[]}
	 *               entries: [path, size, sha256, mtime] by strcmp() of the path.
	 */
	public static function unit( $base, $name, $side, $deadline, $first ) {
		$acc = array(
			'form'     => 'other',
			'complete' => false,
			'stopped'  => false,
			'cause'    => null,
			'lock'     => null,
			'hash'     => null,
			'files'    => 0,
			'bytes'    => 0,
			'entries'  => array(),
		);
		$path = rtrim( (string) $base, '/' ) . '/' . (string) $name;
		try {
			$stat = self::calls()->lstat( $path );
			if ( false === $stat ) {
				return self::ended( $acc, 'unreadable' );
			}
			$type = (int) $stat['mode'] & 0170000;
			if ( 0040000 === $type ) {
				$acc['form'] = 'folder';
				$went        = self::folder( $path, '', $side, (float) $deadline, (bool) $first, $acc );
			} elseif ( 0100000 === $type ) {
				$acc['form'] = 'file';
				$went        = self::file( $path, basename( (string) $name ), $stat, (float) $deadline, (bool) $first, $acc );
			} else {
				$went = self::locked( $acc, $side, 0120000 === $type ? 'link' : 'nonfile' );
			}
		} catch ( Throwable $e ) {
			// A call to the file system that throws: nothing of the unit is
			// known for sure (fail closed).
			return self::ended( $acc, 'unreadable' );
		}
		if ( 'done' !== $went ) {
			return $acc;
		}
		usort(
			$acc['entries'],
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);
		$text = self::text( $acc['entries'] );
		if ( '' === $text ) {
			return self::ended( $acc, 'unreadable' );
		}
		$acc['complete'] = true;
		$acc['hash']     = hash( 'sha256', $text );
		return $acc;
	}

	/**
	 * The text of a manifest: the format and each file with its path, size
	 * and SHA-256 (no mtime), by strcmp() of the path, in the one spelling of
	 * AB_MCP_Sync_Canon::encode().
	 *
	 * @param array[] $entries [path, size, sha256, mtime], sorted.
	 * @return string '' where it cannot be written.
	 */
	public static function text( array $entries ) {
		$files = array();
		foreach ( $entries as $entry ) {
			$files[] = array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );
		}
		return AB_MCP_Sync_Canon::encode(
			array(
				'files'  => $files,
				'format' => self::FORMAT,
			)
		);
	}

	/**
	 * The paths in which two manifests differ: only on one side, or on both
	 * with another size or SHA-256 (never by mtime). Each list by strcmp().
	 *
	 * @param array[] $staging Entries of the staging side.
	 * @param array[] $live    Entries of this side.
	 * @return array{only_staging:string[],only_live:string[],differs:string[]}
	 */
	public static function paths( array $staging, array $live ) {
		$s = array();
		foreach ( $staging as $entry ) {
			$s[ (string) $entry[0] ] = array( (int) $entry[1], (string) $entry[2] );
		}
		$l = array();
		foreach ( $live as $entry ) {
			$l[ (string) $entry[0] ] = array( (int) $entry[1], (string) $entry[2] );
		}
		$out = array(
			'only_staging' => array(),
			'only_live'    => array(),
			'differs'      => array(),
		);
		foreach ( $s as $path => $one ) {
			if ( ! isset( $l[ $path ] ) ) {
				$out['only_staging'][] = (string) $path;
			} elseif ( $l[ $path ] !== $one ) {
				$out['differs'][] = (string) $path;
			}
		}
		foreach ( array_keys( $l ) as $path ) {
			if ( ! isset( $s[ $path ] ) ) {
				$out['only_live'][] = (string) $path;
			}
		}
		foreach ( $out as $key => $list ) {
			usort( $list, 'strcmp' );
			$out[ $key ] = $list;
		}
		return $out;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * One folder of a unit, depth first; its files into $acc.
	 *
	 * @param string $dir      The folder.
	 * @param string $rel      Its path in the unit ('' the unit).
	 * @param string $side     live or staging.
	 * @param float  $deadline Until when.
	 * @param bool   $first    See unit().
	 * @param array  $acc      The reading so far.
	 * @return string done, or ended ($acc says why).
	 */
	private static function folder( $dir, $rel, $side, $deadline, $first, array &$acc ) {
		$names = self::calls()->names( $dir );
		if ( false === $names ) {
			return self::end( $acc, 'unreadable' );
		}
		$names = array_values( array_diff( array_map( 'strval', $names ), array( '.', '..' ) ) );
		usort( $names, 'strcmp' );
		foreach ( $names as $name ) {
			$path = $dir . '/' . $name;
			$in   = '' === $rel ? $name : $rel . '/' . $name;
			$stat = self::calls()->lstat( $path );
			if ( false === $stat ) {
				// Gone since the folder was listed, or not readable.
				return self::end( $acc, 'unreadable' );
			}
			$type = (int) $stat['mode'] & 0170000;
			if ( 0120000 === $type ) {
				return self::locked( $acc, $side, 'link' );
			}
			if ( 0040000 === $type ) {
				if ( in_array( $name, self::VCS, true ) ) {
					return self::locked( $acc, $side, 'vcs' );
				}
				$went = self::folder( $path, $in, $side, $deadline, $first, $acc );
				if ( 'done' !== $went ) {
					return $went;
				}
				continue;
			}
			if ( 0100000 !== $type ) {
				return self::locked( $acc, $side, 'nonfile' );
			}
			$went = self::file( $path, $in, $stat, $deadline, $first, $acc );
			if ( 'done' !== $went ) {
				return $went;
			}
		}
		return 'done';
	}

	/**
	 * One regular file of a unit: hashed in parts from one open handle, its
	 * size and time from that handle before and after, the same file as
	 * lstat() saw (device and inode).
	 *
	 * @param string $path     The file.
	 * @param string $rel      Its path in the unit.
	 * @param array  $stat     What lstat() said of it.
	 * @param float  $deadline Until when.
	 * @param bool   $first    See unit().
	 * @param array  $acc      The reading so far.
	 * @return string done, or ended.
	 */
	private static function file( $path, $rel, array $stat, $deadline, $first, array &$acc ) {
		$calls = self::calls();
		if ( $acc['files'] >= (int) $calls->limit() ) {
			return self::end( $acc, 'too_many' );
		}
		$handle = $calls->open( $path );
		if ( false === $handle || null === $handle ) {
			return self::end( $acc, 'unreadable' );
		}
		$before = $calls->fstat( $handle );
		if ( false === $before || (int) $before['dev'] !== (int) $stat['dev'] || (int) $before['ino'] !== (int) $stat['ino'] ) {
			$calls->close( $handle );
			return self::end( $acc, false === $before ? 'unreadable' : 'changed' );
		}
		$ctx  = hash_init( 'sha256' );
		$read = 0;
		while ( true ) {
			$bytes = $calls->read( $handle, self::CHUNK );
			if ( false === $bytes ) {
				$calls->close( $handle );
				return self::end( $acc, 'unreadable' );
			}
			if ( '' === $bytes ) {
				break;
			}
			hash_update( $ctx, $bytes );
			$read += strlen( $bytes );
			if ( self::now() >= $deadline ) {
				$calls->close( $handle );
				return self::late( $acc, $first );
			}
		}
		$after = $calls->fstat( $handle );
		$calls->close( $handle );
		if ( false === $after ) {
			return self::end( $acc, 'unreadable' );
		}
		// The same bytes from its start to its end, while nothing wrote it.
		if ( $read !== (int) $before['size'] || (int) $after['size'] !== (int) $before['size'] || (int) $after['mtime'] !== (int) $before['mtime'] ) {
			return self::end( $acc, 'changed' );
		}
		$acc['entries'][] = array( (string) $rel, $read, hash_final( $ctx ), (int) $before['mtime'] );
		++$acc['files'];
		$acc['bytes'] += $read;
		return 'done';
	}

	/**
	 * A unit that holds what no manifest holds: locked, no hash.
	 *
	 * @param array  $acc  The reading so far.
	 * @param string $side live or staging.
	 * @param string $why  link, vcs or nonfile.
	 * @return string ended.
	 */
	private static function locked( array &$acc, $side, $why ) {
		$acc['lock'] = self::LOCK_BY_SIDE[ 'live' === $side ? 'live' : 'staging' ];
		$acc['cause'] = (string) $why;
		$acc['complete'] = true;
		$acc['hash']     = null;
		$acc['entries']  = array();
		return 'ended';
	}

	/**
	 * The time of the call is over within the unit: too large where it had a
	 * whole call, else it stops for a later call.
	 *
	 * @param array $acc   The reading so far.
	 * @param bool  $first See unit().
	 * @return string ended.
	 */
	private static function late( array &$acc, $first ) {
		if ( $first ) {
			return self::end( $acc, 'too_large' );
		}
		$acc['stopped'] = true;
		$acc['entries'] = array();
		return 'ended';
	}

	/**
	 * A unit without a hash, with why.
	 *
	 * @param array  $acc The reading so far.
	 * @param string $why A code of WHY.
	 * @return string ended.
	 */
	private static function end( array &$acc, $why ) {
		$acc = self::ended( $acc, $why );
		return 'ended';
	}

	/**
	 * @param array  $acc The reading so far.
	 * @param string $why A code of WHY.
	 * @return array It without a hash and entries, not complete.
	 */
	private static function ended( array $acc, $why ) {
		$acc['complete'] = false;
		$acc['cause']    = (string) $why;
		$acc['hash']     = null;
		$acc['entries']  = array();
		return $acc;
	}

	/**
	 * @return float Seconds (the clock of AB_MCP_Sync_Env, which tests set).
	 */
	private static function now() {
		return class_exists( 'AB_MCP_Sync_Env', false ) && null !== AB_MCP_Sync_Env::$clock ? (float) call_user_func( AB_MCP_Sync_Env::$clock ) : microtime( true );
	}
}
