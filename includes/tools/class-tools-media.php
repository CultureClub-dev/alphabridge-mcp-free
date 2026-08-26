<?php
/**
 * Media library tools.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Media
 */
class AB_MCP_Tools_Media extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_media',
			array(
				'description' => 'List media library attachments with pagination.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'mime'     => array( 'type' => 'string', 'description' => 'e.g. image, image/png, application/pdf.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Max 100. Default 20.' ),
						'page'     => array( 'type' => 'integer' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_media' ),
			)
		);

		$r->register(
			'wp_get_media',
			array(
				'description' => 'Get a single attachment with URL, sizes, alt text and metadata.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'get_media' ),
			)
		);

		$r->register(
			'wp_upload_media_from_url',
			array(
				'description' => 'Download a file from a URL and add it to the media library.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'      => array( 'type' => 'string', 'description' => 'Public URL of the file.' ),
						'title'    => array( 'type' => 'string' ),
						'alt'      => array( 'type' => 'string' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Attach to this post id.' ),
					),
				),
				'callback'    => array( __CLASS__, 'upload_from_url' ),
			)
		);

		$r->register(
			'wp_upload_media',
			array(
				'description' => 'Upload a local image or PDF to the media library from base64-encoded content.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'filename', 'content' ),
					'properties' => array(
						'filename' => array( 'type' => 'string', 'description' => 'File name with extension, e.g. "photo.jpg".' ),
						'content'  => array( 'type' => 'string', 'description' => 'Base64-encoded file bytes (JPEG, PNG, GIF, WebP or PDF).' ),
						'title'    => array( 'type' => 'string' ),
						'alt'      => array( 'type' => 'string' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Attach to this post id.' ),
					),
				),
				'callback'    => array( __CLASS__, 'upload_base64' ),
			)
		);

		$r->register(
			'wp_update_media',
			array(
				'description' => 'Update an attachment title, caption and alt text.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'caption' => array( 'type' => 'string' ),
						'alt'     => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_media' ),
			)
		);

		$r->register(
			'wp_delete_media',
			array(
				'description' => 'Delete an attachment from the media library.',
				'capability'  => 'delete_posts',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'delete_media' ),
			)
		);
	}

	/* --------------------------------------------------------------- handlers */

	/**
	 * List media.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function list_media( $a ) {
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$args     = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => self::s( $a, 'search', '' ),
			'posts_per_page' => $per_page,
			'paged'          => max( 1, self::i( $a, 'page', 1 ) ),
		);
		$mime = self::s( $a, 'mime', '' );
		if ( $mime ) {
			$args['post_mime_type'] = $mime;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			// Only expose attachments the current user may read.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'mime'  => $post->post_mime_type,
				'url'   => wp_get_attachment_url( $post->ID ),
				'date'  => $post->post_date_gmt,
			);
		}

		return array(
			'items'      => $items,
			'pagination' => array(
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			),
		);
	}

	/**
	 * Get media.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_media( $a ) {
		$id  = self::i( $a, 'id' );
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this attachment.', 'alphabridge-mcp' ) );
		}
		return array(
			'id'       => $id,
			'title'    => get_the_title( $att ),
			'mime'     => $att->post_mime_type,
			'url'      => wp_get_attachment_url( $id ),
			'alt'      => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'  => $att->post_excerpt,
			'metadata' => wp_get_attachment_metadata( $id ),
		);
	}

	/**
	 * Upload from URL.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function upload_from_url( $a ) {
		$need = self::need( $a, array( 'url' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		// Permission check first: never download anything for an upload we would
		// reject because the caller may not edit the target post.
		$post_id = self::i( $a, 'post_id', 0 );
		if ( $post_id > 0 && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot attach media to that post.', 'alphabridge-mcp' ) );
		}
		$url = esc_url_raw( self::s( $a, 'url' ) );
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'ab_mcp_bad_url', __( 'Only http(s) URLs are allowed.', 'alphabridge-mcp' ) );
		}
		// SSRF guard: reject loopback / private / link-local hosts (blocks cloud
		// metadata endpoints and internal services). wp_http_validate_url() is
		// WordPress core's own check for safe outbound request targets.
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'ab_mcp_blocked_url', __( 'This URL points to a private or disallowed host and was blocked.', 'alphabridge-mcp' ) );
		}

		// Fetch with wp_safe_remote_get: it sets reject_unsafe_urls, so EVERY redirect
		// hop is re-validated against wp_http_validate_url (download_url does not do
		// this, which would let an allowed URL redirect to an internal address).
		$max_bytes = (int) apply_filters( 'ab_mcp_upload_max_bytes', 20 * MB_IN_BYTES );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => $max_bytes,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			return new WP_Error(
				'ab_mcp_fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Download failed (HTTP %d).', 'alphabridge-mcp' ),
					$http_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'ab_mcp_empty', __( 'The URL returned no content.', 'alphabridge-mcp' ) );
		}
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'ab_mcp_too_large', __( 'The remote file exceeds the allowed size.', 'alphabridge-mcp' ) );
		}

		// Reject clearly dangerous content types outright (HTML/SVG/scripts).
		$ctype = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$ctype = preg_replace( '/;.*$/', '', $ctype );
		$deny  = array( 'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml', 'application/javascript', 'text/javascript' );
		if ( in_array( $ctype, $deny, true ) ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'The server returned a disallowed content type (e.g. HTML or SVG).', 'alphabridge-mcp' ) );
		}

		// Resolve a filename and require an allow-listed image/PDF extension.
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name ) {
			$name = 'upload-' . time();
		}
		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );
		$ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			// No usable extension in the URL: derive one from a safe content type.
			$ctype_ext = array(
				'image/jpeg'      => 'jpg',
				'image/png'       => 'png',
				'image/gif'       => 'gif',
				'image/webp'      => 'webp',
				'application/pdf' => 'pdf',
			);
			if ( isset( $ctype_ext[ $ctype ] ) ) {
				$name .= '.' . $ctype_ext[ $ctype ];
			} else {
				return new WP_Error( 'ab_mcp_bad_type', __( 'Only JPEG, PNG, GIF, WebP and PDF files can be sideloaded from a URL.', 'alphabridge-mcp' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $name );
		if ( ! $tmp ) {
			return new WP_Error( 'ab_mcp_tmp', __( 'Could not create a temporary file.', 'alphabridge-mcp' ) );
		}
		if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return new WP_Error( 'ab_mcp_write_failed', __( 'Could not write the downloaded file.', 'alphabridge-mcp' ) );
		}

		$file_array = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, $post_id, self::s( $a, 'title', '' ) );

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}

		if ( self::s( $a, 'alt', '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( self::s( $a, 'alt' ) ) );
		}

		return array(
			'uploaded' => true,
			'id'       => (int) $id,
			'url'      => wp_get_attachment_url( $id ),
		);
	}

	/**
	 * Upload a local image/PDF supplied as base64 content.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function upload_base64( $a ) {
		$need = self::need( $a, array( 'filename', 'content' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}

		// Permission check first: never decode/write anything for an upload we
		// would reject because the caller may not edit the target post.
		$post_id = self::i( $a, 'post_id', 0 );
		if ( $post_id > 0 && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot attach media to that post.', 'alphabridge-mcp' ) );
		}

		$name = sanitize_file_name( self::s( $a, 'filename' ) );
		if ( '' === $name ) {
			return new WP_Error( 'ab_mcp_bad_name', __( 'A valid filename is required.', 'alphabridge-mcp' ) );
		}
		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );
		$ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'Only JPEG, PNG, GIF, WebP and PDF files can be uploaded.', 'alphabridge-mcp' ) );
		}

		$b64 = self::s( $a, 'content' );
		// Tolerate a data: URI prefix (e.g. "data:image/png;base64,....").
		if ( 0 === strpos( $b64, 'data:' ) && false !== strpos( $b64, ',' ) ) {
			$b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
		}
		$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'ab_mcp_bad_content', __( 'Content is not valid base64.', 'alphabridge-mcp' ) );
		}
		if ( strlen( $bytes ) > (int) apply_filters( 'ab_mcp_upload_max_bytes', 20 * MB_IN_BYTES ) ) {
			return new WP_Error( 'ab_mcp_too_large', __( 'The file exceeds the allowed size.', 'alphabridge-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $name );
		if ( ! $tmp ) {
			return new WP_Error( 'ab_mcp_tmp', __( 'Could not create a temporary file.', 'alphabridge-mcp' ) );
		}
		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return new WP_Error( 'ab_mcp_write_failed', __( 'Could not write the file.', 'alphabridge-mcp' ) );
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$id         = media_handle_sideload( $file_array, $post_id, self::s( $a, 'title', '' ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( self::s( $a, 'alt', '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( self::s( $a, 'alt' ) ) );
		}
		return array(
			'uploaded' => true,
			'id'       => (int) $id,
			'url'      => wp_get_attachment_url( $id ),
		);
	}

	/**
	 * Update media.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_media( $a ) {
		$id  = self::i( $a, 'id' );
		$att = get_post( $id );
		if ( ! $att || 'attachment' !== $att->post_type ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot edit this attachment.', 'alphabridge-mcp' ) );
		}
		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $a ) ) {
			$update['post_title'] = self::s( $a, 'title' );
		}
		if ( array_key_exists( 'caption', $a ) ) {
			$update['post_excerpt'] = self::s( $a, 'caption' );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( array_key_exists( 'alt', $a ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( self::s( $a, 'alt' ) ) );
		}
		return array(
			'updated' => true,
			'id'      => $id,
		);
	}

	/**
	 * Delete media.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_media( $a ) {
		$id = self::i( $a, 'id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot delete this attachment.', 'alphabridge-mcp' ) );
		}
		$res = wp_delete_attachment( $id, true );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}
}
