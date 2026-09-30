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
		add_action( 'admin_post_ab_mcp_review_dismiss', array( $this, 'handle_review_dismiss' ) );
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

		// A connected client may cache the tool list it loaded, and this endpoint
		// offers no server-initiated stream, so it cannot push a
		// notifications/tools/list_changed. Say what to do if the change is not visible.
		$this->redirect( 'tools_saved' );
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
		// The token acts as that user; only someone who may edit the user may
		// hand out its authority (see AB_MCP_Auth::may_issue_token_for()).
		if ( ! AB_MCP_Auth::may_issue_token_for( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot create a connection for that user.', 'alphabridge-mcp' ) ), 403 );
		}
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
		// Rotating hands out a fresh secret for the entry's user: the same rule
		// as creating one for that user.
		$entry = AB_MCP_Settings::get_token_by_hash( $hash );
		if ( null === $entry ) {
			wp_send_json_error( array( 'message' => __( 'Connection not found.', 'alphabridge-mcp' ) ), 404 );
		}
		if ( ! AB_MCP_Auth::may_issue_token_for( isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0 ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot rotate a connection that belongs to that user.', 'alphabridge-mcp' ) ), 403 );
		}
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
	 * «Don't ask again» on the review notice. Final — see AB_MCP_Review_Notice.
	 */
	public function handle_review_dismiss() {
		$this->guard();
		check_admin_referer( 'ab_mcp_review_dismiss' );
		AB_MCP_Review_Notice::dismiss();
		$this->redirect( 'review_dismissed' );
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
	 * The «Mighty» mark for tool groups that can change a lot at once.
	 *
	 * @param bool $mighty Whether the group contains powerful tools.
	 * @return string
	 */
	private function badge( $mighty ) {
		if ( ! $mighty ) {
			return '';
		}
		return '<span class="ab-pill ab-pill--mighty">' . esc_html__( 'Mighty', 'alphabridge-mcp' ) . '</span>';
	}

	/**
	 * Render the page.
	 *
	 * Top to bottom: the band with the logo, this site's state and the choice of
	 * assistant; how to connect the chosen one; what connected assistants may do;
	 * the connections; boxes from add-ons; the log. The side column carries the
	 * Pro box (free edition only), boxes from add-ons and the guides.
	 */
	public function render() {
		$this->guard();

		$tokens    = AB_MCP_Settings::get_tokens();
		$registry  = AB_MCP_Plugin::instance()->registry;
		$groups    = $registry->groups();
		$all       = $registry->all();
		$read_only = (bool) AB_MCP_Settings::get( 'read_only', false );

		echo '<div class="wrap ab-mcp">';
		// WordPress moves admin notices right after this marker. Without it they
		// land after the first heading, which sits inside the band.
		echo '<hr class="wp-header-end">';
		$this->notice();
		// The one-time review request: only here, only once the site has really
		// used the plugin, and never again after «don't ask again».
		echo AB_MCP_Review_Notice::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( $read_only ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Read-only mode is active.', 'alphabridge-mcp' ) . '</strong> ' . esc_html__( 'All writing tools are blocked until you switch it off under Capabilities.', 'alphabridge-mcp' ) . '</p></div>';
		}

		echo $this->hero_html( $tokens, $all, $read_only ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside hero_html().

		echo '<div class="ab-cols">';
		$this->render_main_column( $tokens, $groups, $all, $read_only );
		echo $this->sidebar_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside sidebar_html(); add-on boxes escape their own output.
		echo '</div>'; // columns.
		echo '</div>'; // wrap.
	}

	/**
	 * The main column, top to bottom: connect the chosen assistant, what
	 * connected assistants may do, the connections, boxes from add-ons, the log.
	 * The connections follow the capabilities: first decide what a connection
	 * may do, then manage the connections.
	 *
	 * @param array $tokens    Stored connections.
	 * @param array $groups    Tool groups from the registry.
	 * @param array $all       Every registered tool (name => definition).
	 * @param bool  $read_only Whether read-only mode is on.
	 */
	private function render_main_column( $tokens, $groups, $all, $read_only ) {
		echo '<div class="ab-main">';
		echo $this->connect_card_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside connect_card_html().
		echo $this->capabilities_card_html( $groups, $all, $read_only ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside capabilities_card_html().
		$this->render_connections_card( $tokens );

		/**
		 * Add-ons render extra boxes in the main column here (e.g. the Pro
		 * add-on's Site-Deploy connection).
		 */
		do_action( 'ab_mcp_admin_main_boxes' );

		$this->render_audit();
		echo '</div>';
	}

	/**
	 * The assistants the connect card knows, in the order they are offered.
	 * Claude and ChatGPT connect with this site's login and approval (OAuth);
	 * Cursor and everything else with a token created here.
	 *
	 * @return array<string, array{name: string, hint: string, mark: string}>
	 */
	private function clients() {
		return array(
			'claude'  => array(
				'name' => 'Claude',
				'hint' => __( 'claude.ai, Desktop, iPhone', 'alphabridge-mcp' ),
				'mark' => 'C',
			),
			'chatgpt' => array(
				'name' => 'ChatGPT',
				'hint' => __( 'Plus, Pro, Business', 'alphabridge-mcp' ),
				'mark' => 'G',
			),
			'cursor'  => array(
				'name' => 'Cursor',
				'hint' => __( 'Code editor', 'alphabridge-mcp' ),
				'mark' => 'Cu',
			),
			'other'   => array(
				'name' => __( 'Other', 'alphabridge-mcp' ),
				'hint' => __( 'Claude Code, scripts', 'alphabridge-mcp' ),
				'mark' => '…',
			),
		);
	}

	/**
	 * The band at the top: logo and name, this site's state in three figures,
	 * and the choice of assistant that the connect card below follows.
	 *
	 * @param array $tokens    Stored connections.
	 * @param array $all       Every registered tool (name => definition).
	 * @param bool  $read_only Whether read-only mode is on.
	 * @return string HTML, escaped.
	 */
	private function hero_html( $tokens, $all, $read_only ) {
		$on = 0;
		foreach ( $all as $name => $def ) {
			if ( AB_MCP_Settings::is_tool_enabled( $name, $def ) ) {
				++$on;
			}
		}
		$count = count( (array) $tokens );
		$site  = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $site ) {
			$site = home_url();
		}

		$html  = '<div class="ab-hero">';
		$html .= '<div class="ab-hero__top">';
		$html .= '<img class="ab-logo" src="' . esc_url( AB_MCP_URL . 'assets/logo-128.png' ) . '" width="44" height="44" alt="">';
		$html .= '<h1 class="ab-hero__brand">AlphaBridge MCP</h1>';
		$html .= '<div class="ab-chips">';
		/* translators: %s: number of connections. */
		$html .= '<span class="ab-chip">' . sprintf( esc_html( _n( '%s connection', '%s connections', $count, 'alphabridge-mcp' ) ), '<b>' . (int) $count . '</b>' ) . '</span>';
		/* translators: 1: tools switched on, 2: all tools. */
		$html .= '<span class="ab-chip">' . sprintf( esc_html__( '%1$s of %2$s tools on', 'alphabridge-mcp' ), '<b>' . (int) $on . '</b>', '<b>' . count( $all ) . '</b>' ) . '</span>';
		$html .= '<span class="ab-chip"><span class="ab-dot' . ( $read_only ? ' ab-dot--warn' : '' ) . '" aria-hidden="true"></span>' . ( $read_only ? esc_html__( 'Read-only on', 'alphabridge-mcp' ) : esc_html__( 'Read-only off', 'alphabridge-mcp' ) ) . '</span>';
		$html .= '</div></div>';
		$html .= '<h2 class="ab-hero__title">' . esc_html__( 'Connect your AI assistant', 'alphabridge-mcp' ) . '</h2>';
		/* translators: %s: the site's name. */
		$html .= '<p class="ab-hero__lead">' . esc_html( sprintf( __( 'Which one do you want to connect to “%s”?', 'alphabridge-mcp' ), $site ) ) . '</p>';
		$html .= '<div class="ab-clients" role="group" aria-label="' . esc_attr__( 'Assistant', 'alphabridge-mcp' ) . '">';
		foreach ( $this->clients() as $key => $c ) {
			$html .= '<button type="button" class="ab-client" data-client="' . esc_attr( $key ) . '" aria-controls="ab-panel-' . esc_attr( $key ) . '" aria-pressed="' . ( 'claude' === $key ? 'true' : 'false' ) . '">';
			$html .= '<span class="ab-mark ab-mark--' . esc_attr( $key ) . '" aria-hidden="true">' . esc_html( $c['mark'] ) . '</span>';
			$html .= '<span>' . esc_html( $c['name'] ) . '<small>' . esc_html( $c['hint'] ) . '</small></span></button>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The setup video in the admin's language: German for German, English
	 * otherwise. Linked, never embedded — nothing loads from YouTube until the
	 * viewer clicks.
	 *
	 * @return string
	 */
	private static function video_url() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		return 0 === strpos( (string) $locale, 'de' ) ? 'https://www.youtube.com/watch?v=M262gCOc7gM' : 'https://www.youtube.com/watch?v=DlMJ9tfrqJg';
	}

	/**
	 * A read-only field with a copy button.
	 *
	 * @param string $value  What is shown and copied.
	 * @param string $label  The field's accessible name.
	 * @param string $button Button text; «Copy» when empty.
	 * @return string HTML, escaped.
	 */
	private function copy_field( $value, $label, $button = '' ) {
		$button = '' !== $button ? $button : __( 'Copy', 'alphabridge-mcp' );
		return '<div class="ab-field"><input type="text" readonly value="' . esc_attr( $value ) . '" class="ab-select" aria-label="' . esc_attr( $label ) . '"><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy="' . esc_attr( $value ) . '">' . esc_html( $button ) . '</button></div>';
	}

	/**
	 * A link to another site, opening in a new tab, with a small icon.
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @param string $icon  One character.
	 * @return string HTML, escaped.
	 */
	private function ext_link( $url, $label, $icon ) {
		return '<a class="ab-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><span class="ab-link__ico" aria-hidden="true">' . esc_html( $icon ) . '</span>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a>';
	}

	/**
	 * A button-styled link to another site, opening in a new tab.
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @return string HTML, escaped.
	 */
	private function ext_button( $url, $label ) {
		return '<a class="ab-btn ab-btn--sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . ' <span aria-hidden="true">↗</span><span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a>';
	}

	/**
	 * One numbered step.
	 *
	 * @param int    $n     Number.
	 * @param string $title Title (plain text).
	 * @param string $body  HTML, already escaped.
	 * @return string HTML.
	 */
	private function step( $n, $title, $body ) {
		return '<div class="ab-step"><h3 class="ab-step__title"><span class="ab-step__n" aria-hidden="true">' . (int) $n . '</span>' . esc_html( $title ) . '</h3>' . $body . '</div>';
	}

	/**
	 * What Claude or ChatGPT needs while this site's OAuth flow is switched off.
	 * Both connect with this site's login and approval; the directory entry and
	 * ChatGPT's app cannot work without it, so they are not offered then.
	 *
	 * @param string $name Claude or ChatGPT.
	 * @return string HTML, escaped.
	 */
	private function oauth_off_html( $name ) {
		/* translators: %s: Claude or ChatGPT. */
		$text = sprintf( __( '%s connects with this site’s login and your approval (OAuth), and that is switched off here. Switch it on under Your connections → “Connect from Claude (OAuth, advanced)”.', 'alphabridge-mcp' ), $name );
		$html = '<div class="ab-callout"><p>' . esc_html( $text ) . '</p>';
		if ( 'Claude' === $name ) {
			// The way the page offered before OAuth existed: a token in the
			// connector URL (connector-URL authentication, off by default).
			$html .= '<p>' . esc_html__( 'Until then, Claude.ai can connect with a token in its connector URL: pick “Other”, create a connection and switch on the connector URL it offers.', 'alphabridge-mcp' ) . '</p>';
		}
		return $html . '<p><a class="ab-more" href="#ab-oauth">' . esc_html__( 'Go to the setting', 'alphabridge-mcp' ) . '</a></p></div>';
	}

	/**
	 * The connect card: one panel per assistant (the band above picks which one
	 * shows), the token form for the assistants that need a token, and the
	 * one-time reveal of a new token.
	 *
	 * The reveal sits outside the panels: rotating a connection further down
	 * reveals a new token too, whichever assistant is picked.
	 *
	 * @return string HTML, escaped.
	 */
	private function connect_card_html() {
		$endpoint = untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
		$oauth    = self::oauth_on();
		$guide    = 'https://www.alphabridge-mcp.com/connector.html';
		$docs     = 'https://www.alphabridge-mcp.com/docs';

		$html  = '<div class="ab-card ab-connect" id="ab-connect">';
		$html .= '<p class="ab-eyebrow">' . esc_html__( 'Connect', 'alphabridge-mcp' ) . '</p>';

		// Claude: the directory entry first, the direct way folded away.
		$html .= '<div class="ab-panel" id="ab-panel-claude" data-panel="claude">';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect Claude', 'alphabridge-mcp' ) . '</h2>';
		if ( $oauth ) {
			$html .= '<div class="ab-steps">';
			$html .= $this->step( 1, __( 'Open AlphaBridge in Claude’s directory', 'alphabridge-mcp' ), '<p>' . esc_html__( 'The listing “AlphaBridge MCP for WordPress” opens in Claude.', 'alphabridge-mcp' ) . '</p>' . $this->ext_button( 'https://claude.ai/directory/alphabridge', __( 'Open Claude’s directory', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 2, __( 'Click Connect, enter this address', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Claude asks for your site’s address.', 'alphabridge-mcp' ) . '</p>' . $this->copy_field( home_url(), __( 'This site’s address', 'alphabridge-mcp' ), __( 'Copy address', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 3, __( 'Sign in here and approve', 'alphabridge-mcp' ), '<p>' . esc_html__( 'This site asks you to sign in and to approve. The connection then shows up under Your connections.', 'alphabridge-mcp' ) . '</p>' );
			$html .= '</div>';
			$html .= '<div class="ab-links">' . $this->ext_link( self::video_url(), __( 'Watch the video', 'alphabridge-mcp' ), '▶' ) . $this->ext_link( $guide, __( 'Step-by-step guide', 'alphabridge-mcp' ), '?' ) . '</div>';
			$html .= '<p class="ab-note">' . esc_html__( 'This way runs through the AlphaBridge Connect hub — one hub connection reaches up to 10 sites.', 'alphabridge-mcp' ) . '</p>';
			$html .= '<details class="ab-alt"><summary>' . esc_html__( 'Without the hub: add a custom connector', 'alphabridge-mcp' ) . '</summary>';
			$html .= '<p>' . esc_html__( 'In Claude: Customize → Connectors → Add → Add custom connector. Paste this endpoint and connect; this site asks for your approval — no token to copy.', 'alphabridge-mcp' ) . '</p>';
			$html .= $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) ) . '</details>';
		} else {
			$html .= $this->oauth_off_html( 'Claude' );
		}
		$html .= '</div>';

		// ChatGPT: an app of its own, pointed at this site's endpoint.
		$html .= '<div class="ab-panel" id="ab-panel-chatgpt" data-panel="chatgpt" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect ChatGPT', 'alphabridge-mcp' ) . '</h2>';
		if ( $oauth ) {
			$html .= '<p class="ab-req">' . esc_html__( 'Needs ChatGPT Plus, Pro, Business, Enterprise or Edu.', 'alphabridge-mcp' ) . '</p>';
			$html .= '<div class="ab-steps">';
			$html .= $this->step( 1, __( 'Open ChatGPT’s plugins', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Sign in to ChatGPT and open the plugins page.', 'alphabridge-mcp' ) . '</p>' . $this->ext_button( 'https://chatgpt.com/plugins', __( 'Open ChatGPT', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 2, __( 'Create an MCP app with this endpoint', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Give it a name, paste this endpoint as the server URL, choose OAuth and leave the advanced fields empty.', 'alphabridge-mcp' ) . '</p>' . $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) ) );
			$html .= $this->step( 3, __( 'Approve on this site', 'alphabridge-mcp' ), '<p>' . esc_html__( 'ChatGPT opens this site’s consent page in the same tab. Choose the access level and allow.', 'alphabridge-mcp' ) . '</p>' );
			$html .= '</div>';
			$html .= '<div class="ab-links">' . $this->ext_link( $guide, __( 'Step-by-step guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		} else {
			$html .= $this->oauth_off_html( 'ChatGPT' );
		}
		$html .= '</div>';

		// Cursor: a token and the ready-made configuration.
		$html .= '<div class="ab-panel" id="ab-panel-cursor" data-panel="cursor" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Connect Cursor', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<div class="ab-steps">';
		$html .= $this->step( 1, __( 'Create a connection', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Cursor connects with a token. Create one below.', 'alphabridge-mcp' ) . '</p>' );
		$html .= $this->step( 2, __( 'Copy the configuration', 'alphabridge-mcp' ), '<p>' . esc_html__( 'It appears right after you create the connection, and only then.', 'alphabridge-mcp' ) . '</p>' );
		$html .= $this->step( 3, __( 'Paste it into Cursor', 'alphabridge-mcp' ), '<p>' . esc_html__( 'Open Cursor’s MCP settings (for all projects: ~/.cursor/mcp.json), paste, save and reload the servers.', 'alphabridge-mcp' ) . '</p>' );
		$html .= '</div>';
		$html .= '<div class="ab-links">' . $this->ext_link( $docs, __( 'Setup guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		$html .= '</div>';

		// Everything else: the endpoint and a token.
		$html .= '<div class="ab-panel" id="ab-panel-other" data-panel="other" hidden>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Claude Code, scripts and other clients', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Create a token and send it with every request to this endpoint as “Authorization: Bearer …” — or paste the ready-made configuration.', 'alphabridge-mcp' ) . '</p>';
		$html .= $this->copy_field( $endpoint, __( 'Endpoint URL', 'alphabridge-mcp' ) );
		$html .= '<div class="ab-links">' . $this->ext_link( $docs, __( 'Setup guide', 'alphabridge-mcp' ), '?' ) . '</div>';
		$html .= '</div>';

		$html .= $this->token_form_html();
		$html .= $this->reveal_html();
		$html .= '</div>';

		return $html;
	}

	/**
	 * The token form, shown for Cursor and «Other» — the assistants that need a
	 * token. Claude and ChatGPT never do.
	 *
	 * @return string HTML, escaped.
	 */
	private function token_form_html() {
		$users = wp_dropdown_users(
			array(
				'name'     => 'user_id',
				'selected' => get_current_user_id(),
				'show'     => 'user_login',
				'echo'     => 0,
			)
		);

		$html  = '<div class="ab-token" data-for="cursor other" hidden>';
		$html .= '<div class="ab-create-form">';
		$html .= '<button type="button" class="ab-btn ab-create"><span aria-hidden="true">＋</span> ' . esc_html__( 'Create connection', 'alphabridge-mcp' ) . '</button>';
		$html .= '<details class="ab-advanced"><summary>' . esc_html__( 'Label, user, access, expiry (optional)', 'alphabridge-mcp' ) . '</summary>';
		$html .= '<div class="ab-advanced__fields">';
		$html .= '<label>' . esc_html__( 'Label', 'alphabridge-mcp' ) . ' <input type="text" name="label" placeholder="' . esc_attr__( 'e.g. Cursor on my laptop', 'alphabridge-mcp' ) . '" class="regular-text"></label>';
		$html .= '<label>' . esc_html__( 'User', 'alphabridge-mcp' ) . ' ' . $users . '</label>';
		$html .= '<label>' . esc_html__( 'Access', 'alphabridge-mcp' ) . ' <select name="scope"><option value="full">' . esc_html__( 'Full', 'alphabridge-mcp' ) . '</option><option value="content">' . esc_html__( 'Content only', 'alphabridge-mcp' ) . '</option><option value="read">' . esc_html__( 'Read only', 'alphabridge-mcp' ) . '</option></select></label>';
		$html .= '<label>' . esc_html__( 'Expires in', 'alphabridge-mcp' ) . ' <input type="number" name="expires_days" min="0" max="3650" step="1" value="0"> ' . esc_html__( 'days (0 = never)', 'alphabridge-mcp' ) . '</label>';
		$html .= '</div>';
		$html .= '<p class="ab-note">' . esc_html__( 'Default: full access for you, no expiry. Choose “Content only” or “Read only” to hand a client a deliberately limited key.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</details></div>';
		$html .= '<p class="ab-note">' . esc_html__( 'The token is shown in full only once, right after you create it. Rotate or delete it under Your connections.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * One-time reveal of a new token — appears in place the moment a connection
	 * is created or rotated. Populated by JS from the ajax response and never
	 * shown again (only the hash is stored server-side).
	 *
	 * @return string HTML, escaped.
	 */
	private function reveal_html() {
		$html  = '<div class="ab-reveal" hidden>';
		$html .= '<p class="ab-reveal__ok">' . esc_html__( '✓ Connection created.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="notice notice-warning inline ab-reveal__warn"><p><strong>' . esc_html__( 'Note this down now — it is shown in full only once.', 'alphabridge-mcp' ) . '</strong> ' . esc_html__( 'The token is never stored in readable form. Save it somewhere safe (e.g. a password manager). If you lose it, rotate the connection to get a new one.', 'alphabridge-mcp' ) . '</p></div>';

		// 1) Connector URL with the token embedded — shown ONLY when connector-URL
		// authentication is on, so a copied URL always works; otherwise a one-click
		// enable button takes its place (same option and warning as further down).
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Connector URL', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(for Claude.ai — token included)', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<div class="ab-reveal-url-row ab-field" hidden><input type="text" readonly value="" class="ab-select ab-reveal-url" aria-label="' . esc_attr__( 'Connector URL', 'alphabridge-mcp' ) . '"><button type="button" class="ab-btn ab-btn--sm ab-copy" data-copy-target=".ab-reveal-url">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';
		$html .= '<div class="ab-reveal-url-enable ab-callout" hidden>';
		$html .= '<p>' . esc_html__( 'Claude.ai connects via a URL that embeds the token in its path. This is currently switched off, so no connector URL is offered yet.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-enable-url-auth">' . esc_html__( 'Enable and show the connector URL', 'alphabridge-mcp' ) . '</button>';
		$html .= '</div>';

		// 2) Bearer token — for clients that take a URL and a token separately.
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Bearer token', 'alphabridge-mcp' ) . '</strong> <span class="description">' . esc_html__( '(URL + token entered separately)', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<div class="ab-field"><input type="text" readonly value="" class="ab-select ab-reveal-token" aria-label="' . esc_attr__( 'Bearer token', 'alphabridge-mcp' ) . '"><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy-target=".ab-reveal-token">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';

		// 3) Ready-made config for Cursor / Claude Code (header authentication).
		$html .= '<p class="ab-reveal__label"><strong>' . esc_html__( 'Config for Cursor / Claude Code', 'alphabridge-mcp' ) . '</strong></p>';
		$html .= '<div class="ab-field ab-field--top"><textarea readonly rows="9" class="ab-reveal-json ab-select" aria-label="' . esc_attr__( 'Config for Cursor / Claude Code', 'alphabridge-mcp' ) . '"></textarea><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-copy" data-copy-target=".ab-reveal-json">' . esc_html__( 'Copy', 'alphabridge-mcp' ) . '</button></div>';
		$html .= '<p class="ab-note">' . esc_html__( 'Claude.ai: open Connectors, add a custom connector and paste the Connector URL. Cursor / Claude Code: paste the config into your MCP settings file.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The capabilities card: a switch for all tools, read-only mode, a search,
	 * and one foldable row per group with a switch of its own. Opening a group
	 * lists its tools, each with a switch and what it does (the first sentence;
	 * the whole description behind the «i»).
	 *
	 * The form posts what it always posted: every enabled tool as
	 * enabled_tools[], the list of all tools, and read_only. The switches for
	 * all tools and for a group carry no name — they only set the tool switches.
	 *
	 * @param array $groups    Tool groups from the registry.
	 * @param array $all       Every registered tool (name => definition).
	 * @param bool  $read_only Whether read-only mode is on.
	 * @return string HTML, escaped.
	 */
	private function capabilities_card_html( $groups, $all, $read_only ) {
		$on_total = 0;
		$rows     = '';
		foreach ( $groups as $slug => $g ) {
			$on    = 0;
			$items = '';
			foreach ( $g['tools'] as $tname ) {
				$def     = $all[ $tname ];
				$enabled = AB_MCP_Settings::is_tool_enabled( $tname, $def );
				$on     += $enabled ? 1 : 0;
				$desc    = trim( wp_strip_all_tags( (string) $def['description'] ) );
				$parts   = preg_split( '/(?<=[.!?])\s+/', $desc, 2 );
				$short   = is_array( $parts ) ? (string) $parts[0] : $desc;

				$items .= '<li class="ab-tool-row" data-search="' . esc_attr( strtolower( $tname . ' ' . $desc ) ) . '">';
				$items .= '<label class="ab-switch"><input type="checkbox" class="ab-tool" name="enabled_tools[]" value="' . esc_attr( $tname ) . '"' . checked( $enabled, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $tname ) . '</span></label>';
				$items .= '<div class="ab-tool-row__text"><code>' . esc_html( $tname ) . '</code>';
				if ( is_array( $parts ) && count( $parts ) > 1 ) {
					/* translators: %s: tool name. */
					$items .= '<button type="button" class="ab-info" aria-label="' . esc_attr( sprintf( __( 'Full description of %s', 'alphabridge-mcp' ), $tname ) ) . '" aria-describedby="ab-tip-' . esc_attr( $tname ) . '">i<span class="ab-tip" role="tooltip" id="ab-tip-' . esc_attr( $tname ) . '">' . esc_html( $desc ) . '</span></button>';
				}
				$items .= '<p>' . esc_html( $short ) . '</p></div></li>';
			}
			$on_total += $on;
			$n         = (int) $g['count'];

			$rows .= '<details class="ab-group" data-group="' . esc_attr( (string) $slug ) . '"><summary>';
			/* translators: %s: name of a tool group. */
			$rows .= '<label class="ab-switch ab-group-switch"><input type="checkbox" class="ab-group-toggle"' . checked( $n > 0 && $on >= $n, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( sprintf( __( 'All tools in %s', 'alphabridge-mcp' ), $g['label'] ) ) . '</span></label>';
			$rows .= '<strong>' . esc_html( $g['label'] ) . '</strong>' . ( ! empty( $g['mighty'] ) ? ' ' . $this->badge( true ) : '' );
			/* translators: %d: number of tools. */
			$rows .= ' <span class="ab-group__n">' . esc_html( sprintf( _n( '%d tool', '%d tools', $n, 'alphabridge-mcp' ), $n ) ) . '</span>';
			$rows .= '<span class="ab-group__right">' . $this->group_status( $on, $n ) . '<span class="ab-chev" aria-hidden="true">›</span></span>';
			$rows .= '</summary><ul class="ab-tool-list">' . $items . '</ul></details>';
		}
		$total = count( $all );

		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ab-caps" class="ab-card ab-caps">';
		$html .= wp_nonce_field( 'ab_mcp_save', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_save">';
		$html .= '<input type="hidden" name="all_tools" value="' . esc_attr( implode( ',', array_keys( $all ) ) ) . '">';
		$html .= '<p class="ab-eyebrow">' . esc_html__( 'Capabilities', 'alphabridge-mcp' ) . '</p>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'What connected assistants may do', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Switch whole groups or single tools. Open a group to see every tool and what it does. Disabled tools are removed from the MCP server and REST API entirely — no client can see or call them.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="ab-capbar">';
		$html .= '<label class="ab-capbar__item"><span class="ab-switch"><input type="checkbox" class="ab-all-toggle"' . checked( $on_total === $total, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span></span>' . esc_html__( 'All tools', 'alphabridge-mcp' ) . '</label>';
		$html .= '<label class="ab-capbar__item"><span class="ab-switch"><input type="checkbox" name="read_only" value="1"' . checked( $read_only, true, false ) . '><span class="ab-switch__track" aria-hidden="true"></span></span>' . esc_html__( 'Read-only mode', 'alphabridge-mcp' ) . '</label>';
		$html .= '<input type="search" class="ab-search" placeholder="' . esc_attr__( 'Find a tool…', 'alphabridge-mcp' ) . '" aria-label="' . esc_attr__( 'Find a tool', 'alphabridge-mcp' ) . '">';
		/* translators: 1: tools switched on, 2: all tools. */
		$html .= '<span class="ab-capbar__count">' . sprintf( esc_html__( '%1$s of %2$s on', 'alphabridge-mcp' ), '<b class="ab-on-count">' . (int) $on_total . '</b>', '<b>' . (int) $total . '</b>' ) . '</span>';
		$html .= '</div>';
		$html .= '<p class="ab-note ab-caps__ro">' . esc_html__( 'Read-only mode blocks every writing tool with one switch, regardless of the switches below; read, list and search tools keep working.', 'alphabridge-mcp' ) . '</p>';
		$html .= $rows;
		$html .= '<p class="ab-note ab-caps__none" hidden>' . esc_html__( 'No tool matches.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="ab-savebar"><button type="submit" class="ab-btn">' . esc_html__( 'Save capabilities', 'alphabridge-mcp' ) . '</button>';
		$html .= '<span class="ab-savebar__text" data-dirty="' . esc_attr__( 'Unsaved changes — save to apply them.', 'alphabridge-mcp' ) . '">' . esc_html__( 'New connections see the change at once. A client that is already connected may cache the tool list it loaded; if the change is not visible there, refresh its tool list or reconnect it.', 'alphabridge-mcp' ) . '</span></div>';
		/* translators: %d: requests per minute. */
		$html .= '<p class="ab-note">' . esc_html( sprintf( __( 'Abuse protection active (fixed): %d requests/min.', 'alphabridge-mcp' ), (int) AB_MCP_Settings::get( 'rate_limit_per_min', 120 ) ) ) . '</p>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * A group's state as a pill: on, off, or how many of how many are on.
	 * admin.js keeps it current while switches change (same words, same form).
	 *
	 * @param int $on    Tools switched on.
	 * @param int $total Tools in the group.
	 * @return string HTML, escaped.
	 */
	private function group_status( $on, $total ) {
		if ( $on <= 0 ) {
			return '<span class="ab-pill ab-pill--off ab-status">' . esc_html__( 'Off', 'alphabridge-mcp' ) . '</span>';
		}
		if ( $on >= $total ) {
			return '<span class="ab-pill ab-pill--on ab-status">' . esc_html__( 'On', 'alphabridge-mcp' ) . '</span>';
		}
		/* translators: 1: tools switched on, 2: tools in the group. */
		return '<span class="ab-pill ab-pill--partial ab-status">' . esc_html( sprintf( __( '%1$d/%2$d', 'alphabridge-mcp' ), (int) $on, (int) $total ) ) . '</span>';
	}

	/**
	 * The connections card: the table, then the two advanced settings that
	 * decide how connections may authenticate.
	 *
	 * @param array $tokens Stored connections.
	 */
	private function render_connections_card( $tokens ) {
		$path_auth = (bool) AB_MCP_Settings::get( 'connector_url_auth_enabled', false );
		$oauth_on  = (bool) AB_MCP_Settings::get( 'oauth_enabled', true );

		echo '<div class="ab-card ab-connections" id="ab-connections">';
		echo '<p class="ab-eyebrow">' . esc_html__( 'Connections', 'alphabridge-mcp' ) . '</p>';
		echo '<h2 class="ab-card__title">' . esc_html__( 'Your connections', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="ab-lead">' . esc_html__( 'Every assistant and client connected to this site.', 'alphabridge-mcp' ) . '</p>';
		$this->render_connection_table( $tokens );

		// Advanced (collapsed): connector-URL (token-in-path) authentication, opt-in.
		echo '<details class="ab-alt" id="ab-url-auth"><summary>' . esc_html__( 'Connector-URL authentication (advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ab_mcp_connector_auth' );
		echo '<input type="hidden" name="action" value="ab_mcp_connector_auth">';
		echo '<label class="ab-check"><input type="checkbox" name="connector_url_auth_enabled" value="1"' . checked( $path_auth, true, false ) . '><span>' . esc_html__( 'Also offer a connector URL that embeds the token in its path.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'Off by default. Header authentication (Authorization / X-Api-Key) always works. A token in a URL leaks more easily via referrers, proxy logs and history — treat the connector URL like a password.', 'alphabridge-mcp' ) . '</span></span></label>';
		echo '<p><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';

		// Advanced (collapsed): connecting from Claude or ChatGPT (OAuth). On by
		// default; switching it off removes the discovery metadata, the consent
		// page and the OAuth endpoints entirely.
		echo '<details class="ab-alt" id="ab-oauth"><summary>' . esc_html__( 'Connect from Claude (OAuth, advanced)', 'alphabridge-mcp' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ab_mcp_oauth_settings' );
		echo '<input type="hidden" name="action" value="ab_mcp_oauth_settings">';
		echo '<label class="ab-check"><input type="checkbox" name="oauth_enabled" value="1"' . checked( $oauth_on, true, false ) . '><span>' . esc_html__( 'Let Claude connect with its native Connect button.', 'alphabridge-mcp' ) . ' <span class="description">' . esc_html__( 'On by default. Claude discovers this site, you approve on a login-protected consent screen, and the approved connection appears in the list above (revocable any time). Nothing connects without that approval. Switching this off removes the OAuth endpoints; existing connections keep working.', 'alphabridge-mcp' ) . '</span></span></label>';
		echo '<p><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Save', 'alphabridge-mcp' ) . '</button></p>';
		echo '</form></details>';
		echo '</div>';
	}

	/**
	 * The connections table (label, access, token, expiry, last used, actions).
	 * Just the table — connections are created in the connect card above.
	 *
	 * @param array $tokens Tokens.
	 */
	private function render_connection_table( $tokens ) {
		echo '<div class="ab-table-wrap"><table class="ab-table"><thead><tr><th scope="col">' . esc_html__( 'Label', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Access', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Token', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Expires', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Last used', 'alphabridge-mcp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'alphabridge-mcp' ) . '</span></th></tr></thead><tbody class="ab-conn-rows">';
		echo '<tr class="ab-empty-row"' . ( empty( $tokens ) ? '' : ' hidden' ) . '><td colspan="6"><em>' . esc_html__( 'No connections yet — pick your assistant at the top and follow the steps.', 'alphabridge-mcp' ) . '</em></td></tr>';
		$older = AB_MCP_Settings::older_of_same_app( $tokens );
		foreach ( $tokens as $t ) {
			$is_older = isset( $t['hash'] ) && isset( $older[ (string) $t['hash'] ] );
			echo $this->connection_row_html( $t, $is_older ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside connection_row_html().
		}
		echo '</tbody></table></div>';
		echo '<p class="ab-note">' . esc_html__( 'Rotate re-issues a connection’s token (the old one stops working at once). Delete removes it.', 'alphabridge-mcp' ) . '</p>';
	}

	/**
	 * One connections-table row for a stored token entry. Shared by the initial
	 * render and by the ajax create/rotate response so a new or rotated row can be
	 * inserted in place without a reload. Everything is escaped here.
	 *
	 * The five cells before the actions carry no attributes; the tests read them
	 * as plain cells.
	 *
	 * @param array $t        Token entry (hash, prefix, label, scope, expires, last_used).
	 * @param bool  $is_older The same app has a newer connection for the same
	 *                        user (AB_MCP_Settings::older_of_same_app()).
	 * @return string A single <tr>…</tr>.
	 */
	private function connection_row_html( $t, $is_older = false ) {
		$scope_labels = array(
			'read'    => __( 'Read', 'alphabridge-mcp' ),
			'content' => __( 'Content', 'alphabridge-mcp' ),
			'full'    => __( 'Full', 'alphabridge-mcp' ),
		);
		// last_used and expires are stored as time(), UTC timestamps, and shown
		// as the site's date and time in digits, like the log below. They used
		// to read «%s ago» and «in %s» around human_time_diff(): WordPress
		// translates the time span, the words around it need this plugin's own
		// language pack, and without one a German site read «2 Wochen ago».
		$used      = ! empty( $t['last_used'] ) ? self::site_datetime( (int) $t['last_used'] ) : '—';
		$scope_key = isset( $t['scope'] ) && isset( $scope_labels[ $t['scope'] ] ) ? (string) $t['scope'] : 'full';
		$exp       = isset( $t['expires'] ) ? (int) $t['expires'] : 0;
		if ( $exp <= 0 ) {
			$exp_txt = '<span class="ab-muted">' . esc_html__( 'never', 'alphabridge-mcp' ) . '</span>';
		} elseif ( time() > $exp ) {
			$exp_txt = '<span class="ab-bad">' . esc_html__( 'expired', 'alphabridge-mcp' ) . '</span>';
		} else {
			$exp_txt = esc_html( self::site_datetime( $exp ) );
		}
		$hash = isset( $t['hash'] ) ? (string) $t['hash'] : '';

		$html = '<tr data-hash="' . esc_attr( $hash ) . '"><td><strong>' . esc_html( ! empty( $t['label'] ) ? $t['label'] : '—' ) . '</strong>';
		if ( $is_older ) {
			// A hint, not a verdict: the older connection may still be in use.
			$html .= '<br><span class="description ab-older">' . esc_html__( 'The same app connected again later. Compare “Last used” before you delete this older connection.', 'alphabridge-mcp' ) . '</span>';
		}
		$html .= '</td>';
		$html .= '<td><span class="ab-pill ab-pill--' . esc_attr( $scope_key ) . '">' . esc_html( $scope_labels[ $scope_key ] ) . '</span></td>';
		$html .= '<td><code>' . esc_html( isset( $t['prefix'] ) ? $t['prefix'] : '' ) . '…</code></td>';
		$html .= '<td>' . $exp_txt . '</td>';
		$html .= '<td>' . esc_html( $used ) . '</td>';
		$html .= '<td class="ab-actions">';
		// Rotate reveals a new secret, so it runs over ajax like create.
		$html .= '<button type="button" class="ab-btn ab-btn--ghost ab-btn--sm ab-rotate" data-hash="' . esc_attr( $hash ) . '" data-confirm="' . esc_attr__( 'Issue a new secret for this connection? The current token stops working immediately.', 'alphabridge-mcp' ) . '">' . esc_html__( 'Rotate', 'alphabridge-mcp' ) . '</button> ';
		// Delete reveals nothing, so it stays a plain nonce-protected form post.
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-confirm-form" data-confirm="' . esc_attr__( 'Delete connection?', 'alphabridge-mcp' ) . '">';
		$html .= wp_nonce_field( 'ab_mcp_token', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_token"><input type="hidden" name="delete_token" value="' . esc_attr( $hash ) . '"><button class="ab-btn ab-btn--ghost ab-btn--sm ab-btn--danger">' . esc_html__( 'Delete', 'alphabridge-mcp' ) . '</button></form></td></tr>';

		return $html;
	}

	/**
	 * Audit log card.
	 */
	private function render_audit() {
		echo '<div class="ab-card ab-log" id="ab-log">';
		echo '<p class="ab-eyebrow">' . esc_html__( 'Log', 'alphabridge-mcp' ) . '</p>';
		echo '<h2 class="ab-card__title">' . esc_html__( 'Recent tool calls', 'alphabridge-mcp' ) . '</h2>';
		echo '<p class="ab-lead">' . esc_html__( 'Tool calls are always logged.', 'alphabridge-mcp' ) . '</p>';
		$log = AB_MCP_Audit_Log::recent( 15 );
		echo '<div class="ab-table-wrap"><table class="ab-table"><thead><tr><th scope="col">' . esc_html__( 'Time', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Tool', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Status', 'alphabridge-mcp' ) . '</th></tr></thead><tbody>';
		if ( empty( $log ) ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No entries yet.', 'alphabridge-mcp' ) . '</em></td></tr>';
		}
		foreach ( $log as $row ) {
			$dot = 'ok' === $row['status'] ? 'ab-dot' : ( 'denied' === $row['status'] ? 'ab-dot ab-dot--warn' : 'ab-dot ab-dot--bad' );
			// ts is time(), a UTC timestamp. wp_date() shows it in the site's
			// timezone, like every other date in WordPress. date_i18n() reads its
			// argument as a timestamp already shifted to local time and printed
			// the UTC clock instead.
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['ts'] ) ) . '</td><td><code>' . esc_html( $row['tool'] ) . '</code></td><td><span class="' . esc_attr( $dot ) . '" aria-hidden="true"></span> ' . esc_html( $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-log__clear">';
		wp_nonce_field( 'ab_mcp_audit_clear' );
		echo '<input type="hidden" name="action" value="ab_mcp_audit_clear"><button class="ab-btn ab-btn--ghost ab-btn--sm">' . esc_html__( 'Clear log', 'alphabridge-mcp' ) . '</button></form></div>';
	}

	/**
	 * The side column: the Pro box (free edition only), the invitation to the
	 * affiliate programme, boxes from add-ons, the guides.
	 *
	 * @return string HTML; add-on boxes escape their own output.
	 */
	private function sidebar_html() {
		ob_start();
		/**
		 * Add-ons render boxes in the side column here, below the Pro box and
		 * above the guides — the Pro add-on's affiliate box, for one.
		 *
		 * @since 4.3.10
		 */
		do_action( 'ab_mcp_admin_side_boxes' );
		$addons = (string) ob_get_clean();

		return '<div class="ab-side">' . $this->pro_box_html() . $this->affiliate_invite_html() . $addons . $this->guides_html() . '</div>';
	}

	/**
	 * One line that invites to the affiliate programme, or ''.
	 *
	 * Like the Pro box it stands only on the plugin's OWN settings page
	 * (WordPress.org guideline 11). It links to the programme's page on the
	 * product website, which carries the current terms and how to apply; the
	 * address has no query and so no tracking. The commission is paid on Pro and
	 * Agency sales, never on this free plugin, and the line says so.
	 *
	 * The 60% is the programme's commission as set at Freemius on 29.09.2026
	 * (renewals included, lifetime commission on). This plugin cannot ask
	 * Freemius; a change of the terms needs a new release of this line.
	 *
	 * Where the Pro add-on reads the site's own affiliate state from Freemius,
	 * its box in the side column (figures, invitation or pending application)
	 * takes this line's place. From Pro 4.5.14 the add-on answers the filter
	 * `ab_mcp_affiliate_invite`; an older Pro that already hangs its affiliate
	 * box on the side column has no answer, so the line then stays away rather
	 * than stand next to that box.
	 *
	 * @return string HTML, escaped.
	 */
	private function affiliate_invite_html() {
		/**
		 * Whether the side column shows the invitation to the affiliate programme.
		 *
		 * @since 4.3.11
		 *
		 * @param bool $show True unless an older Pro add-on hangs its own affiliate box there.
		 */
		$show = (bool) apply_filters( 'ab_mcp_affiliate_invite', false === has_action( 'ab_mcp_admin_side_boxes', 'ab_mcp_pro_aff_side_box' ) );
		if ( ! $show ) {
			return '';
		}

		/* translators: the commission of the AlphaBridge affiliate programme; keep the number. */
		$pct = __( '60%', 'alphabridge-mcp' );

		$html  = '<div class="ab-invite">';
		$html .= '<span class="ab-invite__pct" aria-hidden="true">' . esc_html( $pct ) . '</span>';
		$html .= '<p><strong>' . esc_html__( 'Earn with AlphaBridge.', 'alphabridge-mcp' ) . '</strong> ';
		/* translators: %s: the commission, e.g. 60% */
		$html .= esc_html( sprintf( __( '%s of every Pro and Agency sale you refer, renewals included.', 'alphabridge-mcp' ), $pct ) );
		$html .= ' <a class="ab-more" href="' . esc_url( 'https://www.alphabridge-mcp.com/affiliates.html' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Become an affiliate', 'alphabridge-mcp' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The guides card: the video, connecting step by step, all tools.
	 *
	 * @return string HTML, escaped.
	 */
	private function guides_html() {
		$links = array(
			array( self::video_url(), __( 'Watch the video', 'alphabridge-mcp' ) ),
			array( 'https://www.alphabridge-mcp.com/connector.html', __( 'Connect step by step', 'alphabridge-mcp' ) ),
			array( 'https://www.alphabridge-mcp.com/docs', __( 'All tools', 'alphabridge-mcp' ) ),
		);

		$html  = '<div class="ab-card ab-guides"><p class="ab-eyebrow">' . esc_html__( 'Help', 'alphabridge-mcp' ) . '</p>';
		$html .= '<h2 class="ab-card__title">' . esc_html__( 'Guides', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<p class="ab-lead">' . esc_html__( 'Setup, every tool and examples.', 'alphabridge-mcp' ) . '</p><ul class="ab-guides__list">';
		foreach ( $links as $l ) {
			$html .= '<li><a class="ab-more" href="' . esc_url( $l[0] ) . '" target="_blank" rel="noopener">' . esc_html( $l[1] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span></a></li>';
		}
		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * The side-column box about AlphaBridge MCP Pro, or '' where Pro already runs.
	 *
	 * Allowed on the plugin's OWN settings page (WordPress.org guideline 11): it
	 * advertises a separately distributed product and says that nothing in THIS
	 * plugin is limited — no feature here is presented as locked (guideline 9).
	 * The look comes from assets/admin.css, which ships with the plugin; nothing
	 * loads from outside.
	 *
	 * The button starts the 7-day trial, which needs no card; the two prices
	 * below it buy right away. Each link opens the Freemius checkout of the Pro
	 * plan for exactly what it names — the addresses the website's buttons fall
	 * back to. The query says only what opens (trial, monthly or yearly) and
	 * carries no tracking. The website cannot preselect the monthly price, so a
	 * link there would land a «$9/month» click on the yearly one.
	 *
	 * The list names what the Pro edition adds. Site Deploy belongs to the Agency
	 * edition and is left out, so the Pro prices buy everything listed.
	 *
	 * A site whose add-on reports a Pro or Agency licence through
	 * `ab_mcp_site_edition` is not offered what it already has. Every other value,
	 * an expired licence included, reads as the free edition, as it does in the
	 * site's self-description.
	 *
	 * @return string
	 */
	private function pro_box_html() {
		$edition = (string) apply_filters( 'ab_mcp_site_edition', 'free' );
		if ( in_array( $edition, array( 'pro', 'agency' ), true ) ) {
			return '';
		}

		$checkout = 'https://checkout.freemius.com/plugin/35076/plan/57642/';
		$new_tab  = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'alphabridge-mcp' ) . '</span>';
		$link     = static function ( $query, $label, $class = '' ) use ( $checkout, $new_tab ) {
			return '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $checkout . '?' . $query ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . $new_tab . '</a>';
		};
		$items    = array(
			__( 'Over 120 tools on this site', 'alphabridge-mcp' ),
			__( 'Theme and plugin files, database, users, menus', 'alphabridge-mcp' ),
			__( 'WooCommerce, SEO editing, installs and updates', 'alphabridge-mcp' ),
			__( 'Undo points for 24 hours', 'alphabridge-mcp' ),
			__( 'Search and replace with preview', 'alphabridge-mcp' ),
		);

		$html  = '<div class="postbox ab-pro">';
		$html .= '<p class="ab-pro__eyebrow">AlphaBridge MCP Pro <span class="ab-pro__pill">' . esc_html__( '7 days free', 'alphabridge-mcp' ) . '</span></p>';
		$html .= '<h2 class="ab-pro__title">' . esc_html__( 'Let Claude run the whole site', 'alphabridge-mcp' ) . '</h2>';
		$html .= '<ul class="ab-pro__list">';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( $item ) . '</li>';
		}
		$html .= '</ul>';
		$html .= $link( 'trial=free&billing_cycle=monthly', __( 'Try Pro free for 7 days', 'alphabridge-mcp' ), 'ab-pro__cta' );
		$html .= '<p class="ab-pro__alt">' . sprintf(
			/* translators: 1: link with the monthly price, 2: link with the yearly price. */
			esc_html__( 'No card needed · then %1$s or %2$s · cancel anytime', 'alphabridge-mcp' ),
			/* translators: monthly price of AlphaBridge MCP Pro in US dollars; keep the amount. */
			$link( 'billing_cycle=monthly', __( '$9/month', 'alphabridge-mcp' ) ),
			/* translators: yearly price of AlphaBridge MCP Pro in US dollars; keep the amount. */
			$link( 'billing_cycle=annual', __( '$49/year', 'alphabridge-mcp' ) )
		) . '</p>';
		$html .= '<p class="ab-pro__note">' . esc_html__( 'A separate plugin. Everything in this free plugin is complete on its own and stays fully functional without it.', 'alphabridge-mcp' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Enqueue the settings screen's script (copy buttons, the choice of
	 * assistant, the capability switches and search, the token create/rotate
	 * flow) and its stylesheet — only on our own admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_alphabridge-mcp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ab-mcp-admin', AB_MCP_URL . 'assets/admin.css', array(), AB_MCP_VERSION );
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
				'on'         => __( 'On', 'alphabridge-mcp' ),
				'off'        => __( 'Off', 'alphabridge-mcp' ),
				/* translators: 1: tools switched on, 2: tools in the group. */
				'partial'    => __( '%1$d/%2$d', 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * Whether this site's OAuth flow (connecting from Claude) is switched on.
	 *
	 * @return bool
	 */
	private static function oauth_on() {
		return class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled();
	}

	/**
	 * A stored UTC timestamp as the site's date and time, in digits only
	 * («2026-09-27 12:52»). Digits read the same in every language, so the
	 * text needs no translation, and the form matches the log's time column.
	 * Minutes, not seconds: AB_MCP_Settings::touch_token() writes last_used at
	 * most every five minutes.
	 *
	 * @param int $ts Unix timestamp (time()).
	 * @return string Unescaped text.
	 */
	private static function site_datetime( $ts ) {
		$text = wp_date( 'Y-m-d H:i', (int) $ts );
		return is_string( $text ) ? $text : '—';
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
			'tools_saved'   => array( 'success', __( 'Saved. Clients may cache the tool list they loaded. If the change is not visible in a connected client, refresh its tool list or reconnect it (Claude: in Connectors, refresh this connector’s tool list or disconnect and connect again; Claude Code: /mcp, then reconnect).', 'alphabridge-mcp' ) ),
			'token_created' => array( 'success', __( 'Connection created.', 'alphabridge-mcp' ) ),
			'token_deleted' => array( 'success', __( 'Connection deleted.', 'alphabridge-mcp' ) ),
			'audit_cleared' => array( 'success', __( 'Log cleared.', 'alphabridge-mcp' ) ),
			'review_dismissed' => array( 'success', __( 'Noted — the plugin will not ask for a review again.', 'alphabridge-mcp' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice key; no state is changed.
		$key = sanitize_key( wp_unslash( $_GET['ab_notice'] ) );
		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}
}
