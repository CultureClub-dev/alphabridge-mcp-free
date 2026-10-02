<?php
/**
 * The way to what an assistant was refused.
 *
 * An assistant that is told «no» passes that on to the person it works for,
 * and that person wants to get done what they asked for. So every refusal of
 * the site's own rules says, in this order: why, in one sentence; what the
 * person can do about it, step by step; a direct link to the place where it
 * is done; and that the assistant should pass this on kindly and try again
 * afterwards (compose()). The rules themselves stay as they are: the person
 * is led to the switch, nothing is switched for them.
 *
 * The same holds for a tool that is not on this site because it belongs to
 * AlphaBridge MCP Pro or its Agency plan: a call of one of those names is
 * answered with what it is and where it comes from (unavailable_tool())
 * instead of «Unknown tool». The names are a fixed list (PRO_TOOLS,
 * AGENCY_TOOLS); no code of Pro is in this plugin and nothing here is locked.
 * A test in the Pro add-on compares the list with what Pro registers.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Guidance
 */
class AB_MCP_Guidance {

	/**
	 * Where AlphaBridge MCP Pro and its plans are described, with prices.
	 */
	const PRICING_URL = 'https://alphabridge-mcp.com/#pricing';

	/**
	 * The tools AlphaBridge MCP Pro adds, without those of its Agency plan,
	 * as Pro 4.6.0 registers them. Names Pro registers again over a tool of this
	 * plugin (wp_get_post, wp_update_post and others) are not here: they run
	 * on every site.
	 */
	const PRO_TOOLS = array(
		'wp_activate_plugin',
		'wp_activate_theme',
		'wp_add_menu_item',
		'wp_add_widget',
		'wp_blueprint_diff',
		'wp_blueprint_export',
		'wp_bulk_update_status',
		'wp_cleanup_transients',
		'wp_clear_cache',
		'wp_create_application_password',
		'wp_create_menu',
		'wp_create_user',
		'wp_db_execute',
		'wp_db_optimize',
		'wp_db_query',
		'wp_db_schema',
		'wp_db_tables',
		'wp_deactivate_plugin',
		'wp_delete_menu_item',
		'wp_delete_option',
		'wp_delete_php_snippet',
		'wp_delete_plugin',
		'wp_delete_plugin_file',
		'wp_delete_theme',
		'wp_delete_theme_file',
		'wp_delete_transient',
		'wp_delete_upload_file',
		'wp_delete_user',
		'wp_delete_widget',
		'wp_export_content',
		'wp_flush_rewrite_rules',
		'wp_get_cron',
		'wp_get_error_log',
		'wp_get_menu',
		'wp_get_php_snippet',
		'wp_get_user',
		'wp_install_plugin',
		'wp_install_plugin_zip',
		'wp_install_theme',
		'wp_install_theme_zip',
		'wp_list_abilities',
		'wp_list_menus',
		'wp_list_php_snippets',
		'wp_list_plugin_files',
		'wp_list_plugins',
		'wp_list_theme_files',
		'wp_list_themes',
		'wp_list_uploads',
		'wp_list_users',
		'wp_network_activate_plugin',
		'wp_network_create_site',
		'wp_network_list_sites',
		'wp_read_plugin_file',
		'wp_read_theme_file',
		'wp_read_upload_file',
		'wp_restore_revision',
		'wp_revoke_application_password',
		'wp_run_ability',
		'wp_save_php_snippet',
		'wp_schedule_cron',
		'wp_search_replace',
		'wp_seo_set',
		'wp_set_transient',
		'wp_toggle_php_snippet',
		'wp_undo',
		'wp_undo_discard',
		'wp_undo_list',
		'wp_unschedule_cron',
		'wp_update_builder_element',
		'wp_update_core',
		'wp_update_option',
		'wp_update_plugin',
		'wp_update_post_meta',
		'wp_update_site_settings',
		'wp_update_term_meta',
		'wp_update_theme',
		'wp_update_user',
		'wp_update_user_meta',
		'wp_update_widget',
		'wp_upload_abort',
		'wp_upload_append',
		'wp_upload_begin',
		'wp_upload_commit',
		'wp_wc_create_coupon',
		'wp_wc_get_order',
		'wp_wc_get_product',
		'wp_wc_list_coupons',
		'wp_wc_list_customers',
		'wp_wc_list_orders',
		'wp_wc_list_products',
		'wp_wc_update_order_status',
		'wp_wc_update_product',
		'wp_write_plugin_file',
		'wp_write_theme_file',
		'wp_write_upload_file',
	);

	/**
	 * The tools of the Agency plan of AlphaBridge MCP Pro: applying a
	 * blueprint, and Site Deploy.
	 */
	const AGENCY_TOOLS = array(
		'wp_blueprint_apply',
		'wp_deploy_delete',
		'wp_deploy_diff',
		'wp_deploy_list',
		'wp_deploy_push_file',
		'wp_deploy_push_zip',
		'wp_deploy_read',
		'wp_deploy_test',
	);

	/**
	 * The plugin's settings page, at an anchor: «ab-mode» is the main switch
	 * for write access, «ab-fine» the fine-tuning, «ab-tool-<name>» one tool
	 * in it (the page opens the groups around it), «ab-connections» the
	 * connections. The site's own admin address, so on a network the site the
	 * connection belongs to.
	 *
	 * @param string $anchor Id on the page, without «#»; '' for none.
	 * @return string
	 */
	public static function settings_url( $anchor = '' ) {
		$url    = admin_url( 'options-general.php?page=alphabridge-mcp' );
		$anchor = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $anchor );
		return '' !== $anchor ? $url . '#' . $anchor : $url;
	}

	/**
	 * The handover() in English, for an answer whose own sentences are
	 * English (compose() with $translate false). The same words as the text
	 * handover() translates; a test holds them together.
	 */
	const HANDOVER = 'Pass this on to the person you are working for in a friendly way, with the steps and the link, and try again once it is done; do not look for a way around it.';

	/**
	 * The sentence that asks the assistant to pass a refusal on and to try
	 * again: the person decides, the assistant does not look for a way
	 * around the rule.
	 *
	 * @return string
	 */
	public static function handover() {
		return __( 'Pass this on to the person you are working for in a friendly way, with the steps and the link, and try again once it is done; do not look for a way around it.', 'alphabridge-mcp' );
	}

	/**
	 * A refusal with its way: the reason in one sentence, the steps for the
	 * person, the direct link and the handover(). A part that is empty is
	 * left out.
	 *
	 * The link word and the handover are in the site's language. An add-on
	 * whose reason and steps stay English (the Pro add-on ships no
	 * translations) passes $translate false, so that one answer reads in one
	 * language instead of English with German pieces in it.
	 *
	 * @param string $reason    Why, in one sentence.
	 * @param string $steps     What the person can do.
	 * @param string $url       Where it is done.
	 * @param bool   $translate Whether the parts added here are translated.
	 * @return string
	 */
	public static function compose( $reason, $steps, $url, $translate = true ) {
		$link = $translate
			/* translators: %s: address of the page where the person can do it. */
			? sprintf( __( 'Direct link: %s', 'alphabridge-mcp' ), (string) $url )
			: sprintf( 'Direct link: %s', (string) $url );
		$parts = array( trim( (string) $reason ), trim( (string) $steps ), $link, $translate ? self::handover() : self::HANDOVER );
		return implode( ' ', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * The refusal for what the WordPress role of the connection's account
	 * does not allow: this post, this user, publishing. The tool's own
	 * sentences say what was refused and which account may do it; this adds
	 * that an administrator can change the account's role under Users, the
	 * direct link there and the handover(), so the assistant leads the person
	 * to it instead of answering with a bare «no». The role itself is never
	 * changed here.
	 *
	 * @param string $message What was refused and who may do it, as the tool says it.
	 * @param array  $args {
	 *     @type string $code      Error code; 'ab_mcp_forbidden' by default.
	 *     @type string $steps     The steps; role_steps() by default.
	 *     @type string $url       The link; Users (admin_url( 'users.php' )) by default.
	 *     @type bool   $translate See compose(); true by default.
	 * }
	 * @return WP_Error
	 */
	public static function role_refusal( $message, array $args = array() ) {
		$translate = ! array_key_exists( 'translate', $args ) || (bool) $args['translate'];
		$steps     = isset( $args['steps'] ) ? (string) $args['steps'] : self::role_steps( $translate );
		$url       = isset( $args['url'] ) ? (string) $args['url'] : admin_url( 'users.php' );
		$code      = isset( $args['code'] ) && '' !== (string) $args['code'] ? (string) $args['code'] : 'ab_mcp_forbidden';
		return new WP_Error( $code, self::compose( (string) $message, $steps, $url, $translate ) );
	}

	/**
	 * The steps of a role_refusal(): an administrator can give the account
	 * of the connection a role that allows it, under Users.
	 *
	 * @param bool $translate See compose().
	 * @return string
	 */
	public static function role_steps( $translate = true ) {
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$login = is_object( $user ) && isset( $user->user_login ) && '' !== (string) $user->user_login ? (string) $user->user_login : '#' . (int) get_current_user_id();
		$text  = $translate
			/* translators: %s: user name of the connection's account. */
			? __( 'If the account "%s" should be allowed to do this, an administrator can give it a role that allows it under Users.', 'alphabridge-mcp' )
			: 'If the account "%s" should be allowed to do this, an administrator can give it a role that allows it under Users.';
		return sprintf( $text, $login );
	}

	/**
	 * Which edition a tool name belongs to when this plugin does not have it.
	 *
	 * @param string $name Tool name.
	 * @return string 'agency', 'pro' or '' for a name of neither list.
	 */
	public static function edition_of( $name ) {
		$name = (string) $name;
		if ( in_array( $name, self::AGENCY_TOOLS, true ) ) {
			return 'agency';
		}
		return in_array( $name, self::PRO_TOOLS, true ) ? 'pro' : '';
	}

	/**
	 * The answer to a call of a tool this site does not have because it
	 * belongs to AlphaBridge MCP Pro or its Agency plan, or null for any
	 * other name. The caller asks only for a name that is not registered.
	 *
	 * @param string $name Tool name.
	 * @return WP_Error|null Code ab_mcp_needs_pro.
	 */
	public static function unavailable_tool( $name ) {
		$edition = self::edition_of( $name );
		if ( '' === $edition ) {
			return null;
		}
		if ( 'agency' === $edition ) {
			$message = self::compose(
				/* translators: %s: tool name */
				sprintf( __( 'The tool "%s" is not on this site: it belongs to the Agency plan of AlphaBridge MCP Pro, a separate plugin that is not active here.', 'alphabridge-mcp' ), (string) $name ),
				__( 'To use it, the site owner installs and activates AlphaBridge MCP Pro with the Agency plan; its tools then show up for every connection, and the switch for write access on the settings page decides whether they may change the site.', 'alphabridge-mcp' ),
				self::PRICING_URL
			);
		} else {
			$message = self::compose(
				/* translators: %s: tool name */
				sprintf( __( 'The tool "%s" is not on this site: it belongs to AlphaBridge MCP Pro, a separate plugin that is not active here.', 'alphabridge-mcp' ), (string) $name ),
				__( 'To use it, the site owner installs and activates AlphaBridge MCP Pro; its tools then show up for every connection, and the switch for write access on the settings page decides whether they may change the site.', 'alphabridge-mcp' ),
				self::PRICING_URL
			);
		}
		/**
		 * The answer to a call of a tool of AlphaBridge MCP Pro or its Agency
		 * plan that is not registered on this site. The Pro add-on answers
		 * here when it is installed but its licence is not active, or when the
		 * plan does not include the tool: activate or renew the licence, or
		 * change the plan.
		 *
		 * @since 4.5.0
		 *
		 * @param string $message The core's answer.
		 * @param string $name    Tool name.
		 * @param string $edition 'pro' or 'agency'.
		 */
		$filtered = apply_filters( 'ab_mcp_unavailable_tool_message', $message, (string) $name, $edition );
		$message  = is_string( $filtered ) && '' !== trim( $filtered ) ? $filtered : $message;
		return new WP_Error( 'ab_mcp_needs_pro', $message );
	}

	/**
	 * The paragraph of the server instructions on a site without the tools
	 * of AlphaBridge MCP Pro: what Pro adds and where it is, so an assistant
	 * asked for one of those things can say so instead of trying the
	 * impossible or failing without a word. Facts only; written for the
	 * assistant, in the language of the rest of the instructions.
	 *
	 * @return string
	 */
	public static function instructions_paragraph() {
		return 'NOT ON THIS SITE: the tools of AlphaBridge MCP Pro, a separate plugin. It adds theme and plugin files, PHP snippets, the database, users and menus, WooCommerce, the texts of page-builder pages, installing and updating plugins and themes, search and replace with preview, undo, export, multisite and the abilities of other plugins; its Agency plan adds applying blueprints and Site Deploy over FTP/SFTP. When the person asks for one of these, tell them kindly that this site needs AlphaBridge MCP Pro for it and where it is: ' . self::PRICING_URL;
	}
}
