<?php
/**
 * What storing a file in the media library needs beyond the bootstrap, as
 * WordPress does it in the parts these tests look at: a temporary file,
 * media_handle_sideload() (recorded, with an id per call), the image editor
 * (a test sets one through $ab_test_editor), the attached file of an
 * attachment and attachment_url_to_postid() from what a test sets.
 *
 * Loaded by tests/wp-admin/includes/{file,media,image}.php, which the upload
 * tools require, and by the tests that use it directly.
 *
 * @package AlphaBridge_MCP
 */

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		$path = tempnam( sys_get_temp_dir(), 'abmcp' );
		$GLOBALS['ab_test_tempfiles'][] = $path;
		return $path;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		@unlink( $file );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		return (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $name );
	}
}

/**
 * Records what it was handed and gives the next id; a test makes it fail with
 * $ab_test_sideload_error.
 */
if ( ! function_exists( 'media_handle_sideload' ) ) {
	function media_handle_sideload( $file_array, $post_id = 0, $desc = null ) {
		if ( isset( $GLOBALS['ab_test_sideload_error'] ) && $GLOBALS['ab_test_sideload_error'] ) {
			return new WP_Error( 'upload_error', 'Sorry, you are not allowed to upload this file type.' );
		}
		$id = 900 + count( $GLOBALS['ab_test_sideloads'] ?? array() );
		$GLOBALS['ab_test_sideloads'][] = array(
			'id'      => $id,
			'name'    => $file_array['name'],
			'bytes'   => (string) file_get_contents( $file_array['tmp_name'] ),
			'post_id' => (int) $post_id,
			'title'   => (string) $desc,
		);
		return $id;
	}
}

if ( ! function_exists( 'wp_get_image_editor' ) ) {
	function wp_get_image_editor( $path, $args = array() ) {
		$make = $GLOBALS['ab_test_editor'] ?? null;
		return is_callable( $make ) ? $make( $path ) : new WP_Error( 'image_no_editor', 'No editor could be selected.' );
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) {
		return $GLOBALS['ab_test_attached_files'][ (int) $id ] ?? false;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( $url ) {
		return (int) ( $GLOBALS['ab_test_url_ids'][ (string) $url ] ?? 0 );
	}
}
