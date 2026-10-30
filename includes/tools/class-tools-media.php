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
	 * Post meta of an attachment AlphaBridge added (since 4.12.0): the SHA-256
	 * of the bytes it received, so the same file is not stored twice and
	 * wp_find_media finds it by content.
	 */
	const HASH_META = '_ab_mcp_sha256';

	/** Pages of matches wp_find_media checks at most for files the account may read. */
	const FIND_PAGES = 50;

	/** File types the upload tools accept, by extension. */
	const UPLOAD_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );

	/** Extension for a safe content type, where the name carries none. */
	const TYPE_EXTENSIONS = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/gif'       => 'gif',
		'image/webp'      => 'webp',
		'application/pdf' => 'pdf',
	);

	/**
	 * Images the optional max_size shrinks, with the type they are saved in
	 * again (the temporary file's name ends in .tmp, from which the editor
	 * would fall back to JPEG); a GIF keeps its frames and is left out.
	 */
	const SHRINK_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/**
	 * The two arguments both upload tools share: no second copy of the same
	 * file, and an optional longest edge for an image.
	 *
	 * @return array
	 */
	private static function upload_options_schema() {
		return array(
			'allow_duplicate' => array( 'type' => 'boolean', 'description' => 'Whether a file already in the media library (same SHA-256) is added once more. Default false: the answer then names the file that is there, with duplicate: true, and nothing new is added.' ),
			'max_size'        => array( 'type' => 'integer', 'description' => 'Longest edge in pixels for a JPEG, PNG or WebP image; a larger one is scaled down before it is stored (100–10000). Default: as WordPress does it, which scales images above 2560 pixels on its own.' ),
		);
	}

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_media',
			array(
				'description' => 'List files in the media library (attachments), newest first, or by relevance when search is given. Returns items: [{id, title, mime, url, date}] and pagination {total, total_pages}. Only files with the usual status "inherit" are listed, so files set to private are not, and only those the account may read. No match gives an empty list. Needs upload_files. Read-only; wp_get_media returns one file with its sizes, alt text and caption.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Search text, matched against title, caption and description.' ),
						'mime'     => array( 'type' => 'string', 'description' => 'MIME type or its main part, e.g. "image", "image/png" or "application/pdf". Default: all types.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Files per page, 1–100; other values are clamped. Default 20.', 'default' => 20 ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.', 'default' => 1 ),
					),
				),
				'callback'    => array( __CLASS__, 'list_media' ),
			)
		);

		$r->register(
			'wp_get_media',
			array(
				'description' => 'Get one file of the media library (attachment) by id. Returns {id, title, mime, url, alt, caption, metadata}; metadata is WordPress\' attachment metadata (for an image: width, height, file and the generated sizes). The caption of a password-protected file is empty for an account that may not edit it. An id that is not a media file (a post, say) returns a not-found error: posts and pages are read by wp_get_post, and wp_list_media lists the media with their ids. Needs the right to read the file.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Attachment id, as wp_list_media returns it.' ) ),
				),
				'callback'    => array( __CLASS__, 'get_media' ),
			)
		);

		$r->register(
			'wp_find_media',
			array(
				'description' => 'Find files in the media library by content, address or name: sha256 (the SHA-256 of the file; files AlphaBridge added since 4.12.0 carry it), url (the address of the file or of one of its image sizes), filename (part of the stored file name) or search (title, caption, description). At least one of them; several narrow the result. Returns items [{id, title, mime, url, date, sha256}], sha256 null for a file without it. Only files with the usual status "inherit" that the account may read. No match gives an empty list. Needs upload_files. Read-only; wp_get_media returns one file with its sizes, alt text and caption.',
				'capability'  => 'upload_files',
				'readonly'    => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sha256'   => array( 'type' => 'string', 'description' => 'SHA-256 of the file, 64 hexadecimal characters.' ),
						'url'      => array( 'type' => 'string', 'description' => 'Address of the file or of one of its image sizes on this site.' ),
						'filename' => array( 'type' => 'string', 'description' => 'Part of the stored file name, e.g. "logo" or "logo.png".' ),
						'search'   => array( 'type' => 'string', 'description' => 'Search text, matched against title, caption and description.' ),
						'mime'     => array( 'type' => 'string', 'description' => 'MIME type or its main part, e.g. "image" or "application/pdf". Default: all types.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Files at most, 1–100; other values are clamped. Default 20.', 'default' => 20 ),
					),
				),
				'callback'    => array( __CLASS__, 'find_media' ),
			)
		);

		$r->register(
			'wp_upload_media_from_url',
			array(
				'description' => 'Download a file from a public http(s) URL and add it to the media library; the file is public under its new URL afterwards. A file that is already there (same SHA-256 as one AlphaBridge added) is not added again: the answer names it with duplicate: true, and allow_duplicate: true adds a copy all the same. The site fetches the file itself through WordPress\' safe-URL check, repeated on every redirect: private and internal hosts are refused unless WordPress allows them (such as the site\'s own host); at most 3 redirects, 20 seconds per request, 20 MB by default (filter ab_mcp_upload_max_bytes). The URL or the content type must name JPEG, PNG, GIF, WebP or PDF; HTML, SVG, XML and script responses are refused, and WordPress then checks the file as for any upload. No third-party API is involved. Returns {uploaded: true, id, url, sha256}. Needs upload_files, and the right to edit post_id when given. File content at hand is uploaded as base64 with wp_upload_media; wp_find_media finds a file by its content, address or name.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'      => array( 'type' => 'string', 'description' => 'Public http(s) URL of the file. Your site downloads it; nothing is sent anywhere else.' ),
						'title'    => array( 'type' => 'string', 'description' => 'Title of the new attachment.' ),
						'alt'      => array( 'type' => 'string', 'description' => 'Alternative text for an image, stored as plain text.' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Id of a post to attach the file to; needs the right to edit that post. Default: attached to no post.' ),
					) + self::upload_options_schema(),
				),
				'callback'    => array( __CLASS__, 'upload_from_url' ),
			)
		);

		$r->register(
			'wp_upload_media',
			array(
				'description' => 'Upload a JPEG, PNG, GIF, WebP or PDF file and add it to the media library: either a file from the chat (file, as ChatGPT hands it over) or the bytes as base64 (filename and content). WordPress checks the file as for any upload and creates the image sizes; the file is public under its URL afterwards. A file that is already there (same SHA-256 as one AlphaBridge added) is not added again: the answer names it with duplicate: true, and allow_duplicate: true adds a copy all the same. A file from the chat is downloaded by the site from its temporary address (20 MB by default, filter ab_mcp_upload_max_bytes); base64 text is limited to 8 MB, so the file to about 6 MB. Returns {uploaded: true, id, url, sha256}. Needs upload_files, and the right to edit post_id when the file is attached to it. A file already on the web is added with wp_upload_media_from_url; wp_find_media finds a file by its content, address or name.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'file'     => array(
							'type'        => 'object',
							'description' => 'A file from the chat, as ChatGPT passes it: its temporary download_url and file_id (mime_type and file_name when known). Instead of filename and content.',
							'required'    => array( 'download_url', 'file_id' ),
							'properties'  => array(
								'download_url' => array( 'type' => 'string', 'description' => 'Temporary address of the file.' ),
								'file_id'      => array( 'type' => 'string', 'description' => 'Id of the file in the chat.' ),
								'mime_type'    => array( 'type' => 'string', 'description' => 'Content type, e.g. "image/png".' ),
								'file_name'    => array( 'type' => 'string', 'description' => 'Original file name, e.g. "photo.png".' ),
							),
						),
						'filename' => array( 'type' => 'string', 'description' => 'File name with extension, e.g. "photo.jpg"; with content.' ),
						'content'  => array( 'type' => 'string', 'description' => 'Base64-encoded file bytes (JPEG, PNG, GIF, WebP or PDF); a data: URI prefix is accepted. Instead of file.' ),
						'title'    => array( 'type' => 'string', 'description' => 'Title of the new attachment.' ),
						'alt'      => array( 'type' => 'string', 'description' => 'Alternative text for an image, stored as plain text.' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Id of a post to attach the file to; needs the right to edit that post. Default: attached to no post.' ),
					) + self::upload_options_schema(),
				),
				// ChatGPT hands a file from the chat to the fields named here
				// (Apps SDK, _meta["openai/fileParams"]).
				'_meta'       => array( 'openai/fileParams' => array( 'file' ) ),
				'callback'    => array( __CLASS__, 'upload_base64' ),
			)
		);

		$r->register(
			'wp_update_media',
			array(
				'description' => 'Change the title, caption or alt text of one file in the media library (attachment); the file itself stays as it is. Only the fields passed change. Alt text is stored as plain text. Returns {updated: true, id}; an id that is not a media file returns a not-found error. Needs the right to edit that file. Title, caption and alt text can be public wherever the file is shown. A new file is added with wp_upload_media; a file\'s description and parent are changed with wp_update_post.',
				'capability'  => 'upload_files',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'Attachment id, as wp_list_media returns it.' ),
						'title'   => array( 'type' => 'string', 'description' => 'New title. When omitted, it stays as it is.' ),
						'caption' => array( 'type' => 'string', 'description' => 'New caption; an empty string clears it. When omitted, it stays as it is.' ),
						'alt'     => array( 'type' => 'string', 'description' => 'New alternative text for an image; an empty string clears it. When omitted, it stays as it is.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_media' ),
			)
		);

		$r->register(
			'wp_delete_media',
			array(
				'description' => 'Delete one file of the media library (attachment) for good: its entry and metadata are removed, and WordPress deletes the file and its image sizes on the server (a file the server does not let it delete stays, without an error). The media library has no trash here. It is removed as featured image wherever it was one; posts that embed or link it keep its old URL. Needs the right to delete that file. Returns {deleted: true, id}; an id that no longer exists returns a not-found error. Posts and pages are deleted with wp_delete_post.',
				'capability'  => 'delete_posts',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Attachment id of the file to delete.' ) ),
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
		// Newest first, as WordPress orders them anyway, with the id breaking a
		// tie (see paged_orderby()). A search keeps WordPress' order by
		// relevance, which it applies only when no orderby is given.
		if ( '' === $args['s'] ) {
			$args['orderby'] = self::paged_orderby( 'date', 'DESC' );
			$args['order']   = 'DESC';
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
				'date'  => self::site_time( $post->post_date_gmt, $post->post_date ),
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found: no file in the media library has this id. Look it up with wp_list_media.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot read this attachment. Connect with an account that may read it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		return array(
			'id'       => $id,
			'title'    => get_the_title( $att ),
			'mime'     => $att->post_mime_type,
			'url'      => wp_get_attachment_url( $id ),
			'alt'      => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			// An attachment can carry its own post password; its caption is text.
			'caption'  => self::raw_content_allowed( $att ) ? $att->post_excerpt : '',
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
			return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot attach media to that post: it does not exist, or your account may not edit it. Leave post_id out to add the file to the media library alone, or pass a post your account may edit.', 'alphabridge-mcp' ) );
		}
		$url = esc_url_raw( self::s( $a, 'url' ) );
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'ab_mcp_bad_url', __( 'Only http(s) URLs are allowed. Pass the file\'s public http:// or https:// address, or send its content with wp_upload_media.', 'alphabridge-mcp' ) );
		}
		// SSRF guard: reject loopback / private / link-local hosts (blocks cloud
		// metadata endpoints and internal services). wp_http_validate_url() is
		// WordPress core's own check for safe outbound request targets.
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'ab_mcp_blocked_url', __( 'This URL points to a private or disallowed host and was blocked: WordPress downloads only from public hosts. Put the file on a public address, or send its content with wp_upload_media.', 'alphabridge-mcp' ) );
		}

		$got = self::fetch( $url );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		// Resolve a filename and require an allow-listed image/PDF extension.
		$name = self::upload_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), $got['type'] );
		if ( '' === $name ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'Only JPEG, PNG, GIF, WebP and PDF files can be sideloaded from a URL: neither the address nor the server named one of these types. Use an address ending in .jpg, .jpeg, .png, .gif, .webp or .pdf, or convert the file to one of these formats.', 'alphabridge-mcp' ) );
		}
		return self::store( $got['body'], $name, $post_id, $a );
	}

	/**
	 * Download a file for the media library from a public address.
	 *
	 * Fetched with wp_safe_remote_get: it sets reject_unsafe_urls, so every
	 * redirect hop is checked again by wp_http_validate_url (download_url
	 * does not do this, which would let an allowed URL redirect to an
	 * internal address).
	 *
	 * @param string $url Address, already checked by wp_http_validate_url.
	 * @return array|WP_Error { body, type } with the content type in lower case.
	 */
	private static function fetch( $url ) {
		$max_bytes = (int) apply_filters( 'ab_mcp_upload_max_bytes', 20 * MB_IN_BYTES );

		// One byte more than allowed: the transport cuts the body off at its
		// limit, so a file cut at exactly the maximum would look like one that
		// fits and be stored broken. With the extra byte, an oversized file
		// arrives longer than the maximum and is refused below.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => $max_bytes + 1,
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
					__( 'Download failed (HTTP %d). Check that the URL opens the file itself, without a login, or send the content with wp_upload_media.', 'alphabridge-mcp' ),
					$http_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'ab_mcp_empty', __( 'The URL returned no content. Check that it opens the file itself, or send the content with wp_upload_media.', 'alphabridge-mcp' ) );
		}
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'ab_mcp_too_large', __( 'The remote file exceeds the allowed size (20 MB unless the site sets another limit with the ab_mcp_upload_max_bytes filter). Use a smaller file, or ask the site administrator to raise the limit.', 'alphabridge-mcp' ) );
		}

		// Reject clearly dangerous content types outright (HTML/SVG/scripts).
		$ctype = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$ctype = (string) preg_replace( '/;.*$/', '', $ctype );
		$deny  = array( 'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml', 'application/javascript', 'text/javascript' );
		if ( in_array( $ctype, $deny, true ) ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'The server returned a disallowed content type (e.g. HTML or SVG); often the URL opens a page about the file rather than the file. Use the direct address of a JPEG, PNG, GIF, WebP or PDF file.', 'alphabridge-mcp' ) );
		}
		return array(
			'body' => $body,
			'type' => $ctype,
		);
	}

	/**
	 * A file name with an allowed extension: the name as given when it has
	 * one, else the name with the extension of a safe content type; '' when
	 * neither names an allowed type.
	 *
	 * @param string $name  Name from the address or the chat.
	 * @param string $ctype Content type, lower case ('' when unknown).
	 * @return string
	 */
	private static function upload_name( $name, $ctype ) {
		$name = '' !== $name ? $name : 'upload-' . time();
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::UPLOAD_EXTENSIONS, true ) ) {
			return $name;
		}
		return isset( self::TYPE_EXTENSIONS[ $ctype ] ) ? $name . '.' . self::TYPE_EXTENSIONS[ $ctype ] : '';
	}

	/**
	 * Store received bytes in the media library: not a second time where the
	 * same file is there (unless allow_duplicate), an image scaled down to
	 * max_size when asked, the SHA-256 kept with the attachment.
	 *
	 * The hash is the one of the bytes as received, before any scaling, so
	 * the same source file is recognised whatever max_size said.
	 *
	 * @param string $bytes   File content.
	 * @param string $name    File name with an allowed extension.
	 * @param int    $post_id Parent post, 0 for none (checked by the caller).
	 * @param array  $a       Arguments of the call (title, alt, allow_duplicate, max_size).
	 * @return array|WP_Error
	 */
	private static function store( $bytes, $name, $post_id, $a ) {
		$hash = hash( 'sha256', $bytes );
		if ( ! self::b( $a, 'allow_duplicate', false ) ) {
			$there = self::same_file( $hash );
			if ( $there > 0 ) {
				return array(
					'uploaded'  => false,
					'duplicate' => true,
					'id'        => $there,
					'url'       => wp_get_attachment_url( $there ),
					'sha256'    => $hash,
					'note'      => __( 'This file is already in the media library (same SHA-256), so nothing new was added; its title, alt text and post stay as they are. wp_update_media changes them; allow_duplicate: true adds a second copy.', 'alphabridge-mcp' ),
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $name );
		if ( ! $tmp ) {
			return new WP_Error( 'ab_mcp_tmp', __( 'Could not create a temporary file, so nothing was uploaded; the server\'s temporary folder is likely not writable. The site administrator or host can fix that (WordPress uses WP_TEMP_DIR or the system\'s temporary folder).', 'alphabridge-mcp' ) );
		}
		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp );
			return new WP_Error( 'ab_mcp_write_failed', __( 'Could not write the file, so nothing was uploaded; the server\'s temporary folder is likely full or not writable. The site administrator or host can fix that.', 'alphabridge-mcp' ) );
		}

		$shrunk = self::shrink( $tmp, $name, self::i( $a, 'max_size', 0 ) );
		$tmp    = $shrunk['path'];

		$file_array = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);
		$id         = media_handle_sideload( $file_array, $post_id, self::s( $a, 'title', '' ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( self::s( $a, 'alt', '' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( self::s( $a, 'alt' ) ) ) );
		}
		update_post_meta( $id, self::HASH_META, $hash );

		$out = array(
			'uploaded' => true,
			'id'       => (int) $id,
			'url'      => wp_get_attachment_url( $id ),
			'sha256'   => $hash,
		);
		if ( isset( $shrunk['resized'] ) ) {
			$out['resized'] = $shrunk['resized'];
		}
		if ( isset( $shrunk['warning'] ) ) {
			$out['warning'] = $shrunk['warning'];
		}
		return $out;
	}

	/**
	 * The attachment that holds the same file: one AlphaBridge added with
	 * this SHA-256, that the account may read and whose file is still on
	 * disk. 0 when there is none.
	 *
	 * @param string $hash SHA-256, lower case.
	 * @return int
	 */
	private static function same_file( $hash ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per upload.
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one lookup per upload.
				// Every copy with this hash, not the first few: one whose file
				// is gone or that the account may not read must not hide a
				// later one that counts. Copies of one file are few.
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $query->posts as $post ) {
			$id   = is_object( $post ) ? (int) $post->ID : (int) $post;
			$file = get_attached_file( $id );
			if ( current_user_can( 'read_post', $id ) && is_string( $file ) && '' !== $file && file_exists( $file ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Scale an image down to max_size pixels on its longest edge, in place
	 * of the temporary file. Nothing for a PDF, a GIF (its frames would be
	 * lost) or an image that fits. Where the image editor cannot do it, the
	 * file stays as it came, with a warning.
	 *
	 * @param string $tmp  Temporary file.
	 * @param string $name File name, for its extension.
	 * @param int    $max  Longest edge; 0 for none, else clamped to 100–10000.
	 * @return array { path[, resized: {from, to}][, warning] }
	 */
	private static function shrink( $tmp, $name, $max ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( $max <= 0 || ! isset( self::SHRINK_TYPES[ $ext ] ) ) {
			return array( 'path' => $tmp );
		}
		$max    = self::clamp( $max, 100, 10000 );
		$editor = wp_get_image_editor( $tmp );
		$size   = is_wp_error( $editor ) ? null : $editor->get_size();
		if ( ! is_array( $size ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
			return array(
				'path'    => $tmp,
				'warning' => __( 'max_size was not applied: the server\'s image editor cannot read this image, so it is stored at its own size. WordPress still scales images above 2560 pixels.', 'alphabridge-mcp' ),
			);
		}
		$from = array( (int) $size['width'], (int) $size['height'] );
		if ( max( $from ) <= $max ) {
			return array( 'path' => $tmp );
		}
		// GD writes the pixels without the EXIF data, so a phone photo stored
		// sideways would stay sideways: turn it upright first, as
		// wp_create_image_subsizes() does (Codex on #104, 30.10.2026).
		// An error leaves the image half turned: then nothing is saved, as in
		// core, and the file is stored as it came.
		$turned = method_exists( $editor, 'maybe_exif_rotate' ) ? $editor->maybe_exif_rotate() : false;
		if ( is_wp_error( $turned ) ) {
			return array(
				'path'    => $tmp,
				'warning' => __( 'max_size was not applied: the server\'s image editor could not scale this image, so it is stored at its own size. WordPress still scales images above 2560 pixels.', 'alphabridge-mcp' ),
			);
		}
		if ( true === $turned ) {
			$size = $editor->get_size();
			$from = array( (int) $size['width'], (int) $size['height'] );
		}
		$done  = $editor->resize( $max, $max, false );
		$saved = is_wp_error( $done ) ? $done : $editor->save( $tmp, self::SHRINK_TYPES[ $ext ] );
		if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) ) {
			return array(
				'path'    => $tmp,
				'warning' => __( 'max_size was not applied: the server\'s image editor could not scale this image, so it is stored at its own size. WordPress still scales images above 2560 pixels.', 'alphabridge-mcp' ),
			);
		}
		// The editor may write under another extension than the temporary
		// file's; the scaled copy is the one to store.
		if ( $saved['path'] !== $tmp ) {
			wp_delete_file( $tmp );
		}
		return array(
			'path'    => (string) $saved['path'],
			'resized' => array(
				'from' => $from,
				'to'   => array( (int) $saved['width'], (int) $saved['height'] ),
			),
		);
	}

	/**
	 * Upload a local image/PDF supplied as base64 content.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function upload_base64( $a ) {
		$file = isset( $a['file'] ) ? $a['file'] : null;
		if ( null !== $file && ( isset( $a['content'] ) && '' !== self::s( $a, 'content' ) ) ) {
			return new WP_Error( 'ab_mcp_bad_args', __( 'Pass either file (a file from the chat) or filename and content (base64), not both.', 'alphabridge-mcp' ) );
		}
		if ( null === $file ) {
			$need = self::need( $a, array( 'filename', 'content' ) );
			if ( is_wp_error( $need ) ) {
				return $need;
			}
		} elseif ( ! is_array( $file ) || '' === self::s( $file, 'download_url' ) || '' === self::s( $file, 'file_id' ) ) {
			return new WP_Error( 'ab_mcp_bad_args', __( 'file needs the download_url and the file_id of the file from the chat, as ChatGPT passes them. Pass file as it came, or send the bytes as base64 in content with a filename.', 'alphabridge-mcp' ) );
		}

		// Permission check first: never decode, download or write anything for
		// an upload we would reject because the caller may not edit the target post.
		$post_id = self::i( $a, 'post_id', 0 );
		if ( $post_id > 0 && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot attach media to that post: it does not exist, or your account may not edit it. Leave post_id out to add the file to the media library alone, or pass a post your account may edit.', 'alphabridge-mcp' ) );
		}

		if ( null !== $file ) {
			return self::upload_chat_file( $file, $post_id, $a );
		}

		$name = sanitize_file_name( self::s( $a, 'filename' ) );
		if ( '' === $name ) {
			return new WP_Error( 'ab_mcp_bad_name', __( 'A valid filename is required: pass a name with its extension, such as "photo.jpg".', 'alphabridge-mcp' ) );
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::UPLOAD_EXTENSIONS, true ) ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'Only JPEG, PNG, GIF, WebP and PDF files can be uploaded: the filename must end in .jpg, .jpeg, .png, .gif, .webp or .pdf. Convert the file to one of these formats.', 'alphabridge-mcp' ) );
		}

		$b64 = self::s( $a, 'content' );
		// Tolerate a data: URI prefix (e.g. "data:image/png;base64,....").
		if ( 0 === strpos( $b64, 'data:' ) && false !== strpos( $b64, ',' ) ) {
			$b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
		}
		$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'ab_mcp_bad_content', __( 'Content is not valid base64. Pass the file\'s bytes base64-encoded (a data: URI prefix is allowed), or upload it from a public address with wp_upload_media_from_url.', 'alphabridge-mcp' ) );
		}
		if ( strlen( $bytes ) > (int) apply_filters( 'ab_mcp_upload_max_bytes', 20 * MB_IN_BYTES ) ) {
			return new WP_Error( 'ab_mcp_too_large', __( 'The file exceeds the allowed size (20 MB unless the site sets another limit with the ab_mcp_upload_max_bytes filter). Use a smaller file, or ask the site administrator to raise the limit.', 'alphabridge-mcp' ) );
		}
		return self::store( $bytes, $name, $post_id, $a );
	}

	/**
	 * A file from the chat (ChatGPT, _meta["openai/fileParams"]): the site
	 * downloads it from its temporary address, with the same checks as
	 * wp_upload_media_from_url (public host on every redirect, size, type).
	 * Its name comes from file_name, else from the address; the extension
	 * from the name, else from mime_type or the served content type.
	 *
	 * @param array $file    { download_url, file_id[, mime_type][, file_name] }.
	 * @param int   $post_id Parent post, already checked.
	 * @param array $a       Arguments of the call.
	 * @return array|WP_Error
	 */
	private static function upload_chat_file( array $file, $post_id, $a ) {
		$url = esc_url_raw( self::s( $file, 'download_url' ) );
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'ab_mcp_blocked_url', __( 'The download_url of the file from the chat is not a public http(s) address, so nothing was downloaded. Pass file as the chat handed it over, or send the bytes as base64 in content with a filename.', 'alphabridge-mcp' ) );
		}
		$got = self::fetch( $url );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		$given = sanitize_file_name( self::s( $file, 'file_name' ) );
		$name  = '' !== $given ? $given : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$type  = strtolower( self::s( $file, 'mime_type' ) );
		$name  = self::upload_name( $name, isset( self::TYPE_EXTENSIONS[ $type ] ) ? $type : $got['type'] );
		if ( '' === $name ) {
			return new WP_Error( 'ab_mcp_bad_type', __( 'Only JPEG, PNG, GIF, WebP and PDF files can be uploaded: the filename must end in .jpg, .jpeg, .png, .gif, .webp or .pdf. Convert the file to one of these formats.', 'alphabridge-mcp' ) );
		}
		return self::store( $got['body'], $name, $post_id, $a );
	}

	/**
	 * Find media by content, address or name.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function find_media( $a ) {
		$hash     = strtolower( self::s( $a, 'sha256', '' ) );
		$url      = self::s( $a, 'url', '' );
		$filename = self::s( $a, 'filename', '' );
		$search   = self::s( $a, 'search', '' );
		if ( '' === $hash && '' === $url && '' === $filename && '' === $search ) {
			return new WP_Error( 'ab_mcp_missing_arg', __( 'Pass at least one of sha256, url, filename or search. wp_list_media lists the whole media library.', 'alphabridge-mcp' ) );
		}
		if ( '' !== $hash && ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return new WP_Error( 'ab_mcp_bad_args', __( 'sha256 must be 64 hexadecimal characters, as the upload tools return it. Check the value, or search by url, filename or search instead.', 'alphabridge-mcp' ) );
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 ),
			'no_found_rows'  => true,
			// Newest first with the id breaking a tie, so the pages below
			// neither repeat nor skip a file (paged_orderby()).
			'orderby'        => self::paged_orderby( 'date', 'DESC' ),
			'order'          => 'DESC',
		);
		if ( '' !== $url ) {
			$id = self::attachment_by_url( $url );
			if ( 0 === $id ) {
				return array( 'items' => array() );
			}
			$args['post__in'] = array( $id );
		}
		$meta = array();
		if ( '' !== $hash ) {
			$meta[] = array(
				'key'   => self::HASH_META,
				'value' => $hash,
			);
		}
		if ( '' !== $filename ) {
			$meta[] = array(
				'key'     => '_wp_attached_file',
				'value'   => $filename,
				'compare' => 'LIKE',
			);
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the search the tool exists for.
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$mime = self::s( $a, 'mime', '' );
		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		// The database limit comes before the right to read each file: page on
		// until enough readable ones are found or the matches run out, so
		// files the account may not read cannot hide later ones it may.
		$want  = (int) $args['posts_per_page'];
		$items = array();
		$seen  = array();
		$more  = false;
		for ( $page = 1; count( $items ) < $want; $page++ ) {
			if ( $page > self::FIND_PAGES ) {
				$more = true;
				break;
			}
			$query = new WP_Query( $args + array( 'paged' => $page ) );
			$fresh = 0;
			foreach ( $query->posts as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				++$fresh;
				if ( count( $items ) >= $want || ! current_user_can( 'read_post', $post->ID ) ) {
					continue;
				}
				$items[] = self::found_item( $post );
			}
			if ( 0 === $fresh || count( $query->posts ) < $want ) {
				break;
			}
		}
		$out = array( 'items' => $items );
		if ( $more ) {
			$out['more'] = true;
			$out['note'] = __( 'The search stopped after many matches this account may not read, so there may be more files it may read. Narrow it with sha256, url, filename, search or mime.', 'alphabridge-mcp' );
		}
		return $out;
	}

	/**
	 * One file as wp_find_media lists it.
	 *
	 * @param WP_Post $post Attachment.
	 * @return array
	 */
	private static function found_item( $post ) {
		$stored = get_post_meta( $post->ID, self::HASH_META, true );
		return array(
			'id'     => (int) $post->ID,
			'title'  => get_the_title( $post ),
			'mime'   => $post->post_mime_type,
			'url'    => wp_get_attachment_url( $post->ID ),
			'date'   => self::site_time( $post->post_date_gmt, $post->post_date ),
			'sha256' => is_string( $stored ) && '' !== $stored ? $stored : null,
		);
	}

	/**
	 * The attachment behind an address: the file itself, one of its image
	 * sizes ("photo-300x200.jpg"), or the original of an image WordPress
	 * scaled on upload ("photo.jpg", stored as "photo-scaled.jpg"), which
	 * attachment_url_to_postid() alone does not find. A derived address
	 * counts only where the attachment's metadata names that very file
	 * among its sizes or as its original, so a made-up size is no match.
	 *
	 * @param string $url Address on this site.
	 * @return int 0 when none.
	 */
	private static function attachment_by_url( $url ) {
		$id = (int) attachment_url_to_postid( $url );
		if ( $id > 0 ) {
			return $id;
		}
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$requested = basename( $path );
		if ( '' === $requested ) {
			return 0;
		}
		$base       = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
		$candidates = array_unique( array( $base, (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $base ) ) );
		foreach ( $candidates as $candidate ) {
			if ( $candidate === $url ) {
				continue;
			}
			$found = (int) attachment_url_to_postid( $candidate );
			if ( $found > 0 && self::names_file( $found, $requested ) ) {
				return $found;
			}
		}
		return 0;
	}

	/**
	 * Does the attachment's metadata name this file as one of its image
	 * sizes or as the original of a scaled image?
	 *
	 * @param int    $id   Attachment id.
	 * @param string $file Base name of the file.
	 * @return bool
	 */
	private static function names_file( $id, $file ) {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		if ( isset( $meta['original_image'] ) && $file === (string) $meta['original_image'] ) {
			return true;
		}
		foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && $file === (string) $size['file'] ) {
				return true;
			}
		}
		return false;
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found: no file in the media library has this id. Look it up with wp_list_media.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this attachment. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $a ) ) {
			$update['post_title'] = self::s( $a, 'title' );
		}
		if ( array_key_exists( 'caption', $a ) ) {
			$update['post_excerpt'] = self::s( $a, 'caption' );
		}
		if ( count( $update ) > 1 ) {
			// Any save gives the file the parent WordPress stores: none where a
			// loop runs through the file, which a file with "inherit" leaves
			// public. The rule of wp_update_post decides (file_publish_refusal()).
			$now     = (string) $att->post_status;
			$parent  = (int) $att->post_parent;
			$refused = AB_MCP_Tools_Content::file_publish_refusal( $id, $now, $parent, $now, $parent );
			if ( null !== $refused ) {
				return $refused;
			}
			if ( ! wp_update_post( wp_slash( $update ) ) ) {
				return new WP_Error( 'ab_mcp_update_failed', __( 'WordPress did not save the attachment, so its title and caption are unchanged; a plugin may have stopped the save. Check it with wp_get_media and try again, or edit it in wp-admin under Media.', 'alphabridge-mcp' ) );
			}
		}
		if ( array_key_exists( 'alt', $a ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( self::s( $a, 'alt' ) ) ) );
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Attachment not found: no file in the media library has this id. Look it up with wp_list_media.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot delete this attachment. Connect with an account that may delete it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		// wp_delete_attachment() also says false for an id that is not a file
		// (a post or a page), which the check above lets through.
		$res = wp_delete_attachment( $id, true );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'WordPress did not delete it: the id is not a file in the media library (look it up with wp_list_media), or a plugin stopped it. Check it with wp_get_media, and ask the site administrator if it keeps failing.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}
}
