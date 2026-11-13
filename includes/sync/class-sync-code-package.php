<?php
/**
 * A package of code (Z8b branch 4; plan E3 (b), 3.1 Z4): the files of units
 * of code of the staging site that the production site lacks, from the
 * staging site to the production site, with the same manifest, chunks,
 * relay and route as every other package (AB_MCP_Sync_Export,
 * AB_MCP_Sync_Import), for one report (purpose code, report_hash).
 *
 * WHAT A LINE IS. One piece of one file of one unit, in the one spelling of
 * AB_MCP_Sync_Canon::encode(), as a line of a package of media
 * (AB_MCP_Sync_Media_Package): data (the bytes of the piece in base64), kind
 * (the kind of the unit: plugin or theme, KINDS), path (the file relative to
 * the folder of the unit; a unit of one file: its own name), piece and pieces
 * (its number from 0, and how many the file has), ref (the name of the unit
 * in the site, as the environment names it), sha256 and size (of the whole
 * file). A piece holds PIECE bytes, the last one the rest; the pieces of a
 * file are consecutive lines in their order, a file once per unit.
 *
 * WHAT IT HOLDS. Only the files the production site asks for (refs with
 * paths: those whose SHA-256 the live unit lacks, AB_MCP_Sync_Code_Stage::
 * need()), at most MAX_BYTES of them together; what does not fit is named for
 * the next package (one unit may lie in several packages); a file alone
 * larger than that is named too_large. Nothing makes a package complete or
 * true: the stage takes a file only where its path, size and SHA-256 are the
 * ones of the signed manifest of the unit (Z4), and the unit only where its
 * whole manifest is that one. The contents of a file are not read for
 * credentials (plan E13); a path whose name a credential holds is not packed.
 *
 * WHAT THE PRODUCTION SITE CHECKS (unpacked(), once every chunk is there and
 * the package is checked whole): every line in its one spelling and shape,
 * each unit named in the manifest (its kinds: kind and subtype = ref), the
 * pieces of a file in their order and of their sizes, the SHA-256 and the
 * size of every file against its line, as many lines and bytes as the
 * manifest declares. Then it keeps an index (INDEX; read back only in the
 * form of its row code.index of AB_MCP_Store_Inputs::TABLE); extract()
 * reads a file from the chunks that hold it, each against its hash in the
 * manifest, into a file of its own and checks it again.
 *
 * Messages are in gettext, in the language of the site; their translations
 * come with text/z8b. Codes and machine values stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-media-package.php';

/**
 * Class AB_MCP_Sync_Code_Package
 */
final class AB_MCP_Sync_Code_Package {

	/** The kinds of units a package of code carries (plan E3: plugins and themes; branch 7 adds the files of A21). */
	const KINDS = array( 'plugin', 'theme' );

	/** Bytes of a piece of a file, as in a package of media. */
	const PIECE = AB_MCP_Sync_Media_Package::PIECE;

	/** Bytes of the files one package carries at most, as a package of media (48 MiB). */
	const MAX_BYTES = AB_MCP_Sync_Media_Package::MAX_BYTES;

	/** The fields of a line, sorted. */
	const FIELDS = array( 'data', 'kind', 'path', 'piece', 'pieces', 'ref', 'sha256', 'size' );

	/** Bytes of a path in a unit at most. */
	const MAX_PATH = 4096;

	/** The format of the index a package of code keeps on the production site. */
	const INDEX_FORMAT = 'ab-sync-code/1';

	/** The beginning of the name of the index (then its SHA-256 in base32). */
	const INDEX = 'code-';

	/* ------------------------------------------------------- the names */

	/**
	 * Whether a unit is one a package of code names: a kind of KINDS and the
	 * name of its folder or file in the site (not empty, no "/" or "\", no
	 * "." or "..", no control character, UTF-8, at most the 191 characters
	 * of a ref of a pair, AB_MCP_Sync_Pairs::MAX_REF).
	 *
	 * @param mixed $kind Kind.
	 * @param mixed $ref  Name of the unit.
	 * @return bool
	 */
	public static function unit_ok( $kind, $ref ) {
		return in_array( $kind, self::KINDS, true ) && is_string( $ref ) && '' !== $ref && '.' !== $ref && '..' !== $ref
			&& 1 === preg_match( '//u', $ref ) && 1 !== preg_match( '#[/\\\\\x00-\x1F\x7F]#', $ref ) && mb_strlen( $ref, 'UTF-8' ) <= 191;
	}

	/**
	 * Whether a path is one of a file in a unit: relative, parts that are
	 * neither empty nor "." or "..", no NUL, at most MAX_PATH bytes.
	 *
	 * @param mixed $path Path.
	 * @return bool
	 */
	public static function path_ok( $path ) {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > self::MAX_PATH || false !== strpos( $path, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------- the lines */

	/**
	 * The lines of one file of a unit, a piece at a time: each handed to $put
	 * as it is read, against the size and SHA-256 the manifest of the unit
	 * gave it (a file that changed since is refused).
	 *
	 * @param string   $unit The folder of the unit, or its file.
	 * @param string   $form folder or file.
	 * @param string   $kind Kind of the unit.
	 * @param string   $ref  Name of the unit.
	 * @param array    $file path, size, sha256 (an entry of its manifest).
	 * @param callable $put  function( string $line ): true|WP_Error.
	 * @return true|WP_Error
	 */
	public static function lines_of( $unit, $form, $kind, $ref, array $file, callable $put ) {
		$path   = (string) $file['path'];
		$size   = $file['size'];
		$sha    = (string) $file['sha256'];
		$pieces = AB_MCP_Sync_Media_Package::pieces( $size );
		$full   = 'file' === $form ? (string) $unit : rtrim( (string) $unit, '/' ) . '/' . $path;
		if ( ! is_int( $size ) || ! self::path_ok( $path ) || ( 'file' === $form && basename( (string) $unit ) !== $path ) || 'file' !== @filetype( $full ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that went is answered below.
			return self::changed( $path );
		}
		$handle = @fopen( $full, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read only, a piece at a time.
		if ( false === $handle ) {
			return self::changed( $path );
		}
		$hash = hash_init( 'sha256' );
		$read = 0;
		try {
			for ( $i = 0; $i < $pieces; $i++ ) {
				$want  = min( self::PIECE, $size - $read );
				$bytes = $want > 0 ? (string) fread( $handle, $want ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				while ( strlen( $bytes ) < $want && ! feof( $handle ) ) {
					$more = fread( $handle, $want - strlen( $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $more || '' === $more ) {
						break;
					}
					$bytes .= $more;
				}
				if ( strlen( $bytes ) !== $want ) {
					return self::changed( $path );
				}
				$read += $want;
				hash_update( $hash, $bytes );
				$done = $put(
					AB_MCP_Sync_Canon::encode(
						array(
							'data'   => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the bytes of a file in a line of JSON.
							'kind'   => (string) $kind,
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
			// Nothing after the size the manifest measured, and the bytes it hashed.
			if ( '' !== (string) fread( $handle, 1 ) || ! hash_equals( $sha, hash_final( $hash ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				return self::changed( $path );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return true;
	}

	/**
	 * A line of a package of code read: its fields in their types and the
	 * bytes of its piece, or null where it is not one as lines_of() writes it.
	 *
	 * @param string $line Line.
	 * @return array{kind:string,ref:string,path:string,piece:int,pieces:int,sha256:string,size:int,bytes:string}|null
	 */
	public static function line( $line ) {
		$object = json_decode( (string) $line, true, 4 );
		if ( ! is_array( $object ) || AB_MCP_Sync_Canon::encode( $object ) !== (string) $line || self::FIELDS !== self::sorted_keys( $object ) ) {
			return null;
		}
		$path = AB_MCP_Sync_Import::bytes( $object['path'] );
		if ( ! self::unit_ok( $object['kind'], $object['ref'] ) || ! self::path_ok( $path ) ) {
			return null;
		}
		if ( ! is_string( $object['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $object['sha256'] ) || ! is_int( $object['size'] ) || $object['size'] < 0 || ! is_int( $object['pieces'] ) || ! is_int( $object['piece'] ) ) {
			return null;
		}
		if ( $object['pieces'] !== AB_MCP_Sync_Media_Package::pieces( $object['size'] ) || $object['piece'] < 0 || $object['piece'] >= $object['pieces'] || ! is_string( $object['data'] ) ) {
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
			'kind'   => $object['kind'],
			'ref'    => $object['ref'],
			'path'   => $path,
			'piece'  => $object['piece'],
			'pieces' => $object['pieces'],
			'sha256' => $object['sha256'],
			'size'   => $object['size'],
			'bytes'  => $bytes,
		);
	}

	/* -------------------------------------------- on the production site */

	/**
	 * The lines of a package of code checked whole, its index kept in the
	 * group: AB_MCP_Sync_Import::unpacked() for the purpose code. Answers the
	 * objects a credential holds: none (a path a credential holds is never
	 * packed, and the contents of a file are not read for credentials).
	 *
	 * @param array $held AB_MCP_Sync_Import::held(): gid, manifest.
	 * @return array|WP_Error An empty list, or the refusal.
	 */
	public static function unpacked( array $held ) {
		$manifest = $held['manifest'];
		$named    = array();
		foreach ( $manifest['kinds'] as $kind ) {
			$named[ $kind['kind'] . "\0" . $kind['subtype'] ] = true;
		}
		$total   = 0;
		$count   = 0;
		$files   = array();
		$seen    = array();
		$current = null;
		foreach ( $manifest['chunks'] as $i => $chunk ) {
			$data = AB_MCP_Protected_Store::get( 'in', $held['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $chunk['sha256'] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$text = AB_MCP_Sync_Import::inflate( $data, min( $manifest['unpacked'] - $total, AB_MCP_Store_Budget::UNPACK['object_bytes'] ) );
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
				if ( null === $piece || ! isset( $named[ $piece['kind'] . "\0" . $piece['ref'] ] ) ) {
					return self::refusal( __( 'A line is not one piece of a file of a unit as the staging site writes it: a field it has no place for or of another type, a unit the manifest does not name, a path that is not a plain path in a unit, data that is no base64, or a piece of another size than its number gives.', 'alphabridge-mcp' ) );
				}
				$current = self::piece_into( $current, $piece, (int) $i, $seen );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if ( $current['next'] === $current['pieces'] ) {
					if ( ! hash_equals( $current['sha256'], hash_final( $current['hash'] ) ) ) {
						return self::refusal( __( 'A file of the package does not hash to the SHA-256 its lines name.', 'alphabridge-mcp' ) );
					}
					unset( $current['hash'], $current['next'] );
					ksort( $current );
					$files[] = $current;
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
	 * A piece into the file it belongs to (unpacked()): the first piece of a
	 * file begins it, every other one is the next of the file before.
	 *
	 * @param array|null $current The file so far, or null.
	 * @param array      $piece   line().
	 * @param int        $chunk   The chunk the piece lies in.
	 * @param array      $seen    The files begun so far (kind NUL ref NUL path).
	 * @return array|WP_Error
	 */
	private static function piece_into( $current, array $piece, $chunk, array &$seen ) {
		$key = $piece['kind'] . "\0" . $piece['ref'] . "\0" . $piece['path'];
		if ( null === $current ) {
			if ( 0 !== $piece['piece'] || isset( $seen[ $key ] ) ) {
				return self::refusal( __( 'The pieces of a file are not consecutive lines in their order, or a file of a unit is there twice.', 'alphabridge-mcp' ) );
			}
			$seen[ $key ] = true;
			$current      = array(
				'chunks' => array(),
				'hash'   => hash_init( 'sha256' ),
				'kind'   => $piece['kind'],
				'next'   => 0,
				'path'   => $piece['path'],
				'pieces' => $piece['pieces'],
				'ref'    => $piece['ref'],
				'sha256' => $piece['sha256'],
				'size'   => $piece['size'],
			);
		} elseif ( $key !== $current['kind'] . "\0" . $current['ref'] . "\0" . $current['path'] || $piece['piece'] !== $current['next'] || $piece['sha256'] !== $current['sha256'] || $piece['size'] !== $current['size'] || $piece['pieces'] !== $current['pieces'] ) {
			return self::refusal( __( 'The pieces of a file are not consecutive lines in their order, or a file of a unit is there twice.', 'alphabridge-mcp' ) );
		}
		hash_update( $current['hash'], $piece['bytes'] );
		if ( array() === $current['chunks'] || end( $current['chunks'] ) !== $chunk ) {
			$current['chunks'][] = $chunk;
		}
		++$current['next'];
		return $current;
	}

	/**
	 * The index of a package of code as unpacked() wrote it, in the form of
	 * its row code.index of AB_MCP_Store_Inputs::TABLE: its files, or null
	 * (unknown: the package is not used).
	 *
	 * @param mixed $text The index as read.
	 * @return array[]|null
	 */
	public static function index( $text ) {
		$index = AB_MCP_Store_Inputs::read( 'code.index', $text );
		if ( ! is_array( $index ) ) {
			return null;
		}
		foreach ( $index['files'] as $file ) {
			// The rules of a line, which the form does not hold: a unit and a
			// path of a unit, as many pieces as the size gives.
			if ( ! self::unit_ok( $file['kind'], $file['ref'] ) || ! self::path_ok( $file['path'] ) || AB_MCP_Sync_Media_Package::pieces( $file['size'] ) !== $file['pieces'] ) {
				return null;
			}
		}
		return $index['files'];
	}

	/**
	 * A package of code taken over completely (AB_MCP_Sync_Import::taken()
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
		$files = is_string( $text ) ? self::index( $text ) : null;
		if ( null === $files ) {
			return new WP_Error( 'ab_mcp_sync_code_index', __( 'The package of code is marked complete, but its index does not read back as this version keeps it, so it is not used. Export the code again on the staging site (wp_site_export with purpose=code), then take it over here (wp_site_import_chunk).', 'alphabridge-mcp' ) );
		}
		return array(
			'gid'      => (string) $gid,
			'manifest' => $manifest,
			'files'    => $files,
		);
	}

	/**
	 * One file of a package of code into a file of its own: the chunks the
	 * index names for it, each read against its hash in the manifest, its
	 * pieces written in their order, the whole checked against its size and
	 * SHA-256 (fail closed: a file that does not read whole is none).
	 *
	 * @param array  $taken taken().
	 * @param array  $file  An entry of the index.
	 * @param string $to    The file to write (it is made new).
	 * @return true|WP_Error
	 */
	public static function extract( array $taken, array $file, $to ) {
		$out = @fopen( (string) $to, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a file of a building folder of the store, made new.
		if ( false === $out ) {
			return self::unread( $file );
		}
		$hash  = hash_init( 'sha256' );
		$next  = 0;
		$bytes = 0;
		try {
			foreach ( $file['chunks'] as $i ) {
				if ( ! isset( $taken['manifest']['chunks'][ $i ] ) ) {
					return self::unread( $file );
				}
				$data = AB_MCP_Protected_Store::get( 'in', $taken['gid'], AB_MCP_Sync_Export::chunk_name( $i ), $taken['manifest']['chunks'][ $i ]['sha256'] );
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
					if ( null === $piece || $piece['kind'] !== $file['kind'] || $piece['ref'] !== $file['ref'] || $piece['path'] !== $file['path'] ) {
						continue;
					}
					if ( $piece['piece'] !== $next || $piece['sha256'] !== $file['sha256'] || $piece['size'] !== $file['size'] ) {
						return self::unread( $file );
					}
					hash_update( $hash, $piece['bytes'] );
					$bytes += strlen( $piece['bytes'] );
					if ( strlen( $piece['bytes'] ) !== (int) fwrite( $out, $piece['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						return self::unread( $file );
					}
					++$next;
				}
				unset( $text );
			}
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $next !== $file['pieces'] || $bytes !== $file['size'] || ! hash_equals( $file['sha256'], hash_final( $hash ) ) ) {
			return self::unread( $file );
		}
		// Read back from the disk, as it will be put into the store.
		$written = @hash_file( 'sha256', (string) $to ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed read is answered.
		return is_string( $written ) && hash_equals( $file['sha256'], $written ) ? true : self::unread( $file );
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
	 * @param string $path Path.
	 * @return WP_Error A file that changed since its manifest was read.
	 */
	private static function changed( $path ) {
		/* translators: %s: path of the file in its unit. */
		return new WP_Error( 'ab_mcp_sync_code_changed', sprintf( __( 'The file %s of a unit changed, went or could not be read while it was packed, so no package of code was made. Call wp_site_export with purpose=code again; if it keeps failing, check that nothing writes into the plugin or theme on this staging site.', 'alphabridge-mcp' ), (string) $path ) );
	}

	/**
	 * @param array $file An entry of the index.
	 * @return WP_Error A file of the package that does not read whole.
	 */
	private static function unread( array $file ) {
		/* translators: %s: path of the file in its unit. */
		return new WP_Error( 'ab_mcp_sync_code_unread', sprintf( __( 'The file %s of the package of code does not read whole (a piece is missing or out of its order, or it does not hash to its SHA-256), so nothing of the unit was staged. Export the code again on the staging site (wp_site_export with purpose=code), take the package over here (wp_site_import_chunk) and stage again.', 'alphabridge-mcp' ), isset( $file['path'] ) ? (string) $file['path'] : '?' ) );
	}

	/**
	 * @param string $what What is wrong.
	 * @return WP_Error
	 */
	private static function refusal( $what ) {
		return new WP_Error( 'ab_mcp_sync_import_lines', $what . ' ' . __( 'So the package of code is not used, and what arrived of it was removed. 1. Update AlphaBridge MCP on the staging site to the version of this site. 2. Export the code again there (wp_site_export with purpose=code). 3. Import it again with the new package_id and manifest.', 'alphabridge-mcp' ) );
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
