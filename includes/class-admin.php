<?php
/**
 * Admin settings screen (submenu under Settings).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Admin
 */
class AB_MCP_Admin {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ab_mcp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ab_mcp_token', array( $this, 'handle_token' ) );
		add_action( 'admin_post_ab_mcp_connector_auth', array( $this, 'handle_connector_auth' ) );
		add_action( 'admin_post_ab_mcp_oauth_settings', array( $this, 'handle_oauth_settings' ) );
		add_action( 'admin_post_ab_mcp_audit_clear', array( $this, 'handle_audit_clear' ) );
		// Creating or rotating a token reveals a secret. That is done over
		// authenticated admin-ajax so the plaintext is returned once, directly to
		// the admin's own browser, and is never written to the database — not even
		// to a short-lived transient (which, without a persistent object cache,
		// lands in wp_options).
		add_action( 'wp_ajax_ab_mcp_create_token', array( $this, 'ajax_create_token' ) );
		add_action( 'wp_ajax_ab_mcp_rotate_token', array( $this, 'ajax_rotate_token' ) );
		add_action( 'wp_ajax_ab_mcp_enable_url_auth', array( $this, 'ajax_enable_url_auth' ) );
	}

	/**
	 * Register the menu as a sub-item of Settings.
	 */
	public function menu() {
		add_options_page(
			'AlphaBridge MCP',
			'AlphaBridge MCP',
			'manage_options',
			'alphabridge-mcp',
			array( $this, 'render' )
		);
	}

	/**
	 * Capability guard.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'alphabridge-mcp' ) );
		}
	}

	/**
	 * Save tool enable/disable state.
	 */
	public function handle_save() {
		$this->guard();
		check_admin_referer( 'ab_mcp_save' );

		$registry = AB_MCP_Plugin::instance()->registry;
		$enabled  = isset( $_POST['enabled_tools'] ) && is_array( $_POST['enabled_tools'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_tools'] ) )
			: array();

		$state = array();
		foreach ( $registry->all() as $name => $def ) {
			$state[ $name ] = in_array( $name, $enabled, true );
		}
		AB_MCP_Settings::set_tool_state( $state );

		// Global read-only switch (blocks every writing tool regardless of the per-tool toggles).
		AB_MCP_Settings::set( 'read_only', isset( $_POST['read_only'] ) );

		$this->redirect( 'saved' );
	}

	/**
	 * Rename or delete a token. Creating and rotating (which reveal a secret) are
	 * handled over authenticated ajax instead — see ajax_create_token() /
	 * ajax_rotate_token() — so no plaintext token is ever persisted.
	 */
	public function handle_token() {
		$this->guard();
		check_admin_referer( 'ab_mcp_token' );

		if ( isset( $_POST['rename_token'] ) ) {
			AB_MCP_Settings::rename_token(
				sanitize_text_field( wp_unslash( $_POST['rename_token'] ) ),
				sanitize_text_field( wp_unslash( $_POST['rename_label'] ?? '' ) )
			);
			$this->redirect( 'saved' );
		}

		if ( isset( $_POST['delete_token'] ) ) {
			AB_MCP_Settings::delete_token( sanitize_text_field( wp_unslash( $_POST['delete_token'] ) ) );
			$this->redirect( 'token_deleted' );
		}

		$this->redirect( 'saved' );
	}

	/**
	 * Toggle connector-URL (token-in-path) authentication on or off.
	 */
	public function handle_connector_auth() {
		$this->guard();
		check_admin_referer( 'ab_mcp_connector_auth' );
		AB_MCP_Settings::set( 'connector_url_auth_enabled', isset( $_POST['connector_url_auth_enabled'] ) );
		$this->redirect( 'saved' );
	}

	/**
	 * Toggle AlphaBridge Connect (Claude's native Connect button / OAuth) on or
	 * off. Off = the well-known metadata, consent page and OAuth endpoints all
	 * disappear; existing connections keep working (they are ordinary tokens).
	 */
	public function handle_oauth_settings() {
		$this->guard();
		check_admin_referer( 'ab_mcp_oauth_settings' );
		AB_MCP_Settings::set( 'oauth_enabled', isset( $_POST['oauth_enabled'] ) );
		$this->redirect( 'saved' );
	}

	/**
	 * AJAX: create a connection (token) and return the plaintext ONCE.
	 *
	 * The secret is sent only in this JSON response, straight to the authenticated
	 * admin's browser, and is never stored in readable form.
	 */
	public function ajax_create_token() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : get_current_user_id();
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( '' === $label ) {
			$label = __( 'Connection', 'alphabridge-mcp' );
		}
		$scope   = AB_MCP_Settings::sanitize_scope(
			isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'full'
		);
		$days    = isset( $_POST['expires_days'] ) ? absint( wp_unslash( $_POST['expires_days'] ) ) : 0;
		$days    = min( $days, 3650 ); // Cap at ~10 years so a fat-fingered value can't overflow.
		$expires = $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0;

		$plain = AB_MCP_Settings::add_token( $user_id, $label, $scope, $expires );
		$this->send_token_json( $plain );
	}

	/**
	 * AJAX: rotate a connection's secret and return the new plaintext ONCE.
	 */
	public function ajax_rotate_token() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		$hash  = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		$plain = AB_MCP_Settings::rotate_token( $hash );
		if ( '' === (string) $plain ) {
			wp_send_json_error( array( 'message' => __( 'Connection not found.', 'alphabridge-mcp' ) ), 404 );
		}
		$this->send_token_json( $plain );
	}

	/**
	 * AJAX: switch connector-URL (token-in-path) authentication on from the reveal
	 * box. Same option as the Advanced form below — this is the one-click path so
	 * that a connector URL is only ever shown when it will actually authenticate.
	 */
	public function ajax_enable_url_auth() {
		$this->ajax_guard();
		check_ajax_referer( 'ab_mcp_ajax', 'nonce' );

		AB_MCP_Settings::set( 'connector_url_auth_enabled', true );
		wp_send_json_success( array( 'pathAuth' => true ) );
	}

	/**
	 * Capability guard for the token ajax endpoints. The nonce is verified inline
	 * in each handler, right after this call, so it is checked before any request
	 * data is read.
	 */
	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'alphabridge-mcp' ) ), 403 );
		}
	}

	/**
	 * Emit the one-time token JSON payload: the plaintext, the (opt-in) connector
	 * URL, and the freshly rendered connections-table row so the UI can update in
	 * place without a reload (which would lose the never-again-shown secret).
	 *
	 * @param string $plain Plaintext token just created or rotated.
	 */
	private function send_token_json( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			wp_send_json_error( array( 'message' => __( 'Could not create the connection.', 'alphabridge-mcp' ) ), 500 );
		}

		// add_token()/rotate_token() both leave the affected entry last.
		$tokens = AB_MCP_Settings::get_tokens();
		$entry  = ! empty( $tokens ) ? end( $tokens ) : array();

		$endpoint  = untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
		$path_auth = (bool) AB_MCP_Settings::get( 'connector_url_auth_enabled', false );

		wp_send_json_success(
			array(
				'token'     => $plain,
				'endpoint'  => $endpoint,
				'connector' => $path_auth ? $endpoint . '/' . $plain : '',
				'pathAuth'  => $path_auth,
				'hash'      => isset( $entry['hash'] ) ? (string) $entry['hash'] : '',
				'row'       => is_array( $entry ) && ! empty( $entry ) ? $this->connection_row_html( $entry ) : '',
			)
		);
	}

	/**
	 * Clear audit log.
	 */
	public function handle_audit_clear() {
		$this->guard();
		check_admin_referer( 'ab_mcp_audit_clear' );
		AB_MCP_Audit_Log::clear();
		$this->redirect( 'audit_cleared' );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $notice Notice key.
	 */
	private function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'alphabridge-mcp',
					'ab_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Badge markup for powerful ("mighty") tool groups.
	 *
	 * @param bool $mighty Whether the group contains dangerous tools.
	 * @return string
	 */
	private function badge( $mighty ) {
		if ( ! $mighty ) {
			return '';
		}
		return '<span style="font-size:11px;border-radius:10px;padding:1px 8px;color:#8a2b00;background:#fbeae3">' . esc_html__( 'Mighty', 'alphabridge-mcp' ) . '</span>';
	}

	/**
	 * Render the page.
	 */
	public function render() {
		$this->guard();

		$endpoint  = untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
		$tokens    = AB_MCP_Settings::get_tokens();
		$path_auth = (bool) AB_MCP_Settings::get( 'connector_url_auth_enabled', false );
		$registry  = AB_MCP_Plugin::instance()->registry;
		$groups    = $registry->groups();
		$all       = $registry->all();

		echo '<div class="wrap"><h1>AlphaBridge MCP</h1>';
		echo '<p class="description" style="margin:0 0 14px">' . esc_html__( 'Settings › AlphaBridge MCP', 'alphabridge-mcp' ) . '</p>';
		$this->notice();
		if ( AB_MCP_Settings::get( 'read_only', false ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Read-only mode is active.', 'alphabridge-mcp' ) . '</strong> ' . esc_html__( 'All writing tools are blocked until you switch it off under Capabilities.', 'alphabridge-mcp' ) . '</p></div>';
		}

		echo '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-top:10px">';

		/* ---- Main column ---- */
		echo '<div style="flex:2;min-width:340px">';

		// ── Connector box: the ONE place to create and manage connections. ──
		echo '<div class="postbox" style="padding:4px 16px 16px"><h2 class="hndle" style="padding:10px 0;border:0">Claude.ai Connector</h2>';
		echo '<p class="description" style="margin:0 0 16px">' . esc_html__( 'Copy the endpoint, add it in Claude and click Connect — or create a token manually for other clients.', 'alphabridge-mcp' ) . '</p>';

		// Step 1 — endpoint (not a secret; always visible).
		echo '<p style="margin:0 0 4px;font-weight:600">' . esc_html__( 'Step 1 — Copy the endpoint URL', 'alphabridge-mcp' ) . '</p>';
		echo '<div style="display:flex;gap:8px;align-items:center;margin:0 0 12px"><input type="text" readonly value="' . esc_attr( $endpoint ) . '" class="regular-text ab-select" style="flex:1;font-family:monospace;font-size:12px"><button type="button" class="button ab-copy" data-copy="' . esc_attr( $endpoint ) . '">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';

		// Step 2 — the recommended path: Claude's native Connect button (OAuth).
		// The site handles login + consent itself; nothing to copy but the URL.
		if ( class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled() ) {
			echo '<p style="margin:0 0 4px;font-weight:600">' . esc_html__( 'Step 2 — Connect from Claude (recommended)', 'alphabridge-mcp' ) . '</p>';
			echo '<div style="margin:0 0 20px;padding:10px 14px;border:1px solid #b7e0c1;border-radius:6px;background:#f4fbf6">';
			echo '<p style="margin:0">' . esc_html__( 'In Claude: Settings → Connectors → “Add custom connector” → paste the endpoint URL → Connect. Claude opens this site’s login and asks for your approval — no token to copy. The approved connection appears below and can be revoked at any time.', 'alphabridge-mcp' ) . '</p>';
			echo '</div>';
			echo '<p style="margin:0 0 6px;font-weight:600">' . esc_html__( 'Manual setup — create a token (Cursor, Claude Code, scripts)', 'alphabridge-mcp' ) . '</p>';
		} else {
			echo '<p style="margin:0 0 6px;font-weight:600">' . esc_html__( 'Step 2 — Create a connection', 'alphabridge-mcp' ) . '</p>';
		}
		echo '<div class="ab-create-form">';
		echo '<p style="margin:0 0 6px"><button type="button" class="button button-primary button-hero ab-create">' . esc_html__( 'Create connection', 'alphabridge-mcp' ) . '</button></p>';
		echo '<details style="margin:0"><summary style="cursor:pointer;color:#2271b1;display:inline-block;padding:2px 0">' . esc_html__( 'Advanced options (optional)', 'alphabridge-mcp' ) . '</summary>';
		echo '<div style="padding:10px 0 2px;display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center">';
		echo '<label>' . esc_html__( 'Label', 'alphabridge-mcp' ) . ' <input type="text" name="label" placeholder="' . esc_attr__( 'e.g. Claude Desktop', 'alphabridge-mcp' ) . '" class="regular-text" style="width:170px"></label>';
		echo '<label>' . esc_html__( 'User', 'alphabridge-mcp' ) . ' ';
		wp_dropdown_users(
			array(
				'name'     => 'user_id',
				'selected' => get_current_user_id(),
				'show'     => 'user_login',
			)
		);
		echo '</label>';
		echo '<label>' . esc_html__( 'Access', 'alphabridge-mcp' ) . ' <select name="scope"><option value="full">' . esc_html__( 'Full', 'alphabridge-mcp' ) . '</option><option value="content">' . esc_html__( 'Content only', 'alphabridge-mcp' ) . '</option><option value="read">' . esc_html__( 'Read only', 'alphabridge-mcp' ) . '</option></select></label>';
		echo '<label>' . esc_html__( 'Expires in', 'alphabridge-mcp' ) . ' <input type="number" name="expires_days" min="0" max="3650" step="1" value="0" style="width:64px"> ' . esc_html__( 'days (0 = never)', 'alphabridge-mcp' ) . '</label>';
		echo '</div>';
		echo '<p class="description" style="margin:6px 0 0">' . esc_html__( 'Default: full access for you, no expiry. Choose “Content only” or “Read only” to hand a client a deliberately limited key.', 'alphabridge-mcp' ) . '</p>';
		echo '</details>';
		echo '</div>';

		// One-time reveal — appears in place, right below the button, the moment a
		// token is created or rotated. Populated by JS from the ajax response and
		// never shown again (only the hash is stored server-side).
		echo '<div class="ab-reveal" hidden style="margin:14px 0 0;padding:12px 14px;border:1px solid #b7e0c1;border-radius:6px;background:#f4fbf6">';
		echo '<p style="margin:0 0 8px;font-weight:600;color:#008a20">' . esc_html__( '✓ Connection created.', 'alphabridge-mcp' ) . '</p>';
		echo '<div class="notice notice-warning inline" style="margin:0 0 14px;padding:8px 12px"><p style="margin:0"><strong>' . esc_html__( 'Note this down now — it is shown in full only once.', 'alphabridge-mcp' ) . '</strong> ' . esc_html__( 'The token is never stored in readable form. Save it somewhere safe (e.g. a password manager). If you lose it, rotate the connection to get a new one.', 'alphabridge-mcp' ) . '</p></div>';

		// 1) Connector URL with the token embedded — the one-paste form for Claude.ai.
		// The URL row is shown ONLY when connector-URL authentication is enabled, so
		// a copied URL always works. Otherwise a one-click enable button takes its
		// place (same option as under Advanced, same warning) — enabling reveals the
		// URL in place without losing the one-time token.
		echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Connector URL', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(for Claude.ai — token included)', 'alphabridge-mcp' ) . '</span></p>';
		echo '<div class="ab-reveal-url-row" hidden style="display:flex;gap:8px;align-items:center;margin:0 0 4px"><input type="text" readonly value="" class="regular-text ab-select ab-reveal-url" style="flex:1;font-family:monospace;font-size:12px"><button type="button" class="button button-primary ab-copy" data-copy-target=".ab-reveal-url">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';
		echo '<div class="ab-reveal-url-enable" hidden style="margin:0 0 4px;padding:8px 12px;border:1px solid #dba617;border-radius:4px;background:#fcf9e8">';
		echo '<p style="margin:0 0 8px">' . esc_html__( 'Claude.ai connects via a URL that embeds the token in its path. This is currently switched off, so no connector URL is offered yet.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></p>';
		echo '<button type="button" class="button ab-enable-url-auth">' . esc_html__( 'Enable and show the connector URL', 'alphabridge-mcp' ) . '</button>';
		echo '</div>';
		echo '<div class="ab-reveal-url-gap" style="height:10px"></div>';

		// 2) Bearer token — for clients that take a URL and a token separately.
		echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Bearer token', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(URL + token entered separately)', 'alphabridge-mcp' ) . '</span></p>';
		echo '<div style="display:flex;gap:8px;align-items:center;margin:0 0 14px"><input type="text" readonly value="" class="regular-text ab-select ab-reveal-token" style="flex:1;font-family:monospace;font-size:12px"><button type="button" class="button ab-copy" data-copy-target=".ab-reveal-token">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';

		// 3) Ready-made config for Cursor / Claude Code (header authentication).
		echo '<p style="margin:0 0 4px"><strong>' . esc_html__( 'Config for Cursor / Claude Code', 'alphabridge-mcp' ) . '</strong></p>';
		echo '<div style="display:flex;gap:8px;align-items:flex-start;margin:0 0 12px"><textarea readonly rows="9" class="ab-reveal-json ab-select" style="flex:1;font-family:monospace;font-size:12px;white-space:pre;overflow:auto"></textarea><button type="button" class="button ab-copy" data-copy-target=".ab-reveal-json">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';

		echo '<p class="description" style="margin:0">' . esc_html__( 'Claude.ai: Settings → Connectors → “Add custom connector” and paste the Connector URL. Cursor / Claude Code: paste the config into your MCP settings file.', 'alphabridge-mcp' ) . '</p>';
		echo '</div>';

		// Your connections (manage list — rotate / delete; no second create button).
		echo '<hr style="margin:20px 0 12px;border:0;border-top:1px solid #f0f0f1">';
		echo '<p id="ab-connections" style="margin:0 0 8px;font-weight:600">' . esc_html__( 'Your connections', 'alphabridge-mcp' ) . '</p>';
		$this->render_connection_table( $tokens );

		// Advanced (collapsed): connector-URL (token-in-path) authentication, opt-in.
		echo '<details style="margin:16px 0 0;padding:12px 0 0;border-top:1px solid #f0f0f1"><summary style="cursor:pointer;color:#2271b1;display:inline-block">' . esc_html__( 'Connector-URL authentication (advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0 0">';
		wp_nonce_field( 'ab_mcp_connector_auth' );
		echo '<input type="hidden" name="action" value="ab_mcp_connector_auth">';
		echo '<label style="display:flex;align-items:flex-start;gap:8px"><input type="checkbox" name="connector_url_auth_enabled" value="1"' . checked( $path_auth, true, false ) . '><span>' . esc_html__( 'Also offer a connector URL that embeds the token in its path.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'Off by default. Header authentication (Authorization / X-Api-Key) always works. A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></span></label>';
		echo '<p style="margin:8px 0 0"><button class="button button-small">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';

		// Advanced (collapsed): AlphaBridge Connect — Claude's native Connect
		// button (OAuth). On by default; switching it off removes the discovery
		// metadata, the consent page and the OAuth endpoints entirely.
		$oauth_on = (bool) AB_MCP_Settings::get( 'oauth_enabled', true );
		echo '<details style="margin:10px 0 0;padding:12px 0 0;border-top:1px solid #f0f0f1"><summary style="cursor:pointer;color:#2271b1;display:inline-block">' . esc_html__( 'Connect from Claude (OAuth, advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0 0">';
		wp_nonce_field( 'ab_mcp_oauth_settings' );
		echo '<input type="hidden" name="action" value="ab_mcp_oauth_settings">';
		echo '<label style="display:flex;align-items:flex-start;gap:8px"><input type="checkbox" name="oauth_enabled" value="1"' . checked( $oauth_on, true, false ) . '><span>' . esc_html__( 'Let Claude connect with its native Connect button.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'On by default. Claude discovers this site, you approve on a login-protected consent screen, and the approved connection appears in the list above (revocable any time). Nothing connects without that approval. Switching this off removes the OAuth endpoints; existing connections keep working.', 'alphabridge-mcp' ) . '</span></span></label>';
		echo '<p style="margin:8px 0 0"><button class="button button-small">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';
		echo '</div>';

		// Capabilities card.
		echo '<div class="postbox" style="padding:4px 16px 14px"><h2 class="hndle" style="padding:10px 0;border:0">' . esc_html__( 'Capabilities', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="description" style="margin:0 0 8px">' . esc_html__( 'Enable or disable tool groups. Disabled tools are removed from the MCP server and REST API entirely — Claude cannot see or call them.', 'alphabridge-mcp' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ab-caps">';
		wp_nonce_field( 'ab_mcp_save' );
		echo '<input type="hidden" name="action" value="ab_mcp_save">';
		echo '<input type="hidden" name="all_tools" value="' . esc_attr( implode( ',', array_keys( $all ) ) ) . '">';
		echo '<p style="margin:6px 0 12px"><button type="button" class="button ab-all" data-on="1">' . esc_html__( 'Enable all', 'alphabridge-mcp' ) . '</button> <button type="button" class="button ab-all" data-on="0">' . esc_html__( 'Disable all', 'alphabridge-mcp' ) . '</button></p>';

		$read_only = (bool) AB_MCP_Settings::get( 'read_only', false );
		echo '<label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;padding:9px 12px;border:1px solid ' . ( $read_only ? '#f0b849' : '#dcdcde' ) . ';border-radius:6px;background:' . ( $read_only ? '#fcf9e8' : '#f6f7f7' ) . '">';
		echo '<input type="checkbox" name="read_only" value="1" ' . checked( $read_only, true, false ) . '>';
		echo '<span><strong>' . esc_html__( 'Read-only mode', 'alphabridge-mcp' ) . '</strong> — <span class="description">' . esc_html__( 'blocks every writing tool with one switch, regardless of the toggles below; read, list and search tools keep working.', 'alphabridge-mcp' ) . '</span></span></label>';

		foreach ( $groups as $slug => $g ) {
			$on_count = 0;
			foreach ( $g['tools'] as $tname ) {
				if ( AB_MCP_Settings::is_tool_enabled( $tname, $all[ $tname ] ) ) {
					++$on_count;
				}
			}
			$status = $this->group_status( $on_count, (int) $g['count'] );

			echo '<details class="ab-group" style="border:1px solid #dcdcde;border-radius:6px;margin:0 0 8px">';
			echo '<summary style="list-style:none;cursor:pointer;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px">';
			echo '<span style="display:flex;align-items:center;gap:8px"><strong>' . esc_html( $g['label'] ) . '</strong> ' . wp_kses( $this->badge( ! empty( $g['mighty'] ) ), array( 'span' => array( 'style' => true ) ) ) . ' <span class="description">' . sprintf( /* translators: %d: number of tools */ esc_html__( '%d tools', 'alphabridge-mcp' ), (int) $g['count'] ) . '</span></span>';
			echo '<span class="ab-status" style="font-size:12px;font-weight:600">' . wp_kses( $status, array( 'span' => array( 'style' => true ) ) ) . '</span>';
			echo '</summary>';
			echo '<div style="padding:4px 14px 12px">';
			echo '<p style="margin:0 0 6px"><button type="button" class="button button-small ab-grp" data-on="1">' . esc_html__( 'Group on', 'alphabridge-mcp' ) . '</button> <button type="button" class="button button-small ab-grp" data-on="0">' . esc_html__( 'Group off', 'alphabridge-mcp' ) . '</button></p>';
			foreach ( $g['tools'] as $tname ) {
				$def     = $all[ $tname ];
				$checked = AB_MCP_Settings::is_tool_enabled( $tname, $def );
				echo '<label style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:13px">';
				echo '<input type="checkbox" class="ab-tool" name="enabled_tools[]" value="' . esc_attr( $tname ) . '" ' . checked( $checked, true, false ) . '>';
				echo '<code>' . esc_html( $tname ) . '</code> <span class="description">' . esc_html( wp_strip_all_tags( $def['description'] ) ) . '</span></label>';
			}
			echo '</div></details>';
		}

		echo '<p style="margin:14px 0 0"><button class="button button-primary">' . esc_html__( 'Save capabilities', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form>';
		echo '<p class="description" style="margin:12px 0 0">' . esc_html__( 'Abuse protection active (fixed):', 'alphabridge-mcp' ) . ' ' . (int) AB_MCP_Settings::get( 'rate_limit_per_min', 120 ) . ' ' . esc_html__( 'requests/min.', 'alphabridge-mcp' ) . '</p>';
		echo '</div>';

		/**
		 * Add-ons render extra boxes in the main column here (e.g. the Pro
		 * add-on's Site-Deploy connection).
		 */
		do_action( 'ab_mcp_admin_main_boxes' );

		$this->render_audit();

		echo '</div>'; // main.

		/* ---- Sidebar ---- */
		echo '<div style="flex:1;min-width:220px">';

		echo '<div class="postbox" style="padding:4px 16px 14px"><h2 class="hndle" style="padding:10px 0;border:0">' . esc_html__( 'Documentation', 'alphabridge-mcp' ) . '</h2><p class="description" style="margin:0 0 8px">' . esc_html__( 'Setup, all tools and examples.', 'alphabridge-mcp' ) . '</p><p style="margin:0 0 6px"><a href="https://www.alphabridge-mcp.com/docs" target="_blank" rel="noopener">' . esc_html__( 'Getting started →', 'alphabridge-mcp' ) . '</a></p><p style="margin:0"><a href="https://www.alphabridge-mcp.com/docs" target="_blank" rel="noopener">' . esc_html__( 'View all tools →', 'alphabridge-mcp' ) . '</a></p></div>';

		// Pro pointer. Allowed on the plugin's OWN settings page: it advertises a
		// separately distributed product and states clearly that nothing in THIS
		// plugin is limited — no feature here is presented as locked.
		echo '<div class="postbox" style="padding:4px 16px 14px"><h2 class="hndle" style="padding:10px 0;border:0">AlphaBridge MCP Pro</h2>';
		echo '<p class="description" style="margin:0 0 8px">' . esc_html__( 'A separately distributed commercial plugin adds tool groups for the database, users & menus, plugin and theme files, WooCommerce, SEO, install/update, search & bulk actions, operations, migration, multisite and one-step site deployment over SFTP.', 'alphabridge-mcp' ) . '</p>';
		echo '<p class="description" style="margin:0 0 8px">' . esc_html__( 'Everything in this free plugin is complete on its own and stays fully functional without it.', 'alphabridge-mcp' ) . '</p>';
		echo '<p style="margin:0"><a href="https://www.alphabridge-mcp.com" target="_blank" rel="noopener">' . esc_html__( 'Learn more & purchase →', 'alphabridge-mcp' ) . '</a></p></div>';

		echo '</div>'; // sidebar.
		echo '</div>'; // columns.
		echo '</div>'; // wrap.
	}

	/**
	 * Enqueue the settings-screen script (copy buttons, group toggles) — only
	 * on our own admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_alphabridge-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'ab-mcp-admin',
			AB_MCP_URL . 'assets/admin.js',
			array(),
			AB_MCP_VERSION,
			true
		);
		wp_localize_script(
			'ab-mcp-admin',
			'abMcpAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ab_mcp_ajax' ),
				'copied'     => __( 'Copied', 'alphabridge-mcp' ),
				'working'    => __( 'Working…', 'alphabridge-mcp' ),
				'createHint' => __( 'A connection is active. Copy the token above now — it is not shown again.', 'alphabridge-mcp' ),
				'failed'     => __( 'Something went wrong. Please reload and try again.', 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * Group status label (coloured).
	 *
	 * @param int $on    Enabled count.
	 * @param int $total Total.
	 * @return string
	 */
	private function group_status( $on, $total ) {
		if ( $on <= 0 ) {
			return '<span style="color:#d63638">' . esc_html__( 'Off', 'alphabridge-mcp' ) . '</span>';
		}
		if ( $on >= $total ) {
			return '<span style="color:#008a20">' . esc_html__( 'On', 'alphabridge-mcp' ) . '</span>';
		}
		return '<span style="color:#a06400">' . sprintf( /* translators: 1: enabled, 2: total */ esc_html__( 'Partial (%1$d/%2$d)', 'alphabridge-mcp' ), (int) $on, (int) $total ) . '</span>';
	}

	/**
	 * The connections table (label, access, token, expiry, last used, actions).
	 * Just the table — the create flow lives in the connector box above, so there
	 * is a single, unambiguous place to create a connection.
	 *
	 * @param array $tokens Tokens.
	 */
	private function render_connection_table( $tokens ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Access', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Token', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Expires', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Last used', 'alphabridge-mcp' ) . '</th><th></th></tr></thead><tbody class="ab-conn-rows">';
		echo '<tr class="ab-empty-row"' . ( empty( $tokens ) ? '' : ' hidden' ) . '><td colspan="6"><em>' . esc_html__( 'No connections yet — click “Create connection” above.', 'alphabridge-mcp' ) . '</em></td></tr>';
		foreach ( $tokens as $t ) {
			echo $this->connection_row_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside connection_row_html().
		}
		echo '</tbody></table>';
		echo '<p class="description" style="margin:8px 0 0">' . esc_html__( 'Rotate re-issues a connection’s token (the old one stops working at once). Delete removes it.', 'alphabridge-mcp' ) . '</p>';
	}

	/**
	 * One connections-table row for a stored token entry. Shared by the initial
	 * render and by the ajax create/rotate response so a new or rotated row can be
	 * inserted in place without a reload. Everything is escaped here.
	 *
	 * @param array $t Token entry (hash, prefix, label, scope, expires, last_used).
	 * @return string A single <tr>…</tr>.
	 */
	private function connection_row_html( $t ) {
		$scope_labels = array(
			'read'    => __( 'Read', 'alphabridge-mcp' ),
			'content' => __( 'Content', 'alphabridge-mcp' ),
			'full'    => __( 'Full', 'alphabridge-mcp' ),
		);
		$used         = ! empty( $t['last_used'] ) ? sprintf( /* translators: %s: time ago */ esc_html__( '%s ago', 'alphabridge-mcp' ), human_time_diff( (int) $t['last_used'], current_time( 'timestamp' ) ) ) : '—'; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$scope        = isset( $t['scope'] ) && isset( $scope_labels[ $t['scope'] ] ) ? $scope_labels[ $t['scope'] ] : $scope_labels['full'];
		$exp          = isset( $t['expires'] ) ? (int) $t['expires'] : 0;
		if ( $exp <= 0 ) {
			$exp_txt = '<span style="color:#787c82">' . esc_html__( 'never', 'alphabridge-mcp' ) . '</span>';
		} elseif ( time() > $exp ) {
			$exp_txt = '<span style="color:#d63638">' . esc_html__( 'expired', 'alphabridge-mcp' ) . '</span>';
		} else {
			$exp_txt = esc_html( sprintf( /* translators: %s: duration */ __( 'in %s', 'alphabridge-mcp' ), human_time_diff( time(), $exp ) ) );
		}
		$hash = isset( $t['hash'] ) ? (string) $t['hash'] : '';

		$html  = '<tr data-hash="' . esc_attr( $hash ) . '"><td>' . esc_html( ! empty( $t['label'] ) ? $t['label'] : '—' ) . '</td>';
		$html .= '<td>' . esc_html( $scope ) . '</td>';
		$html .= '<td><code>' . esc_html( isset( $t['prefix'] ) ? $t['prefix'] : '' ) . '…</code></td>';
		$html .= '<td>' . $exp_txt . '</td>';
		$html .= '<td>' . esc_html( $used ) . '</td>';
		$html .= '<td style="white-space:nowrap">';
		// Rotate reveals a new secret, so it runs over ajax like create.
		$html .= '<button type="button" class="button button-small ab-rotate" data-hash="' . esc_attr( $hash ) . '" data-confirm="' . esc_attr__( 'Issue a new secret for this connection? The current token stops working immediately.', 'alphabridge-mcp' ) . '">' . esc_html__( 'Rotate', 'alphabridge-mcp' ) . '</button> ';
		// Delete reveals nothing, so it stays a plain nonce-protected form post.
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-confirm-form" data-confirm="' . esc_attr__( 'Delete connection?', 'alphabridge-mcp' ) . '" style="display:inline">';
		$html .= wp_nonce_field( 'ab_mcp_token', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_token"><input type="hidden" name="delete_token" value="' . esc_attr( $hash ) . '"><button class="button button-small">' . esc_html__( 'Delete', 'alphabridge-mcp' ) . '</button></form></td></tr>';

		return $html;
	}

	/**
	 * Audit log card.
	 */
	private function render_audit() {
		echo '<div class="postbox" style="padding:4px 16px 14px"><h2 class="hndle" style="padding:10px 0;border:0">' . esc_html__( 'Log', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="description" style="margin:0 0 8px">' . esc_html__( 'Tool calls are always logged.', 'alphabridge-mcp' ) . '</p>';
		$log = AB_MCP_Audit_Log::recent( 15 );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Tool', 'alphabridge-mcp' ) . '</th><th>' . esc_html__( 'Status', 'alphabridge-mcp' ) . '</th></tr></thead><tbody>';
		if ( empty( $log ) ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No entries yet.', 'alphabridge-mcp' ) . '</em></td></tr>';
		}
		foreach ( $log as $row ) {
			$color = 'ok' === $row['status'] ? '#008a20' : ( 'denied' === $row['status'] ? '#a06400' : '#c00' );
			echo '<tr><td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) $row['ts'] ) ) . '</td><td><code>' . esc_html( $row['tool'] ) . '</code></td><td style="color:' . esc_attr( $color ) . '">' . esc_html( $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'ab_mcp_audit_clear' );
		echo '<input type="hidden" name="action" value="ab_mcp_audit_clear"><button class="button button-small">' . esc_html__( 'Clear log', 'alphabridge-mcp' ) . '</button></form></div>';
	}

	/**
	 * Render admin notices from the ab_notice query arg.
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice key; no state is changed.
		if ( empty( $_GET['ab_notice'] ) ) {
			return;
		}
		$map = array(
			'saved'         => array( 'success', __( 'Saved.', 'alphabridge-mcp' ) ),
			'token_created' => array( 'success', __( 'Connection created.', 'alphabridge-mcp' ) ),
			'token_deleted' => array( 'success', __( 'Connection deleted.', 'alphabridge-mcp' ) ),
			'audit_cleared' => array( 'success', __( 'Log cleared.', 'alphabridge-mcp' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice key; no state is changed.
		$key = sanitize_key( wp_unslash( $_GET['ab_notice'] ) );
		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}
}
