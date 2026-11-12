<?php
/**
 * The budgets of the protected store (Z8, decision 8 of the plan of
 * 01.11.2026), counted under the store's lock.
 *
 * - Packages: at most MAX_PACKAGES open packages (out and in) on the site,
 *   at most MAX_BYTES declared by them together, and at most MAX_EXPORTS
 *   open exports (out) per account. A package is opened with the bytes it
 *   will hold (declared) and never grows past them
 *   (AB_MCP_Protected_Store::put()), so the declared bytes bound what the
 *   packages hold. A package counts until it is removed: a day after it was
 *   made, or later while a run that is not closed holds it. Two rules count
 *   fewer (branch 5): the packages of one series, the parts of one
 *   inventory a cursor leads from one to the next (label SERIES and the
 *   same id), count as one open package and one open export; and a package
 *   that is closed (AB_MCP_Protected_Store::close(), an import checked
 *   whole) counts as open no more. Their bytes count all the same.
 * - Runs: a run books what it needs before its first write (backups,
 *   incoming packages, new media files, temporary files: book()); the
 *   bookings of all runs that are not closed, with this one, must leave
 *   RESERVE free on the disk of the store (disk_free_space()), and a test
 *   write of TEST_WRITE bytes must succeed. release() gives the booking back.
 * - Unpacking: the limits of decision 8 (UNPACK) with a check
 *   (unpack_refusal()); the unpacking itself comes with the packages.
 *
 * Counting and creating happen under one lock, the pattern of Pro's upload
 * sessions (flock() on a file of the folder, waiting for the holder): two
 * requests at the same time cannot both pass the same count. The limits are
 * read after the count, as Pro reads its own. Not AB_MCP_Change_Lock: that
 * lock keeps changes of the code the site runs and the write boundary of a
 * run apart and answers "busy" at once; a booking is a few file operations
 * that must wait for each other, not refuse each other, and it must not
 * stop an activation or a deploy.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Store_Budget
 */
final class AB_MCP_Store_Budget {

	/** Open packages (out and in) on the site at most. */
	const MAX_PACKAGES = 5;

	/** Bytes the open packages declare together at most (512 MiB). */
	const MAX_BYTES = 536870912;

	/** Open exports (out) per account at most. */
	const MAX_EXPORTS = 3;

	/** The label of a package of a series: this and the id of the series (32 hex). */
	const SERIES = '/^series:[0-9a-f]{32}\z/';

	/** The filters of the three limits, read after the count. */
	const FILTER_PACKAGES = 'ab_mcp_store_max_packages';
	const FILTER_BYTES    = 'ab_mcp_store_max_bytes';
	const FILTER_EXPORTS  = 'ab_mcp_store_max_exports';

	/** Bytes of the test write before a run starts (1 MiB). */
	const TEST_WRITE = 1048576;

	/** Bytes the bookings leave free on the disk of the store (32 MiB). */
	const RESERVE = 33554432;

	/** The file of the bookings, in the folder of the store. */
	const BOOKINGS = 'bookings.json';

	/** Bytes of the file of the bookings at most (one entry is under 100 bytes). */
	const BOOKINGS_MAX = 1048576;

	/**
	 * The limits of unpacking a package (decision 8): the raw size of one
	 * object, the depth of a value, the number of objects, the unpacked size
	 * of the whole package.
	 */
	const UNPACK = array(
		'object_bytes'  => 8388608,
		'depth'         => 64,
		'objects'       => 5000,
		'package_bytes' => 268435456,
	);

	/** What each limit of UNPACK is, for the refusal. */
	const UNPACK_WHAT = array(
		'object_bytes'  => 'the raw size of one object',
		'depth'         => 'the depth of a value',
		'objects'       => 'the number of objects',
		'package_bytes' => 'the unpacked size of the package',
	);

	/**
	 * Whether a package may be opened, given the packages that are open: null
	 * when it may, the refusal otherwise. Called by
	 * AB_MCP_Protected_Store::open() under the store's lock, between the count
	 * and the creation.
	 *
	 * @param array[] $packages Entries of the packages (out and in).
	 * @param string  $area     'out' or 'in'.
	 * @param int     $user_id  Account that opens it.
	 * @param int     $declared Bytes it will hold.
	 * @param string  $label    Its label: SERIES joins the open package of
	 *                          that series, which counts already.
	 * @param string  $binding  The trust it is begun with (an import: the
	 *                          print of the pair, AB_MCP_Sync_Anchor::
	 *                          pair_print()), '' for none. An open package
	 *                          of another binding counts as open no more: it
	 *                          is never continued, and the store removes it
	 *                          after its time (its bytes count until then).
	 *                          A binding read from an old cache moves the
	 *                          count for a while, and removes nothing.
	 * @return WP_Error|null
	 */
	public static function package_refusal( array $packages, $area, $user_id, $declared, $label = '', $binding = '' ) {
		$declared = (int) $declared;
		if ( $declared < 1 ) {
			return new WP_Error( 'ab_mcp_store_declare', 'A package is opened with the number of bytes it will hold, and it never grows past them. Pass the size as declared when you open it.' );
		}
		// Each open package counts once, the packages of a series together.
		$open    = array();
		$exports = array();
		$bytes   = 0;
		foreach ( array_values( $packages ) as $i => $entry ) {
			$bytes += max( 0, (int) $entry['declared'] );
			if ( ! empty( $entry['closed'] ) ) {
				continue;
			}
			$other = isset( $entry['binding'] ) ? (string) $entry['binding'] : '';
			if ( '' !== (string) $binding && '' !== $other && (string) $binding !== $other ) {
				continue;
			}
			$key          = isset( $entry['label'] ) && 1 === preg_match( self::SERIES, (string) $entry['label'] ) ? (string) $entry['label'] : $i;
			$open[ $key ] = true;
			if ( 'out' === $entry['area'] && (int) $entry['user_id'] === (int) $user_id ) {
				$exports[ $key ] = true;
			}
		}
		$joins   = 1 === preg_match( self::SERIES, (string) $label );
		$exports = $joins && isset( $exports[ $label ] ) ? 0 : count( $exports );
		$open    = $joins && isset( $open[ $label ] ) ? 0 : count( $open );
		// The limits after the count, as Pro reads its own.
		if ( 'out' === $area ) {
			$max_exports = max( 0, (int) apply_filters( self::FILTER_EXPORTS, self::MAX_EXPORTS ) );
			if ( $exports >= $max_exports ) {
				return new WP_Error(
					'ab_mcp_store_quota',
					sprintf( 'This account has %1$d open exports, the most one account keeps at a time (%2$d). An export counts until it is removed, a day after it was made, or later while a run that is not closed uses it. Wait until one is removed, or use one of them; an administrator can raise the number with the filter ab_mcp_store_max_exports.', $exports, $max_exports ),
					array(
						'open'  => $exports,
						'limit' => $max_exports,
					)
				);
			}
		}
		$max_packages = max( 0, (int) apply_filters( self::FILTER_PACKAGES, self::MAX_PACKAGES ) );
		if ( $open >= $max_packages ) {
			return new WP_Error(
				'ab_mcp_store_quota',
				sprintf( 'This site has %1$d open packages, the most it keeps at a time (%2$d). A package counts until it is removed, a day after it was made, or later while a run that is not closed uses it. Wait until one is removed; an administrator can raise the number with the filter ab_mcp_store_max_packages.', $open, $max_packages ),
				array(
					'open'  => $open,
					'limit' => $max_packages,
				)
			);
		}
		$max_bytes = max( 0, (int) apply_filters( self::FILTER_BYTES, self::MAX_BYTES ) );
		if ( $bytes + $declared > $max_bytes ) {
			return new WP_Error(
				'ab_mcp_store_quota',
				sprintf( 'This package (%1$d bytes) would take the open packages of this site past %2$d bytes; they hold %3$d. Send it in smaller packages, or wait until one is removed (a day after it was made); an administrator can raise the limit with the filter ab_mcp_store_max_bytes.', $declared, $max_bytes, $bytes ),
				array(
					'declared' => $declared,
					'open'     => $bytes,
					'limit'    => $max_bytes,
				)
			);
		}
		return null;
	}

	/**
	 * Book what a run needs before its first write: checked against the free
	 * space of the disk, with the bookings of the other runs that are not
	 * closed and RESERVE, and a test write of TEST_WRITE bytes. Booking the
	 * same run again replaces its booking.
	 *
	 * @param int $run_id Run.
	 * @param int $bytes  Bytes it needs in all.
	 * @return array|WP_Error { run_id, bytes, free, others }.
	 */
	public static function book( $run_id, $bytes ) {
		$run_id = (int) $run_id;
		$bytes  = (int) $bytes;
		if ( $run_id < 1 || $bytes < 0 ) {
			return new WP_Error( 'ab_mcp_store_booking', 'A booking names its run and the bytes the run needs, zero or more. Pass both.' );
		}
		$root = AB_MCP_Protected_Store::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return AB_MCP_Protected_Store::writing(
			$root,
			static function () use ( $root, $run_id, $bytes ) {
				$book = self::read_bookings( $root );
				if ( is_wp_error( $book ) ) {
					return $book;
				}
				$book = self::without_closed( $book );
				unset( $book[ $run_id ] );
				$others = 0;
				foreach ( $book as $entry ) {
					$others += (int) $entry['bytes'];
				}
				$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! is_int( $free ) && ! is_float( $free ) ) {
					return new WP_Error( 'ab_mcp_store_space_unknown', 'This site does not say how much space its disk has free (disk_free_space() gives no answer), so no run books its space and none starts. Ask the host to allow disk_free_space() for PHP, then try again.' );
				}
				if ( $others + $bytes + self::RESERVE > $free ) {
					return new WP_Error(
						'ab_mcp_store_space',
						sprintf( 'The run needs %1$d bytes, other runs have booked %2$d, and %3$d must stay free, but the disk of the protected store has %4$d free. Nothing was written. Ask the host for more disk space, or wait until packages and reports are removed (a day after they were made), then try again.', $bytes, $others, self::RESERVE, (int) $free ),
						array(
							'needed' => $bytes,
							'others' => $others,
							'free'   => (int) $free,
						)
					);
				}
				$written = self::test_write( $root );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
				$book[ $run_id ] = array(
					'bytes'   => $bytes,
					'booked'  => time(),
					'user_id' => get_current_user_id(),
				);
				if ( ! self::write_bookings( $root, $book ) ) {
					return new WP_Error( 'ab_mcp_store_write', 'The booking could not be written to the protected store. Ask the host to check that the folder is writable and the disk has free space, then try again.' );
				}
				return array(
					'run_id' => $run_id,
					'bytes'  => $bytes,
					'free'   => (int) $free,
					'others' => $others,
				);
			}
		);
	}

	/**
	 * Give the booking of a run back.
	 *
	 * @param int $run_id Run.
	 * @return true|WP_Error
	 */
	public static function release( $run_id ) {
		$run_id = (int) $run_id;
		$root   = AB_MCP_Protected_Store::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		return AB_MCP_Protected_Store::writing(
			$root,
			static function () use ( $root, $run_id ) {
				$book = self::read_bookings( $root );
				if ( is_wp_error( $book ) ) {
					return $book;
				}
				if ( ! isset( $book[ $run_id ] ) ) {
					return true;
				}
				unset( $book[ $run_id ] );
				if ( ! self::write_bookings( $root, $book ) ) {
					return new WP_Error( 'ab_mcp_store_write', 'The booking could not be given back in the protected store. Ask the host to check that the folder is writable, then try again.' );
				}
				return true;
			}
		);
	}

	/**
	 * The bookings in the store, by run.
	 *
	 * @return array<int,array{bytes:int,booked:int,user_id:int}>|WP_Error
	 */
	public static function bookings() {
		$root = AB_MCP_Protected_Store::root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$admitted = AB_MCP_Protected_Store::admitted( $root );
		return true === $admitted ? self::read_bookings( $root ) : $admitted;
	}

	/**
	 * Whether what a package unpacks to stays within UNPACK: null when it
	 * does, the refusal for the first limit it passes otherwise.
	 *
	 * @param array<string,int> $measured Keys of UNPACK and what was found.
	 * @return WP_Error|null
	 */
	public static function unpack_refusal( array $measured ) {
		foreach ( self::UNPACK as $key => $max ) {
			if ( isset( $measured[ $key ] ) && (int) $measured[ $key ] > $max ) {
				return new WP_Error(
					'ab_mcp_store_unpack_limit',
					sprintf( 'The package passes the limit for %1$s (%2$d, at most %3$d), so it is not unpacked and nothing of it is used; a staging site of this version never makes such a package. 1. Update AlphaBridge MCP on the staging site to the version of this site. 2. Export the package again there (wp_site_export). 3. Import it again with the new package_id and manifest.', self::UNPACK_WHAT[ $key ], (int) $measured[ $key ], $max ),
					array(
						'limit' => $key,
						'found' => (int) $measured[ $key ],
						'max'   => $max,
					)
				);
			}
		}
		return null;
	}

	/* ------------------------------------------------------------ internals */

	/**
	 * The bookings without those of runs that are closed, when the runs can
	 * be read; all of them when not, so an unknown run keeps its booking.
	 *
	 * @param array $book Bookings.
	 * @return array
	 */
	private static function without_closed( array $book ) {
		$open = AB_MCP_Sync_Schema::open_runs();
		if ( null === $open ) {
			return $book;
		}
		foreach ( array_keys( $book ) as $run_id ) {
			if ( ! in_array( (int) $run_id, $open, true ) ) {
				unset( $book[ $run_id ] );
			}
		}
		return $book;
	}

	/**
	 * Write TEST_WRITE random bytes into a temp file of the store and remove
	 * it again (random, so a file system that compresses cannot store them
	 * in less than they are): a temp file of the area tmp/
	 * (AB_MCP_Protected_Store::temp_file()), written through write_file()
	 * and removed through delete_path() as made by this request (Z37).
	 *
	 * @param string $root Folder of the store.
	 * @return true|WP_Error
	 */
	private static function test_write( $root ) {
		$path = AB_MCP_Protected_Store::temp_file( $root );
		$ok   = ! is_wp_error( $path ) && true === AB_MCP_Protected_Store::write_file( $root, $path, AB_MCP_Payload_File::random_bytes( self::TEST_WRITE ) );
		if ( $ok ) {
			clearstatcache( true, $path );
			$ok = self::TEST_WRITE === @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! is_wp_error( $path ) ) {
			AB_MCP_Protected_Store::delete_path( $root, $path );
		}
		if ( ! $ok ) {
			return new WP_Error( 'ab_mcp_store_test_write', 'A test write of 1 MB to the protected store failed, so the run does not start: its backups could not be written either. Ask the host to check the free space and the rights of the folder, then try again.' );
		}
		return true;
	}

	/**
	 * The bookings of the store (Behebung 1, B2: unreadable is never empty).
	 * A file absent by the listing of the folder is the empty first stock;
	 * a file that cannot be read, is a link, is no JSON object or holds an
	 * entry that is no booking is a fault, and no call writes the bookings
	 * anew over it (book(), release()).
	 *
	 * @param string $root Folder of the store.
	 * @return array<int,array{bytes:int,booked:int,user_id:int}>|WP_Error
	 */
	private static function read_bookings( $root ) {
		$file = AB_MCP_Protected_Store::read_small( $root, $root . '/' . self::BOOKINGS, self::BOOKINGS_MAX );
		if ( 'absent' === $file['state'] ) {
			return array();
		}
		$data = 'ok' === $file['state'] ? json_decode( (string) $file['bytes'], true ) : null;
		if ( ! is_array( $data ) ) {
			return self::unread();
		}
		$out = array();
		foreach ( $data as $run_id => $entry ) {
			if ( ! is_int( $run_id ) || $run_id < 1 || ! is_array( $entry ) || ! isset( $entry['bytes'] ) || ! is_numeric( $entry['bytes'] ) ) {
				return self::unread();
			}
			$out[ $run_id ] = array(
				'bytes'   => max( 0, (int) $entry['bytes'] ),
				'booked'  => isset( $entry['booked'] ) ? (int) $entry['booked'] : 0,
				'user_id' => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
			);
		}
		return $out;
	}

	/**
	 * The refusal where the bookings cannot be read.
	 *
	 * @return WP_Error
	 */
	private static function unread() {
		return new WP_Error( 'ab_mcp_store_bookings_unread', __( 'The bookings of the protected store (the file bookings.json in its folder) could not be read, so no run books its space or gives it back, and nothing was written. Ask the host to check that file: its rights, or whether something else wrote into it; then try again.', 'alphabridge-mcp' ) );
	}

	/**
	 * The bookings, through write_file() (a temp file of tmp/ renamed into
	 * place, Z37).
	 *
	 * @param string $root Folder of the store.
	 * @param array  $book Bookings.
	 * @return bool
	 */
	private static function write_bookings( $root, array $book ) {
		return true === AB_MCP_Protected_Store::write_file( $root, $root . '/' . self::BOOKINGS, (string) json_encode( (object) $book ) );
	}
}
