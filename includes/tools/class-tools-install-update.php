<?php
/**
 * Installing, updating and deleting plugins and themes, and updating
 * WordPress core: the tools of the "lifecycle" group that change files.
 *
 * Free since 4.11.0, before in AlphaBridge MCP Pro only (its class
 * AB_MCP_Tools_Lifecycle_Pro, which extends AB_MCP_Tools_Lifecycle of this
 * plugin). Taken over without a change of behaviour: an update runs through
 * AB_MCP_Update_Guard (test requests before and after, the previous version
 * back where the new one does worse), an activation after an install through
 * AB_MCP_Activation_Guard. A class of its own, beside AB_MCP_Tools_Lifecycle:
 * Pro's class extends that one with private helpers of the same names as
 * these, which a parent may not declare.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lifecycle/class-update-guard.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-activation-guard.php';
// The files of wp-admin the routines and hooks of other plugins expect.
require_once dirname( __DIR__ ) . '/lifecycle/class-admin-files.php';
// The confirmation of the five tools without a way back; loaded here too, so the
// class registers wherever it is loaded (tests and the bundled core of Pro included).
require_once dirname( __DIR__ ) . '/class-confirm-token.php';
require_once dirname( __DIR__ ) . '/class-lifecycle-confirm.php';
// The download of a ZIP from an address, tied to the checked addresses of its host.
require_once dirname( __DIR__ ) . '/class-outbound.php';

/**
 * Class AB_MCP_Tools_Install_Update
 */
class AB_MCP_Tools_Install_Update extends AB_MCP_Tools_Base {

	/** How many redirects the download of a ZIP follows, each target checked and tied like the first. */
	const REDIRECTS = 3;

	/**
	 * Load the WordPress upgrader stack (defensively). These wp-admin
	 * includes are loaded right before the upgrader is used, and only inside
	 * the install and update tools, which write and run with write access on
	 * only. First the files wp-admin loads on every screen
	 * (AB_MCP_Admin_Files): the hooks other plugins hang on the upgrader,
	 * and the deactivation and uninstall routine a delete runs, expect them.
	 */
	protected static function boot_upgrader() {
		AB_MCP_Admin_Files::load();
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) && file_exists( ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
	}

	/**
	 * Register the tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {
		$r->register(
			'wp_install_plugin',
			array(
				'description' => 'Install a plugin from the WordPress.org directory by slug, optionally activating it. Needs install_plugins, and activate_plugins for activate (without it the plugin is installed and activated stays false); refused when DISALLOW_FILE_MODS is set. Returns installed, plugin (its file, e.g. akismet/akismet.php), activated and version, plus what the activation check reports; no undo point is recorded. Where its folder exists already, nothing is changed and the answer names the version installed; an installed plugin is brought to a newer version with wp_update_plugin.' . AB_MCP_Activation_Guard::INSTALL_DESCRIPTION,
				'capability'  => 'install_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array(
						'slug'     => array( 'type' => 'string', 'description' => 'wordpress.org plugin slug, e.g. "akismet".' ),
						'activate' => array( 'type' => 'boolean', 'description' => 'true activates the plugin after install (needs activate_plugins). Default false.' ),
					),
				),
				'callback'    => array( __CLASS__, 'install_plugin' ),
			)
		);

		$r->register(
			'wp_install_plugin_zip',
			array(
				'description' => 'Install a plugin from a ZIP archive: base64 "content" or a public http(s) "url" the site downloads, each up to 30 MB by default; where AlphaBridge MCP Pro is active, also a committed upload "session" of its chunked uploads, for larger archives. A session given is used first, then content, then url. Optionally activates it. Plugins from the WordPress.org directory are installed by slug with wp_install_plugin. Needs install_plugins (and activate_plugins for activate; without it activated stays false); refused when DISALLOW_FILE_MODS is set. Returns installed, plugin (its file) and activated, plus what the activation check reports; no undo point is recorded. Where the plugin\'s folder exists already, nothing is changed and the answer names the version installed, so a repeated call installs nothing twice; an installed plugin is brought to a newer version with wp_update_plugin.' . AB_MCP_Activation_Guard::INSTALL_DESCRIPTION,
				'open_world'  => true, // downloads from the "url" the caller names.
				'capability'  => 'install_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'content'  => array( 'type' => 'string', 'description' => 'Base64-encoded plugin .zip, up to 30 MB by default (filter ab_mcp_install_max_bytes).' ),
						'url'      => array( 'type' => 'string', 'description' => 'Public http or https address of a plugin .zip; the site downloads it (up to 30 MB by default, three redirects). Used when neither session nor content is given.' ),
						'session'  => array( 'type' => 'string', 'description' => 'Id of a committed chunked-upload session of AlphaBridge MCP Pro holding the .zip, for archives over the content and url limit; used first when given. Refused where Pro is not active.' ),
						'activate' => array( 'type' => 'boolean', 'description' => 'true activates the plugin after install (needs activate_plugins). Default false.' ),
					),
				),
				// Runs only with the token from its own preview (AB_MCP_Confirm_Token).
				'confirm'     => AB_MCP_Lifecycle_Confirm::definition( 'wp_install_plugin_zip' ),
				'callback'    => array( __CLASS__, 'install_plugin_zip' ),
			)
		);

		$r->register(
			'wp_install_theme_zip',
			array(
				'description' => 'Install a theme from a ZIP archive: base64 "content" or a public http(s) "url" the site downloads, each up to 30 MB by default; where AlphaBridge MCP Pro is active, also a committed upload "session" of its chunked uploads, for larger archives. A session given is used first, then content, then url. Optionally switches to it. Themes from the WordPress.org directory are installed by slug with wp_install_theme. Needs install_themes (and switch_themes for activate; without it activated stays false); refused when DISALLOW_FILE_MODS is set. Returns installed, stylesheet and activated, plus what the activation check reports. The install records no undo point; with activate, the theme switch records one where it can, returned in undo as with wp_activate_theme (null when none was recorded). Where the theme\'s folder exists already, nothing is changed and the answer names the version installed, so a repeated call installs nothing twice; an installed theme is brought to a newer version with wp_update_theme.' . AB_MCP_Activation_Guard::INSTALL_DESCRIPTION,
				'open_world'  => true, // downloads from the "url" the caller names.
				'capability'  => 'install_themes',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'content'  => array( 'type' => 'string', 'description' => 'Base64-encoded theme .zip, up to 30 MB by default (filter ab_mcp_install_max_bytes).' ),
						'url'      => array( 'type' => 'string', 'description' => 'Public http or https address of a theme .zip; the site downloads it (up to 30 MB by default, three redirects). Used when neither session nor content is given.' ),
						'session'  => array( 'type' => 'string', 'description' => 'Id of a committed chunked-upload session of AlphaBridge MCP Pro holding the .zip, for archives over the content and url limit; used first when given. Refused where Pro is not active.' ),
						'activate' => array( 'type' => 'boolean', 'description' => 'true switches to the theme after install (needs switch_themes). Default false.' ),
					),
				),
				// Runs only with the token from its own preview (AB_MCP_Confirm_Token).
				'confirm'     => AB_MCP_Lifecycle_Confirm::definition( 'wp_install_theme_zip' ),
				'callback'    => array( __CLASS__, 'install_theme_zip' ),
			)
		);

		$r->register(
			'wp_update_plugin',
			array(
				'description' => 'Update an installed plugin to the version WordPress offers for it (wp_get_update_status lists the offers); new plugins are installed by wp_install_plugin. Needs update_plugins; refused under DISALLOW_FILE_MODS, for a plugin of a single file and when nothing is offered. A kept update answers with updated, version_before, version_after, probe, warning and note.' . AB_MCP_Update_Guard::DESCRIPTION,
				'capability'  => 'update_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'plugin' ),
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => 'Exact plugin file of an installed plugin as wp_list_plugins lists it, e.g. akismet/akismet.php.' ) ),
				),
				'callback'    => array( __CLASS__, 'update_plugin' ),
			)
		);

		$r->register(
			'wp_delete_plugin',
			array(
				'description' => 'Delete an installed plugin by its file path as wp_list_plugins lists it (e.g. akismet/akismet.php); an active plugin is deactivated first, and WordPress runs the plugin\'s uninstall routine, which can remove its settings and data. Permanent: no undo point is recorded; switching a plugin off without removing it is wp_deactivate_plugin. Returns deleted and plugin. Needs delete_plugins; refused when DISALLOW_FILE_MODS is set. AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro, the owner drop-in) is refused here, because deleting it would end this connection or take tools away from it, and its uninstall can remove the connections, settings and undo points as well; it is deleted in wp-admin under Plugins.',
				'capability'  => 'delete_plugins',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'plugin' ),
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => 'Exact plugin file of an installed plugin as wp_list_plugins lists it, e.g. "akismet/akismet.php". A folder name alone or an unknown file is refused.' ) ),
				),
				// Runs only with the token from its own preview (AB_MCP_Confirm_Token).
				'confirm'     => AB_MCP_Lifecycle_Confirm::definition( 'wp_delete_plugin' ),
				'callback'    => array( __CLASS__, 'delete_plugin' ),
			)
		);

		$r->register(
			'wp_install_theme',
			array(
				'description' => 'Download and install a theme from the WordPress.org directory by its slug; the theme is not activated. Returns installed, stylesheet (the slug) and the version WordPress.org reported. Needs install_themes; refused when DISALLOW_FILE_MODS is set. No undo point is recorded. A theme whose folder exists already is not replaced; the answer is WordPress\'s error. Themes from a ZIP are installed with wp_install_theme_zip, and switching to a theme is wp_activate_theme.',
				'capability'  => 'install_themes',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array( 'slug' => array( 'type' => 'string', 'description' => 'Theme slug from its wordpress.org address, e.g. "twentytwentyfive". Lower-cased; characters other than a-z, 0-9, "-" and "_" are dropped.' ) ),
				),
				'callback'    => array( __CLASS__, 'install_theme' ),
			)
		);

		$r->register(
			'wp_update_theme',
			array(
				'description' => 'Update an installed theme to the version WordPress offers for it (wp_get_update_status lists the offers); new themes are installed by wp_install_theme. Needs update_themes; refused under DISALLOW_FILE_MODS and when nothing is offered. A kept update answers with updated, stylesheet, role, version_before, version_after, probe, warning and note.' . AB_MCP_Update_Guard::DESCRIPTION,
				'capability'  => 'update_themes',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'stylesheet' ),
					'properties' => array( 'stylesheet' => array( 'type' => 'string', 'description' => 'Exact directory name of an installed theme as wp_list_themes reports it, e.g. "twentytwentyfour"; anything else is refused.' ) ),
				),
				'callback'    => array( __CLASS__, 'update_theme' ),
			)
		);

		$r->register(
			'wp_delete_theme',
			array(
				'description' => 'Delete an installed theme\'s folder and files. The active theme and its parent theme are refused (wp_activate_theme switches to another theme); a newer version of a theme is installed by wp_update_theme, without deleting it. Returns deleted and stylesheet. Needs delete_themes; refused when DISALLOW_FILE_MODS is set. Permanent: no undo point is recorded.',
				'capability'  => 'delete_themes',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'stylesheet' ),
					'properties' => array( 'stylesheet' => array( 'type' => 'string', 'description' => 'Exact directory name of an installed theme as wp_list_themes reports it, e.g. "twentytwentyfour". Anything else is refused.' ) ),
				),
				// Runs only with the token from its own preview (AB_MCP_Confirm_Token).
				'confirm'     => AB_MCP_Lifecycle_Confirm::definition( 'wp_delete_theme' ),
				'callback'    => array( __CLASS__, 'delete_theme' ),
			)
		);

		$r->register(
			'wp_update_core',
			array(
				'description' => 'Update WordPress core itself (not plugins or themes) to the first version WordPress offers, with WordPress\'s own core updater; it checks for offers first, and wp_get_update_status shows them. Already current: updated false with a message; otherwise updated true and new_version, or the updater\'s error. Needs update_core; refused when DISALLOW_FILE_MODS is set. Replaces core files site-wide. Unlike wp_update_plugin, AlphaBridge runs no test requests and puts nothing back; no undo point is recorded.',
				'capability'  => 'update_core',
				'dangerous'   => true,
				// Runs only with the token from its own preview (AB_MCP_Confirm_Token).
				'confirm'     => AB_MCP_Lifecycle_Confirm::definition( 'wp_update_core' ),
				'callback'    => array( __CLASS__, 'update_core' ),
			)
		);
	}

	/**
	 * Guard: refuse file-modifying actions when the site disables them.
	 *
	 * @return true|WP_Error
	 */
	private static function file_mods_allowed() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'ab_mcp_file_mods', __( 'Install/update/delete is disabled on this site (DISALLOW_FILE_MODS). An administrator with access to wp-config.php can remove the constant. Until then plugins and themes are managed over SFTP.', 'alphabridge-mcp' ) );
		}
		return true;
	}
	/**
	 * Turn a base64 "content" argument or a "url" argument into a local .zip
	 * package path. Validates it is really a ZIP. An address is fetched
	 * through AB_MCP_Outbound::get(): every IPv4 and IPv6 address of its host
	 * public, the connection tied to exactly these addresses, each of the
	 * REDIRECTS redirects checked and tied the same way; wp_http_validate_url()
	 * before it keeps WordPress's rules on the form of the address.
	 *
	 * @param array $a Args.
	 * @return string|WP_Error Local temp zip path.
	 */
	private static function package_from_args( $a ) {
		$max     = (int) apply_filters( 'ab_mcp_install_max_bytes', 30 * MB_IN_BYTES );
		$content = self::s( $a, 'content', '' );
		$url     = self::s( $a, 'url', '' );
		$session = self::s( $a, 'session', '' );

		// The chunked uploads are AlphaBridge MCP Pro's (AB_MCP_Pro_Tools_Transfer).
		// Without Pro a session is refused: used silently past it, content or
		// url would install something other than what the caller named first.
		if ( '' !== $session && ! class_exists( 'AB_MCP_Pro_Tools_Transfer' ) ) {
			return new WP_Error( 'ab_mcp_no_session', __( 'Upload sessions come from AlphaBridge MCP Pro, which is not active on this site, so the session cannot be read. Nothing was installed. Pass the ZIP as base64 "content" or as a public http or https "url" instead.', 'alphabridge-mcp' ) );
		}
		if ( '' !== $session ) {
			// Committed chunked upload: stream-copy to a temp package (no RAM spike).
			$src = AB_MCP_Pro_Tools_Transfer::path_for( $session );
			if ( is_wp_error( $src ) ) {
				return $src;
			}
			$fh    = fopen( $src, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$magic = $fh ? (string) fread( $fh, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( "PK\x03\x04" !== $magic && "PK\x05\x06" !== $magic ) {
				return new WP_Error( 'ab_mcp_not_zip', __( 'The session file is not a ZIP archive. Upload the ZIP of the plugin or theme as a new session, or pass it as base64 "content" or a "url".', 'alphabridge-mcp' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam( 'ab-package.zip' );
			if ( ! $tmp || ! copy( $src, $tmp ) ) {
				return new WP_Error( 'ab_mcp_tmp', __( 'Could not stage the package. The server\'s temporary folder is not writable or is full; the host can check it.', 'alphabridge-mcp' ) );
			}
			return $tmp;
		}

		if ( '' !== $content ) {
			$raw = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || '' === $raw ) {
				return new WP_Error( 'ab_mcp_bad_content', __( 'Content is not valid base64. Send the ZIP base64-encoded, or give the public http or https address of the ZIP as "url".', 'alphabridge-mcp' ) );
			}
			if ( strlen( $raw ) > $max ) {
				return new WP_Error( 'ab_mcp_too_large', __( 'The package exceeds the allowed size. Use the "url" argument for large packages.', 'alphabridge-mcp' ) );
			}
		} elseif ( '' !== $url ) {
			$url = esc_url_raw( $url );
			if ( ! $url || ! preg_match( '#^https?://#i', $url ) || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'ab_mcp_blocked_url', __( 'The URL is invalid or points to a disallowed host. Give the public http or https address of the ZIP, or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
			}
			$resp = AB_MCP_Outbound::get(
				$url,
				array(
					'timeout'             => 30,
					'redirection'         => self::REDIRECTS,
					'limit_response_size' => $max,
				)
			);
			if ( is_wp_error( $resp ) ) {
				return self::download_refused( $resp );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'ab_mcp_fetch_failed', __( 'Could not download the package. Check that the address answers with the ZIP, or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
			}
			$raw = wp_remote_retrieve_body( $resp );
			if ( '' === $raw || strlen( $raw ) > $max ) {
				return new WP_Error( 'ab_mcp_too_large', __( 'The package is empty or exceeds the allowed size (30 MB unless an administrator changed it with the ab_mcp_install_max_bytes filter). Check that the address answers with the ZIP; a larger one can be installed in wp-admin.', 'alphabridge-mcp' ) );
			}
		} else {
			return new WP_Error( 'ab_mcp_need_package', __( 'No package was given. Pass the ZIP as base64 "content" or as a public http or https "url".', 'alphabridge-mcp' ) );
		}

		// Must be a real ZIP (local-file or empty-archive magic bytes).
		$magic = substr( $raw, 0, 4 );
		if ( "PK\x03\x04" !== $magic && "PK\x05\x06" !== $magic ) {
			return new WP_Error( 'ab_mcp_not_zip', __( 'The package is not a ZIP archive. Send the plugin or theme as a ZIP file.', 'alphabridge-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'ab-package.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'ab_mcp_tmp', __( 'Could not create a temporary file. The server\'s temporary folder is not writable or is full; the host can check it.', 'alphabridge-mcp' ) );
		}
		if ( false === file_put_contents( $tmp, $raw ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'ab_mcp_write_failed', __( 'Could not write the package. The server\'s temporary folder is not writable or is full; the host can check it.', 'alphabridge-mcp' ) );
		}
		return $tmp;
	}

	/**
	 * What a download AB_MCP_Outbound::get() refused means for the person:
	 * the reason, and the way (the address of the ZIP itself on a public
	 * host, the proxy setting, the curl extension, or the ZIP base64-encoded
	 * as "content"). An error of the transport is passed on as it comes.
	 *
	 * @param WP_Error $error The refusal.
	 * @return WP_Error
	 */
	private static function download_refused( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		$hop  = isset( $data['hop'] ) ? (int) $data['hop'] : 0;
		$host = isset( $data['url'] ) ? (string) wp_parse_url( (string) $data['url'], PHP_URL_HOST ) : '';
		switch ( $error->get_error_code() ) {
			case 'ab_mcp_outbound_local':
				if ( $hop > 0 ) {
					/* translators: %s: host the address redirects to. */
					return new WP_Error( 'ab_mcp_blocked_url', sprintf( __( 'The address redirects to %s, a private or internal host, so nothing was downloaded: the site downloads only from public hosts. 1. Open the address in a browser and use the address of the ZIP itself, on a public host. 2. Or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ), $host ) );
				}
				return new WP_Error( 'ab_mcp_blocked_url', __( 'The URL points to a private or internal host (one of the addresses its name resolves to is not public), so nothing was downloaded: the site downloads only from public hosts. Give the public http or https address of the ZIP, or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
			case 'ab_mcp_outbound_unresolved':
				if ( $hop > 0 ) {
					/* translators: %s: host the address redirects to. */
					return new WP_Error( 'ab_mcp_fetch_failed', sprintf( __( 'The address redirects to %s, whose name has no address, so nothing was downloaded. 1. Open the address in a browser and use the address of the ZIP itself. 2. Or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ), $host ) );
				}
				return new WP_Error( 'ab_mcp_fetch_failed', __( 'The host name of the URL has no address, so nothing was downloaded. Check the spelling of the address, or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
			case 'ab_mcp_outbound_proxy':
				return new WP_Error(
					'ab_mcp_proxy',
					AB_MCP_Guidance::compose(
						/* translators: %s: host name of the address. */
						sprintf( __( 'This site sends its requests through a proxy (WP_PROXY_HOST), which looks the name of %s up itself, so the download could not be tied to the checked addresses and was not started.', 'alphabridge-mcp' ), $host ),
						__( '1. To let the site download from that host directly, an administrator adds it to WP_PROXY_BYPASS_HOSTS in wp-config.php; then try again. 2. Or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ),
						AB_MCP_Guidance::settings_url()
					)
				);
			case 'ab_mcp_outbound_unbound':
				return new WP_Error( 'ab_mcp_unbound', __( 'WordPress sends its requests on this site without cURL, and only cURL can be tied to the checked addresses, so nothing was downloaded. 1. Ask the host to enable the curl extension of PHP. 2. Until then, pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
			case 'ab_mcp_outbound_redirects':
				/* translators: %d: how many redirects are followed at most. */
				return new WP_Error( 'ab_mcp_fetch_failed', sprintf( __( 'The address redirected more than %d times, so nothing was downloaded. 1. Open it in a browser and use the address the browser ends on, the ZIP itself. 2. Or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ), self::REDIRECTS ) );
			case 'ab_mcp_outbound_redirect':
				return new WP_Error( 'ab_mcp_fetch_failed', __( 'The address redirects to something that is not an http(s) address, so nothing was downloaded. 1. Open it in a browser and use the address of the ZIP itself. 2. Or pass the ZIP base64-encoded as "content".', 'alphabridge-mcp' ) );
		}
		return $error;
	}
	/**
	 * Install plugin from slug.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$slug = sanitize_key( self::s( $a, 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'ab_mcp_bad_slug', __( 'A plugin slug is required: pass the plugin\'s name in its wordpress.org address, e.g. "akismet".', 'alphabridge-mcp' ) );
		}
		self::boot_upgrader();

		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			// WordPress passes on the English sentence WordPress.org answers
			// an unknown slug with; the person reads it in the site's language.
			if ( 'plugins_api_failed' === $api->get_error_code() && 'Plugin not found.' === $api->get_error_message() ) {
				/* translators: %s: plugin slug */
				return new WP_Error( 'plugins_api_failed', sprintf( __( 'WordPress.org has no plugin with the slug "%s". Check the slug: it is the plugin\'s name in its wordpress.org address, e.g. "akismet".', 'alphabridge-mcp' ), $slug ) );
			}
			return $api;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( true !== $result ) {
			return self::install_failed( 'plugin', $result, $skin, __( 'Install failed, and WordPress gave no reason. wp_list_plugins shows whether the plugin is there; the web server needs write access to wp-content/plugins.', 'alphabridge-mcp' ) );
		}

		$plugin_file = $upgrader->plugin_info();
		$act         = array( 'activated' => false );
		if ( $plugin_file && self::b( $a, 'activate', false ) && current_user_can( 'activate_plugins' ) ) {
			$act = self::activate_installed_plugin( $plugin_file );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
		}

		return array(
			'installed' => true,
			'plugin'    => $plugin_file,
			'activated' => $act['activated'],
			'version'   => isset( $api->version ) ? $api->version : null,
		) + $act;
	}
	/**
	 * Install a plugin from an uploaded ZIP or a URL.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function install_plugin_zip( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		self::boot_upgrader();
		$pkg = self::package_from_args( $a );
		if ( is_wp_error( $pkg ) ) {
			return $pkg;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $pkg );
		@unlink( $pkg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( true !== $result ) {
			return self::install_failed( 'plugin', $result, $skin, __( 'Install failed, and WordPress gave no reason. wp_list_plugins shows whether the plugin is there; the web server needs write access to wp-content/plugins.', 'alphabridge-mcp' ) );
		}

		$plugin_file = $upgrader->plugin_info();
		$act         = array( 'activated' => false );
		if ( $plugin_file && self::b( $a, 'activate', false ) && current_user_can( 'activate_plugins' ) ) {
			$act = self::activate_installed_plugin( $plugin_file );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
		}
		return array(
			'installed' => true,
			'plugin'    => $plugin_file,
		) + $act;
	}
	/**
	 * Install a theme from an uploaded ZIP or a URL.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function install_theme_zip( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		self::boot_upgrader();
		$pkg = self::package_from_args( $a );
		if ( is_wp_error( $pkg ) ) {
			return $pkg;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $pkg );
		@unlink( $pkg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( true !== $result ) {
			return self::install_failed( 'theme', $result, $skin, __( 'Install failed, and WordPress gave no reason. wp_list_themes shows whether the theme is there; the web server needs write access to wp-content/themes.', 'alphabridge-mcp' ) );
		}

		$stylesheet = '';
		$info       = $upgrader->theme_info();
		if ( $info instanceof WP_Theme ) {
			$stylesheet = $info->get_stylesheet();
		}
		$act = array( 'activated' => false );
		if ( $stylesheet && self::b( $a, 'activate', false ) && current_user_can( 'switch_themes' ) ) {
			$act = self::activate_installed_theme( $stylesheet );
			if ( is_wp_error( $act ) ) {
				return $act;
			}
		}
		return array(
			'installed'  => true,
			'stylesheet' => $stylesheet,
		) + $act;
	}

	/**
	 * Activate a plugin this call installed, checked as wp_activate_plugin
	 * checks it (AB_MCP_Activation_Guard).
	 *
	 * @param string $plugin_file Plugin file.
	 * @return array|WP_Error Fields for the answer: activated, and probe,
	 *                        warning and network_wide (a plugin WordPress
	 *                        activates network-wide on a network), or
	 *                        activation_error where WordPress
	 *                        did not activate it; an error, naming the
	 *                        install first, where it was taken back.
	 */
	private static function activate_installed_plugin( $plugin_file ) {
		$act = AB_MCP_Activation_Guard::plugin( $plugin_file );
		if ( AB_MCP_Activation_Guard::was_taken_back( $act ) ) {
			/* translators: %s: plugin file */
			return self::installed_first( $act, sprintf( __( 'The plugin %s was installed.', 'alphabridge-mcp' ), $plugin_file ) );
		}
		if ( is_wp_error( $act ) ) {
			return array(
				'activated'        => false,
				'activation_error' => $act->get_error_message(),
			);
		}
		return array( 'activated' => true ) + array_intersect_key( $act, array_flip( array( 'network_wide', 'probe', 'warning' ) ) );
	}

	/**
	 * Switch to a theme this call installed, the way wp_activate_theme does
	 * (its refusals, test requests and undo point).
	 *
	 * @param string $stylesheet Theme.
	 * @return array|WP_Error As activate_installed_plugin(), with undo.
	 */
	private static function activate_installed_theme( $stylesheet ) {
		if ( ! class_exists( 'AB_MCP_Tools_Plugins_Themes_Manage' ) ) {
			require_once __DIR__ . '/class-tools-plugins-themes-manage.php';
		}
		$sw = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( $stylesheet, 'wp_install_theme_zip' );
		if ( AB_MCP_Activation_Guard::was_taken_back( $sw ) ) {
			/* translators: %s: stylesheet */
			return self::installed_first( $sw, sprintf( __( 'The theme %s was installed.', 'alphabridge-mcp' ), $stylesheet ) );
		}
		if ( is_wp_error( $sw ) ) {
			return array(
				'activated'        => false,
				'activation_error' => $sw->get_error_message(),
			);
		}
		return array( 'activated' => ! empty( $sw['activated'] ) ) + array_intersect_key( $sw, array_flip( array( 'previous', 'undo', 'undo_note', 'probe', 'warning' ) ) );
	}

	/**
	 * An activation taken back after an install: the error, with the install
	 * named first, as the client sees the message only.
	 *
	 * @param WP_Error $e        The guard's error.
	 * @param string   $sentence What was installed.
	 * @return WP_Error
	 */
	private static function installed_first( WP_Error $e, $sentence ) {
		$data = $e->get_error_data();
		return new WP_Error(
			$e->get_error_code(),
			$sentence . ' ' . $e->get_error_message(),
			( is_array( $data ) ? $data : array() ) + array( 'installed' => true )
		);
	}
	/**
	 * Update plugin, through AB_MCP_Update_Guard: test requests before
	 * and after, the previous version back where the new one does worse.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_plugin( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$plugin = self::s( $a, 'plugin' );
		self::boot_upgrader();

		// As wp_delete_plugin: only the exact identifier of an installed plugin.
		if ( '' === $plugin || 0 !== validate_file( $plugin ) || ! array_key_exists( $plugin, get_plugins() ) ) {
			return new WP_Error( 'ab_mcp_bad_plugin', __( 'plugin must be the exact identifier of an installed plugin, e.g. "akismet/akismet.php". wp_list_plugins lists the installed plugins with their identifiers (file).', 'alphabridge-mcp' ) );
		}
		if ( is_wp_error( AB_MCP_Own_Files::own_file_refusal( WP_PLUGIN_DIR . '/' . $plugin ) ) ) {
			return self::own_update_refusal();
		}
		if ( false === strpos( $plugin, '/' ) ) {
			return new WP_Error(
				'ab_mcp_update_single_file',
				sprintf(
					/* translators: %s: plugin file */
					__( 'Not updated: %s is a plugin of a single file, and for those WordPress keeps no copy of the previous version during an update, so it could not be put back should the new one fail. Nothing was changed. Update it in wp-admin under Plugins.', 'alphabridge-mcp' ),
					$plugin
				)
			);
		}
		$job = array(
			'type'   => 'plugin',
			'item'   => $plugin,
			'slug'   => dirname( $plugin ),
			'root'   => WP_PLUGIN_DIR,
			'dir'    => 'plugins',
			'tool'   => 'wp_update_plugin',
			'active' => is_plugin_active( $plugin ),
			'offer'  => array(),
		);
		if ( ! AB_MCP_Update_Guard::can_write( WP_PLUGIN_DIR ) ) {
			return AB_MCP_Update_Guard::fs_refusal( $job );
		}

		wp_update_plugins();
		$current = get_site_transient( 'update_plugins' );
		$offer   = ( is_object( $current ) && isset( $current->response ) && is_array( $current->response ) && isset( $current->response[ $plugin ] ) ) ? $current->response[ $plugin ] : null;
		if ( ! is_object( $offer ) ) {
			return AB_MCP_Update_Guard::no_offer( $job, AB_MCP_Update_Guard::version_of( $job ) );
		}
		$job['offer'] = array(
			'version' => isset( $offer->new_version ) ? (string) $offer->new_version : '',
			'package' => isset( $offer->package ) ? (string) $offer->package : '',
			// Offers of wordpress.org carry its id; vendors' updaters their own.
			'wporg'   => isset( $offer->id ) && 0 === strpos( (string) $offer->id, 'w.org/' ),
			'slug'    => isset( $offer->slug ) ? (string) $offer->slug : '',
		);
		if ( '' === $job['offer']['package'] ) {
			return self::no_package( $job );
		}
		return AB_MCP_Update_Guard::update( $job );
	}
	/**
	 * Delete plugin.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_plugin( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$plugin = self::s( $a, 'plugin' );
		self::boot_upgrader();

		// delete_plugins() builds a directory path from the string and deletes
		// it recursively without checking that it names a plugin. Only the
		// exact identifier of an installed plugin gets through.
		if ( 0 !== validate_file( $plugin ) || ! array_key_exists( $plugin, get_plugins() ) ) {
			return new WP_Error( 'ab_mcp_bad_plugin', __( 'plugin must be the exact identifier of an installed plugin, e.g. "akismet/akismet.php". wp_list_plugins lists the installed plugins with their identifiers (file).', 'alphabridge-mcp' ) );
		}
		// Recognised as wp_update_plugin does: by identity, so a renamed or
		// symlinked folder of AlphaBridge counts as well.
		if ( is_wp_error( AB_MCP_Own_Files::own_file_refusal( WP_PLUGIN_DIR . '/' . $plugin ) ) ) {
			return self::own_delete_refusal();
		}
		if ( is_plugin_active( $plugin ) ) {
			deactivate_plugins( array( $plugin ) );
		}
		$result = delete_plugins( array( $plugin ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed. The web server needs write access to the folder; the host can grant it, or delete it over SFTP.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted' => true,
			'plugin'  => $plugin,
		);
	}
	/**
	 * Install theme.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function install_theme( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$slug = sanitize_key( self::s( $a, 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'ab_mcp_bad_slug', __( 'A theme slug is required: pass the theme\'s name in its wordpress.org address, e.g. "twentytwentyfive".', 'alphabridge-mcp' ) );
		}
		self::boot_upgrader();

		$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'ab_mcp_install_failed', __( 'Theme install failed. Check that the slug names a theme on wordpress.org; wp_list_themes shows what is installed.', 'alphabridge-mcp' ) );
		}
		return array(
			'installed' => true,
			'stylesheet' => $slug,
			'version'   => isset( $api->version ) ? $api->version : null,
		);
	}
	/**
	 * Update theme, through AB_MCP_Update_Guard like a plugin: WordPress
	 * itself never rolls a theme back.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_theme( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$stylesheet = self::s( $a, 'stylesheet' );
		self::boot_upgrader();

		// As wp_delete_theme: only a directory name WordPress found a theme in.
		if ( '' === $stylesheet || 0 !== validate_file( $stylesheet ) || ! array_key_exists( $stylesheet, wp_get_themes( array( 'errors' => null ) ) ) ) {
			return new WP_Error( 'ab_mcp_bad_theme', __( 'stylesheet must be the exact directory name of an installed theme, as wp_list_themes reports it.', 'alphabridge-mcp' ) );
		}
		if ( get_stylesheet() === $stylesheet ) {
			$role = 'active';
		} elseif ( get_template() === $stylesheet ) {
			$role = 'parent';
		} else {
			$role = 'inactive';
		}
		$root = get_theme_root( $stylesheet );
		$job  = array(
			'type'   => 'theme',
			'item'   => $stylesheet,
			'slug'   => $stylesheet,
			'root'   => $root,
			'dir'    => 'themes',
			'tool'   => 'wp_update_theme',
			'active' => 'inactive' !== $role,
			'role'   => $role,
			'offer'  => array(),
		);
		if ( ! AB_MCP_Update_Guard::can_write( $root ) ) {
			return AB_MCP_Update_Guard::fs_refusal( $job );
		}

		wp_update_themes();
		$current = get_site_transient( 'update_themes' );
		$offer   = ( is_object( $current ) && isset( $current->response ) && is_array( $current->response ) && isset( $current->response[ $stylesheet ] ) ) ? (array) $current->response[ $stylesheet ] : null;
		if ( null === $offer ) {
			return AB_MCP_Update_Guard::no_offer( $job, AB_MCP_Update_Guard::version_of( $job ) );
		}
		$package      = isset( $offer['package'] ) ? (string) $offer['package'] : '';
		$job['offer'] = array(
			'version' => isset( $offer['new_version'] ) ? (string) $offer['new_version'] : '',
			'package' => $package,
			'wporg'   => 0 === strpos( $package, 'https://downloads.wordpress.org/theme/' ),
			'slug'    => $stylesheet,
		);
		if ( '' === $package ) {
			return self::no_package( $job );
		}
		return AB_MCP_Update_Guard::update( $job );
	}

	/**
	 * AlphaBridge is not updated through AlphaBridge: the request runs on the
	 * code being replaced, the roll-back included.
	 *
	 * @return WP_Error
	 */
	private static function own_update_refusal() {
		return new WP_Error(
			'ab_mcp_update_own',
			__( 'Not updated: this is AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro or the owner drop-in). It is not updated through AlphaBridge, because the update and its roll-back would run on the very code being replaced, and a failure would cut this connection. Nothing was changed. Update it in wp-admin under Plugins.', 'alphabridge-mcp' )
		);
	}

	/**
	 * AlphaBridge is not deleted through AlphaBridge: the connection that asks
	 * would end or lose tools, and the uninstall can take the connections,
	 * the settings and the undo points with it. Taking the assistant's access
	 * away is the site owner's step, in wp-admin.
	 *
	 * @return WP_Error
	 */
	private static function own_delete_refusal() {
		return new WP_Error(
			'ab_mcp_delete_own',
			__( 'Not deleted: this is AlphaBridge itself (AlphaBridge MCP, AlphaBridge MCP Pro or the owner drop-in). It is not deleted through AlphaBridge: that would end this connection or take tools away from it, and its uninstall can remove the connections, the settings and the undo points as well. Nothing was changed. Delete it in wp-admin under Plugins.', 'alphabridge-mcp' )
		);
	}

	/**
	 * WordPress knows of a newer version but has no package for it: a
	 * commercial plugin or theme without an active licence, usually.
	 *
	 * @param array $job Job.
	 * @return WP_Error
	 */
	private static function no_package( array $job ) {
		return new WP_Error(
			'ab_mcp_update_no_package',
			sprintf(
				/* translators: 1: plugin or theme, 2: version */
				__( 'Not updated: WordPress knows of version %2$s of %1$s, but its offer carries no package to download — with a commercial plugin or theme, usually because its licence is not active on this site. Nothing was changed. Activate the licence in wp-admin, then try again.', 'alphabridge-mcp' ),
				$job['slug'],
				'' !== (string) $job['offer']['version'] ? (string) $job['offer']['version'] : '?'
			)
		);
	}

	/**
	 * Why an install did not happen, from what the upgrader and its skin
	 * hold. Automatic_Upgrader_Skin has no get_errors(); asking it ended the
	 * call with an internal error (measured 02.10.2026). A folder that exists
	 * already gets the version installed in it.
	 *
	 * @param string           $type     plugin|theme.
	 * @param mixed            $result   What install() returned.
	 * @param WP_Upgrader_Skin $skin     Skin.
	 * @param string           $fallback Message where WordPress gave none.
	 * @return WP_Error
	 */
	private static function install_failed( $type, $result, $skin, $fallback ) {
		$err = null;
		if ( is_wp_error( $result ) ) {
			$err = $result;
		} elseif ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			$err = $skin->result;
		}
		if ( null !== $err && 'folder_exists' === $err->get_error_code() ) {
			$where = $err->get_error_data( 'folder_exists' );
			return self::exists_refusal( $type, is_string( $where ) ? basename( rtrim( $where, '/' ) ) : '' );
		}
		if ( null !== $err ) {
			return $err;
		}
		$said = array();
		if ( method_exists( $skin, 'get_upgrade_messages' ) ) {
			foreach ( (array) $skin->get_upgrade_messages() as $m ) {
				$m = trim( wp_strip_all_tags( (string) $m ) );
				if ( '' !== $m ) {
					$said[] = $m;
				}
			}
		}
		return new WP_Error( 'ab_mcp_install_failed', $said ? implode( ' ', array_slice( $said, -3 ) ) : $fallback );
	}

	/**
	 * Refused: the folder is there already. Says what is installed in it and
	 * the way to a newer version — never to delete it first, which runs the
	 * plugin's uninstall routine and can take its data with it.
	 *
	 * @param string $type   plugin|theme.
	 * @param string $folder Folder name.
	 * @return WP_Error
	 */
	private static function exists_refusal( $type, $folder ) {
		if ( 'theme' === $type ) {
			$theme = ( '' !== $folder ) ? wp_get_theme( $folder ) : null;
			$have  = ( $theme && $theme->exists() )
				/* translators: 1: theme name, 2: version */
				? sprintf( __( 'installed: %1$s %2$s', 'alphabridge-mcp' ), $theme->get( 'Name' ), $theme->get( 'Version' ) )
				: __( 'WordPress finds no theme in it', 'alphabridge-mcp' );
			return new WP_Error(
				'ab_mcp_install_exists',
				sprintf(
					/* translators: 1: folder, 2: what is installed */
					__( 'Not installed: the theme folder %1$s exists already (%2$s). Nothing was changed. An installed theme is brought to a newer version with wp_update_theme once WordPress offers the update (wp_get_update_status lists the offers); this tool does not replace an installed theme from a ZIP.', 'alphabridge-mcp' ),
					'' !== $folder ? $folder : '?',
					$have
				)
			);
		}
		$found = ( '' !== $folder ) ? get_plugins( '/' . $folder ) : array();
		if ( $found ) {
			$file = (string) key( $found );
			$data = (array) reset( $found );
			/* translators: 1: plugin name, 2: version, 3: plugin file */
			$have = sprintf( __( 'installed: %1$s %2$s, %3$s', 'alphabridge-mcp' ), isset( $data['Name'] ) ? $data['Name'] : $folder, isset( $data['Version'] ) ? $data['Version'] : '?', $folder . '/' . $file );
		} else {
			$have = __( 'WordPress finds no plugin in it', 'alphabridge-mcp' );
		}
		return new WP_Error(
			'ab_mcp_install_exists',
			sprintf(
				/* translators: 1: folder, 2: what is installed */
				__( 'Not installed: the plugin folder %1$s exists already (%2$s). Nothing was changed. An installed plugin is brought to a newer version with wp_update_plugin once WordPress offers the update (wp_get_update_status lists the offers); this tool does not replace an installed plugin from a ZIP.', 'alphabridge-mcp' ),
				'' !== $folder ? $folder : '?',
				$have
			)
		);
	}
	/**
	 * Delete theme.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_theme( $a ) {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$stylesheet = self::s( $a, 'stylesheet' );
		// delete_theme() removes the directory the string names, whatever it
		// is — and WP_Theme::exists() is no gate either: "." and ".." name
		// existing directories. Only a key of wp_get_themes() gets through:
		// those are the directory names WordPress found a theme in — broken
		// ones included ('errors' => null), a child whose parent is gone must
		// stay deletable.
		if ( '' === $stylesheet || 0 !== validate_file( $stylesheet ) || ! array_key_exists( $stylesheet, wp_get_themes( array( 'errors' => null ) ) ) ) {
			return new WP_Error( 'ab_mcp_bad_theme', __( 'stylesheet must be the exact directory name of an installed theme, as wp_list_themes reports it.', 'alphabridge-mcp' ) );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'ab_mcp_active_theme', __( 'Cannot delete the active theme or its parent. Activate another theme first (wp_activate_theme).', 'alphabridge-mcp' ) );
		}
		self::boot_upgrader();
		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed. The web server needs write access to the folder; the host can grant it, or delete it over SFTP.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'    => true,
			'stylesheet' => $stylesheet,
		);
	}
	/**
	 * Update core.
	 *
	 * @return array|WP_Error
	 */
	public static function update_core() {
		$g = self::file_mods_allowed();
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		self::boot_upgrader();
		wp_version_check();
		$updates = get_core_updates();
		if ( empty( $updates ) || ! isset( $updates[0] ) || 'upgrade' !== ( $updates[0]->response ?? '' ) ) {
			return array(
				'updated' => false,
				'message' => __( 'WordPress is already up to date.', 'alphabridge-mcp' ),
			);
		}
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $updates[0] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Core_Upgrader::upgrade() answers false where it cannot reach the
		// files (fs_connect(): the host asks for FTP or SSH access, which no
		// one can enter here), before anything is copied; the new version
		// as a string otherwise (Codex, 30.10.2026).
		if ( ! $result ) {
			return new WP_Error( 'ab_mcp_update_failed', __( 'Not updated: WordPress could not reach its own files; this host asks for FTP or SSH access, which a call through AlphaBridge cannot enter. Nothing was changed. Ask the host to give the web server write access to the WordPress files, or update it in wp-admin under Dashboard › Updates.', 'alphabridge-mcp' ) );
		}
		return array(
			'updated'     => true,
			'new_version' => isset( $updates[0]->current ) ? $updates[0]->current : null,
		);
	}
}
