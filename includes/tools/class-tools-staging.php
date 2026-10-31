<?php
/**
 * Staging: the tools around a staging site (free since 4.15.0).
 *
 * The first of them switches the preview protection of a staging site on
 * or off (AB_MCP_Preview_Protection). The group «Staging» grows with the
 * later stages of the staging workflow.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/class-preview-protection.php';

/**
 * Class AB_MCP_Tools_Staging
 */
class AB_MCP_Tools_Staging extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {
		$r->register(
			'wp_set_preview_protection',
			array(
				'description' => 'Switch the preview protection of this site on or off, or set a new shared password. With the protection on, a visitor without a WordPress login sees a password page (HTTP 401) instead of every page, post, archive, feed and search, and the public REST API (wp/v2, oembed/1.0 and every other namespace) answers such a visitor 401 as well; a visitor who enters the password is remembered in a browser cookie for 14 days. Switching on also sets the search engine visibility to discouraged (blog_public=0; the earlier value is remembered and written back when switching off), sends X-Robots-Tag: noindex on every answer, marks every answer as not cacheable and empties the page cache of the supported caching plugins. What stays reachable: this MCP endpoint and the sign-in of apps, wp-admin and wp-login.php, the REST root /wp-json/ (the hub reads it to find the endpoint), the OAuth documents below /.well-known/, and the REST API for signed-in users and application passwords, so Gutenberg, the hub and the connected assistants keep working. What it does not cover: files under /wp-content/uploads/, which the web server delivers without PHP, stay reachable by their address unless the host adds a rule; caches outside PHP (a host cache, Cloudflare APO, a CDN) are not emptied; the shared password identifies nobody, so the protection suits a staging site shown to a client, not confidential data. The password is generated here and returned once, in password, for the person to pass on to whoever should see the preview; only its hash is stored, and a new password signs every visitor out. Deactivating the plugin lifts the protection and writes the search engine visibility back; activating it again restores both, with the same password. For the few requests WordPress serves without loading plugins (wp-activate.php) the plugin keeps a small must-use loader in wp-content/mu-plugins/ while the protection is on; where that folder cannot be written, not_covered says so and loader_source holds the file to create by hand. Returns enabled, changed, password (only when a new one was set), blog_public, blog_public_before, since, set_by, page_cache and not_covered. Needs manage_options and write access.',
				'capability'  => 'manage_options',
				// Switching on hides the site from visitors and switching off
				// shows it again; both are taken back by the same call, and
				// nothing of the site is overwritten.
				'destructive' => false,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'      => array(
							'type'        => 'boolean',
							'description' => 'true switches the protection on and sets a password when it was off; false switches it off and writes the earlier search engine visibility back. When omitted, the state stays as it is and the answer reports it.',
						),
						'new_password' => array(
							'type'        => 'boolean',
							'description' => 'true replaces the shared password while the protection is on; the new one is in the answer, once, and every visitor has to enter it again. Default false. Switching on sets a password anyway.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'set_preview_protection' ),
			)
		);
	}

	/**
	 * wp_set_preview_protection.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function set_preview_protection( array $a ) {
		$enabled = array_key_exists( 'enabled', $a ) ? self::b( $a, 'enabled' ) : null;
		$renew   = self::b( $a, 'new_password' );
		$user_id = get_current_user_id();
		$state   = AB_MCP_Preview_Protection::state();

		$changed    = false;
		$password   = '';
		$page_cache = null;

		if ( false === $enabled ) {
			if ( $state['on'] ) {
				$off        = AB_MCP_Preview_Protection::disable( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
				$changed    = $off['changed'];
				$page_cache = $off['page_cache'];
			}
		} elseif ( true === $enabled && ! $state['on'] ) {
			$on         = AB_MCP_Preview_Protection::enable( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
			$changed    = $on['changed'];
			$password   = $on['password'];
			$page_cache = $on['page_cache'];
		} elseif ( $renew ) {
			// On, or asked to stay on: a new password.
			$new = AB_MCP_Preview_Protection::renew_password( $user_id, AB_MCP_Preview_Protection::SOURCE_TOOL );
			if ( null === $new ) {
				return new WP_Error(
					'ab_mcp_preview_off',
					AB_MCP_Guidance::compose(
						__( 'The preview protection is off, so there is no password to renew.', 'alphabridge-mcp' ),
						__( '1. Switch it on with enabled=true, which sets a password and returns it once. 2. Pass the password on to whoever should see the preview.', 'alphabridge-mcp' ),
						AB_MCP_Guidance::settings_url( AB_MCP_Preview_Protection::ANCHOR )
					)
				);
			}
			$changed  = $new['changed'];
			$password = $new['password'];
		}

		$out            = AB_MCP_Preview_Protection::summary();
		$out['changed'] = $changed;
		if ( '' !== $password ) {
			$out['password']      = $password;
			$out['password_note'] = 'Shown this once; only its hash is stored. Visitors enter it on the password page and are remembered for 14 days; a new password signs them all out.';
		} elseif ( $out['enabled'] ) {
			$out['password_note'] = 'The password stays as it was and is not shown again; new_password=true sets a new one and returns it once.';
		}
		if ( null !== $page_cache ) {
			$out['page_cache'] = $page_cache;
		}
		$out['not_covered'] = AB_MCP_Preview_Protection::limits();
		if ( $out['enabled'] && is_array( $out['loader'] ) && ! $out['loader']['written'] ) {
			$out['not_covered'][] = AB_MCP_Preview_Protection::loader_missing_note();
			$out['loader_source'] = AB_MCP_Preview_Protection::loader_source();
		}
		return $out;
	}
}
