<?php
/**
 * A package of media (Z8, decisions 5, 6, 8 and 26 of the plan of
 * 01.11.2026; branch 10): the bytes of the files of attachments, from the
 * staging site to the production site, with the same manifest, chunks,
 * relay and route as every other package (AB_MCP_Sync_Export,
 * AB_MCP_Sync_Import), for one report (purpose media, report_hash).
 *
 * WHAT A LINE IS. One piece of one file of one attachment, in the one
 * spelling of AB_MCP_Sync_Canon::encode(): data (the bytes of the piece in
 * base64), kind (file), path (the file relative to the uploads, as the file
 * list of the attachment names it, AB_MCP_Sync_Media), piece and pieces
 * (its number from 0, and how many the file has), ref (the attachment on
 * the staging site), sha256 and size (of the whole file). A piece holds
 * PIECE bytes, the last one the rest; the pieces of a file are consecutive
 * lines in their order, a file once per attachment. So a line stays within
 * a chunk (AB_MCP_Sync_Export::SLICE), and the production site reads one
 * file from the chunks that hold it without reading the package whole.
 *
 * WHAT IT HOLDS. The files of whole attachments, at most MAX_BYTES of them
 * together (64 MiB a package in Free, contract B; the base64 and the gzip
 * stay below it): what does not fit is named for the next package, an
 * attachment whose files alone are more is named as too large (the plan
 * locks it, attachment:file_too_large). The contents of a file are not
 * read for credentials: they are the files the staging site serves under
 * their addresses (GRENZEN-MEDIEN.md).
 *
 * WHAT THE PRODUCTION SITE CHECKS (unpacked(), once every chunk is there
 * and the package is checked whole): every line in its one spelling and
 * shape, the pieces of a file in their order and of their sizes, the
 * SHA-256 and the size of every file against its line, as many lines and
 * bytes as the manifest declares. Then it keeps an index (INDEX): each
 * file with its attachment, path, hash, size and the chunks that hold it;
 * extract() reads a file from those chunks, each against its hash in the
 * manifest, into a file of its own and checks it again.
 *
 * Messages are in gettext, in the language of the site, as the other
 * classes of the takeover; each names the way. Codes and machine values
 * (kinds, purposes, parameters, fields) stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-media.php';

/**
 * Class AB_MCP_Sync_Media_Package
 */
final class AB_MCP_Sync_Media_Package {

	/** Bytes of a piece of a file: its base64 (4 MiB) and the fields stay within a chunk's lines. */
	const PIECE = 3145728;

	/**
	 * Bytes of the files one package carries at most: 48 MiB, so the lines
	 * (base64, a third more) and their gzip stay within the 64 MiB of a
	 * package in Free (AB_MCP_Sync_Manifest::MAX_BYTES, contract B). The
	 * files a step of a run lays for one attachment come from one package,
	 * so this bounds them too (AB_MCP_Sync_Attachment_Writer).
	 */
	const MAX_BYTES = 50331648;

	/** The fields of a line, sorted. */
	const FIELDS = array( 'data', 'kind', 'path', 'piece', 'pieces', 'ref', 'sha256', 'size' );

	/** The format of the index a package of media keeps on the production site. */
	const INDEX_FORMAT = 'ab-sync-media/1';

	/** The beginning of the name of the index (then its SHA-256 in base32). */
	const INDEX = 'media-';

	/* ------------------------------------------------------- the lines */

	/**
	 * The lines of one file, a piece at a time: each handed to $put as it is
	 * read, the file read from the resolved uploads, against the size and
	 * SHA-256 its list gave it (a file that changed since is refused).
	 *
	 * @param string   $root The resolved uploads folder.
	 * @param string   $ref  The attachment.
	 * @param array    $file path, size, sha256 (AB_MCP_Sync_Media::files()).
	 * @param callable $put  function( string $line ): true|WP_Error.
	 * @return true|WP_Error
	 */
	public static function lines_of( $root, $ref, array $file, callable $put ) {
		$path   = (string) $file['path'];
		$size   = (int) $file['size'];
		$sha    = (string) $file['sha256'];
		$pieces = self::pieces( $size );
		if ( ! AB_MCP_Sync_Media::safe_path( $path ) ) {
			return self::changed( $path );
		}
		$full   = rtrim( (string) $root, '/' ) . '/' . $path;
		$real   = realpath( $full );
		if ( false === $real || $real !== $full || 'file' !== @filetype( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that went is answered below.
			return self::changed( $path );
		}
		$handle = @fopen( $real, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read only, a piece at a time.
		if ( false === $handle ) {
			return self::changed( $path );
		}
		$hash = hash_init( 'sha256' );
		$read = 0;
		try {
			for ( $i = 0; $i < $pieces; $i++ ) {
				$want  = min( self::PIECE, $size - $read );
				$bytes = $want > 0 ? self::read( $handle, $want ) : '';
				if ( strlen( $bytes ) !== $want ) {
					return self::changed( $path );
				}
				$read += $want;
				hash_update( $hash, $bytes );
				$done = $put(
					AB_MCP_Sync_Canon::encode(
						array(
							'data'   => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the bytes of a file in a line of JSON.
							'kind'   => 'file',
							'path'   => $path,
							'piece'  => $i,
							'pieces' => $pieces,
							'ref'    => (string) $ref,
							'sha256' => $sha,
							'size'   => $size,
						)
					)
				);
				unset( $bytes );
				if ( true !== $done ) {
					return $done;
				}
			}
			// Nothing after the size the list measured, and the bytes it hashed.
			if ( '' !== self::read( $handle, 1 ) || ! hash_equals( $sha, hash_final( $hash ) ) ) {
				return self::changed( $path );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return true;
	}

	/**
	 * How many pieces a file of this size has: one at least.
	 *
	 * @param int $size Bytes.
	 * @return int
	 */
	public static function pieces( $size ) {
		return max( 1, intdiv( max( 0, (int) $size ) + self::PIECE - 1, self::PIECE ) );
	}

	/* -------------------------------------------- on the production site */

	/**
	 * The lines of a package of media checked whole (see the class), its
	 * index kept in the group: AB_MCP_Sync_Import::unpacked() for the
	 * purpose media. Answers the objects a credential holds: none (the
	 * contents of a file are not read for credentials).
	 *
	 * @param array $held AB_MCP_Sync_Import::held(): gid, manifest.
	 * @return array|WP_Error An empty list, or the refusal.
	 */
	public static function unpacked( array $held ) {
		$manifest = $held['manifest'];
		$limits   = AB_MCP_Store_Budget::UNPACK;
		$total    = 0;
		$count    = 0;
		$files    = array();
		$seen     = array();
		$current  = null;
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$data = AB_MCP_Protected_Store::get( 'in', $held['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$declared = $manifest['unpacked'] - $total;
			$text     = AB_MCP_Sync_Import::inflate( $data, min( $declared, $limits['object_bytes'] ) );
			unset( $data );
			if ( ! is_string( $text ) ) {
				return self::refusal( __( 'A chunk does not unpack within the package: it is no gzip, or it inflates to more than the manifest declares.', 'alphabridge-mcp' ) );
			}
			$total += strlen( $text );
			if ( '' === $text ) {
				continue;
			}
			if ( "\n" !== substr( $text, -1 ) ) {
				return self::refusal( __( 'A chunk does not end with a whole line.', 'alphabridge-mcp' ) );
			}
			foreach ( explode( "\n", substr( $text, 0, -1 ) ) as $line ) {
				++$count;
				$refused = AB_MCP_Store_Budget::unpack_refusal( array( 'objects' => $count ) );
				if ( null !== $refused ) {
					return $refused;
				}
				$piece = self::line( $line );
				if ( null === $piece ) {
					return self::refusal( __( 'A line is not one piece of a file as the staging site writes it: a field it has no place for or of another type, a path that is not a plain path under the uploads, data that is no base64, or a piece of another size than its number gives.', 'alphabridge-mcp' ) );
				}
				$key = $piece['ref'] . "\0" . $piece['path'];
				if ( null === $current ) {
					if ( 0 !== $piece['piece'] || isset( $seen[ $key ] ) ) {
						return self::refusal( __( 'The pieces of a file are not consecutive lines in their order, or a file of an attachment is there twice.', 'alphabridge-mcp' ) );
					}
					$seen[ $key ] = true;
					$current      = array(
						'chunks' => array(),
						'hash'   => hash_init( 'sha256' ),
						'path'   => $piece['path'],
						'pieces' => $piece['pieces'],
						'ref'    => $piece['ref'],
						'sha256' => $piece['sha256'],
						'size'   => $piece['size'],
						'next'   => 0,
					);
				} elseif ( $key !== $current['ref'] . "\0" . $current['path'] || $piece['piece'] !== $current['next'] || $piece['sha256'] !== $current['sha256'] || $piece['size'] !== $current['size'] || $piece['pieces'] !== $current['pieces'] ) {
					return self::refusal( __( 'The pieces of a file are not consecutive lines in their order, or a file of an attachment is there twice.', 'alphabridge-mcp' ) );
				}
				hash_update( $current['hash'], $piece['bytes'] );
				if ( array() === $current['chunks'] || end( $current['chunks'] ) !== (int) $i ) {
					$current['chunks'][] = (int) $i;
				}
				++$current['next'];
				if ( $current['next'] === $current['pieces'] ) {
					if ( ! hash_equals( $current['sha256'], hash_final( $current['hash'] ) ) ) {
						return self::refusal( __( 'A file of the package does not hash to the SHA-256 its lines name.', 'alphabridge-mcp' ) );
					}
					$files[] = array(
						'chunks' => $current['chunks'],
						'path'   => $current['path'],
						'pieces' => $current['pieces'],
						'ref'    => $current['ref'],
						'sha256' => $current['sha256'],
						'size'   => $current['size'],
					);
					$current = null;
				}
			}
			unset( $text );
		}
		if ( null !== $current ) {
			return self::refusal( __( 'The package ends within the pieces of a file.', 'alphabridge-mcp' ) );
		}
		if ( $count !== $manifest['objects'] || $total !== $manifest['unpacked'] ) {
			return self::refusal( __( 'The lines are not as many, or not as long, as the manifest declares.', 'alphabridge-mcp' ) );
		}
		$text = AB_MCP_Sync_Canon::encode(
			array(
				'files'  => $files,
				'format' => self::INDEX_FORMAT,
			)
		);
		$sha  = hash( 'sha256', $text );
		$put  = AB_MCP_Protected_Store::put( 'in', $held['gid'], self::index_name( $sha ), $text );
		if ( is_wp_error( $put ) && ! ( 'ab_mcp_store_exists' === $put->get_error_code() && is_string( AB_MCP_Protected_Store::get( 'in', $held['gid'], self::index_name( $sha ), $sha ) ) ) ) {
			return $put;
		}
		return array();
	}

	/**
	 * A package of media taken over completely (AB_MCP_Sync_Import::taken()
	 * checked its manifest and its mark): its index.
	 *
	 * @param string $gid      The group of the package in the area in.
	 * @param array  $manifest The manifest verify() read.
	 * @param array  $have     The files of the group.
	 * @return array|WP_Error manifest, gid, files (as unpacked() kept them).
	 */
	public static function taken( $gid, array $manifest, array $have ) {
		$names = array_values( array_filter( array_map( 'strval', array_keys( $have ) ), static fn( $name ) => null !== self::index_sha( $name ) ) );
		$text  = 1 === count( $names ) ? AB_MCP_Protected_Store::get( 'in', $gid, $names[0], self::index_sha( $names[0] ) ) : null;
		// The index as unpacked() wrote it (AB_MCP_Sync_Canon::encode()): a
		// path that is no UTF-8 stands as the marker of its bytes and comes
		// back as them (Codex round 1 of branch 10, C: json_decode() alone left
		// the marker, and the index of a package with such a name was never
		// used).
		$index = is_string( $text ) ? AB_MCP_Sync_Canon::decode( $text, 8 ) : null;
		if ( ! is_array( $index ) || ! isset( $index['format'], $index['files'] ) || self::INDEX_FORMAT !== $index['format'] || ! is_array( $index['files'] ) || ! array_is_list( $index['files'] ) ) {
			return new WP_Error( 'ab_mcp_sync_media_index', __( 'The package of media is marked complete, but its index does not read back (it was taken over by an older version, or the index was changed), so it is not used. Fetch the package anew: export the media again on the staging site (wp_site_export with purpose=media), then take it over here (wp_site_import_chunk).', 'alphabridge-mcp' ) );
		}
		foreach ( $index['files'] as $file ) {
			if ( ! is_array( $file ) || array( 'chunks', 'path', 'pieces', 'ref', 'sha256', 'size' ) !== self::sorted_keys( $file ) || ! is_string( $file['ref'] ) || ! is_string( $file['path'] ) || ! AB_MCP_Sync_Media::safe_path( $file['path'] ) || ! is_string( $file['sha256'] ) || ! is_int( $file['size'] ) || ! is_int( $file['pieces'] ) || ! is_array( $file['chunks'] ) || array() === $file['chunks'] ) {
				return new WP_Error( 'ab_mcp_sync_media_index', __( 'The index of the package of media does not read as this version keeps it, so it is not used. Export the media again on the staging site (wp_site_export with purpose=media), then take it over here (wp_site_import_chunk).', 'alphabridge-mcp' ) );
			}
		}
		return array(
			'gid'      => (string) $gid,
			'manifest' => $manifest,
			'files'    => $index['files'],
		);
	}

	/**
	 * One file of a package of media into a file of its own: the chunks the
	 * index names for it, each read against its hash in the manifest, its
	 * pieces written in their order, the whole checked against its size and
	 * SHA-256 (fail closed: a file that does not read whole is none).
	 *
	 * @param array  $taken taken().
	 * @param array  $file  An entry of the index (ref, path, sha256, size, pieces, chunks).
	 * @param string $to    The file to write (it exists, empty: a temporary file).
	 * @return true|WP_Error
	 */
	public static function extract( array $taken, array $file, $to ) {
		$out = @fopen( (string) $to, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a temporary file of this request.
		if ( false === $out ) {
			return new WP_Error( 'ab_mcp_sync_media_temp', __( 'A temporary file for a file of the package could not be written, so nothing of the attachment was laid. Ask the host to check that PHP may write to its temporary folder (get_temp_dir()) and that the disk has free space, then call again.', 'alphabridge-mcp' ) );
		}
		$hash  = hash_init( 'sha256' );
		$next  = 0;
		$bytes = 0;
		try {
			foreach ( $file['chunks'] as $i ) {
				if ( ! isset( $taken['manifest']['chunks'][ $i ] ) ) {
					return self::unread( $file );
				}
				$data = AB_MCP_Protected_Store::get( 'in', $taken['gid'], AB_MCP_Sync_Export::chunk_name( (int) $i ), $taken['manifest']['chunks'][ $i ]['sha256'] );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$text = AB_MCP_Sync_Import::inflate( $data, AB_MCP_Store_Budget::UNPACK['object_bytes'] );
				unset( $data );
				if ( ! is_string( $text ) || ( '' !== $text && "\n" !== substr( $text, -1 ) ) ) {
					return self::unread( $file );
				}
				foreach ( '' === $text ? array() : explode( "\n", substr( $text, 0, -1 ) ) as $line ) {
					$piece = self::line( $line );
					if ( null === $piece || $piece['ref'] !== $file['ref'] || $piece['path'] !== $file['path'] ) {
						continue;
					}
					if ( $piece['piece'] !== $next || $piece['sha256'] !== $file['sha256'] || $piece['size'] !== $file['size'] ) {
						return self::unread( $file );
					}
					hash_update( $hash, $piece['bytes'] );
					$bytes += strlen( $piece['bytes'] );
					if ( strlen( $piece['bytes'] ) !== (int) fwrite( $out, $piece['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						return new WP_Error( 'ab_mcp_sync_media_temp', __( 'A temporary file for a file of the package could not be written whole, so nothing of the attachment was laid. Ask the host to check that the disk has free space, then call again.', 'alphabridge-mcp' ) );
					}
					++$next;
				}
				unset( $text );
			}
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $next !== (int) $file['pieces'] || $bytes !== (int) $file['size'] || ! hash_equals( (string) $file['sha256'], hash_final( $hash ) ) ) {
			return self::unread( $file );
		}
		// Read back from the disk, as it will be copied.
		$written = @hash_file( 'sha256', (string) $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed read is answered.
		return is_string( $written ) && hash_equals( (string) $file['sha256'], $written ) && (int) @filesize( (string) $to ) === (int) $file['size'] ? true : self::unread( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * A line of a package of media read: its fields in their types and the
	 * bytes of its piece, or null where it is not one as lines_of() writes
	 * it (the one spelling of the canon, the fields of FIELDS, kind file, a
	 * ref of an id, a plain path under the uploads, a SHA-256 in hex, data
	 * in base64 of exactly the size its number gives).
	 *
	 * @param string $line Line.
	 * @return array{ref:string,path:string,piece:int,pieces:int,sha256:string,size:int,bytes:string}|null
	 */
	public static function line( $line ) {
		$object = json_decode( (string) $line, true, 4 );
		if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== (string) $line || self::FIELDS !== self::sorted_keys( $object ) ) {
			return null;
		}
		$path = AB_MCP_Sync_Import::bytes( $object['path'] );
		if ( 'file' !== $object['kind'] || ! is_string( $object['ref'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,19}\z/', $object['ref'] ) || null === $path || ! AB_MCP_Sync_Media::safe_path( $path ) ) {
			return null;
		}
		if ( ! is_string( $object['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $object['sha256'] ) || ! is_int( $object['size'] ) || $object['size'] < 0 || ! is_int( $object['pieces'] ) || ! is_int( $object['piece'] ) ) {
			return null;
		}
		if ( $object['pieces'] !== self::pieces( $object['size'] ) || $object['piece'] < 0 || $object['piece'] >= $object['pieces'] || ! is_string( $object['data'] ) ) {
			return null;
		}
		$bytes = base64_decode( $object['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the bytes of a piece.
		if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $object['data'] ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the round trip.
			return null;
		}
		$want = $object['piece'] < $object['pieces'] - 1 ? self::PIECE : $object['size'] - ( $object['pieces'] - 1 ) * self::PIECE;
		if ( strlen( $bytes ) !== $want ) {
			return null;
		}
		return array(
			'ref'    => $object['ref'],
			'path'   => $path,
			'piece'  => $object['piece'],
			'pieces' => $object['pieces'],
			'sha256' => $object['sha256'],
			'size'   => $object['size'],
			'bytes'  => $bytes,
		);
	}

	/**
	 * The name of the index: INDEX and its SHA-256 in base32, as the list of
	 * held objects is named (AB_MCP_Sync_Import::held_name()).
	 *
	 * @param string $sha SHA-256 in hex.
	 * @return string
	 */
	public static function index_name( $sha ) {
		return self::INDEX . substr( AB_MCP_Sync_Import::held_name( $sha ), strlen( AB_MCP_Sync_Import::HELD ) );
	}

	/**
	 * The SHA-256 an index name carries, or null.
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	public static function index_sha( $name ) {
		if ( 1 !== preg_match( '/^' . self::INDEX . '[a-z2-7]{52}\z/', (string) $name ) ) {
			return null;
		}
		return AB_MCP_Sync_Import::held_sha( AB_MCP_Sync_Import::HELD . substr( (string) $name, strlen( self::INDEX ) ) );
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Bytes from a handle, as many as asked unless the file ends.
	 *
	 * @param resource $handle Handle.
	 * @param int      $want   Bytes.
	 * @return string
	 */
	private static function read( $handle, $want ) {
		$out = '';
		while ( strlen( $out ) < $want ) {
			$more = fread( $handle, $want - strlen( $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $more || '' === $more ) {
				break;
			}
			$out .= $more;
		}
		return $out;
	}

	/**
	 * @param string $path Path.
	 * @return WP_Error A file that changed since its list was read.
	 */
	private static function changed( $path ) {
		/* translators: %s: path of the file under the uploads. */
		return new WP_Error( 'ab_mcp_sync_media_changed', sprintf( __( 'The file %s of an attachment changed, went or could not be read while it was packed, so no package of media was made. Call wp_site_export with purpose=media again; if it keeps failing, check the file in the uploads of this staging site.', 'alphabridge-mcp' ), (string) $path ) );
	}

	/**
	 * @param array $file An entry of the index.
	 * @return WP_Error A file of the package that does not read whole.
	 */
	private static function unread( array $file ) {
		/* translators: %s: path of the file under the uploads. */
		return new WP_Error( 'ab_mcp_sync_media_unread', sprintf( __( 'The file %s of the package of media does not read whole (a piece is missing or out of its order, or it does not hash to its SHA-256), so nothing of the attachment was laid. Export the media again on the staging site (wp_site_export with purpose=media), take the package over here (wp_site_import_chunk) and plan again.', 'alphabridge-mcp' ), isset( $file['path'] ) ? (string) $file['path'] : '?' ) );
	}

	/**
	 * @param string $what What is wrong.
	 * @return WP_Error
	 */
	private static function refusal( $what ) {
		return new WP_Error( 'ab_mcp_sync_import_lines', $what . ' ' . __( 'So the package of media is not used, and what arrived of it was removed. 1. Update AlphaBridge MCP on the staging site to the version of this site. 2. Export the media again there (wp_site_export with purpose=media). 3. Import it again with the new package_id and manifest.', 'alphabridge-mcp' ) );
	}

	/**
	 * @param array $array Array.
	 * @return string[] Its keys, sorted.
	 */
	private static function sorted_keys( array $array ) {
		$keys = array_map( 'strval', array_keys( $array ) );
		sort( $keys );
		return $keys;
	}
}
