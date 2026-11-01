<?php
/**
 * The file list of an attachment (Z8, decision 26 of the plan of
 * 01.11.2026, the list only): exactly the files wp_delete_attachment_files()
 * knows (wp-includes/post.php:6926-7045 of WordPress 7.1.3) — the file of
 * _wp_attached_file, the thumb, every size of sizes, original_image,
 * source_image, animated_video, animated_video_poster and every edit of
 * _wp_attachment_backup_sizes — each with its path relative to the uploads,
 * its size and its SHA-256. The integration suite holds the list against the
 * core function.
 *
 * The paths are worked out as the core does (the same str_replace() of the
 * file name, the backups beside the dirname of the metadata's file), from
 * the stored meta rows and not through get_attached_file() or
 * wp_get_attachment_metadata(), whose filters replace values; the uploads
 * folder is wp_get_upload_dir()'s, the one the core deletes in.
 *
 * PATH RULES: relative, no "..", no absolute start, no NUL, backslash or
 * control character; an existing file is resolved with realpath() under the
 * resolved basedir, with no symbolic link on the way, and must be a regular
 * file. A path that breaks a rule locks the attachment
 * (attachment:unsafe_path), a listed file that is not there as well
 * (attachment:missing_file). reverse() maps each path to the attachments
 * whose lists name it: a path two attachments share locks both
 * (attachment:shared_path; the core itself keeps a thumb another attachment
 * names, :6932-6936).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-safe.php';

/**
 * Class AB_MCP_Sync_Media
 */
final class AB_MCP_Sync_Media {

	/** The keys of the metadata whose text names one file beside the main one. */
	const COMPANIONS = array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' );

	/** The meta keys the list is read from. */
	const META_KEYS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );

	/**
	 * The paths of an attachment's files as wp_delete_attachment_files() works
	 * them out, relative to the uploads, each with its role.
	 *
	 * @param string $attached _wp_attached_file as stored.
	 * @param mixed  $meta     _wp_attachment_metadata unpacked.
	 * @param mixed  $backup   _wp_attachment_backup_sizes unpacked.
	 * @param string $basedir  The uploads folder (wp_get_upload_dir()).
	 * @return array{files:array<int,array{path:string,roles:string[]}>,problems:array}
	 */
	public static function paths( $attached, $meta, $backup, $basedir ) {
		$basedir  = rtrim( (string) $basedir, '/' );
		$attached = (string) $attached;
		$files    = array();
		$problems = array();
		// An empty or absolute _wp_attached_file names no file of the
		// uploads, or a place of this site only.
		if ( '' === $attached || self::bad_bytes( $attached ) || str_starts_with( $attached, '/' ) || preg_match( '|^.:\\\\|', $attached ) ) {
			return array(
				'files'    => array(),
				'problems' => array(
					array(
						'path'    => self::bad_bytes( $attached ) ? '' : $attached,
						'role'    => 'file',
						'problem' => 'unsafe',
					),
				),
			);
		}
		// get_attached_file(): a relative path is joined to basedir.
		$file = $basedir . '/' . $attached;
		$base = wp_basename( $file );
		$meta = is_array( $meta ) ? $meta : array();

		// Each name as the core puts it beside the file (str_replace() of its
		// name); a name with bytes no path may hold is a problem before any
		// path function sees it (realpath() refuses a NUL with an error).
		$beside = array();
		if ( ! empty( $meta['thumb'] ) && is_string( $meta['thumb'] ) ) {
			$beside['thumb'] = $meta['thumb'];
		}
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( is_array( $info ) && isset( $info['file'] ) && is_string( $info['file'] ) && '' !== $info['file'] ) {
					$beside[ 'size:' . $size ] = $info['file'];
				}
			}
		}
		foreach ( self::COMPANIONS as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$beside[ $key ] = $meta[ $key ];
			}
		}
		foreach ( $beside as $role => $name ) {
			if ( self::bad_bytes( $name ) ) {
				$problems[] = array(
					'path'    => '',
					'role'    => (string) $role,
					'problem' => 'unsafe',
				);
				continue;
			}
			self::add( $files, $problems, path_join( $basedir, str_replace( $base, $name, $file ) ), (string) $role, $basedir );
		}
		if ( is_array( $backup ) ) {
			$dir = isset( $meta['file'] ) && is_string( $meta['file'] ) ? $meta['file'] : '';
			$dir = self::bad_bytes( $dir ) ? null : dirname( $dir );
			foreach ( $backup as $name => $size ) {
				if ( ! is_array( $size ) || ! isset( $size['file'] ) || ! is_string( $size['file'] ) || '' === $size['file'] ) {
					continue;
				}
				if ( null === $dir || self::bad_bytes( $size['file'] ) ) {
					$problems[] = array(
						'path'    => '',
						'role'    => 'backup:' . $name,
						'problem' => 'unsafe',
					);
					continue;
				}
				self::add( $files, $problems, path_join( $basedir, path_join( $dir, $size['file'] ) ), 'backup:' . $name, $basedir );
			}
		}
		self::add( $files, $problems, $file, 'file', $basedir );
		return array(
			'files'    => array_values( $files ),
			'problems' => $problems,
		);
	}

	/**
	 * The file list of one attachment of this site, from its rows: each file
	 * with path, roles, size and SHA-256; the problems of the path rules and
	 * missing files.
	 *
	 * @param int $id Attachment id.
	 * @return array{files:array,problems:array}|null Null when a row could not be read.
	 */
	public static function files( $id ) {
		$raw = array();
		foreach ( self::META_KEYS as $key ) {
			$values = AB_MCP_Sync_Rows::meta_values( 'post', (int) $id, $key );
			if ( null === $values ) {
				return null;
			}
			$raw[ $key ] = array() === $values ? '' : $values[0];
		}
		$basedir = AB_MCP_Sync_Safe::upload_basedir();
		if ( null === $basedir || AB_MCP_Sync_Canon::holds_record( $raw['_wp_attached_file'] ) ) {
			// The uploads settings or the file name hold an object (or could
			// not be read): no folder to list in.
			return array(
				'files'    => array(),
				'problems' => array(
					array(
						'path'    => '',
						'role'    => null === $basedir ? 'uploads' : 'file',
						'problem' => 'php_object',
					),
				),
			);
		}
		$meta   = AB_MCP_Sync_Canon::unserialized( $raw['_wp_attachment_metadata'] );
		$backup = AB_MCP_Sync_Canon::unserialized( $raw['_wp_attachment_backup_sizes'] );
		$list   = self::paths( $raw['_wp_attached_file'], $meta['ok'] ? $meta['value'] : null, $backup['ok'] ? $backup['value'] : null, $basedir );
		foreach ( array( '_wp_attachment_metadata' => $meta, '_wp_attachment_backup_sizes' => $backup ) as $key => $read ) {
			if ( ! $read['ok'] ) {
				$list['problems'][] = array(
					'path'    => '',
					'role'    => $key,
					'problem' => 'php_object' === $read['why'] ? 'php_object' : 'unreadable',
				);
			}
		}
		return self::facts( $list, $basedir );
	}

	/**
	 * Size and SHA-256 of each listed file, after the rules for an existing
	 * source: resolved under the resolved basedir, no link on the way, a
	 * regular file.
	 *
	 * @param array  $list    Answer of paths().
	 * @param string $basedir Uploads folder.
	 * @return array{files:array,problems:array}
	 */
	public static function facts( array $list, $basedir ) {
		$root  = realpath( (string) $basedir );
		$files = array();
		foreach ( $list['files'] as $entry ) {
			$why = false === $root ? 'missing' : self::source_problem( $root, $entry['path'] );
			if ( '' !== $why ) {
				$list['problems'][] = array(
					'path'    => $entry['path'],
					'role'    => implode( ',', $entry['roles'] ),
					'problem' => $why,
				);
				continue;
			}
			$full    = $root . '/' . $entry['path'];
			$size    = filesize( $full );
			$hash    = hash_file( 'sha256', $full );
			$files[] = $entry + array(
				'size'   => false === $size ? null : (int) $size,
				'sha256' => false === $hash ? null : $hash,
			);
		}
		$list['files'] = $files;
		return $list;
	}

	/**
	 * Lock the attachment of an accumulator for the problems of its list.
	 *
	 * @param array $acc  Accumulator.
	 * @param array $list Answer of files().
	 */
	public static function note( array &$acc, array $list ) {
		foreach ( $list['problems'] as $problem ) {
			$ops = array(
				'missing'    => 'missing_file',
				'unreadable' => 'unreadable_value',
				'php_object' => 'php_object',
			);
			$op  = isset( $ops[ $problem['problem'] ] ) ? $ops[ $problem['problem'] ] : 'unsafe_path';
			AB_MCP_Sync_Canon::lock( $acc, 'attachment', $op, 'file:' . $problem['path'] );
		}
	}

	/**
	 * Whether a relative path keeps the rules of a path in a list: not
	 * empty, relative, no "..", no NUL, backslash or control character.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function safe_path( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' === $path[0] || preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) || preg_match( '|^[A-Za-z]:|', $path ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The attachments whose lists name each path, for a page of the
	 * attachments of this site.
	 *
	 * @param int $after Attachment id the page before ended with.
	 * @param int $limit Attachments at most (AB_MCP_Sync_Rows::page_size()).
	 * @return array{paths:array<string,int[]>,cursor:int|null,complete:bool,unreadable:int[]}|null
	 *         complete is false when a list could not be read in full (an
	 *         object, a value that does not unpack, the uploads folder not
	 *         known): those attachments are in unreadable and name no path.
	 */
	public static function reverse( $after, $limit ) {
		$limit = AB_MCP_Sync_Rows::page_size( $limit );
		$ids   = AB_MCP_Sync_Rows::post_ids( array( 'attachment' ), (int) $after, $limit );
		if ( null === $ids ) {
			return null;
		}
		$basedir    = AB_MCP_Sync_Safe::upload_basedir();
		$paths      = array();
		$unreadable = array();
		$last       = null;
		foreach ( $ids as $row ) {
			$last = $row['ID'];
			if ( null === $basedir ) {
				$unreadable[] = $row['ID'];
				continue;
			}
			$raw = array();
			foreach ( self::META_KEYS as $key ) {
				$values = AB_MCP_Sync_Rows::meta_values( 'post', $row['ID'], $key );
				if ( null === $values ) {
					return null;
				}
				$raw[ $key ] = array() === $values ? '' : $values[0];
			}
			$meta   = AB_MCP_Sync_Canon::unserialized( $raw['_wp_attachment_metadata'] );
			$backup = AB_MCP_Sync_Canon::unserialized( $raw['_wp_attachment_backup_sizes'] );
			// A list that cannot be read in full names no paths: the map is
			// incomplete, and the attachment is named so that it locks.
			if ( ! $meta['ok'] || ! $backup['ok'] || AB_MCP_Sync_Canon::holds_record( $raw['_wp_attached_file'] ) ) {
				$unreadable[] = $row['ID'];
				continue;
			}
			$list = self::paths( $raw['_wp_attached_file'], $meta['value'], $backup['value'], $basedir );
			foreach ( $list['files'] as $entry ) {
				$paths[ $entry['path'] ][] = $row['ID'];
			}
		}
		return array(
			'paths'      => $paths,
			'cursor'     => count( $ids ) < $limit ? null : $last,
			'complete'   => array() === $unreadable,
			'unreadable' => $unreadable,
		);
	}

	/**
	 * The paths of a reverse map that more than one attachment names.
	 *
	 * @param array<string,int[]> $paths Path => attachments.
	 * @return array<string,int[]>
	 */
	public static function shared( array $paths ) {
		$out = array();
		foreach ( $paths as $path => $ids ) {
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			if ( count( $ids ) > 1 ) {
				sort( $ids );
				$out[ (string) $path ] = $ids;
			}
		}
		return $out;
	}

	/**
	 * Lock an attachment for each of its paths another attachment names.
	 *
	 * @param array               $acc   Accumulator.
	 * @param int                 $id    The attachment.
	 * @param array               $list  Its list (files()).
	 * @param array<string,int[]> $paths      Reverse map of the site.
	 * @param int[]               $unreadable The attachments the map could not read.
	 */
	public static function note_shared( array &$acc, $id, array $list, array $paths, array $unreadable = array() ) {
		// An attachment whose list the reverse map could not read: whether it
		// shares a path is not known, it locks.
		if ( in_array( (int) $id, array_map( 'intval', $unreadable ), true ) ) {
			AB_MCP_Sync_Canon::lock( $acc, 'attachment', 'unreadable_value', 'files' );
		}
		foreach ( $list['files'] as $entry ) {
			$ids = isset( $paths[ $entry['path'] ] ) ? array_values( array_diff( array_map( 'intval', $paths[ $entry['path'] ] ), array( (int) $id ) ) ) : array();
			if ( array() !== $ids ) {
				AB_MCP_Sync_Canon::lock( $acc, 'attachment', 'shared_path', 'file:' . $entry['path'] );
			}
		}
	}

	/* ---------------------------------------------------------- private */

	/**
	 * @param string $text Part of a path.
	 * @return bool Whether it holds a NUL, a control character or a backslash.
	 */
	private static function bad_bytes( $text ) {
		return 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', (string) $text );
	}

	/**
	 * One file of the list, relative to basedir, once per path with all its
	 * roles.
	 *
	 * @param array  $files    Files by path.
	 * @param array  $problems Problems.
	 * @param string $full     Path as the core works it out.
	 * @param string $role     Role.
	 * @param string $basedir  Uploads folder.
	 */
	private static function add( array &$files, array &$problems, $full, $role, $basedir ) {
		$full = (string) $full;
		$rel  = '';
		if ( '' !== $basedir && 0 === strpos( $full, $basedir . '/' ) ) {
			$rel = substr( $full, strlen( $basedir ) + 1 );
			// path_join( '.', … ) of a backup in the uploads folder itself.
			while ( 0 === strpos( $rel, './' ) ) {
				$rel = substr( $rel, 2 );
			}
		}
		if ( ! self::safe_path( $rel ) ) {
			$problems[] = array(
				'path'    => '' === $rel ? $full : $rel,
				'role'    => $role,
				'problem' => 'unsafe',
			);
			return;
		}
		if ( ! isset( $files[ $rel ] ) ) {
			$files[ $rel ] = array(
				'path'  => $rel,
				'roles' => array(),
			);
		}
		$files[ $rel ]['roles'][] = $role;
	}

	/**
	 * Why an existing source breaks the rules: '' when it does not, else
	 * missing, link or not_file.
	 *
	 * @param string $root Resolved basedir.
	 * @param string $rel  Relative path (safe_path()).
	 * @return string
	 */
	private static function source_problem( $root, $rel ) {
		$at = $root;
		foreach ( explode( '/', $rel ) as $segment ) {
			$at .= '/' . $segment;
			if ( is_link( $at ) ) {
				return 'link';
			}
			if ( ! file_exists( $at ) ) {
				return 'missing';
			}
		}
		$real = realpath( $at );
		if ( false === $real || $real !== $root . '/' . $rel ) {
			return 'link';
		}
		return 'file' === filetype( $real ) ? '' : 'not_file';
	}
}
