<?php
/**
 * Staging: the tools around a staging site (free since 4.15.0).
 *
 * The first of them switches the preview protection of a staging site on
 * or off (AB_MCP_Preview_Protection); the second reports where the site
 * stands on the way to a staging site and what to do next (AB_MCP_Staging).
 * With the second come the guided prompt staging_workflow and, in the
 * server instructions, the question whether to work on staging first.
 * The group «Staging» grows with the later stages of the staging workflow.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/class-preview-protection.php';
require_once dirname( __DIR__ ) . '/class-staging.php';

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
				'description' => 'Switch the preview protection of this site on or off, or set a new shared password. With the protection on, a visitor without a WordPress login sees a password page (HTTP 401) instead of every page, post, archive, feed, search, the sitemaps of SEO plugins and the AJAX answers of plugins for visitors (admin-ajax.php, admin-post.php and the ?wc-ajax= of WooCommerce; the heartbeat and the password generator of the core keep answering), and the public REST API (wp/v2, oembed/1.0 and every other namespace) answers such a visitor 401 as well; a visitor who enters the password is remembered in a browser cookie for 14 days. Switching on also sets the search engine visibility to discouraged (blog_public=0; the earlier value is remembered and written back when switching off), sends X-Robots-Tag: noindex on every answer, marks every answer as not cacheable and empties the page cache of the supported caching plugins. What stays reachable: this MCP endpoint and the sign-in of apps, wp-admin and wp-login.php, the REST root /wp-json/ (the hub reads it to find the endpoint), the OAuth documents below /.well-known/, and the REST API for signed-in users and application passwords, so Gutenberg, the hub and the connected assistants keep working. What it does not cover: files under /wp-content/uploads/, which the web server delivers without PHP, stay reachable by their address unless the host adds a rule; caches outside PHP (a host cache, Cloudflare APO, a CDN) are not emptied; what a plugin answers before WordPress has parsed the request is not held back (the payment callbacks of the wc-api of WooCommerce, a download over ?download_file= and other handlers on init or wp_loaded), nor is pingback.ping of xmlrpc.php, which needs no login; the shared password identifies nobody, so the protection suits a staging site shown to a client, not confidential data. The password is generated here and returned once, in password, for the person to pass on to whoever should see the preview; only its hash is stored, and a new password signs every visitor out. Deactivating the plugin lifts the protection and writes the search engine visibility back; activating it again restores both, with the same password. For the few requests WordPress serves without loading plugins (wp-activate.php) the plugin keeps a small must-use loader in wp-content/mu-plugins/ while the protection is on; where that folder cannot be written, not_covered says so and loader_source holds the file to create by hand. Returns enabled, changed, password (only when a new one was set), blog_public, blog_public_before, since, set_by, page_cache and not_covered. Needs manage_options and write access.',
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

		$r->register(
			'wp_staging_status',
			array(
				'description' => 'Report where this site stands on the way to a staging site, and what to do next. Returns role: the role a person confirmed (production, staging, or unconfirmed, which counts as the production site), with who confirmed it, when and where (the page AlphaBridge → Staging or the hub), and a suggestion from what the site shows: the environment type WordPress reports, an address beginning with staging., stage., dev. or test. or ending in .test, .local or .localhost, the preview protection, the search engine visibility. A suggestion is never proof; only a person confirms the role, on the page AlphaBridge → Staging or in the hub. Returns pair (the other site of the pair, its role and who confirmed it where, or null), host (chosen by the person, detected from what the server shows with the evidence, or unknown; guide: the address of the host\'s own guide to its staging function where one is known, else null), preview_protection, blog_public, checklist (role confirmed, staging site there and answering, preview protection on the staging site, pair set: each with done true, false, or null where it is decided on the other site or not checked yet, its finding and, while open, numbered steps), next_step (the first open step with a direct link, to pass on to the person) and warnings. With check_partner=true it also asks the other site of the pair once whether AlphaBridge MCP answers there: one GET to its MCP endpoint without a token, only when every address of its host is public and tied to those addresses, not through a proxy, no redirect followed; the result is in partner_check. Reads only and changes nothing. Needs manage_options.',
				'capability'  => 'manage_options',
				'readonly'    => true,
				// With check_partner it asks another site, the one of the
				// pair; without, it reads this site only.
				'open_world'  => true,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'check_partner' => array(
							'type'        => 'boolean',
							'description' => 'true asks the other site of the pair whether AlphaBridge MCP answers there (one request to its stored address). Default false: nothing leaves this site.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'staging_status' ),
			)
		);

		if ( false === has_filter( 'ab_mcp_prompts', array( __CLASS__, 'add_prompt' ) ) ) {
			add_filter( 'ab_mcp_prompts', array( __CLASS__, 'add_prompt' ), 10, 2 );
		}
	}

	/**
	 * The guided prompt staging_workflow, under the condition of the
	 * sentence in the server instructions (AB_MCP_Staging::offered():
	 * wp_staging_status on in the registry the server answers with, and a
	 * connection that can write): the way from a change the person asks
	 * for to a staging site, the work there and the changes carried over
	 * one at a time. It names only what wp_staging_status reports about the
	 * host and advertises nothing.
	 *
	 * @param array                     $prompts  Prompts.
	 * @param AB_MCP_Tool_Registry|null $registry The tools of the server.
	 * @return array
	 */
	public static function add_prompt( $prompts, $registry = null ) {
		$prompts = is_array( $prompts ) ? $prompts : array();
		if ( ! AB_MCP_Staging::offered( $registry ) ) {
			return $prompts;
		}
		$prompts[] = array(
			'name'        => 'staging_workflow',
			'description' => 'Guided steps: make a change on a staging site first — say why and how, set up and protect the staging site, work there, then carry the change over to the production site one object at a time and check it.',
			'arguments'   => array(
				array(
					'name'        => 'change',
					'description' => 'What the person wants to change on the site. Optional.',
					'required'    => false,
				),
				array(
					'name'        => 'staging_site',
					'description' => 'Address of a staging site the person already has. Optional.',
					'required'    => false,
				),
			),
			'text'        => "You are helping the person change this WordPress site through AlphaBridge, on a staging site first. Change: {{change}}. Staging site, if there is one already: {{staging_site}}\n\n"
				. "1. Sum up. Say in a sentence or two what the person wants changed, and why staging comes first: a change on the production site is visible to visitors at once; on a staging site it is checked first and carried over once it is approved.\n"
				. "2. Look where the site stands. Call wp_staging_status and pass its findings on in plain words. Call it again after each step of setting up (steps 4 and 5), on the site you are working on: it checks the role, the pair, whether the staging site answers and its preview protection, and next_step says what comes next. It does not check the work or what was carried over: for that, read the objects on both sites with the reading tools.\n"
				. "3. Choose the way with the person: a staging copy made with the host's staging function or with a staging plugin, or an existing staging site connected to this one. " . AB_MCP_Staging::drafts_in_prompt() . " Follow the steps of the checklist. Name a host and its staging function only where wp_staging_status names them, and describe nothing of the host's or the plugin's screens beyond what it says.\n"
				. "4. Set it up. The person makes the copy, in the host's control panel or the plugin's own screen, and AlphaBridge MCP has to be active on it. On the page AlphaBridge → Staging of each site, the person confirms its role and enters the address of the other site; a copy can carry the settings of this site, so check the role on the copy. Then the person connects you to the staging site as well, so you can work there.\n"
				. "5. Protect the staging site. On the staging site, switch on the preview protection with wp_set_preview_protection (enabled=true), or on its page AlphaBridge → Staging, and give the person the password from the answer for whoever should see the preview. If the protection is on already, the answer says changed=false and carries no password: ask the person for the password they have, or, only with their yes, set a new one with new_password=true, which signs every visitor out; say so first.\n"
				. "6. Work on the staging site. Make the change there with the usual tools, show it to the person with the preview password, and get their approval.\n"
				. "7. Carry it over one object at a time. This needs a connection that can write on both sites; if one is missing or only reads, tell the person how to get it: connect you on the page AlphaBridge → AI Connection on that site (Connect your AI assistant) or add the site in the hub, with the access level Full (Content reaches posts, pages, media, terms and comments only) and write access switched on there. Read each changed page, post, menu, term or setting on the staging site and write it on the production site with the same tools, after showing the person what will change. Where a tool records an undo point, its answer carries an undo token, and wp_undo with it takes the change back; where the answer has none, tell the person that this change has no way back through AlphaBridge. A file: wp_upload_media_from_url with its address on the staging site (the preview protection does not cover files under /wp-content/uploads/).\n"
				. "8. Check. Read the changed objects on the production site again and tell the person what changed and where to look.\n\n"
				. 'If the person would rather not use staging, or chooses drafts, go ahead that way: the choice is theirs.',
		);
		return $prompts;
	}

	/**
	 * wp_staging_status.
	 *
	 * @param array $a Arguments.
	 * @return array
	 */
	public static function staging_status( array $a ) {
		return AB_MCP_Staging::report( self::b( $a, 'check_partner' ) );
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
