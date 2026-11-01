<?php
/**
 * What the unit tests of the canonical form need beyond the bootstrap: four
 * path functions of WordPress 7.1.3, each copied unchanged below its line
 * note (wp_basename() from wp-includes/formatting.php, the others from
 * wp-includes/functions.php): wp_basename() for AB_MCP_Sync_Media::paths(),
 * path_join() with what it calls for the test that no stored name reaches
 * a stream wrapper (a mutation puts path_join() back in paths()). Whether
 * the list is what the core deletes is shown against the real core in the
 * integration suite (SyncMediaListTest).
 *
 * WordPress is free software, licensed under the GNU General Public License,
 * version 2 or later (GPL-2.0-or-later), like this plugin. Copyright WordPress
 * contributors, https://wordpress.org/about/license/. Not shipped: tests/ is
 * export-ignored and listed in .distignore.
 *
 * @package AlphaBridge_MCP
 */

// wp-includes/formatting.php line 5758 (WordPress 7.1.3).
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}
}

// wp-includes/functions.php line 7501 (WordPress 7.1.3).
if ( ! function_exists( 'wp_is_stream' ) ) {
	function wp_is_stream( $path ) {
		$scheme_separator = strpos( $path, '://' );

		if ( false === $scheme_separator ) {
			// $path isn't a stream.
			return false;
		}

		$stream = substr( $path, 0, $scheme_separator );

		return in_array( $stream, stream_get_wrappers(), true );
	}
}

// wp-includes/functions.php lines 2127-2157 (WordPress 7.1.3).
if ( ! function_exists( 'path_is_absolute' ) ) {
	function path_is_absolute( $path ) {
		/*
		 * Check to see if the path is a stream and check to see if its an actual
		 * path or file as realpath() does not support stream wrappers.
		 */
		if ( wp_is_stream( $path ) && ( is_dir( $path ) || is_file( $path ) ) ) {
			return true;
		}

		/*
		 * This is definitive if true but fails if $path does not exist or contains
		 * a symbolic link.
		 */
		if ( realpath( $path ) === $path ) {
			return true;
		}

		if ( strlen( $path ) === 0 || '.' === $path[0] ) {
			return false;
		}

		// Windows allows absolute paths like this.
		if ( preg_match( '#^[a-zA-Z]:\\\\#', $path ) ) {
			return true;
		}

		// A path starting with / or \ is absolute; anything else is relative.
		return ( '/' === $path[0] || '\\' === $path[0] );
	}
}

// wp-includes/functions.php lines 2169-2175 (WordPress 7.1.3).
if ( ! function_exists( 'path_join' ) ) {
	function path_join( $base, $path ) {
		if ( path_is_absolute( $path ) ) {
			return $path;
		}

		return rtrim( $base, '/' ) . '/' . $path;
	}
}
